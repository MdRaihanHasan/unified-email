<script setup>
const props = defineProps({
    provider: { type: String, required: true },
})

// Colour by provider so a unified list still makes it obvious which mailbox a
// message arrived in — the main thing you lose by merging inboxes.
const styles = {
    gmail_api: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    imap: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    graph: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
}

const labels = {
    gmail_api: 'Workspace',
    imap: 'Gmail',
    graph: 'Outlook',
}
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[0.65rem] font-medium tracking-wide uppercase"
        :class="styles[props.provider] ?? 'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-400'"
    >
        {{ labels[props.provider] ?? props.provider }}
    </span>
</template>
