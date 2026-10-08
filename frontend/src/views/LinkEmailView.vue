<script setup>
// Link a Jira account, two ways:
// - email: the exact full Jira email; TechDesk then acts with the bot's shared token. No account list is
//   ever shown, and an account already linked to another Telegram account is refused.
// - personal token (PAT): Jira tells whose token it is, and everything then runs as that Jira user.
import { computed, nextTick, ref } from 'vue';
import { api } from '@/api/index.js';
import { useText } from '@/i18n/index.js';
import { useMainButton } from '@/composables/useMainButton.js';
import { useNav } from '@/stores/nav.js';
import { useSession } from '@/stores/session.js';
import Icon from '@/components/Icon.vue';
import UserAvatar from '@/components/UserAvatar.vue';

const { t, d } = useText();
const nav = useNav(), session = useSession();

const method = ref(session.linkMethod || 'email');   // email | pat
const email = ref('');
const token = ref('');
const showToken = ref(false);
const error = ref(null);     // i18n key
const jiraDown = ref(false);
const checking = ref(false);
const tokenInput = ref(null);

const ERRORS = {
    invalid_email: 'link.invalid',
    not_found: 'link.notFound',
    linked_elsewhere: 'link.taken',
    duplicate_accounts: 'link.duplicate',
    invalid_token: 'link.tokenInvalid',
};
const METHODS = ['email', 'pat'];
const byToken = computed(() => method.value === 'pat');

function choose(value) {
    method.value = value;
    error.value = null;
    jiraDown.value = false;
}

function toggleToken() {
    showToken.value = !showToken.value;
    nextTick(() => tokenInput.value?.focus());
}

async function link() {
    jiraDown.value = false;
    const value = (byToken.value ? token : email).value.trim();
    if (byToken.value ? value.length < 20 : !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) {
        error.value = byToken.value ? 'link.tokenShort' : 'link.invalid';
        return;
    }
    error.value = null;
    checking.value = true;
    try {
        const { jira } = await (byToken.value ? api.linkByToken(value) : api.linkByEmail(value));
        token.value = '';
        session.linked(jira);
        nav.reset('link-success');
    } catch (e) {
        if (ERRORS[e.code]) error.value = ERRORS[e.code];
        else jiraDown.value = true;
    } finally {
        checking.value = false;
    }
}

useMainButton(() => ({
    label: checking.value ? t('link.checking') : t('link.submit'),
    busy: checking.value,
    onClick: link,
}));
</script>

<template>
    <section class="screen on" id="link-email">
        <div class="steps" :aria-label="t('link.step', { a: d(1), b: d(2) })"><i class="done"></i><i></i></div>
        <div class="me">
            <UserAvatar />
            <div><b data-content>{{ session.telegram.name }}</b><span class="num">{{ session.handle || t('app.noUsername') }}</span></div>
        </div>
        <h2 class="ob-title">{{ t('link.title') }}</h2>
        <p class="ob-text">{{ t(byToken ? 'link.tokenText' : 'link.text') }}</p>

        <div class="segc wide" id="methodSeg" role="radiogroup" :aria-label="t('link.method')">
            <button
                v-for="m in METHODS" :key="m" :data-method="m" role="radio"
                :aria-checked="String(method === m)" @click="choose(m)"
            >{{ t('link.methods.' + m) }}</button>
        </div>

        <template v-if="!byToken">
            <label class="email" id="emailBox" :class="{ err: error }">
                <span>{{ t('link.email') }}</span>
                <input
                    id="email" v-model="email" aria-describedby="emailErr emailHint" :aria-invalid="String(!!error)"
                    type="email" inputmode="email" autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false"
                    enterkeyhint="go" :placeholder="t('link.emailPlaceholder')" @input="error = null" @keydown.enter="link"
                >
            </label>
            <p v-if="error" class="field-msg err" id="emailErr" role="alert">{{ t(error) }}</p>
            <p class="note" id="emailHint">{{ t('link.hint') }}</p>
        </template>

        <template v-else>
            <div class="email token" id="tokenBox" :class="{ err: error }">
                <label for="token">{{ t('link.token') }}</label>
                <div class="token-row">
                    <input
                        id="token" ref="tokenInput" v-model="token" aria-describedby="tokenErr tokenHint" :aria-invalid="String(!!error)"
                        :type="showToken ? 'text' : 'password'" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"
                        enterkeyhint="go" :placeholder="t('link.tokenPlaceholder')" @input="error = null" @keydown.enter="link"
                    >
                    <button
                        type="button" class="reveal" id="revealToken" :aria-pressed="String(showToken)"
                        :aria-label="t(showToken ? 'link.hideToken' : 'link.showToken')" @click="toggleToken"
                    ><Icon :name="showToken ? 'eyeOff' : 'eye'" /></button>
                </div>
            </div>
            <p v-if="error" class="field-msg err" id="tokenErr" role="alert">{{ t(error) }}</p>
            <div class="howto" id="tokenHint">
                <p class="howto-title">{{ t('link.howTitle') }}</p>
                <ol>
                    <li>{{ t('link.how1') }}</li>
                    <li>{{ t('link.how2') }}</li>
                    <li>{{ t('link.how3') }}</li>
                </ol>
                <p class="howto-safe"><Icon name="lock" />{{ t('link.tokenSafe') }}</p>
            </div>
        </template>

        <div v-if="jiraDown" class="banner" id="jiraDown" role="alert">
            <span class="bi"><Icon name="warning" /></span>
            <span><b>{{ t('link.down') }}</b> {{ t('link.downText') }}<br><button id="retry" @click="link">{{ t('link.retry') }}</button></span>
        </div>
    </section>
</template>
