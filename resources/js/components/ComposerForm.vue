<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AddressInput from './AddressInput.vue'
import RichTextEditor from './RichTextEditor.vue'
import Icon from './Icon.vue'
import IconButton from './IconButton.vue'

const props = defineProps({
    // Shape returned by /compose/prefill, or an already-saved draft row.
    draft: { type: Object, required: true },
    accounts: { type: Array, required: true },
    // The inline reply hides the From row and the subject; a new message needs both.
    compact: { type: Boolean, default: false },
})

const emit = defineEmits(['sent', 'discarded'])

const id = ref(props.draft.id ?? null)
const showCc = ref((props.draft.cc?.length ?? 0) > 0 || (props.draft.bcc?.length ?? 0) > 0)
const saving = ref(false)
const savedOnce = ref(false)
const sending = ref(false)
const error = ref(null)
const attachments = ref(props.draft.attachments ?? [])
const fileInput = ref(null)

const form = ref({
    mail_account_id: props.draft.mail_account_id,
    type: props.draft.type,
    thread_id: props.draft.thread_id ?? null,
    in_reply_to_message_id: props.draft.in_reply_to_message_id ?? null,
    to: props.draft.to ?? [],
    cc: props.draft.cc ?? [],
    bcc: props.draft.bcc ?? [],
    subject: props.draft.subject ?? '',
    body_html: props.draft.body_html ?? '',
})

// Autosave, debounced. The first save creates the row and everything after patches
// it, so a long compose is never lost to a closed tab.
let timer = null
watch(form, () => {
    clearTimeout(timer)
    timer = setTimeout(save, 2500)
}, { deep: true })

onBeforeUnmount(() => clearTimeout(timer))

function payload() {
    return { ...form.value }
}

function save() {
    if (saving.value || sending.value) return
    if (!form.value.subject && !form.value.body_html && !form.value.to.length) return

    saving.value = true

    const done = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            saving.value = false
            savedOnce.value = true
        },
    }

    if (id.value) {
        router.patch(`/compose/${id.value}`, payload(), done)

        return
    }

    // The store route redirects to compose.edit, so the new id comes back in the URL
    // rather than in a response body we can read.
    router.post('/compose', payload(), {
        ...done,
        onSuccess: () => {
            const match = window.location.pathname.match(/\/compose\/(\d+)/)
            if (match) id.value = Number(match[1])
        },
    })
}

function send() {
    clearTimeout(timer)
    error.value = null

    if (!form.value.to.length) {
        error.value = 'Add at least one recipient.'

        return
    }

    sending.value = true

    const finish = {
        onError: (errors) => {
            error.value = errors.to ?? Object.values(errors)[0] ?? 'Could not send.'
            sending.value = false
        },
        onSuccess: () => emit('sent'),
        onFinish: () => (sending.value = false),
    }

    if (id.value) {
        router.post(`/compose/${id.value}/send`, payload(), finish)

        return
    }

    router.post('/compose', payload(), {
        preserveState: true,
        onSuccess: () => {
            const match = window.location.pathname.match(/\/compose\/(\d+)/)

            if (!match) {
                error.value = 'Could not save the draft before sending.'
                sending.value = false

                return
            }

            id.value = Number(match[1])
            router.post(`/compose/${id.value}/send`, payload(), finish)
        },
        onError: finish.onError,
    })
}

function discard() {
    clearTimeout(timer)

    if (!id.value) {
        emit('discarded')

        return
    }

    router.delete(`/compose/${id.value}`, {
        preserveScroll: true,
        onSuccess: () => emit('discarded'),
    })
}

function pickFile() {
    // Uploads are staged against a draft row, so one has to exist first.
    if (!id.value) {
        save()
        window.setTimeout(() => fileInput.value?.click(), 500)

        return
    }

    fileInput.value?.click()
}

function upload(event) {
    const file = event.target.files?.[0]
    if (!file || !id.value) return

    router.post('/compose/attach', { outbound: id.value, file }, {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onSuccess: () => {
            attachments.value = [...attachments.value, { filename: file.name, size_bytes: file.size }]
        },
    })

    event.target.value = ''
}

function size(bytes) {
    if (!bytes) return ''
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

const account = () => props.accounts.find((a) => a.id === form.value.mail_account_id) ?? props.accounts[0]
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <div class="border-b border-stone-200 px-3 dark:border-stone-800">
            <div
                v-if="!props.compact"
                class="flex items-center gap-2.5 border-b border-stone-200 py-2 dark:border-stone-800"
            >
                <span class="w-9 shrink-0 text-xs text-stone-400">From</span>
                <span
                    class="size-2 shrink-0 rounded-full"
                    :style="{ background: account()?.color }"
                />
                <select
                    v-model="form.mail_account_id"
                    class="min-w-0 flex-1 bg-transparent py-0.5 text-sm outline-none"
                >
                    <option v-for="a in props.accounts" :key="a.id" :value="a.id">
                        {{ a.label }} — {{ a.email }}
                    </option>
                </select>
            </div>

            <AddressInput id="composer-to" v-model="form.to" label="To" />

            <template v-if="showCc">
                <AddressInput id="composer-cc" v-model="form.cc" label="Cc" />
                <AddressInput id="composer-bcc" v-model="form.bcc" label="Bcc" />
            </template>

            <div class="flex items-center gap-2.5 border-b border-stone-200 py-2 dark:border-stone-800">
                <span class="w-9 shrink-0 text-xs text-stone-400">Subj</span>
                <input
                    v-model="form.subject"
                    type="text"
                    placeholder="Subject"
                    class="min-w-0 flex-1 bg-transparent py-0.5 text-sm outline-none placeholder:text-stone-400"
                />
                <button
                    v-if="!showCc"
                    type="button"
                    class="shrink-0 text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400"
                    @click="showCc = true"
                >
                    Cc / Bcc
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <RichTextEditor v-model="form.body_html" />

            <ul v-if="attachments.length" class="mt-3 flex flex-wrap gap-2">
                <li
                    v-for="(attachment, index) in attachments"
                    :key="`${attachment.filename}-${index}`"
                    class="flex items-center gap-2 rounded-md border border-stone-200 px-2.5 py-1.5 text-xs dark:border-stone-800"
                >
                    <Icon name="clip" :size="14" class="text-stone-400" />
                    <span class="font-medium">{{ attachment.filename }}</span>
                    <span class="text-stone-400">{{ size(attachment.size_bytes) }}</span>
                </li>
            </ul>

            <p v-if="error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        </div>

        <div
            class="flex shrink-0 items-center gap-2 border-t border-stone-200 bg-stone-50 px-3 py-2 dark:border-stone-800 dark:bg-stone-800/60"
        >
            <button
                type="button"
                :disabled="sending"
                class="flex h-9 items-center gap-2 rounded-full bg-sky-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 disabled:opacity-50"
                @click="send"
            >
                <Icon name="send" :size="16" />
                {{ sending ? 'Sending…' : 'Send' }}
            </button>

            <IconButton name="clip" label="Attach" :size="18" @click="pickFile" />
            <input ref="fileInput" type="file" class="hidden" @change="upload" />

            <span class="ml-auto text-xs text-stone-400">
                <template v-if="saving">Saving…</template>
                <template v-else-if="savedOnce">Draft saved</template>
            </span>

            <IconButton name="trash" label="Discard" :size="18" @click="discard" />
        </div>
    </div>
</template>
