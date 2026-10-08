<script setup>
// The user's own Jira token stopped working (expired or revoked in Jira). The server already erased it;
// the user picks: sign in again with a new token, or keep going with the bot's shared token.
import { ref } from 'vue';
import { api } from '@/api/index.js';
import { useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { useSession } from '@/stores/session.js';
import { useUi } from '@/stores/ui.js';
import Icon from '@/components/Icon.vue';

const { t } = useText();
const nav = useNav(), session = useSession(), ui = useUi();
const busy = ref(false);

function reconnect() {
    session.linkMethod = 'pat';
    nav.reset('link-email');
}

async function useBotToken() {
    busy.value = true;
    try {
        await api.useBotToken();
        ui.notify(t('expired.switched'));
        await session.boot();
        nav.reset('home');
    } catch {
        ui.notify(t('app.serverDown'), 'error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="screen on" id="token-expired">
        <div class="gate" style="min-height:auto;padding-bottom:8px">
            <span class="mark ic amber"><Icon name="key" /></span>
            <h2>{{ t('expired.title') }}</h2>
            <p>{{ t('expired.text', { name: session.jira?.name ?? '' }) }}</p>
        </div>
        <button class="choice primary" id="reconnectToken" @click="reconnect">
            <span class="ic blue"><Icon name="key" /></span>
            <span><b>{{ t('expired.reconnect') }}</b>{{ t('expired.reconnectHint') }}</span>
        </button>
        <button class="choice" id="useBotToken" :disabled="busy" :aria-busy="String(busy)" @click="useBotToken">
            <span class="ic green"><Icon name="swap" /></span>
            <span><b>{{ t(busy ? 'expired.switching' : 'expired.botToken') }}</b>{{ t('expired.botTokenHint') }}</span>
        </button>
    </section>
</template>
