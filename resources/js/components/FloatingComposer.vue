<script setup>
import { onMounted, ref } from 'vue'
import ComposerForm from './ComposerForm.vue'
import Icon from './Icon.vue'
import IconButton from './IconButton.vue'

const emit = defineEmits(['close'])

const prefill = ref(null)
const error = ref(null)
const minimised = ref(false)

onMounted(async () => {
    try {
        const response = await fetch('/compose/prefill', { headers: { Accept: 'application/json' } })

        if (!response.ok) {
            error.value = (await response.json())?.message ?? 'Could not start a message.'

            return
        }

        prefill.value = await response.json()
    } catch {
        error.value = 'Could not start a message.'
    }
})
</script>

<template>
    <!-- Bottom-right rather than a page of its own, so writing never takes you off
         the thread you were reading. On a phone it takes the whole screen, because
         a 600px panel on a 390px viewport is not a panel. -->
    <div
        class="fixed inset-0 z-30 flex flex-col border-stone-200 bg-white shadow-2xl sm:inset-auto sm:right-5 sm:bottom-0 sm:w-[36rem] sm:rounded-t-xl sm:border dark:border-stone-800 dark:bg-stone-900"
        :class="minimised ? 'sm:h-auto' : 'sm:h-[34rem]'"
        @keydown.escape.stop="emit('close')"
    >
        <div
            class="flex shrink-0 items-center gap-2 border-b border-stone-200 bg-stone-50 px-3 py-2 sm:rounded-t-xl dark:border-stone-800 dark:bg-stone-800/70"
        >
            <span class="text-sm font-semibold">New message</span>
            <span class="ml-auto flex items-center gap-0.5">
                <IconButton
                    :name="minimised ? 'expand' : 'minimize'"
                    :label="minimised ? 'Expand' : 'Minimise'"
                    :size="16"
                    class="hidden sm:flex"
                    @click="minimised = !minimised"
                />
                <IconButton name="close" label="Close" :size="16" @click="emit('close')" />
            </span>
        </div>

        <template v-if="!minimised">
            <ComposerForm
                v-if="prefill"
                :draft="prefill.draft"
                :accounts="prefill.accounts"
                class="min-h-0 flex-1"
                @sent="emit('close')"
                @discarded="emit('close')"
            />

            <p v-else-if="error" class="px-3 py-5 text-sm text-red-600 dark:text-red-400">
                {{ error }}
                <a href="/accounts" class="ml-1 underline">Settings</a>
            </p>
            <p v-else class="px-3 py-5 text-sm text-stone-400">Loading…</p>
        </template>
    </div>
</template>
