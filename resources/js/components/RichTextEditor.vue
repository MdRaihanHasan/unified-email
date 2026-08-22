<script setup>
import { onBeforeUnmount, watch } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Write your message…' },
})

const emit = defineEmits(['update:modelValue'])

const editor = useEditor({
    content: props.modelValue,

    // Put the caret at the very top of the document. A reply is prefilled with an
    // empty paragraph above the quoted original precisely so there is somewhere to
    // write; without this the caret lands inside the quote and the reply is typed
    // into the middle of the sender's own words.
    autofocus: 'start',

    extensions: [
        StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
        Link.configure({ openOnClick: false, autolink: true }),
        Placeholder.configure({ placeholder: props.placeholder }),
    ],
    editorProps: {
        attributes: { class: 'email-body min-h-48 text-sm outline-none' },
    },
    onUpdate: ({ editor }) => emit('update:modelValue', editor.getHTML()),
})

// Only push external changes in when they did not come from this editor, or every
// keystroke would reset the cursor to the end.
watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && value !== editor.value.getHTML()) {
            editor.value.commands.setContent(value, { emitUpdate: false })
        }
    },
)

onBeforeUnmount(() => editor.value?.destroy())

const actions = [
    { label: 'B', title: 'Bold', class: 'font-bold', run: (e) => e.chain().focus().toggleBold().run(), active: 'bold' },
    { label: 'I', title: 'Italic', class: 'italic', run: (e) => e.chain().focus().toggleItalic().run(), active: 'italic' },
    { label: 'S', title: 'Strikethrough', class: 'line-through', run: (e) => e.chain().focus().toggleStrike().run(), active: 'strike' },
    { label: '“”', title: 'Quote', class: '', run: (e) => e.chain().focus().toggleBlockquote().run(), active: 'blockquote' },
    { label: '•', title: 'Bullet list', class: '', run: (e) => e.chain().focus().toggleBulletList().run(), active: 'bulletList' },
    { label: '1.', title: 'Numbered list', class: '', run: (e) => e.chain().focus().toggleOrderedList().run(), active: 'orderedList' },
    { label: '</>', title: 'Code', class: 'font-mono text-xs', run: (e) => e.chain().focus().toggleCode().run(), active: 'code' },
]

function addLink() {
    const href = window.prompt('Link URL')
    if (!href) return
    editor.value?.chain().focus().setLink({ href }).run()
}
</script>

<template>
    <div class="rounded-md border border-stone-300 dark:border-stone-700">
        <div
            v-if="editor"
            class="flex flex-wrap items-center gap-0.5 border-b border-stone-200 px-1.5 py-1 dark:border-stone-800"
        >
            <button
                v-for="action in actions"
                :key="action.title"
                type="button"
                :title="action.title"
                class="rounded px-1.5 py-0.5 text-xs transition hover:bg-stone-100 dark:hover:bg-stone-800"
                :class="[action.class, editor.isActive(action.active) ? 'bg-stone-200 dark:bg-stone-700' : '']"
                @click="action.run(editor)"
            >
                {{ action.label }}
            </button>

            <button
                type="button"
                title="Link"
                class="rounded px-1.5 py-0.5 text-xs transition hover:bg-stone-100 dark:hover:bg-stone-800"
                @click="addLink"
            >
                🔗
            </button>
        </div>

        <EditorContent :editor="editor" class="max-h-[26rem] overflow-y-auto px-3 py-2" />
    </div>
</template>

<style>
/* TipTap renders the placeholder as a pseudo-element on the first empty node. */
.tiptap p.is-editor-empty:first-child::before {
    color: var(--color-stone-400);
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}
</style>
