<script setup>
// Manage tasks, step 2: the tasks in the chosen status, searchable by key or title.
import { computed, onMounted, ref } from 'vue';
import { latinDigits, useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { useTasks } from '@/stores/tasks.js';
import { useUi } from '@/stores/ui.js';
import Breakable from '@/components/Breakable.vue';
import Icon from '@/components/Icon.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';

const { t, tc, ago } = useText();
const nav = useNav(), tasks = useTasks(), ui = useUi();
const search = ref(null);

onMounted(() => {
    if (!tasks.listLoading && tasks.listFilter !== tasks.filter) tasks.loadList();
});

const filterName = computed(() => tasks.filterStatus?.name ?? t('status.allOpen'));
const filterCount = computed(() => (tasks.filterStatus ? tasks.filterStatus.count : tasks.openCount));

const rows = computed(() => {
    const q = tasks.query.trim();
    const key = latinDigits(q.toLowerCase());
    return q ? tasks.list.filter(task => task.key.toLowerCase().includes(key) || task.summary.includes(q)) : tasks.list;
});

const sev = task => (task.severity ? task.severity.toLowerCase() : 'none');

function open(task) {
    tasks.openTask(task.key);
    nav.go('detail');
}
function clearSearch() {
    tasks.query = '';
    search.value.focus();
}
</script>

<template>
    <section class="screen on" id="tasks">
        <button class="filterbar" id="filterBar" @click="ui.openOptions({ kind: 'status' })">
            <span class="fv">
                <small>{{ t('tasks.filter') }}</small>
                <b id="filterName" :class="{ num: tasks.filterStatus }"><Breakable :text="filterName" /></b>
            </span>
            <span class="fc" id="filterCount">{{ tc('app.tasks', filterCount) }}</span>
            <span class="fe">{{ t('tasks.change') }}</span>
        </button>
        <label class="search">
            <Icon name="search" />
            <input ref="search" v-model="tasks.query" type="search" enterkeyhint="search" autocapitalize="none" autocorrect="off" :placeholder="t('tasks.search')">
        </label>
        <div class="list" id="list" :aria-busy="String(tasks.listLoading)">
            <SkeletonRows v-if="tasks.listLoading" :count="4" />
            <template v-else-if="rows.length">
                <button v-for="task in rows" :key="task.key" class="row" :style="{ '--sev': `var(--${sev(task)})` }" @click="open(task)">
                    <div class="body">
                        <div class="top"><span class="key num">{{ task.key }}</span><span class="when">{{ ago(task.updated) }}</span></div>
                        <p class="sum">{{ task.summary }}</p>
                        <div class="meta">
                            <span class="badge" :class="'b-' + sev(task)">{{ task.severity || '—' }}</span>
                            <span class="badge b-i">{{ task.impact || '—' }}</span>
                            <span>{{ task.assignee ? task.assignee.name : t('app.unassigned') }}</span>
                        </div>
                    </div>
                </button>
            </template>
            <div v-else class="empty">
                <Icon name="noResults" />
                <b>{{ t('tasks.empty') }}</b>
                <p>{{ tasks.query.trim() ? t('tasks.emptyQuery', { status: filterName, q: tasks.query.trim() }) : t('tasks.emptyStatus', { status: filterName }) }}</p>
                <button v-if="tasks.query.trim()" id="clearSearch" @click="clearSearch">{{ t('tasks.clearSearch') }}</button>
            </div>
        </div>
        <p class="list-foot">{{ t('tasks.sorted') }}</p>
    </section>
</template>
