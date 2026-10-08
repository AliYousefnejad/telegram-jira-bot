// End-to-end tests for the Telegram Mini App (tests/e2e), run against the built app in backend/public/app. Telegram and the /api/webapp backend are
// stubbed per test, so these run offline; the backend itself is covered by PHPUnit (tests/Feature/WebApp).
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 30_000,
    fullyParallel: true,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8099/app/',
        channel: process.env.PLAYWRIGHT_CHANNEL ?? 'chrome',
        viewport: { width: 375, height: 812 },
        locale: 'fa-IR',
    },
    webServer: {
        command: 'php -S 127.0.0.1:8099 -t ../backend/public',
        url: 'http://127.0.0.1:8099/app/index.html',
        reuseExistingServer: !process.env.CI,
    },
});
