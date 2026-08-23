<script setup>
import { computed } from 'vue'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import IconButton from './IconButton.vue'
import RelativeTime from './RelativeTime.vue'

const props = defineProps({
    thread: { type: Object, required: true },
    open: { type: Boolean, default: false },
    checked: { type: Boolean, default: false },
    cursor: { type: Boolean, default: false },
})

const emit = defineEmits(['open', 'check', 'star', 'read'])

const unread = computed(() => props.thread.unread_count > 0)

// Already display names with our own addresses removed, server-side. A thread with
// nobody left is one we only ever sent to ourselves.
const people = computed(() => {
    const list = props.thread.participants ?? []
    if (!list.length) return 'me'

    return list.length > 2
        ? `${list.slice(0, 2).join(', ')} +${list.length - 2}`
        : list.join(', ')
})
</script>

<template>
    <div
        class="group relative flex cursor-pointer items-start gap-2.5 border-b border-l-[3px] border-stone-200 py-2 pr-2.5 transition dark:border-stone-800"
        :class="[
            props.open
                ? 'bg-sky-50 dark:bg-sky-950/50'
                : unread
                  ? 'bg-white hover:bg-stone-100 dark:bg-stone-900/50 dark:hover:bg-stone-800/70'
                  : 'hover:bg-stone-100 dark:hover:bg-stone-800/50',
            props.cursor ? 'ring-1 ring-sky-500 ring-inset' : '',
        ]"
        :style="{ '--mailbox': `var(--mailbox-${props.thread.providers[0] ?? 'graph'})`, borderLeftColor: 'var(--mailbox)' }"
        role="button"
        :aria-current="props.open ? 'true' : undefined"
        @click="emit('open')"
    >
        <div class="flex shrink-0 items-center gap-2.5 pt-0.5 pl-2.5" @click.stop>
            <button
                type="button"
                class="flex size-4 shrink-0 items-center justify-center rounded-[3px] border-[1.6px] transition"
                :class="props.checked
                    ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-500 dark:bg-sky-500'
                    : 'border-stone-400 dark:border-stone-500'"
                :aria-label="props.checked ? 'Deselect' : 'Select'"
                @click="emit('check')"
            >
                <Icon v-if="props.checked" name="check" :size="11" />
            </button>

            <button
                type="button"
                class="shrink-0 transition"
                :class="props.thread.is_starred
                    ? 'text-amber-500'
                    : 'text-stone-300 hover:text-amber-500 dark:text-stone-600'"
                :aria-label="props.thread.is_starred ? 'Unstar' : 'Star'"
                @click="emit('star')"
            >
                <Icon name="star" :size="15" :filled="props.thread.is_starred" />
            </button>
        </div>

        <Avatar :name="people" :provider="props.thread.providers[0]" :size="30" />

        <!-- Three lines, not one. A single line in this pane truncated every subject
             after three words and left no room for the snippet at all. -->
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <span class="truncate" :class="unread ? 'font-semibold' : ''">{{ people }}</span>

                <span
                    v-for="provider in props.thread.providers.slice(1)"
                    :key="provider"
                    class="mailbox-fill size-1.5 shrink-0 rounded-full"
                    :style="{ '--mailbox': `var(--mailbox-${provider})` }"
                    title="Also in another mailbox"
                />

                <span
                    v-if="props.thread.message_count > 1"
                    class="shrink-0 rounded border border-stone-200 px-1 text-[0.65rem] font-semibold text-stone-400 dark:border-stone-700"
                >{{ props.thread.message_count }}</span>

                <div class="ml-auto flex shrink-0 items-center" @click.stop>
                    <div class="hidden group-hover:flex">
                        <!-- Unread becomes read, read becomes unread — so the value
                             we want to write is exactly `unread`. -->
                        <IconButton
                            name="mailopen"
                            :label="unread ? 'Mark read' : 'Mark unread'"
                            :size="16"
                            @click="emit('read', unread)"
                        />
                    </div>
                    <RelativeTime
                        :value="props.thread.last_message_at"
                        class="text-xs group-hover:hidden"
                        :class="unread ? 'font-semibold' : 'text-stone-500 dark:text-stone-400'"
                    />
                </div>
            </div>

            <div class="truncate" :class="unread ? 'font-semibold' : ''">{{ props.thread.subject }}</div>

            <div class="flex items-center gap-1.5">
                <span class="truncate text-[0.8rem] text-stone-400">{{ props.thread.snippet }}</span>
                <Icon
                    v-if="props.thread.has_attachments"
                    name="clip"
                    :size="14"
                    class="ml-auto shrink-0 text-stone-400"
                />
            </div>
        </div>
    </div>
</template>
