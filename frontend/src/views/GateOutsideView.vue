<script setup>
// Opened outside Telegram, or the launch data is invalid/expired.
import { useText } from '@/i18n/index.js';
import Icon from '@/components/Icon.vue';

const { t } = useText();
</script>

<template>
    <section class="screen on" id="gate-outside">
        <div class="gate">
            <span class="mark ic blue"><Icon name="telegram" /></span>
            <h2>{{ t('gate.outsideTitle') }}</h2>
            <p>{{ t('gate.outsideText') }}</p>
            <p class="note" style="margin:0">{{ t('gate.outsideNote') }}</p>
        </div>
    </section>
</template>
