<script setup>
// Report an issue: user bug, admin panel problem or force majeure (the bot's /report, in one form).
import { onMounted, ref } from 'vue';
import { useText } from '@/i18n/index.js';
import { useMainButton } from '@/composables/useMainButton.js';
import { MIN_DESCRIPTION, useReport } from '@/stores/report.js';
import { useTasks } from '@/stores/tasks.js';
import Icon from '@/components/Icon.vue';

const { t, tc, d } = useText();
const report = useReport(), tasks = useTasks();
const nameIn = ref(null), descIn = ref(null);

const TYPES = [['user', 'bug'], ['admin', 'monitor'], ['fm', 'bolt']];

onMounted(() => report.loadOptions());

async function submit() {
    const missing = await report.submit();
    if (missing === 'name') nameIn.value.focus();
    if (missing === 'description') descIn.value.focus();
}

useMainButton(() => ({
    label: report.submitting ? t('report.submitting') : t('report.submit'),
    disabled: report.descriptionLength < MIN_DESCRIPTION,
    busy: report.submitting,
    onClick: submit,
}));
</script>

<template>
    <section class="screen on" id="report">
        <p class="section-title" style="margin-top:4px">{{ t('report.type') }}</p>
        <div class="seg" id="seg">
            <button v-for="[type, icon] in TYPES" :key="type" :class="{ on: report.type === type }" :data-type="type" :aria-pressed="String(report.type === type)" @click="report.type = type">
                <span class="e"><Icon :name="icon" /></span>{{ t('report.types.' + type) }}
            </button>
        </div>
        <div v-show="report.type === 'user'" id="userFields">
            <p class="section-title">{{ t('report.userDetails') }}</p>
            <div class="form">
                <label class="field" id="fName" :class="{ err: report.nameError }">
                    <span>{{ t('report.name') }}</span>
                    <input ref="nameIn" id="rName" v-model="report.name" autocomplete="off" @input="report.nameError = false">
                    <span v-if="report.nameError" class="ferr" role="alert">{{ t('report.nameRequired') }}</span>
                </label>
                <label class="field"><span>{{ t('report.userId') }}</span><input id="rUserId" v-model="report.userId" inputmode="numeric" class="num" style="text-align:var(--align)"></label>
                <label class="field"><span>{{ t('report.mobile') }}</span><input id="rMobile" v-model="report.mobile" type="tel" inputmode="tel" class="num" style="text-align:var(--align)"></label>
                <label v-if="report.categories.length" class="field">
                    <span>{{ t('report.category') }}</span>
                    <select id="rCategory" v-model="report.category"><option value="">—</option><option v-for="c in report.categories" :key="c.id" :value="c.id">{{ c.value }}</option></select>
                </label>
            </div>
        </div>
        <p class="section-title">{{ t('report.assigneeSection') }}</p>
        <div class="form">
            <label class="field">
                <span>{{ t('report.assignee') }}</span>
                <select id="rAssignee" v-model="report.assignee">
                    <option value="">{{ t('report.noAssignee') }}</option>
                    <option v-for="a in tasks.assignees" :key="a.id" :value="a.id">{{ a.name }} ({{ a.telegram || t('app.noTelegram') }})</option>
                </select>
            </label>
        </div>
        <p class="note" style="margin-top:6px">{{ t('report.assigneeNote') }}</p>
        <p class="section-title">{{ t('report.problem') }}</p>
        <div class="form">
            <label class="field"><span>{{ t('report.problemLabel') }}</span><textarea ref="descIn" id="desc" v-model="report.description" rows="5" aria-describedby="cnt"></textarea></label>
        </div>
        <div class="counter">
            <span id="cnt" :class="{ ok: report.descriptionLength >= MIN_DESCRIPTION }">
                {{ report.descriptionLength >= MIN_DESCRIPTION ? tc('report.chars', report.descriptionLength) : tc('report.charsNeeded', MIN_DESCRIPTION - report.descriptionLength) }}
            </span>
            <span>{{ t('report.minChars', { n: d(MIN_DESCRIPTION) }) }}</span>
        </div>
    </section>
</template>
