<script setup>
// Inline SVG icons (24×24, stroked with currentColor).
import { computed } from 'vue';

const ICONS = {
    back: ['<path d="M15 6l-6 6 6 6"/>', 2],
    chevron: ['<path d="M9 6l6 6-6 6"/>', 2],
    globe: ['<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/>', 1.8],
    group: ['<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3 2.9-4.8 5.5-4.8s4.9 1.8 5.5 4.8"/><path d="M16 5.2a3 3 0 010 5.6M18 14.6c1.5.6 2.4 2 2.6 4.4"/>', 2],
    lines: ['<path d="M4 6h16M4 12h16M4 18h10"/>', 2],
    all: ['<path d="M4 6h16M4 12h16M4 18h16"/>', 2.4],
    plus: ['<path d="M12 5v14M5 12h14"/>', 2],
    clock: ['<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>', 2],
    link: ['<path d="M10 14a4 4 0 005.66 0l3-3a4 4 0 00-5.66-5.66l-1 1M14 10a4 4 0 00-5.66 0l-3 3a4 4 0 005.66 5.66l1-1"/>', 2],
    search: ['<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>', 2],
    noResults: ['<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M8.5 11h5"/>', 1.8],
    arrow: ['<path d="M5 12h14M13 6l6 6-6 6"/>', 2.2],
    close: ['<path d="M6 6l12 12M18 6L6 18"/>', 2.4],
    send: ['<path d="M4 12l16-8-6 16-2.5-6.5z"/><path d="M11.5 13.5L20 4"/>', 2],
    check: ['<path d="M5 12.5l4.5 4.5L19 7.5"/>', 2.4],
    bug: ['<rect x="8" y="6" width="8" height="13" rx="4"/><path d="M12 6V4M8 11H4M20 11h-4M8 16H5M19 16h-3M9 4l1.5 2M15 4l-1.5 2"/>', 2],
    monitor: ['<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>', 2],
    bolt: ['<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>', 2],
    telegram: ['<path d="M21 4L3 11l6 2 2 6 3-4 5 4z"/>', 2],
    lock: ['<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>', 2],
    warning: ['<path d="M12 4L2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>', 2],
    swap: ['<path d="M4 7h13l-3-3M20 17H7l3 3"/>', 2],
    logout: ['<path d="M10 5H6a1 1 0 00-1 1v12a1 1 0 001 1h4M14 8l-4 4 4 4M10 12h10"/>', 2],
    eye: ['<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>', 2],
    eyeOff: ['<path d="M4 4l16 16M10.6 6A9.6 9.6 0 0112 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 01-2.7 3.4M14.1 14.2a3 3 0 01-4.3-4.3M6.6 7.7A17 17 0 002.5 12S6 18.5 12 18.5a9 9 0 004-.9"/>', 2],
    key: ['<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L19 4M16 7l2.5 2.5M14 9l2 2"/>', 2],
};

const props = defineProps({ name: { type: String, required: true } });
const icon = computed(() => ICONS[props.name]);
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="icon[1]" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="icon[0]"></svg>
</template>
