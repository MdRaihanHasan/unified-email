<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import IconButton from './IconButton.vue'
import RelativeTime from './RelativeTime.vue'
import InlineReply from './InlineReply.vue'

const props = defineProps({
    open: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const thread = computed(() => props.open.thread)
const messages = computed(() => props.open.messages ?? [])
const pending = computed(() => props.open.pending ?? [])

// Declared before the watcher below, which runs immediately and clears it — a
// const referenced from an immediate callback that runs before its own declaration
// throws on the temporal dead zone.
const replyTo = ref(null)

// Collapse everything but the last message, the way a mail client does. Switching
// threads also drops any half-written reply target rather than carrying it over.
const expanded = ref(new Set())
watch(
    () => thread.value.id,
    () => {
        // Trashed/spam messages stay collapsed behind their label — auto-expanding
        // a deleted message on open would be the opposite of deleting it.
        const visible = messages.value.filter((message) => !message.hidden_reason)
        const pool = visible.length ? visible : messages.value
        const last = pool[pool.length - 1]
        expanded.value = new Set(last ? [last.id] : [])
        replyTo.value = null

        // Opening a conversation reads it, the way every mail client works. The
        // bulk action writes locally first and pushes to the provider, so the row
        // un-bolds immediately and Gmail follows on the next poll. quiet: a flash
        // saying "Marked 1 conversation read." on every open is noise.
        if (messages.value.some((message) => !message.is_read)) {
            router.post('/threads/actions', {
                thread_ids: [thread.value.id],
                action: 'read',
                quiet: true,
            }, { preserveScroll: true, preserveState: true })
        }
    },
    { immediate: true },
)

function toggleThreadStar() {
    router.post('/threads/actions', {
        thread_ids: [thread.value.id],
        action: thread.value.is_starred ? 'unstar' : 'star',
        quiet: true,
    }, { preserveScroll: true, preserveState: true })
}

function toggle(id) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

function flag(message, changes) {
    router.patch(`/messages/${message.id}/flags`, changes, { preserveScroll: true, preserveState: true })
}

// Per message, not per thread: agreeing to load images in one message must not
// enable tracking pixels in the rest of the conversation.
function showImages(message) {
    router.get(
        '/inbox',
        { thread: thread.value.id, show_images: message.id },
        { preserveScroll: true, preserveState: true, only: ['open'] },
    )
}

function startReply(message, type) {
    replyTo.value = { message, type }
    expanded.value = new Set([...expanded.value, message.id])
}

// Bound to `r` in the list: reply to the newest message, which is what a reply
// almost always means.
function replyToLast() {
    const last = messages.value[messages.value.length - 1]
    if (last) startReply(last, 'reply')
}

defineExpose({ replyToLast })

const pendingLabels = {
    draft: 'Unsent draft',
    queued: 'Queued to send',
    sending: 'Sending',
    failed: 'Send failed',
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

// Reply-all only means something when somebody else is on the message.
function replyActions(message) {
    const actions = [{ type: 'reply', label: 'Reply', icon: 'reply' }]

    if (message.to.length + message.cc.length > 1) {
        actions.push({ type: 'reply_all', label: 'Reply all', icon: 'replyall' })
    }

    return [...actions, { type: 'forward', label: 'Forward', icon: 'forward' }]
}
</script>

<template>
    <section class="flex min-h-0 flex-1 flex-col bg-white dark:bg-stone-900">
        <header class="flex shrink-0 items-start gap-2.5 border-b border-stone-200 px-4 py-3 dark:border-stone-800">
            <button
                type="button"
                class="-ml-1 flex size-8 shrink-0 items-center justify-center rounded-full text-stone-500 transition hover:bg-stone-100 lg:hidden dark:text-stone-400 dark:hover:bg-stone-800"
                aria-label="Back to list"
                @click="emit('close')"
            >
                <Icon name="chevleft" :size="20" />
            </button>

            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-2">
                    <h1 class="truncate text-base font-semibold tracking-tight">{{ thread.subject }}</h1>
                    <span v-if="thread.message_count > 1" class="shrink-0 text-xs text-stone-400">
                        {{ thread.message_count }} messages
                    </span>
                </div>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-stone-400">
                    <span
                        v-for="(provider, index) in thread.providers"
                        :key="provider.value"
                        class="inline-flex items-center gap-1.5"
                    >
                        <span v-if="index" aria-hidden="true">+</span>
                        <span
                            class="mailbox-fill size-1.5 rounded-full"
                            :style="{ '--mailbox': `var(--mailbox-${provider.value})` }"
                        />
                        {{ provider.label }}
                    </span>
                    <span v-if="thread.providers.length > 1">— stitched across two mailboxes</span>
                </div>
            </div>

            <div class="flex shrink-0 gap-0.5">
                <IconButton
                    name="star"
                    :label="thread.is_starred ? 'Unstar' : 'Star'"
                    :size="19"
                    :filled="thread.is_starred"
                    :active="thread.is_starred"
                    @click="toggleThreadStar"
                />
                <IconButton name="close" label="Close" :size="19" class="hidden lg:flex" @click="emit('close')" />
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
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
                    <a
                        :href="`/compose/${outbound.id}`"
                        class="shrink-0 text-xs text-sky-600 hover:underline dark:text-sky-400"
                    >{{ outbound.status === 'failed' ? 'Fix and retry' : 'Open' }}</a>
                </li>
            </ul>

            <article
                v-for="message in messages"
                :key="message.id"
                class="border-b border-stone-200 last:border-b-0 dark:border-stone-800"
            >
                <button
                    v-if="!expanded.has(message.id) && message.hidden_reason"
                    type="button"
                    class="flex w-full items-center gap-2.5 px-4 py-2 text-left transition hover:bg-stone-50 dark:hover:bg-stone-800/50"
                    @click="toggle(message.id)"
                >
                    <Icon :name="message.hidden_reason === 'trash' ? 'trash' : 'warn'" :size="14" class="ml-1.5 shrink-0 text-stone-400" />
                    <span class="min-w-0 flex-1 truncate text-xs text-stone-400 italic">
                        {{ message.hidden_reason === 'trash' ? 'Deleted message' : 'Marked as spam' }}
                        — {{ name(message.from) }} · show
                    </span>
                    <RelativeTime :value="message.received_at" class="shrink-0 text-xs text-stone-400" />
                </button>

                <button
                    v-else-if="!expanded.has(message.id)"
                    type="button"
                    class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left transition hover:bg-stone-50 dark:hover:bg-stone-800/50"
                    @click="toggle(message.id)"
                >
                    <Avatar :name="name(message.from)" :provider="message.account.provider" :size="26" />
                    <span class="shrink-0 text-sm font-semibold">{{ name(message.from) }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm text-stone-400">{{ message.snippet }}</span>
                    <RelativeTime :value="message.received_at" class="shrink-0 text-xs text-stone-400" />
                </button>

                <div v-else class="px-4 py-3.5">
                    <div class="flex items-start gap-2.5">
                        <Avatar :name="name(message.from)" :provider="message.account.provider" :size="34" />
                        <button type="button" class="min-w-0 flex-1 text-left" @click="toggle(message.id)">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-semibold">{{ name(message.from) }}</span>
                                <span class="text-xs text-stone-400">{{ message.from?.address }}</span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-stone-400">
                                <span>to {{ message.to.map((a) => a.address).join(', ') || '(undisclosed)' }}</span>
                                <span v-if="message.cc.length">cc {{ message.cc.map((a) => a.address).join(', ') }}</span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="mailbox-fill size-1.5 rounded-full"
                                        :style="{ '--mailbox': `var(--mailbox-${message.account.provider})` }"
                                    />
                                    {{ message.account.label }}
                                </span>
                            </div>
                        </button>

                        <div class="flex shrink-0 items-center gap-0.5">
                            <RelativeTime :value="message.received_at" class="mr-1 text-xs text-stone-400" />
                            <IconButton
                                name="star"
                                :label="message.is_starred ? 'Unstar' : 'Star'"
                                :size="18"
                                :filled="message.is_starred"
                                :active="message.is_starred"
                                @click="flag(message, { is_starred: !message.is_starred })"
                            />
                            <IconButton
                                name="mailopen"
                                :label="message.is_read ? 'Mark unread' : 'Mark read'"
                                :size="18"
                                @click="flag(message, { is_read: !message.is_read })"
                            />
                        </div>
                    </div>

                    <div class="mt-3 sm:pl-11">
                        <div
                            v-if="message.blocked_images"
                            class="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs dark:border-stone-800 dark:bg-stone-800/60"
                        >
                            <Icon name="warn" :size="15" class="text-stone-500 dark:text-stone-400" />
                            <span class="text-stone-500 dark:text-stone-400">
                                {{ message.blocked_images }} remote
                                {{ message.blocked_images === 1 ? 'image' : 'images' }} blocked — loading them
                                tells the sender you opened this.
                            </span>
                            <button
                                type="button"
                                class="ml-auto shrink-0 font-semibold text-sky-600 hover:underline dark:text-sky-400"
                                @click="showImages(message)"
                            >
                                Show images
                            </button>
                        </div>

                        <div v-if="message.body_html" class="email-body text-sm" v-html="message.body_html" />
                        <p v-else-if="!message.has_body" class="text-sm text-stone-400 italic">
                            Body not downloaded yet.
                        </p>

                        <ul v-if="message.attachments.length" class="mt-4 flex flex-wrap gap-2">
                            <li v-for="attachment in message.attachments" :key="attachment.id">
                                <a
                                    :href="attachment.url"
                                    class="flex items-center gap-2 rounded-md border border-stone-200 bg-stone-50 px-2.5 py-1.5 text-xs transition hover:border-stone-300 hover:bg-stone-100 dark:border-stone-800 dark:bg-stone-800/60 dark:hover:border-stone-700 dark:hover:bg-stone-800"
                                >
                                    <Icon name="clip" :size="14" class="text-stone-400" />
                                    <span class="font-medium">{{ attachment.filename }}</span>
                                    <span class="text-stone-400">{{ size(attachment.size_bytes) }}</span>
                                </a>
                            </li>
                        </ul>

                        <InlineReply
                            v-if="replyTo?.message.id === message.id"
                            :parent="message"
                            :type="replyTo.type"
                            :thread-id="thread.id"
                            class="mt-5"
                            @close="replyTo = null"
                        />
                        <div v-else class="mt-5 flex flex-wrap gap-2">
                            <button
                                v-for="action in replyActions(message)"
                                :key="action.type"
                                type="button"
                                class="flex h-8 items-center gap-2 rounded-full border border-stone-300 px-3.5 text-[0.8rem] font-medium transition hover:bg-stone-100 dark:border-stone-700 dark:hover:bg-stone-800"
                                @click="startReply(message, action.type)"
                            >
                                <Icon :name="action.icon" :size="15" />
                                {{ action.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
