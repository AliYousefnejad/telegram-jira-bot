// WCAG AA text contrast and 44px touch targets on every main screen, in both themes and both languages.
import { expect, test } from '@playwright/test';

// link-token: the link screen with the personal token method chosen
const SCREENS = ['home', 'tasks-status', 'tasks', 'detail', 'report', 'timeline', 'link-email', 'link-token', 'token-expired', 'gate-denied', 'gate-group'];

for (const lang of ['fa', 'en']) {
    for (const theme of ['light', 'dark']) {
        test(`contrast and touch targets — ${lang}/${theme}`, async ({ page }) => {
            const problems = [];
            for (const screen of SCREENS) {
                await page.goto(`index.html?lang=${lang}&theme=${theme}&screen=${screen === 'link-token' ? 'link-email' : screen}`);
                if (screen === 'link-token') await page.locator('[data-method="pat"]').click();
                const found = await page.evaluate(() => {
                    const lum = c => { const [r, g, b] = c.match(/[\d.]+/g).map(Number).slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
                    const bg = el => { for (; el; el = el.parentElement) { const c = getComputedStyle(el).backgroundColor; if (!/rgba\(.*,\s*0\)$/.test(c)) return c; } return getComputedStyle(document.body).backgroundColor; };
                    const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
                    const roots = [document.querySelector('.screen.on'), document.querySelector('.tg-header'), document.getElementById('mainBtn')];
                    const out = [];
                    for (const root of roots) {
                        if (!root) continue;
                        for (const el of root.querySelectorAll('*')) {
                            if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim()) || !el.getBoundingClientRect().width || el.closest('[hidden]')) continue;
                            const cs = getComputedStyle(el), size = parseFloat(cs.fontSize);
                            const need = size >= 24 || (size >= 18.66 && +cs.fontWeight >= 700) ? 3 : 4.5;
                            const r = ratio(cs.color, bg(el));
                            if (r < need && !cs.textDecorationLine.includes('line-through')) out.push(`contrast ${r.toFixed(2)} "${el.textContent.trim().slice(0, 20)}"`);
                        }
                        for (const el of root.querySelectorAll('button')) {
                            const box = el.getBoundingClientRect(), after = getComputedStyle(el, '::after');
                            const extended = after.content !== 'none' && after.position === 'absolute';
                            if (box.width && (box.height < 44 || box.width < 44) && !extended) out.push(`target ${Math.round(box.width)}x${Math.round(box.height)} #${el.id || el.className}`);
                        }
                    }
                    if (document.documentElement.scrollWidth > innerWidth + 1) out.push('horizontal scroll');
                    return out;
                });
                problems.push(...found.map(p => `${screen}: ${p}`));
            }
            expect(problems).toEqual([]);
        });
    }
}
