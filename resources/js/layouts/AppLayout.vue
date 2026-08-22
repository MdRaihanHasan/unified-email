<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import ProviderBadge from '../components/ProviderBadge.vue'

const page = usePage()

const accounts = computed(() => page.props.accounts ?? [])
const filters = computed(() => page.props.filters ?? {})

// Silent staleness is this app's characteristic failure, so it gets a banner
// rather than only a log line.
const stale = computed(() => accounts.value.filter((a) => a.is_stale))
const broken = computed(() => accounts.value.filter((a) => a.status === 'auth_error'))
const backfilling = computed(() => accounts.value.filter((a) => a.backfilling))

const views = [
    { key: 'inbox', label: 'Inbox' },
    { key: 'unread', label: 'Unread' },
    { key: 'starred', label: 'Starred' },
    { key: 'sent', label: 'Sent' },
    { key: 'all', label: 'All mail' },
]

function go(params) {
    router.get('/inbox', { ...filters.value, ...params }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
    <div class="flex min-h-screen">
        <aside
            class="hidden w-60 shrink-0 flex-col border-r border-stone-200 bg-white px-3 py-4 md:flex dark:border-stone-800 dark:bg-stone-900"
        >
            <Link href="/inbox" class="mb-5 px-2 text-sm font-semibold tracking-tight">Unified Email</Link>

            <nav class="space-y-0.5">
                <button
                    v-for="view in views"
                    :key="view.key"
                    type="button"
                    class="w-full rounded px-2 py-1.5 text-left text-sm transition"
                    :class="
                        filters.view === view.key
                            ? 'bg-stone-100 font-medium dark:bg-stone-800'
                            : 'text-stone-600 hover:bg-stone-50 dark:text-stone-400 dark:hover:bg-stone-800/60'
                    "
                    @click="go({ view: view.key, page: undefined })"
                >
                    {{ view.label }}
                </button>
            </nav>

            <p class="mt-6 mb-1.5 px-2 text-[0.65rem] font-medium tracking-wider text-stone-400 uppercase">
                Accounts
            </p>

            <div class="space-y-0.5">
                <button
                    type="button"
                    class="w-full rounded px-2 py-1.5 text-left text-sm transition"
                    :class="
                        !filters.account
                            ? 'bg-stone-100 font-medium dark:bg-stone-800'
                            : 'text-stone-600 hover:bg-stone-50 dark:text-stone-400 dark:hover:bg-stone-800/60'
                    "
                    @click="go({ account: undefined, page: undefined })"
                >
                    All accounts
                </button>

                <button
                    v-for="account in accounts"
                    :key="account.id"
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded px-2 py-1.5 text-left text-sm transition"
                    :class="
                        filters.account === account.id
                            ? 'bg-stone-100 font-medium dark:bg-stone-800'
                            : 'text-stone-600 hover:bg-stone-50 dark:text-stone-400 dark:hover:bg-stone-800/60'
                    "
                    @click="go({ account: account.id, page: undefined })"
                >
                    <span class="truncate">{{ account.label }}</span>
                    <ProviderBadge :provider="account.provider" />
                </button>

                <p v-if="!accounts.length" class="px-2 py-1.5 text-sm text-stone-400">No accounts yet</p>
            </div>

            <div class="mt-auto space-y-1 pt-4">
                <Link
                    href="/accounts"
                    class="block rounded px-2 py-1.5 text-sm text-stone-600 hover:bg-stone-50 dark:text-stone-400 dark:hover:bg-stone-800/60"
                >
                    Settings
                </Link>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="block w-full rounded px-2 py-1.5 text-left text-sm text-stone-600 hover:bg-stone-50 dark:text-stone-400 dark:hover:bg-stone-800/60"
                >
                    Sign out
                </Link>
            </div>
        </aside>

        <main class="min-w-0 flex-1">
            <div v-if="broken.length" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">
                <span class="font-medium">Reconnect needed.</span>
                {{ broken.map((a) => a.email).join(', ') }} rejected our credentials — a revoked token, or a
                Google app password invalidated by a password change.
            </div>

            <div v-else-if="stale.length" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                <span class="font-medium">Sync is behind.</span>
                <span v-for="account in stale" :key="account.id" class="ml-1">
                    {{ account.email }} last synced {{ account.last_synced_for_humans ?? 'never' }}.
                </span>
            </div>

            <div v-if="backfilling.length" class="border-b border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200">
                Still importing history for {{ backfilling.map((a) => a.email).join(', ') }} — older mail will keep
                appearing.
            </div>

            <slot />
        </main>
    </div>
</template>
