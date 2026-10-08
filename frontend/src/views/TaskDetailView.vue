<script setup>
// A task: severity/impact, its fields (tap to stage an edit), staged edits, description, comment, timeline, share.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '@/api/index.js';
import { useText } from '@/i18n/index.js';
import { useMainButton } from '@/composables/useMainButton.js';
import { useNav } from '@/stores/nav.js';
import { FIELDS, useTasks } from '@/stores/tasks.js';
import { useUi } from '@/stores/ui.js';
import Breakable from '@/components/Breakable.vue';
import Icon from '@/components/Icon.vue';
import ShareBox from '@/components/ShareBox.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';

const { t, tc, d } = useText();
const nav = useNav(), tasks = useTasks(), ui = useUi();

const TEXT_FIELDS = ['description', 'comment'];
// Only user bug reports have a category.
const fields = computed(() => Object.fromEntries(Object.entries(FIELDS).filter(([field]) => field !== 'category' || tasks.options.categories)));
const show = value => (value === null ? t('app.unassigned') : value);

// Severity and impact as 4-step level bars (S1/I1 = all four filled)
const level = value => {
    const m = /[SI](\d)/.exec(value ?? '');
    return m ? Number(m[1]) : null;
};
const levels = computed(() => {
    const sev = level(tasks.current.severity), imp = level(tasks.current.impact);
    return { sev, imp };
});

// The exact message the bot would send for this task, rebuilt after every applied edit
const preview = ref(null);
watch(() => tasks.task, (task, before) => {
    const key = tasks.key;
    if (!task || task.key !== before?.key) preview.value = null;
    if (task && key) api.sharePreview(key).then(r => { if (tasks.key === key) preview.value = r.html; }).catch(() => {});
}, { immediate: true });

// Comment composer: grows with its text and stays above the keyboard
const comment = ref('');
const commentIn = ref(null), composer = ref(null);
function fitComment() {
    const el = commentIn.value;
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 2 + 'px';
    el.classList.toggle('scroll', el.scrollHeight > el.clientHeight + 2);
    const box = composer.value.getBoundingClientRect();
    const vh = window.visualViewport ? visualViewport.height : innerHeight;
    if (box.bottom > vh - 12) window.scrollBy(0, box.bottom - vh + 12);
}
const fitAfterKeyboard = () => setTimeout(fitComment, 250);
const onViewportResize = () => { if (document.activeElement === commentIn.value) fitComment(); };
onMounted(() => window.visualViewport?.addEventListener('resize', onViewportResize));
onBeforeUnmount(() => window.visualViewport?.removeEventListener('resize', onViewportResize));
function addComment() {
    const value = comment.value.trim();
    if (value.length < 2) { commentIn.value.focus(); return; }
    tasks.stageText('comment', value);
    comment.value = '';
    nextTick(fitComment);
}

useMainButton(() => (tasks.pendingCount ? {
    label: tasks.applying ? t('detail.applying') : tc('detail.apply', tasks.pendingCount),
    busy: tasks.applying,
    onClick: () => tasks.apply(),
} : null));
</script>

<template>
    <section class="screen on" id="detail">
        <div class="d-head">
            <h2 v-if="tasks.detailLoading"><span class="sk" style="display:block;height:18px;width:90%"></span><span class="sk" style="display:block;height:18px;width:60%;margin-top:8px"></span></h2>
            <h2 v-else-if="tasks.detailError">{{ t(tasks.detailError) }}</h2>
            <h2 v-else>{{ tasks.task?.summary }}</h2>
            <div v-if="tasks.task" class="meta">
                <span class="num">{{ tasks.task.type }}</span>
                <span v-if="tasks.task.created_jalali">{{ t('detail.created', { date: d(tasks.task.created_jalali) }) }}</span>
            </div>
            <div v-if="tasks.task" class="levels" id="levels">
                <div v-if="levels.sev" class="lv" role="img" :aria-label="t('detail.severityAria', { v: tasks.current.severity })" :style="{ '--lv': `var(--s${levels.sev})`, '--lv-ink': `var(--s${levels.sev}-ink)` }">
                    <span class="lk">{{ t('detail.severity') }}</span>
                    <span class="bars" aria-hidden="true"><i v-for="i in 4" :key="i" :class="{ on: i <= 5 - levels.sev }"></i></span>
                    <span class="lt num">{{ tasks.current.severity }}</span>
                </div>
                <div v-if="levels.imp" class="lv" role="img" :aria-label="t('detail.impactAria', { v: tasks.current.impact })" style="--lv:var(--tg-text);--lv-ink:var(--tg-text)">
                    <span class="lk">{{ t('detail.impact') }}</span>
                    <span class="bars" aria-hidden="true"><i v-for="i in 4" :key="i" :class="{ on: i <= 5 - levels.imp }"></i></span>
                    <span class="lt num">{{ tasks.current.impact }}</span>
                </div>
                <p v-if="tasks.rank" class="rank">
                    <i18n-t keypath="detail.rank" tag="span"><template #owner><span class="num">{{ tasks.rank.owner }}</span></template></i18n-t>
                    <b>{{ t('detail.rankOf', { a: d(tasks.rank.position), b: d(tasks.rank.of) }) }}</b>
                </p>
            </div>
        </div>

        <p class="section-title">{{ t('detail.fields') }}</p>
        <div class="list fields" id="fields">
            <SkeletonRows v-if="tasks.detailLoading" :count="3" />
            <template v-else-if="tasks.task">
                <button v-for="(def, field) in fields" :key="field" class="f" :class="{ staged: tasks.pending[field] }" :data-field="field" @click="ui.openOptions({ kind: 'field', field })">
                    <span class="l">{{ t(`field.${field}.label`) }}</span>
                    <span class="v">
                        <span :class="{ num: def.latin }">
                            <template v-if="tasks.pending[field]"><span class="old"><Breakable :text="show(tasks.current[field])" /></span>{{ ' ' }}</template>
                            <Breakable :text="show(tasks.valueOf(field))" />
                            <template v-if="field === 'assignee' && tasks.telegramOf[tasks.valueOf(field)]">{{ ' ' }}<span class="tg">{{ tasks.telegramOf[tasks.valueOf(field)] }}</span></template>
                        </span>
                        <Icon name="chevron" />
                    </span>
                </button>
            </template>
        </div>

        <div v-if="tasks.pendingCount" class="pending" id="pending">
            <div class="pending-head">
                <span><b>{{ tc('detail.changes', tasks.pendingCount) }}</b> {{ t('detail.notApplied') }}</span>
                <button data-undo="*" @click="tasks.discardAll()">{{ t('detail.discardAll') }}</button>
            </div>
            <div v-for="(edit, field) in tasks.pending" :key="field" class="chg">
                <span class="k">{{ t(`field.${field}.label`) }}</span>
                <span v-if="TEXT_FIELDS.includes(field)" class="txt">{{ edit.to }}</span>
                <span v-else class="ft">
                    <span class="from" :class="{ num: FIELDS[field].latin }"><Breakable :text="show(tasks.current[field])" /></span>
                    <span class="to" :class="{ num: FIELDS[field].latin }"><Breakable :text="show(edit.to)" /></span>
                </span>
                <button class="undo" :data-undo="field" :aria-label="t('detail.undo', { field: t(`field.${field}.label`) })" @click="tasks.undo(field)"><Icon name="close" /></button>
                <span v-if="edit.auto" class="auto">{{ t('detail.automatic') }}</span>
            </div>
        </div>

        <div class="desc-head">
            <p class="section-title" style="margin:16px 4px 4px">{{ t('detail.description') }}</p>
            <button id="editDesc" :disabled="!tasks.task" @click="ui.openSheet('description')">{{ t('detail.edit') }}</button>
        </div>
        <div class="desc" id="descText">
            <template v-if="tasks.detailLoading"><span class="sk" style="display:block;height:14px;width:95%"></span><span class="sk" style="display:block;height:14px;width:70%;margin-top:8px"></span></template>
            <template v-else>{{ tasks.current.description ?? '—' }}</template>
        </div>
        <div ref="composer" class="composer" id="composer">
            <textarea ref="commentIn" id="commentIn" v-model="comment" rows="1" enterkeyhint="enter" :placeholder="t('detail.commentPlaceholder')" :aria-label="t('detail.comment')" @input="fitComment" @focus="fitAfterKeyboard"></textarea>
            <button class="send" id="commentAdd" :disabled="comment.trim().length < 2" :aria-label="t('detail.addComment')" @click="addComment"><Icon name="arrow" /></button>
        </div>
        <p class="composer-hint">{{ t('detail.commentHint') }}</p>
        <button class="link-row" data-go="timeline" @click="nav.go('timeline')"><Icon name="clock" /><span>{{ t('detail.viewTimeline') }}</span></button>
        <ShareBox
            v-if="tasks.key" :key="tasks.key" :task-key="tasks.key" :title="t('share.taskTitle')" :preview="preview"
            :ids="{ box: 'taskShareBox', bubble: 'taskBubble', button: 'taskShareBtn', label: 'taskShareLabel' }"
        />
    </section>
</template>
