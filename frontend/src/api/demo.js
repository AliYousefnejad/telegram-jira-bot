// Sample data for a plain browser (no Telegram launch data): the same calls and response shapes as live.js,
// so every screen runs one code path. Nothing here ever reaches Jira.

const wait = (ms = 300) => new Promise(resolve => setTimeout(resolve, ms));
const hoursAgo = h => new Date(Date.now() - h * 36e5).toISOString();
const escape = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const ME = {
    telegram: { id: 512340987, username: 'jane_doe', name: 'جین دو' },
    jira: { name: 'Jane Doe', email: 'jane.doe@example.com', username: 'jane.doe', has_avatar: false, auth_method: 'email', token_status: 'none' },
};

const STAGES = [
    ['OPEN', 'L1'], ['IN PROGRESS', 'L1'], ['ESCALATED', 'L2'],
    ['WAITING FOR REPORTER', 'Reporter'], ['DONE', 'Reporter'],
];

const ASSIGNEES = [
    { id: 'sara', name: 'سارا احمدی', telegram: '@sara_a' },
    { id: 'reza', name: 'رضا کریمی', telegram: '@reza_k' },
    { id: 'jane', name: ME.telegram.name, telegram: '@' + ME.telegram.username },
    { id: 'maryam', name: 'مریم احمدی', telegram: null },
];
const person = id => {
    const a = ASSIGNEES.find(x => x.id === id);
    return a ? { name: a.name, telegram: a.telegram } : null;
};

const CATEGORIES = [{ id: '1', value: 'وب‌سایت' }, { id: '2', value: 'اپلیکیشن موبایل' }, { id: '3', value: 'احراز هویت' }, { id: '4', value: 'پنل مدیریت' }];

const SEVERITY = { S1: 'S1 – Critical', S2: 'S2 – High', S3: 'S3 – Medium', S4: 'S4 – Low' };
const IMPACT = { I1: 'I1 – Extensive', I2: 'I2 – Significant', I3: 'I3 – Limited', I4: 'I4 – Minimal' };

const task = (key, status, sev, imp, summary, assignee, updatedHoursAgo, created, description = '', category = 'وب‌سایت') => ({
    key, summary, type: 'Bug', status,
    severity: sev, severity_label: SEVERITY[sev], impact: imp, impact_label: IMPACT[imp],
    owner: STAGES.find(s => s[0] === status)?.[1] ?? null,
    category, assignee: person(assignee),
    created_jalali: created, updated: hoursAgo(updatedHoursAgo), description,
});

const TASKS = [
    task('TS-412', 'IN PROGRESS', 'S1', 'I2', 'سفارش‌ها پس از پرداخت موفق در وضعیت «در انتظار» باقی می‌مانند', 'sara', 50, '1405/07/12 10:24',
        'کاربر: محمد رضایی · شناسه ۸۴۲۱۹ · ۰۹۱۲۳۴۵۶۷۸۹\nپرداخت انجام شده ولی سفارش در حساب کاربری همچنان «در انتظار» نمایش داده می‌شود. از ساعت ۱۰:۲۰ حداقل ۱۴ مورد مشابه گزارش شده است.'),
    task('TS-409', 'ESCALATED', 'S1', 'I1', 'خطای ۵۰۲ هنگام ورود کاربران به پنل از ساعت ۹ صبح', 'reza', 26, '1405/07/13 09:05'),
    task('TS-405', 'OPEN', 'S2', 'I2', 'ایمیل بازیابی رمز عبور برای برخی کاربران ارسال نمی‌شود', null, 5, '1405/07/14 06:40', '', 'اپلیکیشن موبایل'),
    task('TS-401', 'OPEN', 'S3', 'I3', 'نمایش اشتباه تاریخ در گزارش ماهانه', 'sara', 30, '1405/07/10 15:12'),
    task('TS-398', 'WAITING FOR REPORTER', 'S4', 'I4', 'غلط املایی در متن صفحه راهنما', null, 75, '1405/07/09 11:48', '', 'اپلیکیشن موبایل'),
];

let nextKey = 413;
const created = {};   // key -> the report body, for its share preview

const notFound = () => Object.assign(new Error('not_found'), { code: 'not_found', status: 404 });
const open = () => TASKS.filter(t => t.status !== 'DONE');
const list = ({ description, ...rest }) => rest;

export default {
    async me() { return ME; },
    async statuses() {
        await wait();
        return {
            open: open().length,
            statuses: STAGES.map(([name, owner], i) => ({ id: String(i + 1), name, owner, count: TASKS.filter(t => t.status === name).length })),
        };
    },
    async tasks(status) {
        await wait(600);
        return { tasks: (status && status !== 'all' ? TASKS.filter(t => t.status === status) : open()).map(list) };
    },
    async task(key) {
        await wait();
        const t = TASKS.find(x => x.key === key);
        if (!t) throw notFound();
        return { task: { ...t, reporter: ME.jira.name } };
    },
    async assignees() { await wait(); return { assignees: ASSIGNEES }; },
    async sharePreview(key) {
        await wait(200);
        const t = TASKS.find(x => x.key === key);
        if (!t) throw notFound();
        const tag = h => `<span class="mention">${escape(h)}</span>`;
        const who = a => (a ? escape(a.name) + (a.telegram ? ' ' + tag(a.telegram) : '') : '');
        const r = created[key];
        if (r) {
            return { html: `<b>🪲 گزارش جدید: ${key}</b>\n` +
                (r.user_name ? `گزارش کاربر: ${escape(r.user_name)}، ${escape(r.user_identifier)}، ${escape(r.user_mobile)}\n` : '') +
                `${escape(r.description)}\n` + (t.assignee ? `مسئول: ${who(t.assignee)}\n` : '') +
                `مالک: ${t.owner} · ثبت‌کننده: ${tag('@' + ME.telegram.username)}` };
        }
        return { html: `📌 <b>${key}</b> · ${escape(t.status)}\n${escape(t.summary)}` + (t.assignee ? `\nمسئول: ${who(t.assignee)}` : '') };
    },
    async share() { await wait(600); return { sent: true }; },
    async reportOptions() { await wait(); return { categories: CATEGORIES }; },
    async createReport(body) {
        await wait(800);
        const key = 'TS-' + nextKey++;
        TASKS.unshift(task(key, 'OPEN', null, null, body.description.split('\n')[0].slice(0, 80), body.assignee, 0, '', body.description, CATEGORIES.find(c => c.id === body.category)?.value ?? null));
        created[key] = body;
        return { task: TASKS[0] };
    },
    async avatar() { return null; },
    async taskOptions() {
        await wait(300);
        return {
            transitions: [{ id: '21', name: 'IN PROGRESS', owner: 'L1' }, { id: '31', name: 'ESCALATED', owner: 'L2' }, { id: '41', name: 'DONE', owner: 'Reporter' }],
            categories: CATEGORIES,
        };
    },
    async applyEdits(key, edits) {
        await wait(700);
        return { results: Object.fromEntries(Object.keys(edits).map(field => [field, true])), task: null };
    },

    // Sample sign-in: emails starting with down/nobody/taken/dup and tokens starting with "bad" show the error states.
    async linkByEmail(email) {
        await wait(700);
        const fail = { down: 'jira_failed', nobody: 'not_found', taken: 'linked_elsewhere', dup: 'duplicate_accounts' };
        const code = Object.entries(fail).find(([prefix]) => email.startsWith(prefix))?.[1];
        if (code) throw Object.assign(new Error(code), { code, status: code === 'jira_failed' ? 502 : 422 });
        ME.jira = { ...ME.jira, email, auth_method: 'email', token_status: 'none' };
        return { jira: ME.jira };
    },
    async linkByToken(token) {
        await wait(700);
        if (token.startsWith('bad')) throw Object.assign(new Error('invalid_token'), { code: 'invalid_token', status: 422 });
        ME.jira = { ...ME.jira, auth_method: 'pat', token_status: 'ok' };
        return { jira: ME.jira };
    },
    async useBotToken() { await wait(400); ME.jira = { ...ME.jira, auth_method: 'email', token_status: 'none' }; return { jira: ME.jira }; },
    async logout() { await wait(400); return { logged_out: true }; },
};
