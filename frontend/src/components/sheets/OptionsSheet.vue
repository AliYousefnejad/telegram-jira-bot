<script setup>
// One sheet for two lists: the status filter (task list) and a field's values (task detail).
import { computed } from 'vue';
import { useText } from '@/i18n/index.js';
import { FIELDS, useTasks } from '@/stores/tasks.js';
import { useUi } from '@/stores/ui.js';
import BottomSheet from '../BottomSheet.vue';
import StatusRows from '../StatusRows.vue';

const { t } = useText();
const ui = useUi(), tasks = useTasks();

const field = computed(() => (ui.options?.kind === 'field' ? ui.options.field : null));
const hint = computed(() => (field.value ? t(`field.${field.value}.hint`) : ''));
const choices = computed(() => (field.value ? tasks.choices(field.value) : []));

function pickStatus(filter) {
    tasks.setFilter(filter);
    ui.closeSheet();
}
function pick(choice) {
    tasks.stage(field.value, choice.value, choice.owner, choice.id);
    setTimeout(() => ui.closeSheet(), 180);
}
</script>

<template>
    <BottomSheet id="sheet" :open="ui.sheet === 'options'" aria-labelledby="sheetTitle">
        <h3 id="sheetTitle">{{ field ? t(`field.${field}.title`) : t('status.choose') }}</h3>
        <p v-if="hint" class="hint" id="sheetHint">{{ hint }}</p>
        <div id="opts">
            <StatusRows v-if="ui.options?.kind === 'status'" :selected="tasks.filter" @pick="pickStatus" />
            <p v-else-if="field && !choices.length" class="hint" id="noChoices">{{ t(tasks.optionsLoading ? 'detail.loadingChoices' : 'detail.noChoices') }}</p>
            <template v-else-if="field">
                <button v-for="choice in choices" :key="String(choice.value)" class="opt" :class="{ sel: tasks.valueOf(field) === choice.value }" @click="pick(choice)">
                    <span class="dot"></span>
                    <span class="ot" :style="FIELDS[field].latin ? null : 'direction:rtl;text-align:var(--align)'">{{ choice.value ?? t('app.unassigned') }}</span>
                    <span v-if="choice.owner" class="own">{{ t('detail.owner', { owner: choice.owner }) }}</span>
                    <span v-if="field === 'assignee' && choice.value !== null" class="own" :class="{ num: tasks.telegramOf[choice.value] }">{{ tasks.telegramOf[choice.value] || t('app.noTelegram') }}</span>
                </button>
            </template>
        </div>
    </BottomSheet>
</template>
