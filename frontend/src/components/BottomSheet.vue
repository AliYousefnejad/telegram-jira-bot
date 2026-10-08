<script setup>
// A bottom sheet. Focus moves into it when it opens (the selected option or the text field) and back to
// whatever opened it when it closes.
import { ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    role: { type: String, default: 'dialog' },
});
const el = ref(null);
let opener = null;

watch(() => props.open, on => {
    if (on) {
        opener = document.activeElement;
        setTimeout(() => (el.value.querySelector('.opt.sel, .srow.sel, textarea') || el.value).focus({ preventScroll: true }), 60);
    } else if (opener) {
        opener.focus?.({ preventScroll: true });
        opener = null;
    }
});
</script>

<template>
    <div ref="el" class="sheet" :class="{ on: open }" :role="role" tabindex="-1">
        <div class="grab"></div>
        <slot />
    </div>
</template>
