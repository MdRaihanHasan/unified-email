<script setup>
import { computed } from 'vue'

const props = defineProps({
    value: { type: String, default: null },
})

// Mail lists read better with a coarse stamp: time for today, date beyond that.
const label = computed(() => {
    if (!props.value) return ''

    const date = new Date(props.value)
    const now = new Date()
    const sameDay = date.toDateString() === now.toDateString()

    if (sameDay) {
        return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
    }

    const sameYear = date.getFullYear() === now.getFullYear()

    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        ...(sameYear ? {} : { year: 'numeric' }),
    })
})

const full = computed(() => (props.value ? new Date(props.value).toLocaleString() : ''))
</script>

<template>
    <time :datetime="props.value" :title="full">{{ label }}</time>
</template>
