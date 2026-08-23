<script setup>
const emit = defineEmits(['close'])

const groups = [
    {
        name: 'Moving',
        keys: [
            ['j', 'Next conversation'],
            ['k', 'Previous conversation'],
            ['Enter or o', 'Open'],
            ['u', 'Back to the list'],
            ['/', 'Search'],
        ],
    },
    {
        name: 'Acting',
        keys: [
            ['x', 'Select / deselect'],
            ['s', 'Star'],
            ['r', 'Reply to the last message'],
            ['c', 'Compose'],
            ['Esc', 'Close, or clear the selection'],
        ],
    },
]
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" @click.self="emit('close')">
        <div
            class="w-full max-w-lg overflow-hidden rounded-xl border border-stone-200 bg-white shadow-2xl dark:border-stone-800 dark:bg-stone-900"
        >
            <div class="flex items-center border-b border-stone-200 px-4 py-3 dark:border-stone-800">
                <h2 class="text-sm font-semibold">Keyboard shortcuts</h2>
                <button
                    type="button"
                    class="ml-auto text-xs text-stone-400 hover:text-stone-700 dark:hover:text-stone-200"
                    @click="emit('close')"
                >
                    Close
                </button>
            </div>

            <div class="grid gap-6 p-4 sm:grid-cols-2">
                <div v-for="group in groups" :key="group.name">
                    <p class="mb-2 text-[0.65rem] font-semibold tracking-wider text-stone-400 uppercase">
                        {{ group.name }}
                    </p>
                    <dl class="space-y-1.5">
                        <div v-for="[key, what] in group.keys" :key="key" class="flex items-baseline gap-3 text-sm">
                            <dt class="w-24 shrink-0">
                                <kbd
                                    class="rounded border border-stone-300 px-1.5 py-0.5 font-sans text-xs dark:border-stone-600"
                                >{{ key }}</kbd>
                            </dt>
                            <dd class="text-stone-600 dark:text-stone-400">{{ what }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <p class="border-t border-stone-200 px-4 py-2.5 text-xs text-stone-400 dark:border-stone-800">
                Archive, delete and snooze arrive with the provider adapters — they need a real mailbox to move
                mail in.
            </p>
        </div>
    </div>
</template>
