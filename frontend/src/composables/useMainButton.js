// A screen's main action (bottom button in a browser, Telegram's MainButton inside Telegram).
// `getter` returns { label, disabled?, busy?, onClick } or null for no button.
import { onBeforeUnmount, watchEffect } from 'vue';
import { useUi } from '@/stores/ui.js';

export function useMainButton(getter) {
    const ui = useUi();
    let mine = null;
    const stop = watchEffect(() => {
        mine = getter();
        ui.main = mine;
    });
    onBeforeUnmount(() => {
        stop();
        if (ui.main === mine) ui.main = null;
    });
}
