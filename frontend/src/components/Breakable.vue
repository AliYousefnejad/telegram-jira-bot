<script setup>
// Long Jira values like "ESCALATED TO DEVOPS/VENDOR/DEVELOPER": allow a line break after each slash.
import { computed } from 'vue';

const props = defineProps({ text: { type: [String, Number], default: '' } });
const parts = computed(() => String(props.text).split('/'));
</script>

<template><template v-for="(part, i) in parts" :key="i"><template v-if="i">/<wbr></template>{{ part }}</template></template>
