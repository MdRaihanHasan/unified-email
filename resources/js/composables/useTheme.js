import { onMounted, ref } from 'vue'

const KEY = 'unified-mail-theme'
const theme = ref('system')

function apply(value) {
    const dark = value === 'dark'
        || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)

    document.documentElement.classList.toggle('dark', dark)
}

/**
 * Light / dark / follow-the-OS, remembered per browser.
 *
 * Reads and writes are wrapped: a private window or blocked site data throws on
 * access rather than returning null, and a mail client that white-screens over a
 * theme preference would be absurd.
 */
export function useTheme() {
    onMounted(() => {
        try {
            theme.value = window.localStorage.getItem(KEY) ?? 'system'
        } catch {
            theme.value = 'system'
        }

        apply(theme.value)

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (theme.value === 'system') apply('system')
        })
    })

    function setTheme(value) {
        theme.value = value
        apply(value)

        try {
            window.localStorage.setItem(KEY, value)
        } catch {
            // Preference simply is not remembered; the page still renders correctly.
        }
    }

    function toggleTheme() {
        const dark = document.documentElement.classList.contains('dark')
        setTheme(dark ? 'light' : 'dark')
    }

    return { theme, setTheme, toggleTheme }
}
