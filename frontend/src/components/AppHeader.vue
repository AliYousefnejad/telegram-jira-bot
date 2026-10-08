<script setup>
// The header Telegram would draw: Back, the screen title, and the language switch on screens without a profile.
// Inside Telegram the Back button is Telegram's own (see lib/bridge.js).
import { computed } from 'vue';
import { useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { usePrefs } from '@/stores/prefs.js';
import { useTasks } from '@/stores/tasks.js';
import Icon from './Icon.vue';

const { t } = useText();
const nav = useNav(), prefs = usePrefs(), tasks = useTasks();

const title = computed(() => (nav.current === 'detail' ? tasks.key : nav.meta.title ? t(nav.meta.title) : null));
</script>

<template>
    <header class="tg-header">
        <button class="back" id="back" :class="{ on: nav.showBack }" :aria-label="t('app.back')" @click="nav.back()"><Icon name="back" /></button>
        <h1 v-if="title" id="title">{{ title }}</h1>
        <h1 v-else id="title" class="brand"><span dir="ltr">Tech<span>Desk</span></span></h1>
        <button class="lang-btn" id="langBtn" :class="{ on: nav.meta.language }" :aria-label="t('app.changeLanguage')" @click="prefs.setLang(prefs.lang === 'fa' ? 'en' : 'fa')">
            <Icon name="globe" />
            <span :lang="prefs.lang === 'fa' ? 'en' : 'fa'">{{ prefs.lang === 'fa' ? 'English' : 'فارسی' }}</span>
        </button>
    </header>
</template>
