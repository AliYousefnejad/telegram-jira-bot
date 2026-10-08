// The /report form (same path in Jira as the bot's /report: POST /api/webapp/reports → ReportFlowService).
import { defineStore } from 'pinia';
import { api } from '@/api/index.js';
import { text } from '@/i18n/index.js';
import { useNav } from './nav.js';
import { useUi } from './ui.js';

export const MIN_DESCRIPTION = 10;
const TYPES = { user: 'user_bug', admin: 'admin_panel_problem', fm: 'force_majeure' };

export const useReport = defineStore('report', {
    state: () => ({
        type: 'user',
        name: '',
        userId: '',
        mobile: '',
        category: '',
        /** The project's category options in Jira ({ id, value }); empty when it has no category field */
        categories: [],
        assignee: '',
        description: '',
        nameError: false,
        submitting: false,
        /** The task Jira created, and the message the bot will send about it */
        created: null,
        preview: null,
    }),
    getters: {
        descriptionLength: s => s.description.trim().length,
    },
    actions: {
        async loadOptions() {
            this.categories = await api.reportOptions().then(r => r.categories).catch(() => []);
        },
        /** Returns the field to focus when the form is not complete. */
        async submit() {
            const userBug = this.type === 'user';
            this.nameError = userBug && this.name.trim().length <= 1;
            if (this.nameError) return 'name';
            if (this.descriptionLength < MIN_DESCRIPTION) return 'description';

            const body = { type: TYPES[this.type], description: this.description.trim(), assignee: this.assignee || null };
            if (userBug) {
                Object.assign(body, {
                    user_name: this.name.trim(),
                    user_identifier: this.userId.trim(),
                    user_mobile: this.mobile.trim(),
                    category: this.category || null,
                });
            }
            this.submitting = true;
            try {
                const { task } = await api.createReport(body);
                this.created = task;
                this.preview = await api.sharePreview(task.key).then(r => r.html).catch(() => null);
                useNav().reset('home', 'report-done');
            } catch (e) {
                useUi().notify(text(e.status === 422 ? 'report.incomplete' : 'report.failed'), 'error');
            } finally {
                this.submitting = false;
            }
            return null;
        },
    },
});
