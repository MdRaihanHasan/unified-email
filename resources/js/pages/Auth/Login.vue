<script setup>
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '' })

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') })
}
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-screen items-center justify-center bg-stone-50 px-4 dark:bg-stone-950">
        <div class="w-full max-w-sm rounded-xl border border-stone-200 bg-white p-7 shadow-sm dark:border-stone-800 dark:bg-stone-900">
            <h1 class="mb-1 text-lg font-semibold tracking-tight">Unified <span class="text-sky-600 dark:text-sky-400">mail</span></h1>
            <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">Sign in to your mailboxes.</p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        autofocus
                        class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 dark:border-stone-700 dark:bg-stone-900"
                    />
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 dark:border-stone-700 dark:bg-stone-900"
                    />
                </div>

                <p v-if="form.errors.email" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.email }}</p>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-sky-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-sky-500 disabled:opacity-50"
                >
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>

            <p class="mt-6 text-xs text-stone-400">
                Single-user instance. Create the login with
                <code class="rounded bg-stone-100 px-1 py-0.5 dark:bg-stone-800">php artisan mail:user</code>.
            </p>
        </div>
    </div>
</template>
