// TechDesk (Telegram Mini App). Builds into the Laravel app's public/app, where your web server / `php artisan serve`
// serve it at /app/index.html next to the /api/webapp backend. `base: './'` keeps every asset URL relative.
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    base: './',
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    build: {
        outDir: process.env.WEBAPP_OUT_DIR ?? '../backend/public/app',
        emptyOutDir: true,
    },
    server: {
        // `npm run dev` against a local backend (`php artisan serve --port=8010`)
        proxy: { '/api': 'http://127.0.0.1:8010' },
    },
});
