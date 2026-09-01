<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import AddressInput from '../../components/AddressInput.vue'
import RichTextEditor from '../../components/RichTextEditor.vue'

const props = defineProps({
    draft: { type: Object, required: true },
    accounts: { type: Array, required: true },
})

const draftId = ref(props.draft.id)
const showCc = ref((props.draft.cc?.length ?? 0) > 0 || (props.draft.bcc?.length ?? 0) > 0)
const savedAt = ref(null)
const saving = ref(false)
const attachments = ref(props.draft.attachments ?? [])
const fileInput = ref(null)

const form = useForm({
    mail_account_id: props.draft.mail_account_id,
    type: props.draft.type,
    thread_id: props.draft.thread_id,
    in_reply_to_message_id: props.draft.in_reply_to_message_id,
    to: props.draft.to ?? [],
    cc: props.draft.cc ?? [],
    bcc: props.draft.bcc ?? [],
    subject: props.draft.subject ?? '',
    body_html: props.draft.body_html ?? '',
})

const heading = computed(() => ({
    reply: 'Reply',
    reply_all: 'Reply to all',
    forward: 'Forward',
}[props.draft.type] ?? 'New message'))

const sendingFrom = computed(
    () => props.accounts.find((a) => a.id === form.mail_account_id) ?? props.accounts[0],
)

// Autosave, debounced. The first save creates the row and swaps to PATCH for
// everything after, so a long compose is never lost to a closed tab.
let timer = null

watch(
    () => [form.to, form.cc, form.bcc, form.subject, form.body_html, form.mail_account_id],
    () => {
        clearTimeout(timer)
        timer = setTimeout(save, 2500)
    },
    { deep: true },
)

function save() {
    if (form.processing || saving.value) return
    if (!form.subject && !form.body_html && !form.to.length) return

    saving.value = true

    const done = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            saving.value = false
            savedAt.value = new Date()
        },
    }

    if (draftId.value) {
        form.patch(`/compose/${draftId.value}`, done)
    } else {
        // Inertia follows the redirect to compose.edit, which is where the new id
        // comes from — read it back off the URL rather than guessing.
        form.post('/compose', {
            ...done,
            onSuccess: () => {
                const match = window.location.pathname.match(/\/compose\/(\d+)/)
                if (match) draftId.value = Number(match[1])
            },
        })
    }
}

function send() {
    clearTimeout(timer)

    if (!draftId.value) {
        // Nothing persisted yet, so create the row first and send once it exists.
        form.post('/compose', {
            onSuccess: () => {
                const match = window.location.pathname.match(/\/compose\/(\d+)/)
                if (match) {
                    draftId.value = Number(match[1])
                    form.post(`/compose/${draftId.value}/send`)
                }
            },
        })

        return
    }

    form.post(`/compose/${draftId.value}/send`)
}

function discard() {
    clearTimeout(timer)

    if (!draftId.value) {
        router.visit('/inbox')

        return
    }

    router.delete(`/compose/${draftId.value}`)
}

function pickFile() {
    if (!draftId.value) {
        // Attachments are staged against a draft row, so it has to exist first.
        save()
        window.setTimeout(() => fileInput.value?.click(), 400)

        return
    }

    fileInput.value?.click()
}

function upload(event) {
    const file = event.target.files?.[0]
    if (!file || !draftId.value) return

    router.post(
        '/compose/attach',
        { outbound: draftId.value, file },
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                attachments.value = [
                    ...attachments.value,
                    { filename: file.name, size_bytes: file.size, mime_type: file.type },
                ]
            },
        },
    )

    event.target.value = ''
}

function size(bytes) {
    if (!bytes) return ''
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<template>
    <Head :title="heading" />

    <AppLayout>
        <div class="min-h-0 flex-1 overflow-y-auto bg-white md:mt-2.5 md:rounded-xl md:border md:border-stone-200 md:shadow-sm dark:bg-stone-900 md:dark:border-stone-800">
        <div class="mx-auto max-w-3xl px-4 py-5">
            <div class="mb-3 flex items-baseline justify-between">
                <h1 class="text-base font-semibold tracking-tight">{{ heading }}</h1>

                <p class="text-xs text-stone-400">
                    <span v-if="saving">Saving…</span>
                    <span v-else-if="savedAt">Draft saved</span>
                </p>
            </div>

            <div
                v-if="props.draft.error"
                class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200"
            >
                Last send failed: {{ props.draft.error }}
            </div>

            <div
                v-else-if="props.draft.status === 'queued' || props.draft.status === 'sending'"
                class="mb-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200"
            >
                This message is {{ props.draft.status === 'queued' ? 'queued to send' : 'sending now' }} —
                track it in the <a href="/outbox" class="font-semibold underline">Outbox</a>.
            </div>

            <div class="rounded-md border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
                <div class="px-3">
                    <div class="flex items-baseline gap-2 border-b border-stone-200 py-1.5 dark:border-stone-800">
                        <label for="from" class="w-10 shrink-0 text-xs text-stone-400">From</label>
                        <select
                            id="from"
                            v-model="form.mail_account_id"
                            class="min-w-0 flex-1 bg-transparent py-0.5 text-sm outline-none"
                        >
                            <option v-for="account in props.accounts" :key="account.id" :value="account.id">
                                {{ account.label }} — {{ account.email }}
                            </option>
                        </select>
                    </div>

                    <AddressInput id="to" v-model="form.to" label="To" />

                    <template v-if="showCc">
                        <AddressInput id="cc" v-model="form.cc" label="Cc" />
                        <AddressInput id="bcc" v-model="form.bcc" label="Bcc" />
                    </template>

                    <div class="flex items-baseline gap-2 border-b border-stone-200 py-1.5 dark:border-stone-800">
                        <label for="subject" class="w-10 shrink-0 text-xs text-stone-400">Subj</label>
                        <input
                            id="subject"
                            v-model="form.subject"
                            type="text"
                            class="min-w-0 flex-1 bg-transparent py-0.5 text-sm outline-none"
                            placeholder="Subject"
                        />
                        <button
                            v-if="!showCc"
                            type="button"
                            class="shrink-0 text-xs text-sky-600 hover:underline dark:text-sky-400"
                            @click="showCc = true"
                        >
                            Cc / Bcc
                        </button>
                    </div>
                </div>

                <div class="p-3">
                    <RichTextEditor v-model="form.body_html" />

                    <ul v-if="attachments.length" class="mt-3 flex flex-wrap gap-2">
                        <li
                            v-for="(attachment, index) in attachments"
                            :key="`${attachment.filename}-${index}`"
                            class="rounded border border-stone-200 px-2 py-1 text-xs dark:border-stone-800"
                        >
                            <span class="font-medium">{{ attachment.filename }}</span>
                            <span class="ml-1.5 text-stone-400">{{ size(attachment.size_bytes) }}</span>
                        </li>
                    </ul>

                    <p v-if="form.errors.to" class="mt-3 text-sm text-red-600 dark:text-red-400">
                        {{ form.errors.to }}
                    </p>
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="rounded-md bg-sky-600 px-3.5 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-sky-500 disabled:opacity-50"
                    @click="send"
                >
                    {{ form.processing ? 'Sending…' : 'Send' }}
                </button>

                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm transition hover:bg-stone-100 dark:border-stone-700 dark:hover:bg-stone-800"
                    @click="pickFile"
                >
                    Attach
                </button>

                <input ref="fileInput" type="file" class="hidden" @change="upload" />

                <span class="ml-auto text-xs text-stone-400">
                    sending as {{ sendingFrom?.email }}
                </span>

                <button
                    type="button"
                    class="text-sm text-stone-500 hover:text-red-600 dark:text-stone-400 dark:hover:text-red-400"
                    @click="discard"
                >
                    Discard
                </button>
            </div>
        </div>
        </div>
    </AppLayout>
</template>
