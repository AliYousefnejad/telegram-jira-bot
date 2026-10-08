// Tasks: the open queue (home), the board's statuses (status menu), the filtered list, and the open task with
// the edits staged on top of it (like the bot's rpt_ctx pending[]).
import { defineStore } from 'pinia';
import { api } from '@/api/index.js';
import { text } from '@/i18n/index.js';
import { useUi } from './ui.js';

/** The editable fields, in display order. `latin`: Jira values shown left-to-right. */
export const FIELDS = {
    status: { latin: true },
    assignee: {},
    owner: { latin: true },
    severity: { latin: true },
    impact: { latin: true },
    category: {},
};

// Fixed choices: [shown label, value sent to Jira]. Statuses (reachable transitions), assignees and categories
// come from Jira per task.
const FIXED = {
    owner: [['L1', 'L1'], ['L2', 'L2'], ['Reporter', 'Reporter'], ['CTO', 'CTO']],
    severity: [['S1 – Critical', 'S1'], ['S2 – High', 'S2'], ['S3 – Medium', 'S3'], ['S4 – Low', 'S4']],
    impact: [['I1 – Extensive', 'I1'], ['I2 – Significant', 'I2'], ['I3 – Limited', 'I3'], ['I4 – Minimal', 'I4']],
};

const NONE = '—';
let listRequest = 0;

export const useTasks = defineStore('tasks', {
    state: () => ({
        open: [],
        openLoaded: false,
        statuses: [],
        openCount: 0,
        statusesLoaded: false,
        assignees: [],

        filter: 'all',
        list: [],
        /** Which filter `list` holds (null = not loaded yet) */
        listFilter: null,
        listLoading: false,
        query: '',

        key: null,
        task: null,
        detailLoading: false,
        detailError: null,
        /** Jira values as they are now (assignee: name or null) */
        current: {},
        /** field -> { to (shown), id (sent to Jira), auto } */
        pending: {},
        /** What this task can change to: { transitions: [{ id, name, owner }], categories: [{ id, value }] | null } */
        options: { transitions: [], categories: null },
        optionsLoading: false,
        applying: false,
    }),
    getters: {
        pendingCount: s => Object.keys(s.pending).length,
        /** Jira display name -> linked Telegram handle (null = not linked) */
        telegramOf(s) {
            const map = Object.fromEntries(s.assignees.map(a => [a.name, a.telegram]));
            if (s.task?.assignee) map[s.task.assignee.name] = s.task.assignee.telegram;
            return map;
        },
        filterStatus: s => s.statuses.find(st => st.name === s.filter) ?? null,
        severityCounts: s => {
            const by = { S1: 0, S2: 0, S3: 0, S4: 0 };
            s.open.forEach(t => { if (t.severity in by) by[t.severity]++; });
            return by;
        },
        /** S1 tasks nobody has touched for a day */
        staleS1: s => s.open.filter(t => t.severity === 'S1' && Date.now() - Date.parse(t.updated) > 864e5).length,
        /** Where the open task sits in its owner's queue (the list is sorted by severity, impact, update) */
        rank(s) {
            const owner = s.task?.owner;
            if (!owner) return null;
            const queue = s.open.filter(t => t.owner === owner);
            const at = queue.findIndex(t => t.key === s.key);
            return at < 0 ? null : { owner, position: at + 1, of: queue.length };
        },
    },
    actions: {
        async loadOpen() {
            this.open = (await api.tasks()).tasks;
            this.openLoaded = true;
        },
        async loadStatuses() {
            const data = await api.statuses();
            this.openCount = data.open;
            this.statuses = data.statuses;
            this.statusesLoaded = true;
        },
        async loadAssignees() {
            try { this.assignees = (await api.assignees()).assignees; } catch { /* the form still works without */ }
        },

        setFilter(filter) {
            this.filter = filter;
            return this.loadList();
        },
        async loadList() {
            const request = ++listRequest;
            this.listLoading = true;
            try {
                const { tasks } = await api.tasks(this.filter);
                if (request === listRequest) { this.list = tasks; this.listFilter = this.filter; }
            } catch {
                if (request === listRequest) { this.list = []; useUi().notify(text('tasks.loadFailed'), 'error'); }
            } finally {
                if (request === listRequest) this.listLoading = false;
            }
        },

        async openTask(key) {
            Object.assign(this, { key, task: null, detailLoading: true, detailError: null, pending: {}, current: {} });
            this.loadOptions(key);
            try {
                const { task } = await api.task(key);
                if (this.key !== key) return;
                this.showTask(task);
            } catch (e) {
                if (this.key === key) this.detailError = e.status === 404 ? 'detail.notFound' : 'detail.loadFailed';
            } finally {
                if (this.key === key) this.detailLoading = false;
            }
        },
        async loadOptions(key) {
            Object.assign(this, { options: { transitions: [], categories: null }, optionsLoading: true });
            try {
                const options = await api.taskOptions(key);
                if (this.key === key) this.options = options;
            } catch {
                // the field sheets then offer what needs no lookup (owner, severity, impact, assignee)
            } finally {
                if (this.key === key) this.optionsLoading = false;
            }
        },
        showTask(task) {
            this.task = task;
            this.current = {
                status: task.status || NONE,
                assignee: task.assignee?.name ?? null,
                owner: task.owner || NONE,
                severity: task.severity_label || NONE,
                impact: task.impact_label || NONE,
                category: task.category || NONE,
                description: task.description || NONE,
            };
        },

        /** [{ value (shown), id (sent to Jira), owner? }] for a field's options sheet */
        choices(field) {
            if (field === 'status') return this.options.transitions.map(t => ({ value: t.name, id: t.id, owner: t.owner }));
            if (field === 'assignee') return [...this.assignees.map(a => ({ value: a.name, id: a.id })), { value: null, id: null }];
            if (field === 'category') return (this.options.categories ?? []).map(c => ({ value: c.value, id: c.id }));
            return FIXED[field].map(([value, id]) => ({ value, id }));
        },
        valueOf(field) {
            return field in this.pending ? this.pending[field].to : this.current[field];
        },
        /** Stage an edit. A status change drives the owner, as TaskOwnerService::syncOwnerForStatus does. */
        stage(field, to, owner, id = to) {
            if (to === this.current[field]) delete this.pending[field];
            else this.pending[field] = { to, id, auto: false };
            if (field !== 'status') return;
            const ownerEdit = this.pending.owner;
            if (ownerEdit && !ownerEdit.auto) return;   // a manual owner choice wins
            if (this.pending.status && owner && owner !== this.current.owner) this.pending.owner = { to: owner, id: owner, auto: true };
            else if (ownerEdit?.auto) delete this.pending.owner;
        },
        undo(field) {
            delete this.pending[field];
            if (field === 'status' && this.pending.owner?.auto) delete this.pending.owner;
        },
        discardAll() {
            this.pending = {};
        },
        stageText(field, value) {
            if (field === 'description' && value === this.current.description.trim()) delete this.pending.description;
            else this.pending[field] = { to: value, id: value, auto: false };
        },
        async apply() {
            const ui = useUi();
            this.applying = true;
            const key = this.key;
            try {
                // The automatic owner is left to the server, which syncs it from the new status as the bot does.
                const edits = Object.entries(this.pending).filter(([field, edit]) => !(field === 'owner' && edit.auto));
                const { results, task } = await api.applyEdits(key, Object.fromEntries(edits.map(([field, edit]) => [field, edit.id])));
                const failed = Object.keys(results).filter(field => !results[field]);
                this.pending = Object.fromEntries(Object.entries(this.pending).filter(([field]) => failed.includes(field)));
                if (task && this.key === key) this.showTask(task);
                const done = Object.keys(results).length - failed.length;
                if (failed.length) ui.notify(text('detail.partlyApplied', { fields: failed.map(f => text(`field.${f}.label`)).join('، ') }), 'warning');
                else ui.notify(text('detail.applied', {}, done));
                if (results.status) this.loadOptions(key);
                this.loadOpen(); this.loadStatuses();
                if (this.listFilter) this.loadList();
            } catch (e) {
                if (e.code !== 'pat_invalid') ui.notify(text('detail.applyFailed'), 'error');
            } finally {
                this.applying = false;
            }
        },
    },
});
