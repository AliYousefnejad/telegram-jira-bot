<script setup>
import { computed } from 'vue';
import { useText } from '@/i18n/index.js';
import { useUi } from '@/stores/ui.js';
import BottomSheet from '../BottomSheet.vue';

const { t, tc } = useText();
const ui = useUi();
const box = computed(() => ui.confirmBox ?? {});
const text = computed(() => {
    const b = box.value;
    if (!b.text) return '';
    return b.count === undefined ? t(b.text, b.named ?? {}) : tc(b.text, b.count, b.named ?? {});
});
</script>

<template>
    <BottomSheet id="confirmSheet" class="confirm" role="alertdialog" :open="ui.sheet === 'confirm'" aria-labelledby="cfTitle">
        <h3 id="cfTitle">{{ box.title ? t(box.title) : '' }}</h3>
        <p id="cfText">{{ text }}</p>
        <div class="btns">
            <button class="ok" id="cfOk" @click="ui.confirmed()">{{ box.ok ? t(box.ok) : '' }}</button>
            <button class="no" id="cfNo" @click="ui.closeSheet()">{{ box.no ? t(box.no) : '' }}</button>
        </div>
    </BottomSheet>
</template>
