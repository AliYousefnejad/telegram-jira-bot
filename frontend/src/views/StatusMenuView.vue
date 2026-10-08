<script setup>
// Manage tasks, step 1: pick a status (the bot's rptfilter_* menu).
import { useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { useTasks } from '@/stores/tasks.js';
import SkeletonRows from '@/components/SkeletonRows.vue';
import StatusRows from '@/components/StatusRows.vue';

const { t } = useText();
const nav = useNav(), tasks = useTasks();

function pick(filter) {
    tasks.setFilter(filter);
    nav.go('tasks');
}
</script>

<template>
    <section class="screen on" id="tasks-status">
        <h2 class="ob-title" style="font-size:19px">{{ t('status.question') }}</h2>
        <p class="ob-text" style="margin-bottom:4px">{{ t('status.intro') }}</p>
        <div id="statusMenu">
            <StatusRows v-if="tasks.statusesLoaded" @pick="pick" />
            <div v-else class="list" style="margin-top:12px"><SkeletonRows :count="5" /></div>
        </div>
    </section>
</template>
