<script setup>
import { computed } from 'vue'

const props = defineProps({
    name: { type: String, default: '' },
    provider: { type: String, default: null },
    size: { type: Number, default: 30 },
})

// First letter of whatever we have, which for Bangla and other scripts means the
// first grapheme rather than the first byte.
const initial = computed(() => {
    const trimmed = (props.name || '').trim()
    if (!trimmed) return '?'

    return [...trimmed][0].toUpperCase()
})
</script>

<template>
    <div
        class="flex shrink-0 items-center justify-center rounded-full font-semibold text-white"
        :class="props.provider ? 'mailbox-fill' : 'bg-stone-400 dark:bg-stone-600'"
        :style="{
            width: `${props.size}px`,
            height: `${props.size}px`,
            fontSize: `${Math.round(props.size * 0.43)}px`,
            ...(props.provider ? { '--mailbox': `var(--mailbox-${props.provider})` } : {}),
        }"
    >
        {{ initial }}
    </div>
</template>
