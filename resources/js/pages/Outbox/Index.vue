<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../../layouts/AppLayout.vue'
import RelativeTime from '../../components/RelativeTime.vue'

const props = defineProps({
    undelivered: { type: Array, required: true },
    drafts: { type: Array, required: true },
})

const statusStyles = {
    queued: 'text-sky-600 dark:text-sky-400',
    sending: 'text-amber-600 dark:text-amber-400',
    failed: 'text-red-600 dark:text-red-400',
    draft: 'text-stone-400',
}

function recipients(item) {
    const names = (item.to ?? []).map((a) => a.name || a.address).filter(Boolean)
    return names.length ? names.join(', ') : '(no recipients yet)'
}

function retry(item) {
    router.post(`/outbox/${item.id}/retry`, {}, { preserveScroll: true })
}

function discard(item) {
    if (confirm('Discard this message? Staged attachments are deleted with it.')) {
        router.delete(`/outbox/${item.id}`, { preserveScroll: true })
    }
}
</script>

<template>
    <Head title="Outbox" />

    <AppLayout>
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto max-w-2xl px-4 py-6">
                <h1 class="text-base font-semibold tracking-tight">Outbox</h1>
                <p class="mt-1 text-xs text-stone-400">
                    Everything trying to leave, and everything not finished yet. A send never
                    disappears: it is here until it lands in Sent.
                </p>

                <ul v-if="undelivered.length" class="mt-4 divide-y divide-stone-200 dark:divide-stone-800">
                    <li v-for="item in undelivered" :key="item.id" class="py-3">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <Link :href="`/compose/${item.id}`" class="text-sm font-medium hover:underline">
                                    {{ item.subject || '(no subject)' }}
                                </Link>
                                <p class="text-xs text-stone-400">
                                    to {{ recipients(item) }}<span v-if="item.account"> · from {{ item.account }}</span>
                                </p>
                                <p v-if="item.error" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                    {{ item.error }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-medium" :class="statusStyles[item.status]">
                                    {{ item.status }}<template v-if="item.attempts"> · attempt {{ item.attempts }}</template>
                                </p>
                                <p class="text-xs text-stone-400"><RelativeTime :value="item.updated_at" /></p>
                                <div class="mt-0.5 flex items-center justify-end gap-3">
                                    <button
                                        v-if="item.status !== 'sending'"
                                        type="button"
                                        class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400"
                                        @click="retry(item)"
                                    >Retry</button>
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-red-600 hover:underline dark:text-red-400"
                                        @click="discard(item)"
                                    >Discard</button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>

                <p v-else class="mt-4 text-sm text-stone-500 dark:text-stone-400">
                    Nothing in flight — every send has landed.
                </p>

                <section class="mt-9">
                    <h2 class="text-sm font-semibold">Drafts</h2>

                    <ul v-if="drafts.length" class="mt-3 divide-y divide-stone-200 dark:divide-stone-800">
                        <li v-for="item in drafts" :key="item.id" class="flex items-start gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <Link :href="`/compose/${item.id}`" class="text-sm font-medium hover:underline">
                                    {{ item.subject || '(no subject)' }}
                                </Link>
                                <p class="text-xs text-stone-400">
                                    to {{ recipients(item) }}<span v-if="item.account"> · from {{ item.account }}</span>
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs text-stone-400"><RelativeTime :value="item.updated_at" /></p>
                                <div class="mt-0.5 flex items-center justify-end gap-3">
                                    <Link
                                        :href="`/compose/${item.id}`"
                                        class="text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400"
                                    >Edit</Link>
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-red-600 hover:underline dark:text-red-400"
                                        @click="discard(item)"
                                    >Discard</button>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <p v-else class="mt-3 text-sm text-stone-500 dark:text-stone-400">No drafts.</p>
                </section>

                <Link href="/inbox" class="mt-9 inline-block text-sm text-sky-600 hover:underline dark:text-sky-400">
                    ← Back to the inbox
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
