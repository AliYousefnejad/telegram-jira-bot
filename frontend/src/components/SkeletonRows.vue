<script setup>
defineProps({ count: { type: Number, default: 4 } });
</script>

<template>
    <div v-for="i in count" :key="i" class="sk-row" aria-hidden="true">
        <span class="sk" style="width:64px;height:12px"></span>
        <span class="sk" style="width:92%;height:14px;margin-top:10px"></span>
        <span class="sk" style="width:60%;height:14px;margin-top:8px"></span>
    </div>
</template>
