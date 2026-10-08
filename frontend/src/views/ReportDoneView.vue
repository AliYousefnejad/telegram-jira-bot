<script setup>
// The report is in Jira: its key, owner, and the message the bot will send about it.
import { computed } from 'vue';
import { useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { useReport } from '@/stores/report.js';
import { useTasks } from '@/stores/tasks.js';
import Icon from '@/components/Icon.vue';
import ShareBox from '@/components/ShareBox.vue';

const { t } = useText();
const nav = useNav(), report = useReport(), tasks = useTasks();
const key = computed(() => report.created?.key ?? '');

// An assignee who never linked Telegram is named but can't be tagged.
const note = computed(() => {
    const a = tasks.assignees.find(x => x.id === report.assignee);
    return a && !a.telegram ? t('share.notLinked', { name: a.name }) : null;
});

function viewTask() {
    tasks.openTask(key.value);
    nav.go('detail');
}
</script>

<template>
    <section class="screen on" id="report-done">
        <div class="done-card">
            <span class="done-mark"><Icon name="check" /></span>
            <h2>{{ t('report.doneTitle') }}</h2>
            <p><span class="key num" id="doneKey">{{ key }}</span><br>{{ t('report.doneMeta') }} <span class="num" id="doneOwner">{{ report.created?.owner || '—' }}</span></p>
        </div>
        <ShareBox
            v-if="key" :task-key="key" :title="t('share.previewTitle')" :preview="report.preview" :note="note"
            :ids="{ box: 'shareBox', bubble: 'bubble', button: 'shareBtn', label: 'shareLabel' }"
        />
        <button class="ghost-btn" id="doneOpen" @click="viewTask">{{ t('report.view', { key }) }}</button>
        <button class="ghost-btn" id="newReport" @click="nav.reset('home', 'report')">{{ t('report.again') }}</button>
    </section>
</template>
