<script setup>
// Edit the task description. Staged like every other edit; nothing reaches Jira until "apply".
import { ref, watch } from 'vue';
import { useText } from '@/i18n/index.js';
import { useTasks } from '@/stores/tasks.js';
import { useUi } from '@/stores/ui.js';
import BottomSheet from '../BottomSheet.vue';

const { t } = useText();
const ui = useUi(), tasks = useTasks();
const draft = ref('');

watch(() => ui.sheet === 'description', open => {
    if (open) draft.value = tasks.valueOf('description') ?? '';
});

function save() {
    const value = draft.value.trim();
    if (value.length < 10) return;
    tasks.stageText('description', value);
    ui.closeSheet();
}
</script>

<template>
    <BottomSheet id="textSheet" :open="ui.sheet === 'description'" aria-labelledby="txTitle">
        <h3 id="txTitle">{{ t('detail.editDescription') }}</h3>
        <p class="hint">{{ t('detail.editDescriptionHint') }}</p>
        <textarea id="txArea" v-model="draft"></textarea>
        <button class="sheet-btn" id="txSave" @click="save">{{ t('detail.addToChanges') }}</button>
    </BottomSheet>
</template>
