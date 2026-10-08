import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { i18n } from './i18n/index.js';
import { router } from './router.js';
import { SCREENS } from './screens.js';
import { installTelegramBridge } from './lib/bridge.js';
import { openedInGroup } from './lib/telegram.js';
import { useNav } from './stores/nav.js';
import { usePrefs } from './stores/prefs.js';
import { useSession } from './stores/session.js';
import { useTasks } from './stores/tasks.js';
import './styles/app.css';

const app = createApp(App).use(createPinia()).use(i18n).use(router);

usePrefs().apply();   // right language, direction and colours before the first paint

// Deep links (demo, flow board, e2e tests): ?screen=<name>, ?key=<task> for the task screen.
// TechDesk works only in the private chat: opened from a group, it shows the "private chat only" gate.
const params = new URLSearchParams(location.search);
const nav = useNav();
const start = openedInGroup ? 'gate-group' : params.get('screen');
if (start && SCREENS[start]) {
    if (SCREENS[start].root) nav.reset(start); else nav.go(start);
    if (start === 'detail') useTasks().openTask(params.get('key') || 'TS-412');
}

watch(() => nav.current, name => {
    router.replace({ name });
    window.scrollTo(0, 0);
}, { immediate: true });

// Any API call can find that the user's own Jira token stopped working (see api/live.js).
window.addEventListener('techdesk:pat-invalid', () => useSession().tokenRejected());

router.isReady().then(() => {
    app.mount('#app');
    installTelegramBridge();
    if (!openedInGroup) useSession().boot();
});
