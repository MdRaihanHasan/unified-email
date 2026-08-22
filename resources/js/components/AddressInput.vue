<script setup>
import { ref } from 'vue'

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    label: { type: String, required: true },
    id: { type: String, required: true },
})

const emit = defineEmits(['update:modelValue'])

const draft = ref('')

// Commit on comma, semicolon, Enter or blur — the separators people actually
// paste address lists with.
function commit() {
    const parts = draft.value
        .split(/[,;]/)
        .map((part) => part.trim())
        .filter(Boolean)

    if (!parts.length) return

    const existing = new Set(props.modelValue.map((a) => a.address.toLowerCase()))
    const added = []

    for (const part of parts) {
        // "Name <addr@host>" or a bare address.
        const match = part.match(/^(.*?)\s*<([^>]+)>$/)
        const address = (match ? match[2] : part).trim()
        const name = match ? match[1].trim().replace(/^"|"$/g, '') : null

        if (!address || existing.has(address.toLowerCase())) continue

        existing.add(address.toLowerCase())
        added.push({ address, name: name || null })
    }

    if (added.length) emit('update:modelValue', [...props.modelValue, ...added])

    draft.value = ''
}

function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}

function onBackspace() {
    if (draft.value === '' && props.modelValue.length) {
        remove(props.modelValue.length - 1)
    }
}
</script>

<template>
    <div class="flex items-baseline gap-2 border-b border-stone-200 py-1.5 dark:border-stone-800">
        <label :for="props.id" class="w-10 shrink-0 text-xs text-stone-400">{{ props.label }}</label>

        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
            <span
                v-for="(address, index) in props.modelValue"
                :key="address.address"
                class="inline-flex items-center gap-1 rounded bg-stone-100 px-1.5 py-0.5 text-xs dark:bg-stone-800"
            >
                <span :title="address.address">{{ address.name || address.address }}</span>
                <button
                    type="button"
                    class="text-stone-400 hover:text-stone-700 dark:hover:text-stone-200"
                    :aria-label="`Remove ${address.address}`"
                    @click="remove(index)"
                >
                    ×
                </button>
            </span>

            <input
                :id="props.id"
                v-model="draft"
                type="text"
                class="min-w-40 flex-1 bg-transparent py-0.5 text-sm outline-none"
                @keydown.enter.prevent="commit"
                @keydown="(e) => ((e.key === ',' || e.key === ';') && (e.preventDefault(), commit()))"
                @keydown.backspace="onBackspace"
                @blur="commit"
            />
        </div>
    </div>
</template>
