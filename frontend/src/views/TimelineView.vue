<script setup>
// A task's status history. Not wired to the backend yet: sample data.
import { useText } from '@/i18n/index.js';
import Icon from '@/components/Icon.vue';

const { t, d } = useText();

const STEPS = [
    { status: 'OPEN', who: 'جین دو', at: '1405/07/12 10:24', took: '40m', created: true },
    { status: 'IN PROGRESS', who: 'سارا احمدی', at: '1405/07/12 11:04', took: '1d 3h', long: true },
    { status: 'ESCALATED', who: 'سارا احمدی', at: '1405/07/13 14:10', took: '18h' },
    { status: 'WAITING FOR REPORTER', who: 'رضا کریمی', at: '1405/07/14 08:30', took: '10h', now: true },
];
</script>

<template>
    <section class="screen on" id="timeline">
        <label class="search">
            <Icon name="search" />
            <input enterkeyhint="search" autocapitalize="characters" autocorrect="off" value="TS-412" class="num" style="text-align:var(--align)">
        </label>
        <div class="tl-sum">
            <span>{{ t('timeline.total') }}<b class="num">2d 6h</b></span>
            <span>{{ t('timeline.current') }}<b class="num">WAITING FOR REPORTER</b></span>
            <span>{{ t('timeline.assignee') }}<b>سارا احمدی</b></span>
        </div>
        <div class="tl">
            <div v-for="(step, i) in STEPS" :key="step.status" class="tl-item" :class="{ now: step.now }">
                <span class="tl-dot num">{{ i + 1 }}</span>
                <div>
                    <b>{{ step.status }}</b>
                    <span class="who">{{ t(step.created ? 'timeline.createdBy' : 'timeline.by', { name: step.who, date: d(step.at) }) }}</span><br>
                    <span class="dur num" :class="{ long: step.long }">{{ step.now ? t('timeline.ongoing', { d: step.took }) : step.took }}</span>
                </div>
            </div>
        </div>
    </section>
</template>
