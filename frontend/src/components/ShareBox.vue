<script setup>
// "Send to this chat": previews the exact message the bot sends (HTML built by the server), then sends it.
import { ref } from 'vue';
import { api } from '@/api/index.js';
import { useText } from '@/i18n/index.js';
import { useUi } from '@/stores/ui.js';
import Icon from './Icon.vue';

const props = defineProps({
    taskKey: { type: String, required: true },
    title: { type: String, required: true },
    /** The message preview (Telegram HTML from the server); hidden when null */
    preview: { type: String, default: null },
    note: { type: String, default: null },
    ids: { type: Object, default: () => ({}) },
});
const { t } = useText();
const ui = useUi();
const state = ref('idle');   // idle | sending | sent

async function send() {
    state.value = 'sending';
    try {
        await api.share(props.taskKey);
        state.value = 'sent';
        ui.notify(t('share.toast', { key: props.taskKey }));
    } catch {
        state.value = 'idle';
        ui.notify(t('share.failed'), 'error');
    }
}
</script>

<template>
    <div class="share" :id="ids.box">
        <p class="share-title">{{ title }}</p>
        <div v-if="preview" class="bubble" :id="ids.bubble" data-content v-html="preview.replace(/\n/g, '<br>')"></div>
        <p v-if="note" class="share-note">{{ note }}</p>
        <button class="share-btn" :id="ids.button" :disabled="state !== 'idle'" @click="send">
            <Icon :name="state === 'sent' ? 'check' : 'send'" />
            <span :id="ids.label">{{ t(state === 'sent' ? 'share.sent' : state === 'sending' ? 'share.sending' : 'share.send') }}</span>
        </button>
        <p class="share-dest">{{ t('share.private') }}</p>
    </div>
</template>
