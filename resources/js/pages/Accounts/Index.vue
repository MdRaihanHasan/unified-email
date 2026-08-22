<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import ProviderBadge from '../../components/ProviderBadge.vue'

defineProps({
    providers: { type: Array, required: true },
})

const accounts = computed(() => usePage().props.accounts ?? [])

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
        <div class="max-w-2xl px-4 py-6">
            <h1 class="text-base font-semibold tracking-tight">Accounts</h1>

            <ul v-if="accounts.length" class="mt-4 divide-y divide-stone-200 dark:divide-stone-800">
                <li v-for="account in accounts" :key="account.id" class="flex items-baseline gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 text-sm font-medium">
                            {{ account.label }}
                            <ProviderBadge :provider="account.provider" />
                        </p>
                        <p class="text-xs text-stone-400">{{ account.email }}</p>
                        <p v-if="account.last_error" class="mt-1 text-xs text-red-600 dark:text-red-400">
                            {{ account.last_error }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-xs font-medium" :class="statusStyles[account.status]">
                            {{ account.status.replace('_', ' ') }}
                        </p>
                        <p class="text-xs text-stone-400">
                            synced {{ account.last_synced_for_humans ?? 'never' }}
                        </p>
                    </div>
                </li>
            </ul>

            <p v-else class="mt-4 text-sm text-stone-500 dark:text-stone-400">No mailboxes connected yet.</p>

            <section class="mt-10">
                <h2 class="text-sm font-semibold">Connect a mailbox</h2>
                <p class="mt-1 text-sm text-stone-500 dark:text-stone-400">
                    The OAuth and app-password flows land in the next phase. Setup steps for all three providers are
                    in <code class="rounded bg-stone-100 px-1 py-0.5 text-xs dark:bg-stone-800">docs/provider-setup.md</code>.
                </p>

                <ul class="mt-3 space-y-2">
                    <li
                        v-for="provider in providers"
                        :key="provider.value"
                        class="flex items-center gap-2 rounded-md border border-stone-200 px-3 py-2 text-sm dark:border-stone-800"
                    >
                        <ProviderBadge :provider="provider.value" />
                        <span>{{ provider.label }}</span>
                        <span v-if="provider.supports_idle" class="ml-auto text-xs text-stone-400">
                            real-time via IMAP IDLE
                        </span>
                        <span v-else class="ml-auto text-xs text-stone-400">polled every minute</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
