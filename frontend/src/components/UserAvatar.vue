<script setup>
// The Jira avatar when there is one, otherwise the Telegram name's initials.
import { useSession } from '@/stores/session.js';

const session = useSession();
</script>

<template>
    <div class="avatar" data-content :style="session.avatarUrl ? { background: `center / cover no-repeat url(&quot;${session.avatarUrl}&quot;)` } : null">
        <template v-if="!session.avatarUrl">{{ session.initials }}</template>
    </div>
</template>
