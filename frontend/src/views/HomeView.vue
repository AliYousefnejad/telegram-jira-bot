<script setup>
// Home: who you are, the open queue by severity, and the tools.
import { useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { useSession } from '@/stores/session.js';
import { useTasks } from '@/stores/tasks.js';
import { useUi } from '@/stores/ui.js';
import Icon from '@/components/Icon.vue';
import UserAvatar from '@/components/UserAvatar.vue';

const { t, tc, d } = useText();
const nav = useNav(), session = useSession(), tasks = useTasks(), ui = useUi();

const SEVERITIES = ['S1', 'S2', 'S3', 'S4'];

function openAll() {
    tasks.setFilter('all');
    nav.go('tasks');
}
</script>

<template>
    <section class="screen on" id="home">
        <button class="hello" id="profileBtn" :aria-label="t('home.profile')" @click="ui.openSheet('profile')">
            <UserAvatar />
            <div>
                <b data-content>{{ session.telegram.name }}</b>
                <span>{{ t('home.linkedTo') }} <span class="num">{{ session.jira?.name }}</span></span>
            </div>
            <Icon name="chevron" />
        </button>

        <div class="queue">
            <div class="queue-head">
                <div class="big num">
                    <template v-if="tasks.openLoaded">{{ tasks.open.length }}</template>
                    <span v-else class="sk" style="display:inline-block;width:48px;height:34px"></span>
                </div>
                <div class="cap">{{ t('home.openTasks') }}</div>
            </div>
            <div class="strip" role="group" :aria-label="t('home.bySeverity')">
                <button
                    v-for="sev in SEVERITIES" :key="sev"
                    :class="[sev.toLowerCase(), { sk: !tasks.openLoaded }]"
                    :style="{ flex: tasks.openLoaded ? tasks.severityCounts[sev] : 1 }"
                    :hidden="tasks.openLoaded && !tasks.severityCounts[sev]"
                    :aria-label="tc('home.severityCount', tasks.severityCounts[sev], { sev })"
                    @click="openAll"
                ></button>
            </div>
            <div class="strip-legend">
                <span v-for="sev in SEVERITIES" :key="sev">{{ sev }}<b>{{ tasks.openLoaded ? tasks.severityCounts[sev] : '–' }}</b></span>
            </div>
            <p v-if="tasks.openLoaded && tasks.staleS1" class="queue-note">
                <strong>{{ tc('home.staleS1', tasks.staleS1) }}</strong> {{ t('home.staleNote', { h: d(24) }) }}
            </p>
        </div>

        <p class="section-title">{{ t('home.tools') }}</p>
        <div class="tools">
            <button class="tool wide" data-go="tasks-status" @click="nav.go('tasks-status')">
                <span class="ic blue"><Icon name="lines" /></span>
                <span><b>{{ t('title.manageTasks') }}</b><small style="display:block">{{ t('home.manageTasksHint') }}</small></span>
            </button>
            <button class="tool" data-go="report" @click="nav.go('report')">
                <span class="ic red"><Icon name="plus" /></span>
                <b>{{ t('title.report') }}</b><small>{{ t('home.reportHint') }}</small>
            </button>
            <button class="tool" data-go="timeline" @click="nav.go('timeline')">
                <span class="ic green"><Icon name="clock" /></span>
                <b>{{ t('title.timeline') }}</b><small>{{ t('home.timelineHint') }}</small>
            </button>
        </div>
    </section>
</template>
