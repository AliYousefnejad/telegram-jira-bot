// Who is using the app: the Telegram user (from the launch data) and their linked Jira account.
import { defineStore } from 'pinia';
import { api } from '@/api/index.js';
import { text } from '@/i18n/index.js';
import { telegramUser } from '@/lib/telegram.js';
import { useNav } from './nav.js';
import { useTasks } from './tasks.js';
import { useUi } from './ui.js';

const fromLaunchData = telegramUser && {
    id: telegramUser.id,
    username: telegramUser.username ?? null,
    name: [telegramUser.first_name, telegramUser.last_name].filter(Boolean).join(' ') || 'Telegram user',
};

export const useSession = defineStore('session', {
    state: () => ({
        telegram: fromLaunchData || { id: null, username: null, name: '' },
        jira: null,
        avatarUrl: null,
        /** Which sign-in method the link screen opens with (email | pat) */
        linkMethod: 'email',
    }),
    getters: {
        handle: s => (s.telegram.username ? '@' + s.telegram.username : ''),
        initials: s => s.telegram.name.split(/\s+/).map(w => w[0] ?? '').join('').slice(0, 2).toUpperCase(),
    },
    actions: {
        /** Who is this, and may they in? Then load what the home screen and the forms need. */
        async boot() {
            const nav = useNav(), tasks = useTasks();
            try {
                const me = await api.me();
                if (!fromLaunchData && me.telegram) this.telegram = me.telegram;
                if (!me.jira) { nav.reset('link-email'); return; }
                this.jira = me.jira;
                if (me.jira.token_status === 'expired') { nav.reset('token-expired'); return; }
                if (me.jira.has_avatar) api.avatar().then(url => { this.avatarUrl = url; }).catch(() => {});
                await Promise.all([tasks.loadOpen(), tasks.loadStatuses(), tasks.loadAssignees()]);
            } catch (e) {
                if (e.code === 'not_admin') nav.reset('gate-denied');
                else if (e.code === 'private_only') nav.reset('gate-group');
                else if (e.code === 'pat_invalid') this.tokenRejected();
                else if (e.code === 'invalid_init_data' || e.code === 'missing_init_data') nav.reset('gate-outside');
                else useUi().notify(text('app.serverDown'), 'error');
            }
        },
        /** A Jira account was just linked on the link screen. */
        linked(jira) {
            this.jira = jira;
            this.avatarUrl = null;
            this.linkMethod = 'email';
        },
        /** The server erased the user's Jira token (expired or revoked): ask how to go on. */
        tokenRejected() {
            if (this.jira) this.jira = { ...this.jira, token_status: 'expired' };
            useUi().closeSheet();
            useNav().reset('token-expired');
        },
        /** Unlink on the server (this also erases a stored token), then start over at the link screen. */
        async logout() {
            try {
                await api.logout();
            } catch {
                useUi().notify(text('app.serverDown'), 'error');
                return;
            }
            useTasks().discardAll();
            this.jira = null;
            this.avatarUrl = null;
            this.linkMethod = 'email';
            useNav().reset('link-email');
            useUi().notify(text('profile.loggedOut'));
        },
    },
});
