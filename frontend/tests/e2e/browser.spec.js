// The app outside Telegram (sample data): navigation, languages, and the guards around unapplied edits.
import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => { try { localStorage.clear(); } catch {} });
});

test('sample tasks use the TS key format', async ({ page }) => {
    await page.goto('index.html?screen=tasks');
    await expect(page.locator('#list .row .key').first()).toHaveText(/^TS-\d+$/);
    await expect(page.locator('body')).not.toContainText('SUP-');
});

test('home → status menu → list → task → back', async ({ page }) => {
    await page.goto('index.html');
    await page.locator('.tool.wide[data-go="tasks-status"]').click();
    await expect(page.locator('#tasks-status')).toBeVisible();

    await page.locator('#statusMenu .srow').nth(2).click();
    await expect(page.locator('#tasks')).toBeVisible();
    await page.locator('#list .row').first().click();
    await expect(page.locator('#detail')).toBeVisible();

    await page.locator('#back').click();
    await expect(page.locator('#tasks')).toBeVisible();
});

test('leaving a task with unapplied changes asks first', async ({ page }) => {
    await page.goto('index.html?screen=detail');
    await page.locator('#fields .f[data-field="severity"]').click();
    await page.locator('#opts .opt').nth(1).click();
    await expect(page.locator('#pending .chg')).toHaveCount(1);

    await page.locator('#back').click();
    await expect(page.locator('#confirmSheet')).toHaveClass(/on/);
    await page.locator('#cfNo').click();
    await expect(page.locator('#detail')).toBeVisible();
});

test('English switches the layout to left-to-right and back again', async ({ page }) => {
    await page.goto('index.html');
    await expect(page.locator('.strip-legend b').first()).not.toHaveText('–');   // demo data loaded
    const persian = await page.locator('body').innerText();

    await page.locator('#profileBtn').click();
    await page.locator('#langSeg [data-lang="en"]').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('#home .section-title')).toHaveText('Tools');

    await page.locator('#langSeg [data-lang="fa"]').click();
    await page.keyboard.press('Escape');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    expect(await page.locator('body').innerText()).toBe(persian);
});

test('the report form needs a description of at least 10 characters', async ({ page }) => {
    await page.goto('index.html?screen=report');
    await page.locator('#desc').fill('کوتاه');
    await expect(page.locator('#mainBtnLabel')).toBeDisabled();
    await page.locator('#desc').fill('توضیح کامل‌تر از ده نویسه');
    await expect(page.locator('#mainBtnLabel')).toBeEnabled();
});
