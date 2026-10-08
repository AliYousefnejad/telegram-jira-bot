// Signing in (Jira email or personal token), an expired token, logging out, and the private-chat-only rule.
import { expect, test } from '@playwright/test';
import { defaultApi, openInTelegram, tapMainButton } from './support.js';

const JIRA = { name: 'Robin Jira', email: 'robin@example.com', username: 'robin', has_avatar: false };

function unlinked() {
    const api = defaultApi();
    api['GET me'] = { json: { telegram: { id: 4242, username: 'robin_test', name: 'Robin Test' }, jira: null } };
    return api;
}

test('signing in by email links the account and opens home', async ({ page }) => {
    const api = unlinked();
    api['POST link'] = { json: { jira: { ...JIRA, auth_method: 'email' } } };
    const requests = await openInTelegram(page, { api });

    await page.locator('#email').fill('robin@example.com');
    api['GET me'] = defaultApi()['GET me'];
    await tapMainButton(page);

    await expect(page.locator('#link-success')).toContainText('Robin Jira');
    expect(requests.find(r => r.key === 'POST link').body).toEqual({ method: 'email', email: 'robin@example.com' });
    await tapMainButton(page);
    await expect(page.locator('#home .hello .num')).toHaveText('Robin Jira');
});

test('email sign-in errors are shown next to the field, never as an account list', async ({ page }) => {
    const api = unlinked();
    const errors = ['not_found', 'linked_elsewhere', 'duplicate_accounts'];
    api['POST link'] = () => ({ status: 422, json: { error: errors.shift() } });
    await openInTelegram(page, { api });

    await page.locator('#email').fill('nobody@example.com');
    await tapMainButton(page);
    await expect(page.locator('#emailErr')).toContainText('حسابی با این ایمیل');
    await tapMainButton(page);
    await expect(page.locator('#emailErr')).toContainText('تلگرام دیگر');
    await tapMainButton(page);
    await expect(page.locator('#emailErr')).toContainText('چند حساب فعال');
    await expect(page.locator('.acct')).toHaveCount(0);
});

test('signing in with a personal token sends it once, hidden, and reports a refused token', async ({ page }) => {
    const api = unlinked();
    const answers = [{ status: 422, json: { error: 'invalid_token' } }, { json: { jira: { ...JIRA, auth_method: 'pat', token_status: 'ok' } } }];
    api['POST link'] = () => answers.shift();
    const requests = await openInTelegram(page, { api });

    await page.locator('[data-method="pat"]').click();
    await expect(page.locator('#token')).toHaveAttribute('type', 'password');
    await page.locator('#revealToken').click();
    await expect(page.locator('#token')).toHaveAttribute('type', 'text');

    await page.locator('#token').fill('short');
    await tapMainButton(page);
    await expect(page.locator('#tokenErr')).toContainText('کامل');
    expect(requests.some(r => r.key === 'POST link')).toBe(false);

    await page.locator('#token').fill('NjM0-wrong-token-abcdefghijkl');
    await tapMainButton(page);
    await expect(page.locator('#tokenErr')).toContainText('جیرا این توکن را نپذیرفت');

    await page.locator('#token').fill('NjM0-personal-token-abcdefghij');
    await tapMainButton(page);
    await expect(page.locator('#link-success')).toContainText('با توکن خودتان');
    expect(requests.filter(r => r.key === 'POST link').at(-1).body).toEqual({ method: 'pat', token: 'NjM0-personal-token-abcdefghij' });
    await expect(page.locator('body')).not.toContainText('NjM0-personal-token');
});

test('Jira being down shows a banner with a retry', async ({ page }) => {
    const api = unlinked();
    api['POST link'] = { status: 502, json: { error: 'jira_failed' } };
    await openInTelegram(page, { api });

    await page.locator('#email').fill('robin@example.com');
    await tapMainButton(page);
    await expect(page.locator('#jiraDown')).toBeVisible();
});

test('an expired token asks to reconnect or to continue with the bot token', async ({ page }) => {
    const api = defaultApi();
    api['GET me'] = { json: { telegram: { id: 4242, username: 'robin_test', name: 'Robin Test' }, jira: { ...JIRA, auth_method: 'pat', token_status: 'expired' } } };
    api['POST link/bot-token'] = { json: { jira: { ...JIRA, auth_method: 'email' } } };
    await openInTelegram(page, { api });

    await expect(page.locator('#token-expired')).toBeVisible();
    await page.locator('#reconnectToken').click();
    await expect(page.locator('#link-email [data-method="pat"]')).toHaveAttribute('aria-checked', 'true');

    const requests = await openInTelegram(page, { api });
    await expect(page.locator('#token-expired')).toBeVisible();
    api['GET me'] = defaultApi()['GET me'];
    await page.locator('#useBotToken').click();
    await expect(page.locator('#home')).toBeVisible();
    expect(requests.some(r => r.key === 'POST link/bot-token')).toBe(true);
});

test('a token rejected mid-session opens the expired-token screen', async ({ page }) => {
    const api = defaultApi();
    api['GET me'] = { json: { telegram: { id: 4242, username: 'robin_test', name: 'Robin Test' }, jira: { ...JIRA, auth_method: 'pat', token_status: 'ok' } } };
    await openInTelegram(page, { api });
    await expect(page.locator('#home')).toBeVisible();

    api['GET tasks'] = { status: 401, json: { error: 'pat_invalid' } };
    await page.locator('.strip button.s1').click();
    await expect(page.locator('#token-expired')).toBeVisible();
});

test('the profile shows the sign-in method and logging out unlinks on the server', async ({ page }) => {
    const api = defaultApi();
    api['DELETE link'] = { json: { logged_out: true } };
    const requests = await openInTelegram(page, { api });

    await page.locator('#profileBtn').click();
    await expect(page.locator('#signinMethod')).toContainText('ایمیل');
    await expect(page.locator('#useOwnToken')).toBeVisible();
    await page.locator('#logoutBtn').click();
    await page.locator('#cfOk').click();

    await expect(page.locator('#link-email')).toBeVisible();
    expect(requests.some(r => r.key === 'DELETE link')).toBe(true);
});

test('a token user can switch to the bot token from the profile', async ({ page }) => {
    const api = defaultApi();
    api['GET me'] = { json: { telegram: { id: 4242, username: 'robin_test', name: 'Robin Test' }, jira: { ...JIRA, auth_method: 'pat', token_status: 'ok' } } };
    api['POST link/bot-token'] = { json: { jira: { ...JIRA, auth_method: 'email' } } };
    await openInTelegram(page, { api });

    await page.locator('#profileBtn').click();
    await expect(page.locator('#signinMethod')).toContainText('توکن شخصی');
    await page.locator('#profileBotToken').click();
    await expect(page.locator('#signinMethod')).toContainText('ایمیل');
});

for (const chatType of ['group', 'supergroup', 'channel']) {
    test(`opened from a ${chatType}, only the private-chat gate shows and no data is loaded`, async ({ page }) => {
        const requests = await openInTelegram(page, { chatType });

        await expect(page.locator('#gate-group')).toBeVisible();
        await page.locator('#openPrivate').click();
        expect(await page.evaluate(() => window.__tg.calls.some(c => c[0] === 'openLink'))).toBe(true);
        expect(requests).toEqual([]);
    });
}

test('the server refusing a group launch also shows the gate', async ({ page }) => {
    const api = defaultApi();
    api['GET me'] = { status: 403, json: { error: 'private_only' } };
    await openInTelegram(page, { api, chatType: 'private' });
    await expect(page.locator('#gate-group')).toBeVisible();
});
