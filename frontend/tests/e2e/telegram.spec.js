// The Mini App as Telegram opens it: real data from /api/webapp, Telegram's own buttons.
import { expect, test } from '@playwright/test';
import { defaultApi, lastCall, openInTelegram, tapBackButton, tapMainButton } from './support.js';

test('home and the task list show Jira data, never sample tasks', async ({ page }) => {
    const requests = await openInTelegram(page);

    await expect(page.locator('.queue-head .big')).toHaveText('3');
    await expect(page.locator('.strip-legend b')).toHaveText(['1', '1', '0', '0']);
    expect(requests.every(r => r.auth?.startsWith('tma auth_date='))).toBe(true);

    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    await expect(page.locator('#statusMenu .srow .st')).toHaveText(['همه موارد باز', 'Raised', 'Under Triage', 'Closed']);
    await page.locator('#statusMenu .srow[data-filter="all"]').click();

    await expect(page.locator('#list .row .key')).toHaveText(['TS-20', 'TS-18', 'TS-9']);
    await expect(page.locator('body')).not.toContainText('SUP-');
});

test('a task opens from Jira and can be sent to the chat', async ({ page }) => {
    const requests = await openInTelegram(page);
    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    await page.locator('#statusMenu .srow[data-filter="all"]').click();
    await page.locator('#list .row').first().click();

    await expect(page.locator('#title')).toHaveText('TS-20');
    await expect(page.locator('.d-head h2')).toHaveText('سفارش‌ها در انتظار مانده‌اند');
    await expect(page.locator('#fields .f[data-field="assignee"] .v')).toContainText('@sara_tg');
    await expect(page.locator('#taskBubble')).toContainText('TS-20');

    await page.locator('#taskShareBtn').click();
    await expect(page.locator('#taskShareLabel')).toHaveText('در همین گفتگو ارسال شد');
    await expect(page.locator('#toast')).toContainText('TS-20');
    expect(requests.map(r => r.key)).toContain('POST tasks/TS-20/share');
});

test('a failed send says so and lets the user retry', async ({ page }) => {
    const api = defaultApi();
    api['POST tasks/TS-20/share'] = { status: 502, json: { error: 'send_failed' } };
    await openInTelegram(page, { api });
    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    await page.locator('#statusMenu .srow[data-filter="all"]').click();
    await page.locator('#list .row').first().click();

    await page.locator('#taskShareBtn').click();
    await expect(page.locator('#toast')).toContainText('ارسال پیام ناموفق بود');
    await expect(page.locator('#taskShareBtn')).toBeEnabled();
});

test('a report is created in Jira and its message goes to the chat', async ({ page }) => {
    const requests = await openInTelegram(page);
    await page.locator('.tool[data-go="report"]').click();

    await expect(page.locator('#rName')).toHaveValue('');
    await page.locator('#rName').fill('محمد رضایی');
    await page.locator('#rUserId').fill('84219');
    await page.locator('#rMobile').fill('09123456789');
    await page.locator('#rCategory').selectOption('Mobile app');
    await page.locator('#rAssignee').selectOption('sara');
    await page.locator('#desc').fill('پرداخت انجام شده ولی سفارش در انتظار است.');
    await tapMainButton(page);

    await expect(page.locator('#doneKey')).toHaveText('TS-31');
    const created = requests.find(r => r.key === 'POST reports');
    expect(created.body).toMatchObject({ type: 'user_bug', user_name: 'محمد رضایی', user_identifier: '84219', user_mobile: '09123456789', category: '10402', assignee: 'sara' });
    await expect(page.locator('#bubble')).toContainText('TS-31');

    await page.locator('#shareBtn').click();
    await expect(page.locator('#shareLabel')).toHaveText('در همین گفتگو ارسال شد');
    expect(requests.map(r => r.key)).toContain('POST tasks/TS-31/share');
});

test('a Jira failure while reporting keeps the form and explains it', async ({ page }) => {
    const api = defaultApi();
    api['POST reports'] = { status: 502, json: { error: 'jira_failed' } };
    await openInTelegram(page, { api });
    await page.locator('.tool[data-go="report"]').click();
    await page.locator('[data-type="admin"]').click();
    await page.locator('#desc').fill('صفحه ورود پنل خطای ۵۰۰ می‌دهد.');
    await tapMainButton(page);

    await expect(page.locator('#toast')).toContainText('ثبت گزارش در جیرا ناموفق بود');
    await expect(page.locator('#report')).toBeVisible();
});

test('non-admins get the access gate and unlinked admins the link screen', async ({ page }) => {
    const notAdmin = defaultApi();
    notAdmin['GET me'] = { status: 403, json: { error: 'not_admin' } };
    await openInTelegram(page, { api: notAdmin });
    await expect(page.locator('#gate-denied')).toBeVisible();

    const notLinked = defaultApi();
    notLinked['GET me'] = { json: { telegram: { id: 1, username: null, name: 'X' }, jira: null } };
    await openInTelegram(page, { api: notLinked });
    await expect(page.locator('#link-email')).toBeVisible();
});

test('the profile shows the Telegram user and the Jira profile', async ({ page }) => {
    await openInTelegram(page);
    await expect(page.locator('.hello b')).toHaveText('Robin Test');
    await expect(page.locator('.hello .num')).toHaveText('Robin Jira');

    await page.locator('#profileBtn').click();
    await expect(page.locator('#profileSheet')).toContainText('@robin_test');
    await expect(page.locator('#profileSheet')).toContainText('4242');
    await expect(page.locator('#profileSheet .prof-acct small')).toHaveText('robin@example.com');
    await expect(page.locator('body')).not.toContainText('jane_doe');
});

test("Telegram's Back button follows the screens", async ({ page }) => {
    await openInTelegram(page);
    expect((await lastCall(page, 'Back.'))[0]).toBe('Back.hide');

    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    expect((await lastCall(page, 'Back.'))[0]).toBe('Back.show');

    await tapBackButton(page);
    await expect(page.locator('#home')).toBeVisible();
});

test('edits are applied in Jira: status by transition, owner left to the server, comment as text', async ({ page }) => {
    const api = defaultApi();
    api['GET tasks/TS-20/options'] = { json: { transitions: [{ id: '31', name: 'Under Triage', owner: 'L2' }, { id: '41', name: 'Closed', owner: 'Reporter' }], categories: null } };
    api['POST tasks/TS-20/edits'] = { json: { results: { status: true, severity: true, comment: true }, task: { ...api['GET tasks/TS-20'].json.task, status: 'Closed', severity: 'S2', severity_label: 'S2 – High' } } };
    const requests = await openInTelegram(page, { api });
    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    await page.locator('#statusMenu .srow[data-filter="all"]').click();
    await page.locator('#list .row').first().click();
    await expect(page.locator('#fields .f')).toHaveCount(5);   // no category: not a user bug report

    await page.locator('#fields .f[data-field="status"]').click();
    await page.locator('#opts .opt', { hasText: 'Closed' }).click();
    await expect(page.locator('#pending .chg')).toHaveCount(2);   // status + its automatic owner
    await page.locator('#fields .f[data-field="severity"]').click();
    await page.locator('#opts .opt').nth(1).click();
    await page.locator('#commentIn').fill('Checked the gateway');
    await page.locator('#commentAdd').click();
    await tapMainButton(page);

    await expect(page.locator('#toast')).toContainText('اعمال شد');
    expect(requests.find(r => r.key === 'POST tasks/TS-20/edits').body).toEqual({ status: '41', severity: 'S2', comment: 'Checked the gateway' });
    await expect(page.locator('#pending')).toHaveCount(0);
    await expect(page.locator('#fields .f[data-field="status"]')).toContainText('Closed');
});

test('an edit Jira refuses stays staged and is named', async ({ page }) => {
    const api = defaultApi();
    api['GET tasks/TS-20/options'] = { json: { transitions: [], categories: null } };
    api['POST tasks/TS-20/edits'] = { json: { results: { severity: false, impact: true }, task: null } };
    await openInTelegram(page, { api });
    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    await page.locator('#statusMenu .srow[data-filter="all"]').click();
    await page.locator('#list .row').first().click();

    await page.locator('#fields .f[data-field="severity"]').click();
    await page.locator('#opts .opt').nth(1).click();
    await page.locator('#fields .f[data-field="impact"]').click();
    await page.locator('#opts .opt').nth(2).click();
    await tapMainButton(page);

    await expect(page.locator('#toast')).toContainText('نپذیرفت');
    await expect(page.locator('#pending .chg')).toHaveCount(1);
    await expect(page.locator('#pending .chg .k')).toHaveText('شدت');

    await page.locator('#fields .f[data-field="status"]').click();
    await expect(page.locator('#noChoices')).toBeVisible();   // no reachable status: said, not an empty sheet
});
