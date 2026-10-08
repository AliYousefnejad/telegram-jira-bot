<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { RouterView } from 'vue-router';
import AppHeader from '@/components/AppHeader.vue';
import OptionsSheet from '@/components/sheets/OptionsSheet.vue';
import ProfileSheet from '@/components/sheets/ProfileSheet.vue';
import ConfirmSheet from '@/components/sheets/ConfirmSheet.vue';
import DescriptionSheet from '@/components/sheets/DescriptionSheet.vue';
import { useUi } from '@/stores/ui.js';

const ui = useUi();
const onKey = e => { if (e.key === 'Escape') ui.closeSheet(); };
onMounted(() => addEventListener('keydown', onKey));
onBeforeUnmount(() => removeEventListener('keydown', onKey));
</script>

<template>
    <AppHeader />
    <main>
        <RouterView />
    </main>

    <div class="scrim" :class="{ on: ui.sheet }" @click="ui.closeSheet()"></div>
    <OptionsSheet />
    <ProfileSheet />
    <ConfirmSheet />
    <DescriptionSheet />

    <div class="main-btn" id="mainBtn" :class="{ on: ui.main }">
        <button v-if="ui.main" id="mainBtnLabel" :disabled="ui.main.disabled || ui.main.busy" @click="ui.main.onClick()">
            <span v-if="ui.main.busy" class="spin"></span>{{ ui.main.label }}
        </button>
    </div>
    <div class="toast" id="toast" :class="{ on: ui.toast.on }" role="status" aria-live="polite">{{ ui.toast.text }}</div>
</template>
