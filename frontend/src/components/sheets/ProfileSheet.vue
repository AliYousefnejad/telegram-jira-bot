<script setup>
// Profile: who you are in Telegram and Jira (and how you signed in), language and theme, switch account, log out.
import { computed, ref } from 'vue';
import { api } from '@/api/index.js';
import { useText } from '@/i18n/index.js';
import { useNav } from '@/stores/nav.js';
import { usePrefs } from '@/stores/prefs.js';
import { useSession } from '@/stores/session.js';
import { useUi } from '@/stores/ui.js';
import BottomSheet from '../BottomSheet.vue';
import UserAvatar from '../UserAvatar.vue';
import Icon from '../Icon.vue';

const { t, d } = useText();
const ui = useUi(), nav = useNav(), prefs = usePrefs(), session = useSession();

const LANGUAGES = [['fa', 'فارسی'], ['en', 'English']];
const THEMES = ['system', 'light', 'dark'];

const byToken = computed(() => session.jira?.auth_method === 'pat');
const switching = ref(false);

function switchAccount() {
    ui.closeSheet();
    nav.go('link-email', { forceBack: true });
}
/** Signed in by email: sign in again with a personal token to act as yourself in Jira. */
function useOwnToken() {
    session.linkMethod = 'pat';
    switchAccount();
}
/** Signed in with a token: forget it and act with the bot's shared token. */
async function useBotToken() {
    switching.value = true;
    try {
        const { jira } = await api.useBotToken();
        session.jira = { ...session.jira, ...jira, token_status: 'none' };
        ui.notify(t('profile.switchedToBot'));
    } catch {
        ui.notify(t('app.serverDown'), 'error');
    } finally {
        switching.value = false;
    }
}
function logout() {
    ui.closeSheet();
    setTimeout(() => ui.confirm({
        title: 'profile.logoutTitle',
        text: 'profile.logoutText',
        named: { name: session.jira?.name ?? '' },
        ok: 'profile.logoutOk',
        no: 'profile.cancel',
    }, () => session.logout()), 180);
}
</script>

<template>
    <BottomSheet id="profileSheet" :open="ui.sheet === 'profile'" :aria-label="t('profile.title')">
        <div class="me" style="margin-bottom:0">
            <UserAvatar />
            <div>
                <b data-content>{{ session.telegram.name }}</b>
                <span class="num">{{ session.handle || t('app.noUsername') }} · {{ session.telegram.id }}</span>
            </div>
        </div>
        <div v-if="session.jira" class="prof-acct">
            <span>{{ t('profile.linked') }}</span>
            <b>{{ session.jira.name }}</b>
            <small>{{ session.jira.email }}</small>
            <p class="signin" id="signinMethod">
                <Icon :name="byToken ? 'key' : 'link'" />{{ t(byToken ? 'profile.viaToken' : 'profile.viaEmail') }}
            </p>
        </div>
        <p class="grp-title" style="margin:6px 0 8px">{{ t('profile.settings') }}</p>
        <div class="pref">
            <span>{{ t('profile.language') }}</span>
            <div class="segc" id="langSeg" role="radiogroup" :aria-label="t('profile.language')">
                <button v-for="[code, name] in LANGUAGES" :key="code" :data-lang="code" role="radio" :lang="code" :aria-checked="String(prefs.lang === code)" @click="prefs.setLang(code)">{{ name }}</button>
            </div>
        </div>
        <div class="pref">
            <span>{{ t('profile.theme') }}</span>
            <div class="segc" id="themeSeg" role="radiogroup" :aria-label="t('profile.theme')">
                <button v-for="theme in THEMES" :key="theme" :data-theme-v="theme" role="radio" :aria-checked="String(prefs.theme === theme)" @click="prefs.setTheme(theme)">{{ t('profile.themes.' + theme) }}</button>
            </div>
        </div>
        <p class="pref-hint">{{ t('profile.themeHint') }}</p>
        <button v-if="session.jira && !byToken" class="act" id="useOwnToken" @click="useOwnToken"><Icon name="key" />{{ t('profile.useToken') }}</button>
        <button v-if="byToken" class="act" id="profileBotToken" :disabled="switching" @click="useBotToken"><Icon name="swap" />{{ t('profile.useBotToken') }}</button>
        <button class="act" id="changeAcct" @click="switchAccount"><Icon name="swap" />{{ t('profile.switchAccount') }}</button>
        <button class="act danger" id="logoutBtn" @click="logout"><Icon name="logout" />{{ t('profile.logout') }}</button>
    </BottomSheet>
</template>
