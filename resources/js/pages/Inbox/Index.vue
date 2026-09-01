<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import ThreadRow from '../../components/ThreadRow.vue'
import ThreadPane from '../../components/ThreadPane.vue'
import FloatingComposer from '../../components/FloatingComposer.vue'
import Icon from '../../components/Icon.vue'
import IconButton from '../../components/IconButton.vue'
import ShortcutHelp from '../../components/ShortcutHelp.vue'

const props = defineProps({
    threads: { type: Object, required: true },
    filters: { type: Object, required: true },
    open: { type: Object, default: null },
    coverage: { type: String, default: null },
})

const rows = computed(() => props.threads.data)

const checked = ref(new Set())
const cursor = ref(-1)
const composing = ref(false)
const help = ref(false)
const pane = ref(null)

// A new list is a new selection; keeping ticks across a filter change would act on
// threads no longer on screen.
watch(() => props.threads.data.map((t) => t.id).join(','), () => {
    checked.value = new Set()
    cursor.value = props.open ? rows.value.findIndex((t) => t.id === props.open.thread.id) : -1
})

watch(() => props.open?.thread.id, (id) => {
    if (id) cursor.value = rows.value.findIndex((t) => t.id === id)
})

function openThread(thread) {
    // Only the opened thread travels: re-running the whole list query on every
    // open made reading cost a page load. Read-state on the row catches up via
    // the mark-read action's own round trip.
    router.get('/inbox', { ...props.filters, thread: thread.id }, {
        preserveState: true,
        preserveScroll: true,
        only: ['open', 'filters'],
    })
}

function closeThread() {
    router.get('/inbox', { ...props.filters, thread: undefined }, {
        preserveState: true,
        preserveScroll: true,
        only: ['open', 'filters'],
    })
}

function toggleCheck(thread) {
    const next = new Set(checked.value)
    next.has(thread.id) ? next.delete(thread.id) : next.add(thread.id)
    checked.value = next
}

function checkAll() {
    checked.value = checked.value.size === rows.value.length
        ? new Set()
        : new Set(rows.value.map((t) => t.id))
}

// In Trash and Spam the natural verb is the way back out, not further in.
const isTrashLike = computed(() => ['trash', 'junk'].includes(props.filters.view))

function bulk(action) {
    if (!checked.value.size) return

    router.post('/threads/actions', { thread_ids: [...checked.value], action }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (checked.value = new Set()),
    })
}

function star(thread) {
    router.post('/threads/actions', {
        thread_ids: [thread.id],
        action: thread.is_starred ? 'unstar' : 'star',
    }, { preserveScroll: true, preserveState: true })
}

function markRead(thread, read) {
    router.post('/threads/actions', {
        thread_ids: [thread.id],
        action: read ? 'read' : 'unread',
    }, { preserveScroll: true, preserveState: true })
}

function syncNow() {
    router.post('/sync', {}, { preserveScroll: true, preserveState: true })
}

// Sticky day separators: the list used to run today → last week as one
// undifferentiated column. A label renders where the day changes.
function dayGroup(thread) {
    if (!thread.last_message_at) return ''

    const date = new Date(thread.last_message_at)
    const today = new Date()
    const yesterday = new Date(today)
    yesterday.setDate(today.getDate() - 1)

    if (date.toDateString() === today.toDateString()) return 'Today'
    if (date.toDateString() === yesterday.toDateString()) return 'Yesterday'

    return date.toLocaleDateString(undefined, {
        weekday: undefined,
        day: 'numeric',
        month: 'short',
        year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    })
}

function dayLabel(thread, previous) {
    const label = dayGroup(thread)

    return previous && dayGroup(previous) === label ? null : label
}

// Triage on the focused thread. The row disappears from most views, so the
// cursor stays where it is and lands on whatever slides up into the slot.
function moveThread(thread, action) {
    router.post('/threads/actions', {
        thread_ids: [thread.id],
        action,
    }, { preserveScroll: true, preserveState: true })
}

// ---- keyboard -----------------------------------------------------------
// The thing that matters most day to day, and the reason a reading pane is worth
// having: you can walk a mailbox without reaching for the mouse.
function move(delta) {
    if (!rows.value.length) return

    cursor.value = Math.max(0, Math.min(rows.value.length - 1, cursor.value + delta))

    const thread = rows.value[cursor.value]
    document.getElementById(`thread-${thread.id}`)?.scrollIntoView({ block: 'nearest' })

    // Walking the list moves the reading pane with it, which is the whole point.
    if (props.open) openThread(thread)
}

function onKey(event) {
    const tag = event.target?.tagName
    if (tag === 'INPUT' || tag === 'TEXTAREA' || event.target?.isContentEditable) return
    if (event.metaKey || event.ctrlKey || event.altKey) return

    if (event.key === '?') {
        event.preventDefault()
        help.value = !help.value

        return
    }

    if (event.key === 'Escape') {
        if (help.value) return (help.value = false)
        if (composing.value) return (composing.value = false)
        if (checked.value.size) return (checked.value = new Set())
        if (props.open) closeThread()

        return
    }

    const at = () => rows.value[cursor.value] ?? null

    switch (event.key) {
        case 'j': event.preventDefault(); move(1); break
        case 'k': event.preventDefault(); move(-1); break
        case 'Enter':
        case 'o': {
            const thread = at()
            if (thread) { event.preventDefault(); openThread(thread) }
            break
        }
        case 'u': if (props.open) { event.preventDefault(); closeThread() } break
        case 'x': {
            const thread = at()
            if (thread) { event.preventDefault(); toggleCheck(thread) }
            break
        }
        case 's': {
            const thread = at()
            if (thread) { event.preventDefault(); star(thread) }
            break
        }
        case 'e': {
            const thread = at()
            if (thread) { event.preventDefault(); moveThread(thread, isTrashLike.value ? 'restore' : 'archive') }
            break
        }
        case '#': {
            const thread = at()
            if (thread) { event.preventDefault(); moveThread(thread, 'trash') }
            break
        }
        case '!': {
            const thread = at()
            if (thread) { event.preventDefault(); moveThread(thread, 'spam') }
            break
        }
        case 'c': event.preventDefault(); composing.value = true; break
        case 'r': if (props.open) { event.preventDefault(); pane.value?.replyToLast() } break
    }
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

const title = computed(() => {
    if (props.filters.q) return `Search: ${props.filters.q}`

    return { inbox: 'Inbox', unread: 'Unread', starred: 'Starred', sent: 'Sent', all: 'All mail' }[props.filters.view]
})
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <div class="flex min-h-0 flex-1">
            <!-- On a phone the list gives way to the thread rather than sitting beside it. -->
            <section
                class="min-h-0 min-w-0 flex-col border-r border-stone-200 lg:flex lg:w-[27rem] lg:shrink-0 dark:border-stone-800"
                :class="props.open ? 'hidden' : 'flex flex-1'"
            >
                <div
                    class="flex h-11 shrink-0 items-center gap-0.5 border-b border-stone-200 pr-2 pl-3.5 dark:border-stone-800"
                    :class="checked.size ? 'bg-sky-50 dark:bg-sky-950/60' : ''"
                >
                    <button
                        type="button"
                        class="mr-2 flex size-4 shrink-0 items-center justify-center rounded-[3px] border-[1.6px] transition"
                        :class="checked.size
                            ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-500 dark:bg-sky-500'
                            : 'border-stone-400 dark:border-stone-500'"
                        :aria-label="checked.size ? 'Clear selection' : 'Select all'"
                        @click="checkAll"
                    >
                        <Icon v-if="checked.size" name="check" :size="11" />
                    </button>

                    <template v-if="checked.size">
                        <IconButton name="mailopen" label="Mark read" :size="18" @click="bulk('read')" />
                        <IconButton name="inbox" label="Mark unread" :size="18" @click="bulk('unread')" />
                        <IconButton name="star" label="Star" :size="18" @click="bulk('star')" />
                        <IconButton name="star" label="Unstar" :size="18" filled @click="bulk('unstar')" />
                        <IconButton v-if="isTrashLike" name="inbox" label="Restore to inbox" :size="18" @click="bulk('restore')" />
                        <IconButton v-else name="archive" label="Archive" :size="18" @click="bulk('archive')" />
                        <IconButton name="trash" label="Move to trash" :size="18" @click="bulk('trash')" />
                        <IconButton v-if="!isTrashLike" name="warn" label="Mark as spam" :size="18" @click="bulk('spam')" />
                        <span class="ml-auto text-xs font-semibold text-sky-700 dark:text-sky-300">
                            {{ checked.size }} selected
                        </span>
                    </template>

                    <template v-else>
                        <IconButton name="refresh" label="Sync" :size="18" @click="syncNow" />
                        <IconButton name="keyboard" label="Keyboard shortcuts" :size="18" @click="help = true" />
                        <span class="ml-auto text-xs text-stone-400">
                            {{ props.threads.total }} {{ props.threads.total === 1 ? 'conversation' : 'conversations'
                            }}<template v-if="props.threads.last_page > 1"> · page {{ props.threads.current_page }} of {{ props.threads.last_page }}</template>
                        </span>
                    </template>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <div v-for="(thread, index) in rows" :id="`thread-${thread.id}`" :key="thread.id">
                        <div
                            v-if="dayLabel(thread, rows[index - 1])"
                            class="sticky top-0 z-10 border-b border-stone-200 bg-stone-50/95 px-4 py-1 text-[0.65rem] font-semibold tracking-wider text-stone-400 uppercase backdrop-blur dark:border-stone-800 dark:bg-stone-950/95"
                        >
                            {{ dayLabel(thread, rows[index - 1]) }}
                        </div>
                        <ThreadRow
                            :thread="thread"
                            :open="props.open?.thread.id === thread.id"
                            :checked="checked.has(thread.id)"
                            :cursor="cursor === index"
                            @open="openThread(thread)"
                            @check="toggleCheck(thread)"
                            @star="star(thread)"
                            @read="(read) => markRead(thread, read)"
                        />
                    </div>

                    <div v-if="!rows.length" class="px-4 py-16 text-center">
                        <p class="text-sm text-stone-500 dark:text-stone-400">
                            <template v-if="props.filters.q">
                                Nothing matched “{{ props.filters.q }}”.
                                <span v-if="props.coverage" class="mt-1 block text-xs text-stone-400">
                                    Searchable mail goes back to
                                    {{ new Date(props.coverage).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) }}
                                    — older mail can be imported from
                                    <Link href="/accounts" class="underline">the accounts page</Link>.
                                </span>
                            </template>
                            <template v-else>Nothing here yet.</template>
                        </p>
                        <Link
                            v-if="!props.filters.q"
                            href="/accounts"
                            class="mt-2 inline-block text-sm text-sky-600 underline dark:text-sky-400"
                        >
                            Connect a mailbox
                        </Link>
                    </div>
                </div>

                <div
                    v-if="props.threads.last_page > 1"
                    class="flex h-10 shrink-0 items-center justify-between border-t border-stone-200 px-3 text-xs dark:border-stone-800"
                >
                    <Link
                        v-if="props.threads.prev_page_url"
                        :href="props.threads.prev_page_url"
                        class="text-sky-600 hover:underline dark:text-sky-400"
                        preserve-scroll
                    >← Newer</Link>
                    <span v-else />
                    <span class="text-stone-400">
                        Page {{ props.threads.current_page }} of {{ props.threads.last_page }}
                    </span>
                    <Link
                        v-if="props.threads.next_page_url"
                        :href="props.threads.next_page_url"
                        class="text-sky-600 hover:underline dark:text-sky-400"
                        preserve-scroll
                    >Older →</Link>
                    <span v-else />
                </div>
            </section>

            <ThreadPane v-if="props.open" ref="pane" :open="props.open" @close="closeThread" />

            <section v-else class="hidden min-h-0 flex-1 items-center justify-center lg:flex">
                <div class="max-w-xs text-center">
                    <p class="text-sm text-stone-400">Pick a conversation to read it here.</p>
                    <button
                        type="button"
                        class="mt-2 text-sm text-sky-600 hover:underline dark:text-sky-400"
                        @click="help = true"
                    >
                        Keyboard shortcuts
                    </button>
                </div>
            </section>
        </div>

        <!-- Phone compose button. The desktop rail has its own, but below md the rail
             is not on screen at all. -->
        <button
            type="button"
            class="fixed right-4 bottom-5 z-20 flex h-14 items-center gap-2.5 rounded-full bg-sky-600 px-5 font-semibold text-white shadow-lg transition hover:bg-sky-500 md:hidden"
            @click="composing = true"
        >
            <Icon name="pencil" :size="20" />
            Compose
        </button>

        <FloatingComposer v-if="composing" @close="composing = false" />
        <ShortcutHelp v-if="help" @close="help = false" />
    </AppLayout>
</template>
