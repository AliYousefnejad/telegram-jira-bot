// Shared helpers for the Mini App e2e tests: a fake Telegram WebApp SDK and a fake /api/webapp backend.

export const USER = { id: 4242, first_name: 'Robin', last_name: 'Test', username: 'robin_test', language_code: 'fa' };

const TASKS = [
    { key: 'TS-20', summary: 'سفارش‌ها در انتظار مانده‌اند', type: 'Bug', status: 'Raised', severity: 'S1', severity_label: 'S1 – Critical', impact: 'I1', impact_label: 'I1 – Extensive', owner: 'L1', category: 'وب‌سایت', assignee: { name: 'Sara', telegram: '@sara_tg' }, created: '2026-09-06T02:32:00+03:30', created_jalali: '1405/06/15 02:32', updated: '2026-10-07T08:00:00+03:30' },
    { key: 'TS-18', summary: 'خطای ورود به پنل', type: 'Task', status: 'Under Triage', severity: 'S2', severity_label: 'S2 – High', impact: 'I2', impact_label: 'I2 – Significant', owner: 'L1', category: null, assignee: null, created: '2026-09-06T02:14:00+03:30', created_jalali: '1405/06/15 02:14', updated: '2026-10-01T08:00:00+03:30' },
    { key: 'TS-9', summary: 'ایمیل بازیابی ارسال نمی‌شود', type: 'Bug', status: 'Raised', severity: null, severity_label: null, impact: null, impact_label: null, owner: null, category: null, assignee: null, created: '2026-08-29T16:24:00+03:30', created_jalali: '1405/06/07 16:24', updated: '2026-09-29T08:00:00+03:30' },
];

export function defaultApi() {
    return {
        'GET me': { json: { telegram: { id: USER.id, username: USER.username, name: 'Robin Test' }, jira: { name: 'Robin Jira', email: 'robin@example.com', username: 'robin', time_zone: 'Asia/Tehran', active: true, has_avatar: false, auth_method: 'email', token_status: 'none' } } },
        'GET statuses': { json: { open: 3, statuses: [
            { id: '1', name: 'Raised', count: 2, owner: 'L1' },
            { id: '2', name: 'Under Triage', count: 1, owner: 'L1' },
            { id: '9', name: 'Closed', count: 4, owner: 'Reporter' },
        ] } },
        'GET tasks': { json: { tasks: TASKS } },
        'GET tasks/TS-20': { json: { task: { ...TASKS[0], description: 'کاربر: محمد رضایی\nسفارش ثبت شد.', reporter: 'Robin Jira' } } },
        'GET reports/options': { json: { categories: [{ id: '10401', value: 'Website' }, { id: '10402', value: 'Mobile app' }] } },
        'GET assignees': { json: { assignees: [{ id: 'sara', name: 'Sara', telegram: '@sara_tg' }, { id: 'maryam', name: 'Maryam', telegram: null }] } },
        'POST tasks/TS-20/share?preview=1': { json: { html: '📌 <b>TS-20</b> · Raised\nمسئول: @sara_tg' } },
        'POST tasks/TS-20/share': { json: { sent: true } },
        'POST reports': { status: 201, json: { task: { ...TASKS[0], key: 'TS-31', owner: 'L1' } } },
        'POST tasks/TS-31/share?preview=1': { json: { html: '📌 <b>TS-31</b> · Raised' } },
        'POST tasks/TS-31/share': { json: { sent: true } },
    };
}

/** A minimal Telegram.WebApp that records what the app asks Telegram to do. */
function sdkStub(user, chatType) {
    return `(() => {
        const calls = []; window.__tg = { calls, handlers: {} };
        const rec = name => (...args) => calls.push([name, ...args.filter(a => typeof a !== 'function')]);
        const button = name => ({
            show: rec(name + '.show'), hide: rec(name + '.hide'), setText: rec(name + '.setText'),
            enable: rec(name + '.enable'), disable: rec(name + '.disable'), showProgress: rec(name + '.progress'), hideProgress: rec(name + '.noProgress'),
            onClick: f => { window.__tg.handlers[name] = f; },
        });
        const userJson = ${JSON.stringify(JSON.stringify(user))};
        window.Telegram = { WebApp: {
            initData: 'auth_date=1&user=' + encodeURIComponent(userJson) + '&hash=test',
            initDataUnsafe: { user: JSON.parse(userJson), chat_type: ${JSON.stringify(chatType)} },
            themeParams: {}, colorScheme: 'light', platform: 'android', version: '8.0',
            ready: rec('ready'), expand: rec('expand'), setHeaderColor: rec('header'), setBackgroundColor: rec('bg'), onEvent() {},
            BackButton: button('Back'), MainButton: button('Main'), SettingsButton: button('Settings'),
            HapticFeedback: { impactOccurred: rec('impact'), selectionChanged: rec('select'), notificationOccurred: rec('notify') },
            enableClosingConfirmation: rec('closeConfirmOn'), disableClosingConfirmation: rec('closeConfirmOff'), openTelegramLink: rec('openLink'),
        } };
    })();`;
}

/**
 * Opens the app as Telegram would, with the API answered from `api` (see defaultApi()).
 * Returns the list of API requests the app made, for assertions.
 */
export async function openInTelegram(page, { user = USER, api = defaultApi(), path = 'index.html', chatType = 'sender' } = {}) {
    const requests = [];
    await page.route('**/vendor/telegram-web-app.js', route => route.fulfill({ contentType: 'application/javascript', body: sdkStub(user, chatType) }));
    await page.route('**/api/webapp/**', async route => {
        const req = route.request();
        const url = new URL(req.url());
        const key = `${req.method()} ${url.pathname.replace(/^.*\/api\/webapp\//, '')}${url.search}`;
        requests.push({ key, body: req.postDataJSON?.() ?? null, auth: req.headers().authorization });
        let answer = api[key] ?? api[key.replace(/\?.*$/, '')] ?? { status: 404, json: { error: 'not_found' } };
        if (typeof answer === 'function') answer = answer(requests.at(-1));
        await route.fulfill({ status: answer.status ?? 200, contentType: 'application/json', body: JSON.stringify(answer.json ?? {}) });
    });
    await page.addInitScript(() => { try { localStorage.clear(); } catch {} });
    await page.goto(path);
    return requests;
}

/** Click Telegram's Main button the way a user would. */
export async function tapMainButton(page) {
    await page.evaluate(() => window.__tg.handlers.Main());
}

export async function tapBackButton(page) {
    await page.evaluate(() => window.__tg.handlers.Back());
}

export const lastCall = (page, prefix) => page.evaluate(p => [...window.__tg.calls].reverse().find(c => c[0].startsWith(p)), prefix);
