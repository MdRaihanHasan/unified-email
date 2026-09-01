<script setup>
import { computed } from 'vue'

/**
 * Deterministic tinted avatar: the same sender wears the same color everywhere.
 * Hashes the seed (the email address where the caller has one, else the name)
 * into one of eight muted tint pairs defined in app.css (.av-0 … .av-7).
 */
const props = defineProps({
    name: { type: String, default: '' },
    seed: { type: String, default: null },
    size: { type: Number, default: 30 },
})

// First letter of whatever we have, which for Bangla and other scripts means the
// first grapheme rather than the first byte.
const initial = computed(() => {
    const trimmed = (props.name || '').trim()
    if (!trimmed) return '?'

    return [...trimmed][0].toUpperCase()
})

const tint = computed(() => {
    const value = (props.seed || props.name || '?').toLowerCase()
    let hash = 7

    for (const ch of value) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0

    return `av-${hash % 8}`
})
</script>

<template>
    <div
        class="flex shrink-0 items-center justify-center rounded-full font-semibold"
        :class="tint"
        :style="{
            width: `${props.size}px`,
            height: `${props.size}px`,
            fontSize: `${Math.round(props.size * 0.43)}px`,
        }"
    >
        {{ initial }}
    </div>
</template>
