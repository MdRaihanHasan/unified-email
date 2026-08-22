<script setup>
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import ProviderBadge from '../../components/ProviderBadge.vue'
import RelativeTime from '../../components/RelativeTime.vue'

const props = defineProps({
    thread: { type: Object, required: true },
    messages: { type: Array, required: true },
    pending: { type: Array, default: () => [] },
})

const pendingLabels = {
    draft: 'Unsent draft',
    queued: 'Queued to send',
    sending: 'Sending',
    failed: 'Send failed',
}

// Collapse everything but the last message, the way a mail client does.
const expanded = ref(new Set(props.messages.length ? [props.messages[props.messages.length - 1].id] : []))

function toggle(id) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

function flag(message, changes) {
    router.patch(`/messages/${message.id}/flags`, changes, { preserveScroll: true })
}

// Per message, not per thread: agreeing to load images in one message should not
// enable tracking pixels in the rest of the conversation.
function showImages(message) {
    router.get(`/threads/${props.thread.id}`, { show_images: message.id }, { preserveScroll: true })
}

// Reply-all is only meaningful when there is somebody else on the message.
function replyActions(message) {
    const actions = [{ type: 'reply', label: 'Reply' }]

    if (message.to.length + message.cc.length > 1) {
        actions.push({ type: 'reply_all', label: 'Reply all' })
    }

    return [...actions, { type: 'forward', label: 'Forward' }]
}

function name(address) {
    if (!address) return '(unknown)'

    return address.name || address.address || '(unknown)'
}

function size(bytes) {
    if (!bytes) return ''
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<template>
    <Head :title="thread.subject" />

    <AppLayout>
        <div class="border-b border-stone-200 px-4 py-3 dark:border-stone-800">
            <Link href="/inbox" class="text-sm text-sky-600 hover:underline dark:text-sky-400">← Inbox</Link>
            <h1 class="mt-1 text-base font-semibold tracking-tight">{{ thread.subject }}</h1>
            <p v-if="thread.message_count > 1" class="text-xs text-stone-400">
                {{ thread.message_count }} messages
            </p>
        </div>

        <ul v-if="pending.length" class="divide-y divide-stone-200 dark:divide-stone-800">
            <li
                v-for="outbound in pending"
                :key="outbound.id"
                class="flex items-baseline gap-2 px-4 py-2.5 text-sm"
                :class="outbound.status === 'failed'
                    ? 'bg-red-50 dark:bg-red-950/40'
                    : 'bg-amber-50 dark:bg-amber-950/30'"
            >
                <span class="font-medium">{{ pendingLabels[outbound.status] ?? outbound.status }}</span>

                <span class="min-w-0 flex-1 truncate text-stone-500 dark:text-stone-400">
                    to {{ outbound.to.map((a) => a.address).join(', ') || '(no recipient)' }}
                    <template v-if="outbound.error"> — {{ outbound.error }}</template>
                    <template v-else-if="outbound.attempts > 1">
                        — retrying, attempt {{ outbound.attempts }}
                    </template>
                </span>

                <Link
                    :href="`/compose/${outbound.id}`"
                    class="shrink-0 text-xs text-sky-600 hover:underline dark:text-sky-400"
                >
                    {{ outbound.status === 'failed' ? 'Fix and retry' : 'Open' }}
                </Link>
            </li>
        </ul>

        <div class="divide-y divide-stone-200 dark:divide-stone-800">
            <article v-for="message in messages" :key="message.id" class="px-4 py-3">
                <header class="flex items-baseline gap-2">
                    <button
                        type="button"
                        class="min-w-0 flex-1 text-left"
                        @click="toggle(message.id)"
                    >
                        <span class="text-sm font-medium">{{ name(message.from) }}</span>
                        <span v-if="message.from?.address" class="ml-1.5 text-xs text-stone-400">
                            {{ message.from.address }}
                        </span>
                        <span
                            v-if="!expanded.has(message.id)"
                            class="ml-2 truncate text-xs text-stone-400"
                        >
                            {{ message.subject }}
                        </span>
                    </button>

                    <ProviderBadge :provider="message.account.provider" />

                    <button
                        type="button"
                        class="shrink-0 text-sm"
                        :class="message.is_starred ? 'text-amber-500' : 'text-stone-300 hover:text-amber-500 dark:text-stone-600'"
                        :title="message.is_starred ? 'Unstar' : 'Star'"
                        @click="flag(message, { is_starred: !message.is_starred })"
                    >
                        ★
                    </button>

                    <button
                        type="button"
                        class="shrink-0 text-xs text-stone-400 hover:text-stone-600 dark:hover:text-stone-200"
                        @click="flag(message, { is_read: !message.is_read })"
                    >
                        {{ message.is_read ? 'Mark unread' : 'Mark read' }}
                    </button>

                    <RelativeTime :value="message.received_at" class="shrink-0 text-xs text-stone-400" />
                </header>

                <div v-if="expanded.has(message.id)" class="mt-2">
                    <p class="mb-3 text-xs text-stone-400">
                        to {{ message.to.map((a) => a.address).join(', ') || '(undisclosed)' }}
                        <template v-if="message.cc.length">
                            · cc {{ message.cc.map((a) => a.address).join(', ') }}
                        </template>
                        · via {{ message.account.email }}
                    </p>

                    <div
                        v-if="message.blocked_images"
                        class="mb-3 flex items-center gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs dark:border-stone-800 dark:bg-stone-900"
                    >
                        <span class="text-stone-500 dark:text-stone-400">
                            {{ message.blocked_images }} remote
                            {{ message.blocked_images === 1 ? 'image' : 'images' }} blocked — loading them tells the
                            sender you opened this.
                        </span>
                        <button
                            type="button"
                            class="ml-auto shrink-0 font-medium text-sky-600 hover:underline dark:text-sky-400"
                            @click="showImages(message)"
                        >
                            Show images
                        </button>
                    </div>

                    <div v-if="message.body_html" class="email-body text-sm" v-html="message.body_html" />
                    <p v-else-if="!message.has_body" class="text-sm text-stone-400 italic">
                        Body not downloaded yet.
                    </p>

                    <div class="mt-4 flex gap-2">
                        <Link
                            v-for="action in replyActions(message)"
                            :key="action.type"
                            :href="`/compose?type=${action.type}&message=${message.id}`"
                            class="rounded-md border border-stone-300 px-2.5 py-1 text-xs transition hover:bg-stone-100 dark:border-stone-700 dark:hover:bg-stone-800"
                        >
                            {{ action.label }}
                        </Link>
                    </div>

                    <ul v-if="message.attachments.length" class="mt-4 flex flex-wrap gap-2">
                        <li
                            v-for="attachment in message.attachments"
                            :key="attachment.id"
                            class="rounded border border-stone-200 px-2 py-1 text-xs dark:border-stone-800"
                        >
                            <span class="font-medium">{{ attachment.filename }}</span>
                            <span class="ml-1.5 text-stone-400">{{ size(attachment.size_bytes) }}</span>
                        </li>
                    </ul>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
