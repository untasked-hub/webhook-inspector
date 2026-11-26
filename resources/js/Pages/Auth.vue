<script setup lang="ts">
import PublicLayout from '../Components/PublicLayout.vue'
import { Head, useForm, Link, router } from '@inertiajs/vue3'
import { ref, onMounted, onUnmounted } from 'vue'
const props = defineProps<{
  mode: 'login' | 'register'
  selected_plan?: string | null
  selected_plan_name?: string | null
  billing_enabled?: boolean
  billing_mode?: string | null
}>()
const form = useForm({ name: '', email: '', password: '', password_confirmation: '', plan: props.selected_plan ?? '' })
const switchHref = props.selected_plan
  ? `${props.mode === 'register' ? '/login' : '/register'}?plan=${encodeURIComponent(props.selected_plan)}`
  : (props.mode === 'register' ? '/login' : '/register')
const networkError = ref('')
let removeExceptionListener: (() => void) | undefined
onMounted(() => {
  removeExceptionListener = router.on('exception', (event) => {
    event.preventDefault()
    networkError.value = 'Unable to connect. Check your connection and try again. Your entries have been kept.'
  })
})
onUnmounted(() => removeExceptionListener?.())
const submit = () => {
  networkError.value = ''
  form.post(props.mode === 'register' ? '/register' : '/login', {
    onSuccess: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head :title="(mode === 'register' ? 'Create your account' : 'Log in') + ' – Webhook Inspector'" />
  <PublicLayout>
  <div class="flex items-center justify-center px-4 py-12 sm:py-16">
    <form class="public-panel w-full max-w-md space-y-5" @submit.prevent="submit">
      <p v-if="networkError" role="alert" class="rounded border border-red-500/50 bg-red-950/40 p-2 text-sm text-red-300">{{ networkError }}</p>
      <p v-if="Object.keys(form.errors).length" role="alert" class="rounded border border-red-500/50 bg-red-950/40 p-2 text-sm text-red-300">Please fix the errors below and try again.</p>
      <Link href="/" class="text-sm text-emerald-400 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400">← Webhook Inspector</Link>
      <h1 class="text-2xl font-semibold">{{ mode === 'register' ? 'Create your account' : 'Log in' }}</h1>
      <p v-if="selected_plan_name && billing_enabled" role="status" class="rounded-lg border border-amber-500/50 bg-amber-950/30 p-3 text-sm leading-6 text-amber-200">
        <strong class="font-semibold">{{ selected_plan_name }} plan selected.</strong>
        <span> {{ mode === 'register' ? 'Create an account' : 'Sign in' }} to continue directly to the {{ selected_plan_name }} upgrade page.</span>
      </p>
      <div v-if="mode === 'register'" class="space-y-1">
        <label for="name" class="block text-sm font-medium">Name</label>
        <input id="name" v-model="form.name" autocomplete="name" required :aria-invalid="!!form.errors.name" :aria-describedby="form.errors.name ? 'name-error' : undefined" class="w-full rounded bg-zinc-900 border border-zinc-700 p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400" />
        <p v-if="form.errors.name" id="name-error" class="text-sm text-red-400" role="alert">{{ form.errors.name }}</p>
      </div>
      <div class="space-y-1">
        <label for="email" class="block text-sm font-medium">Email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required :aria-invalid="!!form.errors.email" :aria-describedby="form.errors.email ? 'email-error' : undefined" class="w-full rounded bg-zinc-900 border border-zinc-700 p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400" />
        <p v-if="form.errors.email" id="email-error" class="text-sm text-red-400" role="alert">{{ form.errors.email }}</p>
      </div>
      <div class="space-y-1">
        <label for="password" class="block text-sm font-medium">Password</label>
        <input id="password" v-model="form.password" type="password" :autocomplete="mode === 'register' ? 'new-password' : 'current-password'" required :aria-invalid="!!form.errors.password" :aria-describedby="form.errors.password ? 'password-error' : (mode === 'register' ? 'password-help' : undefined)" class="w-full rounded bg-zinc-900 border border-zinc-700 p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400" />
        <p v-if="mode === 'register'" id="password-help" class="text-xs text-zinc-400">Use at least 12 characters.</p>
        <p v-if="form.errors.password" id="password-error" class="text-sm text-red-400" role="alert">{{ form.errors.password }}</p>
      </div>
      <div v-if="mode === 'register'" class="space-y-1">
        <label for="password-confirmation" class="block text-sm font-medium">Confirm password</label>
        <input id="password-confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required :aria-invalid="!!form.errors.password_confirmation" :aria-describedby="form.errors.password_confirmation ? 'confirmation-error' : undefined" class="w-full rounded bg-zinc-900 border border-zinc-700 p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400" />
        <p v-if="form.errors.password_confirmation" id="confirmation-error" class="text-sm text-red-400" role="alert">{{ form.errors.password_confirmation }}</p>
      </div>
      <button :disabled="form.processing" class="w-full rounded bg-emerald-500 p-2 font-medium text-zinc-950 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400">{{ form.processing ? 'Please wait…' : mode === 'register' ? 'Sign up' : 'Log in' }}</button>
      <Link :href="switchHref" class="block text-sm text-zinc-400 hover:text-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400">{{ mode === 'register' ? 'Have an account? Log in' : 'New here? Sign up' }}</Link>
    </form>
  </div>
  </PublicLayout>
</template>
