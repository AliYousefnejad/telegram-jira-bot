<script setup>
import { computed } from 'vue';
import { useText } from '@/i18n/index.js';
import { useMainButton } from '@/composables/useMainButton.js';
import { useNav } from '@/stores/nav.js';
import { useSession } from '@/stores/session.js';
import Icon from '@/components/Icon.vue';

const { t, d } = useText();
const nav = useNav(), session = useSession();
const jiraName = computed(() => session.jira?.name || '');

const byToken = computed(() => session.jira?.auth_method === 'pat');

// Load the queue and the forms for the account just linked, then open home.
useMainButton(() => ({ label: t('link.enter'), onClick: () => { session.boot(); nav.reset('home'); } }));
</script>

<template>
    <section class="screen on" id="link-success">
        <div class="steps" :aria-label="t('link.step', { a: d(2), b: d(2) })"><i class="done"></i><i class="done"></i></div>
        <div class="gate" style="min-height:calc(100dvh - 260px)">
            <span class="done-mark"><Icon name="check" /></span>
            <h2>{{ t('link.doneTitle') }}</h2>
            <i18n-t :keypath="byToken ? 'link.doneTextToken' : 'link.doneText'" tag="p">
                <template #name><b class="num" style="color:var(--tg-text);white-space:nowrap">{{ jiraName }}</b></template>
            </i18n-t>
            <p class="linkpair">
                <span class="num">{{ session.handle || session.telegram.name }}</span>
                <Icon name="link" />
                <span class="num">{{ jiraName }}</span>
            </p>
        </div>
    </section>
</template>
