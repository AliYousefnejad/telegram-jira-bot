<script setup>
// Every board status with its open-task count, grouped by owner (same first step as the bot's /managetasks).
import { computed } from 'vue';
import { useText } from '@/i18n/index.js';
import { useTasks } from '@/stores/tasks.js';
import Breakable from './Breakable.vue';
import Icon from './Icon.vue';

defineProps({ selected: { type: String, default: null } });
const emit = defineEmits(['pick']);
const { t, tc } = useText();
const tasks = useTasks();

const OWNERS = ['L1', 'L2', 'Reporter', '?'];
const groups = computed(() => OWNERS
    .map(owner => ({
        owner,
        rows: tasks.statuses.map((st, i) => ({ ...st, n: i + 1 })).filter(st => (st.owner || '?') === owner),
    }))
    .filter(g => g.rows.length));
</script>

<template>
    <div class="list" style="margin-top:12px">
        <button class="srow all" :class="{ sel: selected === 'all' }" data-filter="all" @click="emit('pick', 'all')">
            <span class="sn num"><Icon name="all" /></span>
            <span class="st">{{ t('status.allOpen') }}</span>
            <span class="sc">{{ tc('app.tasks', tasks.openCount) }}</span>
            <Icon name="chevron" />
        </button>
    </div>
    <template v-for="group in groups" :key="group.owner">
        <p v-if="group.owner === '?'" class="grp-title">{{ t('status.groups.none') }}</p>
        <i18n-t v-else keypath="status.owner" tag="p" class="grp-title">
            <template #owner><span class="num">{{ group.owner }}</span></template>
            <template #desc>{{ t('status.groups.' + group.owner) }}</template>
        </i18n-t>
        <div class="list">
            <button v-for="st in group.rows" :key="st.name" class="srow" :class="{ sel: selected === st.name }" :data-filter="st.name" @click="emit('pick', st.name)">
                <span class="sn num">{{ st.n }}</span>
                <span class="st"><Breakable :text="st.name" /></span>
                <span class="sc">{{ tc('app.tasks', st.count) }}</span>
                <Icon name="chevron" />
            </button>
        </div>
    </template>
</template>
