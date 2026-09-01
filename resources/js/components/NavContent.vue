<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import Icon from './Icon.vue'

const props = defineProps({
    // The drawer wants larger hit targets than the desktop rail.
    dense: { type: Boolean, default: true },
})

const emit = defineEmits(['navigate'])

const page = usePage()
const accounts = computed(() => page.props.accounts ?? [])
const counts = computed(() => page.props.counts ?? {})
const filters = computed(() => page.props.filters ?? {})

const views = computed(() => [
    { key: 'inbox', label: 'Inbox', icon: 'inbox', count: counts.value.inbox },
    { key: 'unread', label: 'Unread', icon: 'mailopen', count: counts.value.unread },
    { key: 'starred', label: 'Starred', icon: 'star', count: counts.value.starred },
    { key: 'sent', label: 'Sent', icon: 'send', count: null },
    { key: 'all', label: 'All mail', icon: 'archive', count: null },
    { key: 'junk', label: 'Spam', icon: 'warn', count: null },
    { key: 'trash', label: 'Trash', icon: 'trash', count: null },
])

// A send must never be invisible: the Outbox badge is the constant reminder
// that something is still trying to leave, red when one has given up.
const outboxCount = computed(() => (counts.value.outbox ?? 0) + (counts.value.drafts ?? 0))
const outboxFailed = computed(() => (counts.value.outbox_failed ?? 0) > 0)

// The "all mailboxes" dot wears every connected account's color at once.
const allDot = computed(() => {
    const colors = accounts.value.map((a) => a.color).filter(Boolean)
    if (!colors.length) return 'var(--color-sky-600)'
    if (colors.length === 1) return colors[0]

    const step = 100 / colors.length
    const stops = colors.map((c, i) => `${c} ${i * step}% ${(i + 1) * step}%`).join(', ')

    return `conic-gradient(${stops})`
})

const rowHeight = computed(() => (props.dense ? 'h-9' : 'h-12'))
const textSize = computed(() => (props.dense ? 'text-sm' : 'text-[0.95rem]'))

function go(params) {
    emit('navigate')
    // Dropping the open thread on a view change is deliberate: the thread you were
    // reading is usually not in the list you just switched to.
    router.get('/inbox', { ...filters.value, thread: undefined, page: undefined, ...params }, {
        preserveState: true,
        preserveScroll: true,
    })
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <Link
            href="/compose"
            class="mx-1 mb-3.5 flex items-center justify-center gap-2.5 rounded-lg bg-sky-600 px-4 font-semibold text-white shadow-[0_1px_2px_rgba(79,70,229,.4),0_2px_6px_rgba(79,70,229,.25)] transition hover:bg-sky-500"
            :class="[props.dense ? 'h-10 text-sm' : 'h-12 text-[0.95rem]']"
            @click="emit('navigate')"
        >
            <Icon name="pencil" :size="props.dense ? 18 : 20" />
            Compose
        </Link>

        <nav class="space-y-px">
            <button
                v-for="view in views"
                :key="view.key"
                type="button"
                class="mr-1 flex w-full items-center gap-3 rounded-lg pr-3 pl-2.5 text-left transition"
                :class="[
                    rowHeight, textSize,
                    filters.view === view.key
                        ? 'bg-sky-50 font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300'
                        : 'text-stone-600 hover:bg-white/80 dark:text-stone-400 dark:hover:bg-stone-800/70',
                ]"
                @click="go({ view: view.key })"
            >
                <Icon :name="view.icon" :size="props.dense ? 18 : 20" />
                <span class="truncate">{{ view.label }}</span>
                <span v-if="view.count" class="ml-auto text-xs font-semibold">{{ view.count }}</span>
            </button>

            <Link
                href="/outbox"
                class="mr-1 flex w-full items-center gap-3 rounded-lg pr-3 pl-2.5 text-left text-stone-600 transition hover:bg-white/80 dark:text-stone-400 dark:hover:bg-stone-800/70"
                :class="[rowHeight, textSize]"
                @click="emit('navigate')"
            >
                <Icon name="pencil" :size="props.dense ? 18 : 20" />
                <span class="truncate">Outbox</span>
                <span
                    v-if="outboxCount"
                    class="ml-auto text-xs font-semibold"
                    :class="outboxFailed ? 'text-red-600 dark:text-red-400' : ''"
                >{{ outboxCount }}</span>
            </Link>
        </nav>

        <div class="mx-3 my-3 h-px bg-stone-200 dark:bg-stone-800" />

        <p class="px-3 pb-1.5 text-[0.65rem] font-semibold tracking-wider text-stone-400 uppercase">
            Mailboxes
        </p>

        <button
            type="button"
            class="mr-1 flex w-full items-center gap-3 rounded-lg pr-3 pl-2.5 text-left transition"
            :class="[
                rowHeight, textSize,
                !filters.account
                    ? 'bg-sky-50 font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300'
                    : 'text-stone-600 hover:bg-white/80 dark:text-stone-400 dark:hover:bg-stone-800/70',
            ]"
            @click="go({ account: undefined })"
        >
            <span class="ml-1 size-2 shrink-0 rounded-full" :style="{ background: allDot }" />
            <span class="truncate">All mailboxes</span>
        </button>

        <button
            v-for="account in accounts"
            :key="account.id"
            type="button"
            class="mr-1 flex w-full items-center gap-3 rounded-lg pr-3 pl-2.5 text-left transition"
            :class="[
                rowHeight, textSize,
                filters.account === account.id
                    ? 'bg-sky-50 font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300'
                    : 'text-stone-600 hover:bg-white/80 dark:text-stone-400 dark:hover:bg-stone-800/70',
            ]"
            @click="go({ account: account.id })"
        >
            <span class="ml-1 size-2 shrink-0 rounded-full" :style="{ background: account.color }" />
            <span class="truncate">{{ account.label }}</span>
            <span
                v-if="account.status === 'auth_error'"
                class="ml-auto text-xs font-semibold text-red-600 dark:text-red-400"
                title="Reconnect needed"
            >!</span>
        </button>

        <p v-if="!accounts.length" class="px-3 py-2 text-sm text-stone-400">No mailboxes yet</p>

        <div class="mt-auto pt-3">
            <div class="mx-3 mb-2 h-px bg-stone-200 dark:bg-stone-800" />
            <Link
                href="/accounts"
                class="mr-1 flex items-center gap-3 rounded-lg pr-3 pl-2.5 text-stone-600 transition hover:bg-white/80 dark:text-stone-400 dark:hover:bg-stone-800/70"
                :class="[rowHeight, textSize]"
                @click="emit('navigate')"
            >
                <Icon name="settings" :size="props.dense ? 18 : 20" />
                Settings
            </Link>
            <Link
                href="/logout"
                method="post"
                as="button"
                class="mr-1 flex w-full items-center gap-3 rounded-lg pr-3 pl-2.5 text-left text-stone-600 transition hover:bg-white/80 dark:text-stone-400 dark:hover:bg-stone-800/70"
                :class="[rowHeight, textSize]"
            >
                <Icon name="close" :size="props.dense ? 18 : 20" />
                Sign out
            </Link>
        </div>
    </div>
</template>
