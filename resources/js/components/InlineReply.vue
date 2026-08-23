<script setup>
import { onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ComposerForm from './ComposerForm.vue'
import Icon from './Icon.vue'
import IconButton from './IconButton.vue'

const props = defineProps({
    parent: { type: Object, required: true },
    type: { type: String, required: true },
    threadId: { type: Number, required: true },
})

const emit = defineEmits(['close'])

const prefill = ref(null)
const error = ref(null)

// Recipient resolution and quoting stay on the server — Reply-To precedence,
// excluding our own addresses, stripping the sender's tracker out of the quote.
// None of that should be reimplemented here.
onMounted(async () => {
    try {
        const response = await fetch(
            `/compose/prefill?type=${props.type}&message=${props.parent.id}`,
            { headers: { Accept: 'application/json' } },
        )

        if (!response.ok) {
            error.value = (await response.json())?.message ?? 'Could not start a reply.'

            return
        }

        prefill.value = await response.json()
    } catch {
        error.value = 'Could not start a reply.'
    }
})

const heading = { reply: 'Reply to', reply_all: 'Reply to all on', forward: 'Forward' }

function afterSend() {
    emit('close')
    router.get('/inbox', { thread: props.threadId }, { preserveScroll: true, only: ['open'] })
}
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border border-stone-200 bg-white shadow-sm dark:border-stone-800 dark:bg-stone-900"
        @keydown.escape.stop="emit('close')"
    >
        <div
            class="flex items-center gap-2 border-b border-stone-200 px-3 py-2 text-xs text-stone-500 dark:border-stone-800 dark:text-stone-400"
        >
            <Icon name="reply" :size="15" />
            <span class="min-w-0 truncate">
                {{ heading[props.type] ?? 'Reply to' }}
                <strong class="font-semibold text-stone-900 dark:text-stone-100">
                    {{ props.parent.from?.name || props.parent.from?.address }}
                </strong>
            </span>
            <span class="ml-auto flex shrink-0 items-center gap-0.5">
                <a
                    v-if="prefill?.draft"
                    href="/compose"
                    class="hidden sm:block"
                    title="Full screen"
                    @click.prevent="router.get('/compose', { type: props.type, message: props.parent.id })"
                >
                    <Icon name="expand" :size="15" class="text-stone-400" />
                </a>
                <IconButton name="close" label="Discard reply" :size="16" @click="emit('close')" />
            </span>
        </div>

        <ComposerForm
            v-if="prefill"
            :draft="prefill.draft"
            :accounts="prefill.accounts"
            compact
            @sent="afterSend"
            @discarded="emit('close')"
        />

        <p v-else-if="error" class="px-3 py-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <p v-else class="px-3 py-4 text-sm text-stone-400">Loading…</p>
    </div>
</template>
