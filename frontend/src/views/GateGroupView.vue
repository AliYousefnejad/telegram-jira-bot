<script setup>
// Opened from a group, supergroup or channel: TechDesk works only in the private chat with the bot.
import { useText } from '@/i18n/index.js';
import { botUsername, inTelegram, webApp } from '@/lib/telegram.js';
import { useUi } from '@/stores/ui.js';
import Icon from '@/components/Icon.vue';

const { t } = useText();
const ui = useUi();

function openPrivate() {
    if (inTelegram) webApp.openTelegramLink(`https://t.me/${botUsername}`);
    else ui.notify(t('gate.openPrivateDemo'));
}
</script>

<template>
    <section class="screen on" id="gate-group">
        <div class="gate">
            <span class="mark ic amber"><Icon name="group" /></span>
            <h2>{{ t('gate.groupTitle') }}</h2>
            <p>{{ t('gate.groupText') }}</p>
            <p class="note" style="margin:0">{{ t('gate.groupNote') }}</p>
            <button class="ghost-btn" id="openPrivate" style="background:var(--tg-bg)" @click="openPrivate">{{ t('gate.openPrivate') }}</button>
        </div>
    </section>
</template>
