<script setup>
import Icon from './Icon.vue'

const props = defineProps({
    name: { type: String, required: true },
    label: { type: String, required: true },
    size: { type: Number, default: 20 },
    filled: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
})
</script>

<template>
    <button
        type="button"
        :title="props.label"
        :aria-label="props.label"
        class="flex size-8 shrink-0 items-center justify-center rounded-full transition hover:bg-stone-200/70 dark:hover:bg-stone-700/70"
        :class="props.active ? 'text-amber-500' : 'text-stone-500 dark:text-stone-400'"
    >
        <Icon :name="props.name" :size="props.size" :filled="props.filled" />
    </button>
</template>
