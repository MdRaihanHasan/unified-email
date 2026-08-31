<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import Icon from '../components/Icon.vue'
import IconButton from '../components/IconButton.vue'
import Avatar from '../components/Avatar.vue'
import NavContent from '../components/NavContent.vue'
import { useTheme } from '../composables/useTheme'

const page = usePage()
const { toggleTheme } = useTheme()

const accounts = computed(() => page.props.accounts ?? [])
const filters = computed(() => page.props.filters ?? {})
const user = computed(() => page.props.auth?.user)

// Silent staleness is this app's characteristic failure, so it gets a banner
// rather than only a log line.
const broken = computed(() => accounts.value.filter((a) => a.status === 'auth_error'))
const stale = computed(() => accounts.value.filter((a) => a.is_stale))
const stalled = computed(() => accounts.value.filter((a) => a.import_stalled))
const backfilling = computed(() => accounts.value.filter((a) => a.backfilling && !a.import_stalled))

const flash = computed(() => page.props.flash?.message ?? null)
const dismissed = ref(null)
watch(flash, () => (dismissed.value = null))

const drawer = ref(false)
const search = ref(filters.value.q ?? '')
const searchInput = ref(null)

watch(() => filters.value.q, (value) => (search.value = value ?? ''))

let debounce = null
watch(search, (value) => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        if ((filters.value.q ?? '') === value) return

        router.get('/inbox', {
            ...filters.value,
            q: value || undefined,
            thread: undefined,
            page: undefined,
        }, { preserveState: true, preserveScroll: true, replace: true })
    }, 300)
})

function focusSearch() {
    searchInput.value?.focus()
    searchInput.value?.select()
}

function syncNow() {
    router.post('/sync', {}, { preserveScroll: true, preserveState: true })
}

// One truthful line for the whole account set. The previous version showed only
// accounts[0], so four mailboxes were summarized by whichever had the lowest id —
// "Synced never" beside a banner saying the import was busy.
const syncStatus = computed(() => {
    if (!accounts.value.length) return 'Nothing connected'
    if (accounts.value.some((a) => a.status === 'auth_error')) return 'Reconnect needed'
    if (accounts.value.some((a) => a.backfilling)) return 'Importing…'

    const stamps = accounts.value
        .filter((a) => a.status === 'active')
        .map((a) => (a.last_synced_at ? new Date(a.last_synced_at).getTime() : null))

    if (!stamps.length || stamps.some((t) => t === null)) return 'Synced: never'

    const minutes = Math.floor((Date.now() - Math.min(...stamps)) / 60000)

    if (minutes < 1) return 'Synced just now'
    if (minutes < 60) return `Synced ${minutes}m ago`

    return `Synced ${Math.floor(minutes / 60)}h ${minutes % 60}m ago`
})

// "/" focuses search from anywhere, the way every mail client does — but not while
// the caret is already in a field, or it eats the character.
function onKey(event) {
    const tag = event.target?.tagName
    const typing = tag === 'INPUT' || tag === 'TEXTAREA' || event.target?.isContentEditable

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault()
        focusSearch()
    }

    if (event.key === 'Escape') {
        drawer.value = false
        if (tag === 'INPUT') event.target.blur()
    }
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => {
    window.removeEventListener('keydown', onKey)
    clearTimeout(debounce)
})

defineExpose({ focusSearch })
</script>

<template>
    <div class="flex h-screen flex-col overflow-hidden bg-stone-50 dark:bg-stone-950">
        <header
            class="flex h-14 shrink-0 items-center gap-2 border-b border-stone-200 bg-white px-2 sm:gap-3 sm:px-3 dark:border-stone-800 dark:bg-stone-900"
        >
            <button
                type="button"
                class="flex size-9 items-center justify-center rounded-full text-stone-500 transition hover:bg-stone-100 md:hidden dark:text-stone-400 dark:hover:bg-stone-800"
                aria-label="Open menu"
                @click="drawer = true"
            >
                <Icon name="menu" :size="22" />
            </button>

            <Link href="/inbox" class="hidden shrink-0 items-baseline gap-1.5 pl-1.5 md:flex" style="width: 13rem">
                <span class="text-[0.95rem] font-semibold tracking-tight">Unified</span>
                <span class="text-[0.95rem] text-stone-400">mail</span>
            </Link>

            <label
                class="flex h-10 min-w-0 flex-1 items-center gap-2.5 rounded-full bg-stone-100 px-3.5 transition focus-within:ring-1 focus-within:ring-sky-500 sm:max-w-2xl dark:bg-stone-800"
            >
                <Icon name="search" :size="19" class="text-stone-500 dark:text-stone-400" />
                <input
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    placeholder="Search all mailboxes"
                    class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-stone-400"
                />
                <kbd
                    v-if="!search"
                    class="hidden rounded border border-stone-300 px-1.5 text-xs text-stone-400 sm:block dark:border-stone-600"
                >/</kbd>
            </label>

            <div class="ml-auto flex shrink-0 items-center gap-0.5">
                <IconButton name="refresh" label="Sync now" :size="19" class="hidden sm:flex" @click="syncNow" />
                <IconButton name="moon" label="Light / dark" :size="19" @click="toggleTheme" />
                <Avatar :name="user?.name ?? '?'" :size="30" class="ml-1" />
            </div>
        </header>

        <div class="flex min-h-0 flex-1">
            <aside
                class="hidden w-52 shrink-0 flex-col overflow-y-auto border-r border-stone-200 bg-white py-3 pr-2 md:flex dark:border-stone-800 dark:bg-stone-900"
            >
                <NavContent />
                <div class="flex items-center gap-2 px-3 pt-3 text-xs text-stone-400">
                    <Icon name="check" :size="14" />
                    <span>{{ syncStatus }}</span>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <div
                    v-if="broken.length"
                    class="shrink-0 border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200"
                >
                    <span class="font-medium">Reconnect needed.</span>
                    {{ broken.map((a) => a.email).join(', ') }} rejected our credentials — a revoked token, or
                    a Google app password invalidated by a password change.
                </div>

                <div
                    v-else-if="stale.length"
                    class="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
                >
                    <span class="font-medium">Sync is behind.</span>
                    <span v-for="account in stale" :key="account.id" class="ml-1">
                        {{ account.email }} last synced {{ account.last_synced_for_humans ?? 'never' }}.
                    </span>
                </div>

                <!-- Nothing has run at all. Say what to check, rather than leaving a
                     mailbox that looks connected and never fills. -->
                <div
                    v-if="stalled.length"
                    class="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
                >
                    <span class="font-medium">Import has not started</span>
                    for {{ stalled.map((a) => a.email).join(', ') }}. The job is queued but nothing is
                    running it — check that the queue worker is up:
                    <code class="rounded bg-amber-100 px-1 py-0.5 text-xs dark:bg-amber-900/60">docker compose ps worker</code>
                </div>

                <div
                    v-else-if="backfilling.length"
                    class="shrink-0 border-b border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200"
                >
                    <span v-for="account in backfilling" :key="account.id" class="mr-3">
                        Importing {{ account.email }}
                        <template v-if="account.import_progress">
                            — {{ account.import_progress.folders_done }} of
                            {{ account.import_progress.folders_total }} folders,
                            {{ account.import_progress.messages }} messages so far.
                        </template>
                    </span>
                    <span class="text-sky-700/80 dark:text-sky-300/80">Older mail keeps appearing as it lands.</span>
                </div>

                <div
                    v-if="flash && flash !== dismissed"
                    class="flex shrink-0 items-center gap-2 border-b border-stone-200 bg-stone-100 px-4 py-2 text-sm dark:border-stone-800 dark:bg-stone-800"
                >
                    <span>{{ flash }}</span>
                    <button
                        type="button"
                        class="ml-auto text-xs text-stone-500 transition hover:text-stone-800 dark:text-stone-400 dark:hover:text-stone-100"
                        @click="dismissed = flash"
                    >
                        Dismiss
                    </button>
                </div>

                <slot />
            </div>
        </div>

        <!-- Phone navigation. Without this the sidebar simply vanishes below md and
             takes compose, the view switcher and the account switcher with it. -->
        <Transition
            enter-active-class="transition duration-200" leave-active-class="transition duration-150"
            enter-from-class="opacity-0" leave-to-class="opacity-0"
        >
            <div v-if="drawer" class="fixed inset-0 z-40 bg-black/45 md:hidden" @click="drawer = false" />
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out" leave-active-class="transition duration-150 ease-in"
            enter-from-class="-translate-x-full" leave-to-class="-translate-x-full"
        >
            <aside
                v-if="drawer"
                class="fixed inset-y-0 left-0 z-50 flex w-[17rem] flex-col overflow-y-auto bg-white py-4 pr-2 shadow-xl md:hidden dark:bg-stone-900"
            >
                <div class="mb-3 flex items-center gap-1.5 pr-2 pl-4">
                    <span class="text-lg font-semibold tracking-tight">Unified</span>
                    <span class="text-lg text-stone-400">mail</span>
                    <button
                        type="button"
                        class="ml-auto flex size-10 items-center justify-center rounded-full text-stone-500 transition hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
                        aria-label="Close menu"
                        @click="drawer = false"
                    >
                        <Icon name="close" :size="20" />
                    </button>
                </div>
                <NavContent :dense="false" @navigate="drawer = false" />
            </aside>
        </Transition>
    </div>
</template>
