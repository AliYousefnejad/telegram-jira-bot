// The /api/webapp backend (Laravel, app/Http/Controllers/WebApp), authenticated by Telegram's signed launch data.
import { initData } from '@/lib/telegram.js';

// The app is served at /app/index.html, so ../api resolves to /api.
const BASE = '../api/webapp/';
const auth = { Authorization: 'tma ' + initData };

async function request(path, body, method = body !== undefined ? 'POST' : 'GET') {
    const post = body !== undefined;
    const res = await fetch(BASE + path, {
        method,
        headers: { ...auth, Accept: 'application/json', ...(post ? { 'Content-Type': 'application/json' } : {}) },
        body: post ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        // The user's own Jira token stopped working: the app asks them to reconnect or use the bot token.
        if (data.error === 'pat_invalid') window.dispatchEvent(new CustomEvent('techdesk:pat-invalid'));
        throw Object.assign(new Error(data.error || String(res.status)), { code: data.error, status: res.status });
    }
    return data;
}

const key = k => encodeURIComponent(k);

export default {
    me: () => request('me'),
    statuses: () => request('statuses'),
    tasks: status => request('tasks' + (status && status !== 'all' ? '?status=' + encodeURIComponent(status) : '')),
    task: k => request(`tasks/${key(k)}`),
    assignees: () => request('assignees'),
    sharePreview: k => request(`tasks/${key(k)}/share?preview=1`, {}),
    share: k => request(`tasks/${key(k)}/share`, {}),
    reportOptions: () => request('reports/options'),
    createReport: body => request('reports', body),

    /** Jira avatar as an object URL (an <img> can't send the auth header). */
    async avatar() {
        const res = await fetch(BASE + 'me/avatar', { headers: auth });
        if (!res.ok) return null;
        return URL.createObjectURL(await res.blob());
    },

    // Editing tasks from the app is not wired to Jira yet: say so instead of pretending.
    taskOptions: key => request(`tasks/${encodeURIComponent(key)}/options`),
    applyEdits: (key, edits) => request(`tasks/${encodeURIComponent(key)}/edits`, edits),

    // Signing in: by Jira email (acts with the bot's token) or by the user's own Personal Access Token.
    linkByEmail: email => request('link', { method: 'email', email }),
    linkByToken: token => request('link', { method: 'pat', token }),
    useBotToken: () => request('link/bot-token', {}),
    logout: () => request('link', {}, 'DELETE'),
};
