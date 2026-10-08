import { defineStore } from 'pinia';

let toastTimer;

export const useUi = defineStore('ui', {
    state: () => ({
        /** The open bottom sheet: 'options' | 'profile' | 'confirm' | 'description' | null */
        sheet: null,
        /** What the options sheet lists: { kind: 'field', field } or { kind: 'status' } */
        options: null,
        /** { title, text, count?, named?, ok, no } as i18n keys, and the callback for "ok" */
        confirmBox: null,
        onConfirm: null,
        toast: { text: '', kind: 'success', on: false, seq: 0 },
        /** The screen's main action: { label, disabled, busy, onClick } (Telegram's MainButton outside a browser) */
        main: null,
    }),
    actions: {
        openSheet(name) {
            this.sheet = name;
        },
        /** Returns whether a sheet was open. */
        closeSheet() {
            if (!this.sheet) return false;
            this.sheet = null;
            return true;
        },
        openOptions(options) {
            this.options = options;
            this.openSheet('options');
        },
        confirm(box, onOk) {
            this.confirmBox = box;
            this.onConfirm = onOk;
            this.openSheet('confirm');
        },
        confirmed() {
            const cb = this.onConfirm;
            this.onConfirm = null;
            this.closeSheet();
            cb?.();
        },
        /** kind: 'success' | 'warning' | 'error' (drives Telegram's haptic feedback) */
        notify(text, kind = 'success') {
            this.toast = { text, kind, on: true, seq: this.toast.seq + 1 };
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { this.toast.on = false; }, 3500);
        },
    },
});
