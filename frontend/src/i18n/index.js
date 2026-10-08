import { createI18n, useI18n } from 'vue-i18n';
import fa from './fa.js';
import en from './en.js';

export const i18n = createI18n({
    legacy: false,
    locale: 'fa',
    fallbackLocale: 'fa',
    messages: { fa, en },
});

const PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

/** Persian digits in Persian, Latin digits in English. */
export function localDigits(value, locale) {
    const s = String(value);
    return locale === 'fa' ? s.replace(/\d/g, d => PERSIAN_DIGITS[d]) : s.replace(/[۰-۹]/g, d => PERSIAN_DIGITS.indexOf(d));
}

/** Search input may be typed with Persian digits; keys are Latin. */
export const latinDigits = s => s.replace(/[۰-۹]/g, d => PERSIAN_DIGITS.indexOf(d));

/** For stores (outside components): translated text, with a count in local digits when given. */
export function text(key, named = {}, count) {
    const locale = i18n.global.locale.value;
    return count === undefined
        ? i18n.global.t(key, named)
        : i18n.global.t(key, { n: localDigits(count, locale), ...named }, count);
}

/** `t` plus a digit formatter and a count helper: tc('app.tasks', 3) → "۳ تسک" / "3 tasks". */
export function useText() {
    const { t, locale } = useI18n();
    const d = value => localDigits(value, locale.value);
    const tc = (key, count, named = {}) => t(key, { n: d(count), ...named }, count);
    /** "5 hours ago", "Yesterday", … from an ISO date */
    const ago = iso => {
        const h = Math.floor((Date.now() - Date.parse(iso)) / 36e5);
        if (!Number.isFinite(h)) return '';
        if (h < 1) return tc('ago.underHour', 1);
        if (h < 24) return tc('ago.hours', h);
        const days = Math.floor(h / 24);
        return days === 1 ? t('ago.yesterday') : tc('ago.days', days);
    };
    return { t, tc, d, ago, locale };
}
