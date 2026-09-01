<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'

const props = defineProps({
    providers: { type: Array, required: true },
    googleConfigured: { type: Boolean, default: false },
})

const accounts = computed(() => usePage().props.accounts ?? [])

function removeAccount(account) {
    const warning =
        `Remove ${account.email}?\n\n` +
        'Its imported mail disappears from this app. The mailbox itself is untouched — ' +
        'reconnecting later imports everything again.'

    if (confirm(warning)) {
        router.delete(`/accounts/${account.id}`)
    }
}

const statusStyles = {
    active: 'text-emerald-600 dark:text-emerald-400',
    connecting: 'text-sky-600 dark:text-sky-400',
    auth_error: 'text-red-600 dark:text-red-400',
    disabled: 'text-stone-400',
}
</script>

<template>
    <Head title="Accounts" />

    <AppLayout>
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-2xl px-4 py-6">
                <h1 class="text-base font-semibold tracking-tight">Mailboxes</h1>

                <ul v-if="accounts.length" class="mt-4 divide-y divide-stone-200 dark:divide-stone-800">
                    <li v-for="account in accounts" :key="account.id" class="flex items-start gap-3 py-3">
                        <Avatar :name="account.label" :provider="account.provider" :size="32" />

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ account.label }}</p>
                            <p class="text-xs text-stone-400">{{ account.email }}</p>
                            <p v-if="account.last_error" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                {{ account.last_error }}
                            </p>
                            <p v-if="account.sync_failures" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                {{ account.sync_failures }} message{{ account.sync_failures === 1 ? '' : 's' }}
                                could not be imported — quarantined, not lost. Check
                                <code>php artisan mail:status</code>.
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-xs font-medium" :class="statusStyles[account.status]">
                                {{ account.status.replace('_', ' ') }}
                            </p>
                            <p class="text-xs text-stone-400">
                                synced {{ account.last_synced_for_humans ?? 'never' }}
                            </p>
                            <div class="mt-0.5 flex items-center justify-end gap-3">
                                <a
                                    v-if="account.status === 'auth_error'"
                                    href="/gmail/connect"
                                    class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400"
                                >Reconnect</a>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-red-600 hover:underline dark:text-red-400"
                                    @click="removeAccount(account)"
                                >Remove</button>
                            </div>
                        </div>
                    </li>
                </ul>

                <p v-else class="mt-4 text-sm text-stone-500 dark:text-stone-400">Nothing connected yet.</p>

                <section class="mt-9">
                    <h2 class="text-sm font-semibold">Connect a mailbox</h2>

                    <a
                        v-if="props.googleConfigured"
                        href="/gmail/connect"
                        class="mt-3 inline-flex h-10 items-center gap-2.5 rounded-full bg-stone-900 px-4 text-sm font-semibold text-white transition hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-900 dark:hover:bg-white"
                    >
                        <Icon name="plus" :size="17" />
                        Connect a Gmail account
                    </a>

                    <p
                        v-else
                        class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        Set <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in
                        <code>.env</code> to connect a Gmail mailbox.
                    </p>

                    <p class="mt-3 max-w-prose text-xs text-stone-500 dark:text-stone-400">
                        The same OAuth client covers personal Gmail and Workspace alike. Google will show an
                        “unverified app” warning once per mailbox — that is expected for an app that has not been
                        through its review, and clicking past it is safe here because the app is yours.
                    </p>
                </section>

                <section class="mt-9">
                    <h2 class="text-sm font-semibold">Other providers</h2>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="provider in props.providers.filter((p) => p.value !== 'gmail_api')"
                            :key="provider.value"
                            class="flex items-center gap-2.5 rounded-md border border-stone-200 px-3 py-2 text-sm dark:border-stone-800"
                        >
                            <span
                                class="mailbox-fill size-2 shrink-0 rounded-full"
                                :style="{ '--mailbox': `var(--mailbox-${provider.value})` }"
                            />
                            <span>{{ provider.label }}</span>
                            <span class="ml-auto text-xs text-stone-400">not wired up yet</span>
                        </li>
                    </ul>
                    <p class="mt-2 text-xs text-stone-400">
                        Outlook needs an Entra app registration; the IMAP path exists for any other host.
                    </p>
                </section>

                <Link href="/inbox" class="mt-9 inline-block text-sm text-sky-600 hover:underline dark:text-sky-400">
                    ← Back to the inbox
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
