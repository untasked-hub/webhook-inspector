<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import ProductDemo from '../Components/ProductDemo.vue'
import PublicLayout from '../Components/PublicLayout.vue'
import { Radio, RotateCcw, History } from 'lucide-vue-next'
defineProps<{ title: string }>()
const features = [
  { icon: Radio, title: 'Capture & inspect', copy: 'Unique receiver URLs, headers, query parameters, encrypted payloads, and a searchable JSON viewer.' },
  { icon: RotateCcw, title: 'Replay & forward', copy: 'Replay with explicit confirmation. Public-destination forwarding with delivery logs, retries, and SSRF restrictions.' },
  { icon: History, title: 'History & export', copy: 'Combine filters, browse older requests, and export JSON, NDJSON, cURL or CSV. Configure retention for each endpoint.' },
]
</script>
<template>
  <Head title="Webhook Inspector – Inspect, debug, and replay webhooks in seconds"><meta name="description" content="Open-source webhook debugging, testing, forwarding, and replay. Self-host with Docker or explore managed hosting." /><meta property="og:title" content="Webhook Inspector – Open-source webhook debugging and replay" /><meta property="og:description" content="Inspect, debug, and replay webhooks in seconds. Open source, self-hostable, or fully managed." /><meta property="og:type" content="website" /><meta name="twitter:card" content="summary" /></Head>
  <PublicLayout>
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
      <section class="grid items-center gap-12 py-16 lg:grid-cols-2 lg:py-24">
        <div><p class="mb-5 text-sm font-medium uppercase tracking-widest text-emerald-300">A clearer view of every webhook</p><h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl lg:text-6xl">Inspect, debug, and replay webhooks in seconds.</h1><p class="mt-6 max-w-lg text-lg leading-8 text-zinc-400">See what arrived. Understand what happened. Try it again.<br />Open source, self-hostable, or managed.</p><div class="mt-8 flex flex-wrap gap-3"><Link href="/register" class="public-primary">Start free →</Link><Link href="/docs" class="public-secondary">Explore docs</Link></div><Link href="/self-hosting" class="mt-4 inline-flex min-h-11 items-center text-sm text-zinc-400 hover:text-emerald-300">Prefer your own infrastructure? Self-host →</Link></div>
        <ProductDemo />
      </section>
      <section class="border-t border-zinc-800 py-12"><h2 class="text-2xl font-semibold tracking-tight">Your first webhook in three steps</h2><p class="mt-3 text-zinc-400">Designed to get you inspecting quickly.</p><ol class="mt-8 grid gap-6 md:grid-cols-3"><li v-for="(step, i) in ['Create a project and endpoint', 'Copy the receiver URL and send a test', 'Select a request and inspect its payload']" :key="step" class="flex gap-4"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-zinc-700 font-mono text-emerald-300">{{ i + 1 }}</span><p class="pt-1 leading-7 text-zinc-300">{{ step }}</p></li></ol></section>
      <p class="-mt-4 pb-4 text-sm text-zinc-400">Using an AI agent or CI? <Link href="/docs#section-2" class="text-emerald-300 underline-offset-4 hover:underline">Read the Agent &amp; CLI quickstart →</Link></p>
      <section class="grid gap-4 py-10 md:grid-cols-3" aria-label="Features"><div v-for="f in features" :key="f.title" class="public-panel"><component :is="f.icon" class="mb-5 h-6 w-6 text-emerald-300" aria-hidden="true"/><h2 class="text-lg font-semibold">{{ f.title }}</h2><p class="mt-3 text-sm leading-7 text-zinc-400">{{ f.copy }}</p></div></section>
      <section class="public-panel flex flex-wrap items-center justify-between gap-6"><div><h2 class="text-2xl font-semibold">Your webhooks. Your choice.</h2><p class="mt-3 text-zinc-400">Run the open-source core, or explore managed limits and retention.</p></div><div class="flex flex-wrap gap-3"><Link href="/self-hosting" class="public-secondary">Self-host</Link><Link href="/pricing" class="public-primary">Compare plans</Link></div></section>
    </div>
  </PublicLayout>
</template>
