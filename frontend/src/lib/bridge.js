// Inside Telegram: native Back/Main/Settings buttons, Telegram's theme colours, haptics, and a closing
// confirmation while edits are unapplied. Does nothing in a plain browser.
import { watch, watchEffect } from 'vue';
import { inTelegram, webApp as tg } from './telegram.js';
import { useNav } from '@/stores/nav.js';
import { usePrefs } from '@/stores/prefs.js';
import { useTasks } from '@/stores/tasks.js';
import { useUi } from '@/stores/ui.js';

const THEME_VARS = {
    bg_color: '--tg-bg', secondary_bg_color: '--tg-secondary-bg', text_color: '--tg-text', hint_color: '--tg-hint',
    button_color: '--tg-button', button_text_color: '--tg-button-text', link_color: '--tg-link',
    header_bg_color: '--tg-header', section_separator_color: '--tg-section-sep',
};

export function installTelegramBridge() {
    if (!inTelegram) return;
    const root = document.documentElement;
    const nav = useNav(), ui = useUi(), prefs = usePrefs(), tasks = useTasks();
    root.classList.add('in-telegram');
    tg.ready();
    tg.expand();

    // Theme: "Auto" uses Telegram's own colours; Light/Dark use the app palette.
    function applyTheme() {
        const auto = prefs.theme === 'system';
        for (const [param, cssVar] of Object.entries(THEME_VARS)) {
            if (auto && tg.themeParams[param]) root.style.setProperty(cssVar, tg.themeParams[param]);
            else root.style.removeProperty(cssVar);
        }
        if (auto) root.dataset.theme = tg.colorScheme;   // severity colours follow Telegram's light/dark
        const css = getComputedStyle(root);
        const header = css.getPropertyValue('--tg-header').trim(), bg = css.getPropertyValue('--tg-secondary-bg').trim();
        try {
            if (header.startsWith('#')) tg.setHeaderColor(header);
            if (bg.startsWith('#')) tg.setBackgroundColor(bg);
        } catch { /* older clients */ }
    }
    watch(() => prefs.theme, applyTheme, { immediate: true });
    tg.onEvent('themeChanged', applyTheme);

    // Back button: shown whenever the app's own Back would be (or a sheet can be closed).
    tg.BackButton.onClick(() => nav.back());
    watchEffect(() => (nav.showBack || ui.sheet ? tg.BackButton.show() : tg.BackButton.hide()));

    // Main button mirrors the screen's main action; hidden while a sheet is open.
    tg.MainButton.onClick(() => {
        const main = ui.main;
        if (!main || main.disabled || main.busy) return;
        tg.HapticFeedback.impactOccurred('medium');
        main.onClick();
    });
    watchEffect(() => {
        const main = ui.main;
        if (!main || ui.sheet) { tg.MainButton.hide(); return; }
        tg.MainButton.setText(main.label || '…');
        if (main.busy) tg.MainButton.showProgress(false); else tg.MainButton.hideProgress();
        if (main.disabled && !main.busy) tg.MainButton.disable(); else tg.MainButton.enable();
        tg.MainButton.show();
    });

    // Settings (⋮ menu) opens the profile.
    try {
        tg.SettingsButton.show();
        tg.SettingsButton.onClick(() => ui.openSheet('profile'));
    } catch { /* older clients */ }

    // Haptics
    document.addEventListener('click', e => {
        if (e.target.closest('.opt, .srow, .segc button, .acct')) tg.HapticFeedback.selectionChanged();
        else if (e.target.closest('.tool, .row, .f, .share-btn')) tg.HapticFeedback.impactOccurred('light');
    }, true);
    watch(() => ui.toast.seq, () => tg.HapticFeedback.notificationOccurred(ui.toast.kind));

    // Ask before closing while edits are not applied.
    watch(() => tasks.pendingCount > 0, dirty => (dirty ? tg.enableClosingConfirmation() : tg.disableClosingConfirmation()));
}
