import { defineStore } from 'pinia';
import { SCREENS } from '@/screens.js';
import { useUi } from './ui.js';
import { useTasks } from './tasks.js';

export const useNav = defineStore('nav', {
    state: () => ({
        stack: [{ name: 'home', forceBack: false }],
    }),
    getters: {
        top: s => s.stack[s.stack.length - 1],
        current() { return this.top.name; },
        meta() { return SCREENS[this.current] ?? {}; },
        /** Root screens have no Back button, unless they were opened on top of another screen on purpose. */
        showBack() { return this.top.forceBack || !this.meta.root; },
    },
    actions: {
        go(name, { forceBack = false } = {}) {
            this.stack.push({ name, forceBack });
        },
        /** Start a new stack, e.g. reset('home', 'report-done'). */
        reset(...names) {
            this.stack = names.map(name => ({ name, forceBack: false }));
        },
        pop() {
            this.stack.pop();
            if (!this.stack.length) this.stack.push({ name: 'home', forceBack: false });
        },
        /** Back (header or Telegram's button): close an open sheet first, and never drop unapplied edits silently. */
        back() {
            const ui = useUi();
            if (ui.closeSheet()) return;
            const tasks = useTasks();
            if (this.current === 'detail' && tasks.pendingCount) {
                ui.confirm({
                    title: 'detail.leaveTitle',
                    text: 'detail.leaveText',
                    count: tasks.pendingCount,
                    ok: 'detail.leaveOk',
                    no: 'detail.leaveNo',
                }, () => { tasks.discardAll(); this.pop(); });
                return;
            }
            this.pop();
        },
    },
});
