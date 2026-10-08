<script setup>
// A real Telegram user, but not in TELEGRAM_ADMIN_IDS: show the ID to send to the bot's owner.
import { ref } from 'vue';
import { useText } from '@/i18n/index.js';
import { useSession } from '@/stores/session.js';
import Icon from '@/components/Icon.vue';

const { t } = useText();
const session = useSession();
const copied = ref(false);

function copyId() {
    try { navigator.clipboard.writeText(String(session.telegram.id ?? '')); } catch { /* no clipboard */ }
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1600);
}
</script>

<template>
    <section class="screen on" id="gate-denied">
        <div class="gate">
            <span class="mark ic red"><Icon name="lock" /></span>
            <h2>{{ t('gate.deniedTitle') }}</h2>
            <p>{{ t('gate.deniedText') }}</p>
            <div class="idbox">
                <div><span>{{ t('gate.yourId') }}</span><b class="num">{{ session.telegram.id }}</b></div>
                <button id="copyId" @click="copyId">{{ t(copied ? 'gate.copied' : 'gate.copyId') }}</button>
            </div>
        </div>
    </section>
</template>
