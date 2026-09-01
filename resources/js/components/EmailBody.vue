<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'

/**
 * Renders a sanitized email body inside a sandboxed iframe.
 *
 * Two reasons. Defense in depth: the sanitizer is strong, but one bypass used
 * to mean session-riding XSS in the app document — here it lands in a frame
 * whose sandbox has no scripts. And fidelity: email is authored against a
 * white page, so the frame always paints paper-light, which is what fixed
 * light-gray-on-dark text becoming invisible in dark mode.
 *
 * allow-same-origin without allow-scripts is the safe pairing: no script can
 * run inside, while the parent can measure the body for auto-height and the
 * frame can fetch same-origin cid images with the session cookie.
 */
const props = defineProps({
    html: { type: String, default: '' },
})

const frame = ref(null)
const height = ref(80)

const styles = `
    body {
        margin: 14px 16px;
        font: 14px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        color: #1c1917;
        background: #ffffff;
        overflow-wrap: break-word;
        word-break: break-word;
    }
    p { margin: 0 0 0.85em; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; margin: 1.2em 0 0.5em; line-height: 1.25; }
    h1 { font-size: 1.5em; } h2 { font-size: 1.3em; } h3 { font-size: 1.15em; }
    ul, ol { margin: 0 0 0.85em; padding-left: 1.5em; }
    ul { list-style: disc; } ol { list-style: decimal; }
    li { margin: 0.2em 0; }
    blockquote { border-left: 3px solid #d6d3d1; color: #78716c; margin: 0 0 0.85em; padding-left: 1em; }
    a { color: #0284c7; text-decoration: underline; }
    hr { border: 0; border-top: 1px solid #e7e5e4; margin: 1.5em 0; }
    pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9em; }
    pre { background: #f5f5f4; border-radius: 6px; overflow-x: auto; padding: 0.75em; white-space: pre-wrap; }
    pre.email-plain { background: transparent; padding: 0; }
    table { border-collapse: collapse; max-width: 100%; }
    td, th { padding: 0.25em 0.5em; }
    img { max-width: 100%; height: auto; }
    img[data-blocked-src] {
        background: #f5f5f4;
        border: 1px dashed #d6d3d1;
        border-radius: 4px;
        min-width: 24px;
        min-height: 24px;
    }
`

const doc = computed(() =>
    `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>${styles}</style></head>`
    + `<body>${props.html}</body></html>`)

let observer = null

function measure() {
    const body = frame.value?.contentDocument?.body
    if (body) height.value = Math.max(40, body.scrollHeight + 6)
}

function onLoad() {
    measure()
    observer?.disconnect()

    const body = frame.value?.contentDocument?.body

    // Images finishing and fonts settling change the height after load.
    if (body && window.ResizeObserver) {
        observer = new ResizeObserver(measure)
        observer.observe(body)
    }
}

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
    <iframe
        ref="frame"
        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
        :srcdoc="doc"
        class="block w-full rounded-md border border-stone-200 bg-white dark:border-stone-800"
        :style="{ height: `${height}px` }"
        title="Message body"
        @load="onLoad"
    />
</template>
