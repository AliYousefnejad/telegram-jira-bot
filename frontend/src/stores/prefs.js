// Language and theme. Defaults follow Telegram (the user's language, Telegram's colours); the user's choice is
// remembered per device. ?lang= and ?theme= override both (used by the e2e tests).
import { defineStore } from 'pinia';
import { i18n } from '@/i18n/index.js';
import { telegramUser } from '@/lib/telegram.js';

const storage = {
    get: k => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* private mode */ } },
};

const params = new URLSearchParams(location.search);
const telegramLang = telegramUser?.language_code || 'fa';

function applyToDocument(lang, theme) {
    const root = document.documentElement;
    root.lang = lang;
    root.dir = lang === 'fa' ? 'rtl' : 'ltr';
    if (theme === 'system') delete root.dataset.theme; else root.dataset.theme = theme;
    i18n.global.locale.value = lang;
}

export const usePrefs = defineStore('prefs', {
    state: () => ({
        lang: params.get('lang') || storage.get('td.lang') || (telegramLang.startsWith('fa') ? 'fa' : 'en'),
        theme: params.get('theme') || storage.get('td.theme') || 'system',
    }),
    actions: {
        apply() {
            applyToDocument(this.lang, this.theme);
        },
        setLang(lang) {
            this.lang = lang;
            storage.set('td.lang', lang);
            this.apply();
        },
        setTheme(theme) {
            this.theme = theme;
            storage.set('td.theme', theme);
            this.apply();
        },
    },
});
