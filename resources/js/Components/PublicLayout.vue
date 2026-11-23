<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
const menu = ref(false)
const page = usePage<{ auth?: { user?: { name: string } | null } }>()
const signedIn = computed(() => Boolean(page.props.auth?.user))
watch(() => page.url, () => menu.value = false)
const links = [['/', 'Home'], ['/pricing', 'Plans'], ['/docs', 'Docs'], ['/self-hosting', 'Self-host']]
const active = (path: string) => page.url.split('?')[0] === path
</script>
<template>
  <div class="min-h-screen bg-zinc-950 text-zinc-100">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-emerald-400 focus:p-4 focus:text-zinc-950">Skip to content</a>
    <header class="border-b border-zinc-800" @keydown.esc="menu = false">
      <div class="mx-auto flex min-h-16 max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <Link href="/" class="flex min-h-11 items-center gap-2 font-semibold tracking-tight"><svg viewBox="0 0 28 28" class="h-7 w-7 text-emerald-400" fill="none" aria-hidden="true"><path d="M5 8h8l4 6h6M5 20h8l4-6" stroke="currentColor" stroke-width="2"/><circle cx="5" cy="8" r="3" fill="currentColor"/><circle cx="5" cy="20" r="3" fill="currentColor"/><circle cx="23" cy="14" r="3" fill="currentColor"/></svg>Webhook Inspector</Link>
        <button type="button" class="public-secondary md:hidden" :aria-expanded="menu" aria-controls="public-navigation" @click="menu = !menu">{{ menu ? 'Close' : 'Menu' }}</button>
        <nav id="public-navigation" aria-label="Main navigation" :class="[menu ? 'flex' : 'hidden', 'w-full flex-col gap-2 md:flex md:w-auto md:flex-row md:items-center']">
          <Link v-for="[href, label] in links" :key="href" :href="href" :aria-current="active(href) ? 'page' : undefined" :class="['inline-flex min-h-11 items-center rounded-lg px-3 text-sm', active(href) ? 'bg-zinc-800 text-emerald-300' : 'text-zinc-300 hover:bg-zinc-900']">{{ label }}</Link>
          <a href="https://github.com/untasked-hub/webhook-inspector" class="inline-flex min-h-11 items-center px-3 text-sm text-zinc-300">GitHub ↗</a>
          <template v-if="signedIn">
            <Link href="/dashboard" class="public-primary">Dashboard</Link>
            <button type="button" class="public-secondary" @click="router.post('/logout')">Log out</button>
          </template>
          <template v-else>
            <Link href="/login" class="public-secondary">Log in</Link>
            <Link href="/register" class="public-primary">Start free</Link>
          </template>
        </nav>
      </div>
    </header>
    <main id="main-content" tabindex="-1"><slot /></main>
    <footer class="mt-16 border-t border-zinc-800">
      <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 text-sm sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        <div><p class="font-semibold">Webhook Inspector</p><p class="mt-3 leading-6 text-zinc-400">Inspect, debug, and replay.<br />Open source or managed.</p></div>
        <div><h2 class="font-medium">Product</h2><Link class="footer-link" href="/pricing">Plans</Link><Link class="footer-link" href="/docs">Documentation</Link><Link class="footer-link" href="/security">Security &amp; support</Link></div>
        <div><h2 class="font-medium">Build</h2><Link class="footer-link" href="/self-hosting">Self-hosting</Link><a class="footer-link" href="https://github.com/untasked-hub/webhook-inspector">GitHub ↗</a></div>
        <div><h2 class="font-medium">Legal</h2><Link class="footer-link" href="/privacy">Privacy</Link><Link class="footer-link" href="/terms">Terms</Link></div>
        <p class="text-xs text-zinc-400 sm:col-span-2 lg:col-span-4">Built and maintained by Untasked agents.</p>
      </div>
    </footer>
  </div>
</template>
