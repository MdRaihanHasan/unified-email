<script setup>
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import ProviderBadge from '../../components/ProviderBadge.vue'
import RelativeTime from '../../components/RelativeTime.vue'

const props = defineProps({
    threads: { type: Object, required: true },
    filters: { type: Object, required: true },
})

const search = ref(props.filters.q ?? '')
let debounce = null

watch(search, (value) => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get(
            '/inbox',
            { ...props.filters, q: value || undefined, page: undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        )
    }, 300)
})

// Our own addresses are already filtered out server-side; a thread with nothing
// left is one we only ever sent to ourselves.
function people(participants) {
    if (!participants.length) return 'me'

    const names = participants.slice(0, 2).map((address) => address.split('@')[0])

    return participants.length > 2 ? `${names.join(', ')} +${participants.length - 2}` : names.join(', ')
}
</script>

<template>
    <Head :title="filters.view === 'inbox' ? 'Inbox' : filters.view" />

    <AppLayout>
        <div class="border-b border-stone-200 px-4 py-3 dark:border-stone-800">
            <input
                v-model="search"
                type="search"
                placeholder="Search mail — try from:someone invoice"
                class="w-full max-w-md rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 dark:border-stone-700 dark:bg-stone-900"
            />
        </div>

        <ul v-if="threads.data.length" class="divide-y divide-stone-200 dark:divide-stone-800">
            <li v-for="thread in threads.data" :key="thread.id">
                <Link
                    :href="`/threads/${thread.id}`"
                    class="flex items-baseline gap-3 px-4 py-2.5 transition hover:bg-stone-100/70 dark:hover:bg-stone-800/50"
                    :class="thread.unread_count ? 'bg-white dark:bg-stone-900/40' : ''"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full"
                        :class="thread.unread_count ? 'bg-sky-500' : 'bg-transparent'"
                        :title="thread.unread_count ? `${thread.unread_count} unread` : ''"
                    />

                    <span
                        class="w-40 shrink-0 truncate text-sm"
                        :class="thread.unread_count ? 'font-semibold' : 'text-stone-600 dark:text-stone-400'"
                    >
                        {{ people(thread.participants) }}
                    </span>

                    <span class="min-w-0 flex-1 truncate text-sm">
                        <span :class="thread.unread_count ? 'font-semibold' : ''">{{ thread.subject }}</span>
                        <span v-if="thread.message_count > 1" class="ml-1 text-xs text-stone-400">
                            ({{ thread.message_count }})
                        </span>
                        <span v-if="thread.snippet" class="ml-2 text-stone-400">— {{ thread.snippet }}</span>
                    </span>

                    <ProviderBadge
                        v-for="provider in thread.providers"
                        :key="provider"
                        :provider="provider"
                    />

                    <span v-if="thread.is_starred" class="shrink-0 text-amber-500" title="Starred">★</span>
                    <span v-if="thread.has_attachments" class="shrink-0 text-stone-400" title="Has attachments">
                        ⏚
                    </span>

                    <RelativeTime
                        :value="thread.last_message_at"
                        class="w-20 shrink-0 text-right text-xs text-stone-400"
                    />
                </Link>
            </li>
        </ul>

        <div v-else class="px-4 py-16 text-center">
            <p class="text-sm text-stone-500 dark:text-stone-400">
                <template v-if="filters.q">Nothing matched “{{ filters.q }}”.</template>
                <template v-else>Nothing here yet.</template>
            </p>
            <Link
                v-if="!filters.q"
                href="/accounts"
                class="mt-2 inline-block text-sm text-sky-600 underline dark:text-sky-400"
            >
                Connect a mailbox
            </Link>
        </div>

        <div v-if="threads.last_page > 1" class="flex items-center justify-between px-4 py-3 text-sm">
            <Link
                v-if="threads.prev_page_url"
                :href="threads.prev_page_url"
                class="text-sky-600 hover:underline dark:text-sky-400"
                preserve-scroll
            >
                ← Newer
            </Link>
            <span v-else />

            <span class="text-stone-400">Page {{ threads.current_page }} of {{ threads.last_page }}</span>

            <Link
                v-if="threads.next_page_url"
                :href="threads.next_page_url"
                class="text-sky-600 hover:underline dark:text-sky-400"
                preserve-scroll
            >
                Older →
            </Link>
            <span v-else />
        </div>
    </AppLayout>
</template>
