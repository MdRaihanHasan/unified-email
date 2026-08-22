import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp({
    title: (title) => (title ? `${title} — Unified Email` : 'Unified Email'),

    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true })

        return pages[`./pages/${name}.vue`]
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },

    progress: { color: '#0ea5e9' },
})
