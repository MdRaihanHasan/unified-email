<script setup>
// One stroke-based set on a 24px grid, so nothing here is an emoji or a dingbat
// that renders differently per platform.
const paths = {
    menu: 'M4 7h16M4 12h16M4 17h16',
    search: 'M20 20l-4.5-4.5',
    settings: 'M5 7h14M5 12h14M5 17h14',
    refresh: 'M20 12a8 8 0 1 1-2.3-5.7M20 4v4h-4',
    archive: 'M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 13h4',
    trash: 'M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6',
    clock: 'M12 8v4l3 2',
    mailopen: 'M4 10v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9l-8-5zM4 10l8 5 8-5',
    star: 'm12 4 2.5 5.3 5.5.7-4 3.9 1 5.6L12 16.8 7 19.5l1-5.6-4-3.9 5.5-.7z',
    clip: 'M16 7 9.4 13.6a2.5 2.5 0 0 0 3.5 3.5l6.6-6.6a4.5 4.5 0 0 0-6.4-6.4L6 11.3a6.5 6.5 0 0 0 9.2 9.2l4.3-4.3',
    reply: 'M9 7 4 12l5 5M4 12h9a6 6 0 0 1 6 6v1',
    replyall: 'M8 7 3 12l5 5M13 7l-5 5 5 5M8 12h7a5 5 0 0 1 5 5v1',
    forward: 'M15 7l5 5-5 5M20 12h-9a6 6 0 0 0-6 6v1',
    send: 'M4 12l16-7-7 16-2.5-6.5z',
    close: 'M6 6l12 12M18 6L6 18',
    plus: 'M12 5v14M5 12h14',
    chevdown: 'M7 10l5 5 5-5',
    chevleft: 'M14 7l-5 5 5 5',
    chevright: 'M10 7l5 5-5 5',
    minimize: 'M6 12h12',
    expand: 'M9 5H5v4M15 19h4v-4',
    pencil: 'M5 19h3l10-10-3-3L5 16z',
    inbox: 'M4 13h4l1.5 3h5L16 13h4M4 13 6.5 5h11L20 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z',
    check: 'M5 12.5 10 17l9-10',
    warn: 'M12 4 3 20h18zM12 10v4M12 17v.5',
    sun: 'M12 3v2m0 14v2M5 12H3m18 0h-2M6.3 6.3 4.9 4.9m14.2 14.2-1.4-1.4M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4',
    moon: 'M20 14a8 8 0 1 1-10-10 7 7 0 0 0 10 10z',
    keyboard: 'M7 10h1M11 10h1M15 10h1M8.5 14h7',
}

// A few glyphs need a circle the path syntax cannot express.
const circles = {
    search: [{ cx: 11, cy: 11, r: 6 }],
    settings: [{ cx: 9, cy: 7, r: 1.7 }, { cx: 15, cy: 12, r: 1.7 }, { cx: 10, cy: 17, r: 1.7 }],
    sun: [{ cx: 12, cy: 12, r: 4 }],
    clock: [{ cx: 12, cy: 12, r: 8 }],
    more: [{ cx: 12, cy: 5.5, r: 1.3 }, { cx: 12, cy: 12, r: 1.3 }, { cx: 12, cy: 18.5, r: 1.3 }],
    keyboard: [],
}

const rects = {
    archive: [{ x: 3, y: 4, width: 18, height: 4, rx: 1 }],
    keyboard: [{ x: 3, y: 5, width: 18, height: 14, rx: 2 }],
}

const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 20 },
    filled: { type: Boolean, default: false },
})
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        :width="props.size"
        :height="props.size"
        :fill="props.filled ? 'currentColor' : 'none'"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="shrink-0"
        aria-hidden="true"
    >
        <rect v-for="(r, i) in rects[props.name] ?? []" :key="`r${i}`" v-bind="r" />
        <circle v-for="(c, i) in circles[props.name] ?? []" :key="`c${i}`" v-bind="c" />
        <path v-if="paths[props.name]" :d="paths[props.name]" />
    </svg>
</template>
