<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import PublicLayout from '../Components/PublicLayout.vue'
import CodeBlock from '../Components/CodeBlock.vue'
const props = defineProps({ page: Object })
const sections = computed(() => props.page.sections.map((s, i) => ({ ...s, id: `section-${i + 1}` })))
</script>

<template>
  <Head :title="page.title + ' – Webhook Inspector'"><meta name="description" :content="page.description" /><meta property="og:title" :content="page.title + ' – Webhook Inspector'" /><meta property="og:description" :content="page.description" /><meta property="og:type" content="website" /><meta name="twitter:card" content="summary" /></Head>
  <PublicLayout>
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16">
      <header class="mb-10 max-w-3xl"><p class="mb-3 text-sm font-medium uppercase tracking-widest text-emerald-300">{{ page.slug === 'pricing' ? 'Managed hosting' : 'Resources' }}</p><h1 class="text-3xl font-semibold tracking-tight sm:text-5xl">{{ page.slug === 'pricing' ? 'Plans for every stage' : page.title }}</h1><p class="mt-5 text-lg leading-8 text-zinc-400">{{ page.description }}</p></header>
      <template v-if="page.plans">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <section v-for="plan in page.plans" :key="plan.key" :class="['public-panel flex flex-col', plan.key === 'pro' ? 'border-emerald-500/70' : '']">
            <h2 class="text-lg font-semibold">{{ plan.name }}</h2><p class="my-5"><span class="text-4xl font-semibold">${{ plan.price }}</span><span class="text-sm text-zinc-400"> / month</span></p>
            <ul class="mb-8 space-y-3 text-sm text-zinc-300"><li>{{ plan.requests.toLocaleString() }} requests / calendar month</li><li>Up to {{ plan.retention }} days retention</li><li>Capture, inspect, replay &amp; forward</li></ul>
            <Link v-if="plan.price === 0" href="/register" class="public-primary mt-auto">Start free</Link>
            <Link v-else-if="page.billing_enabled" href="/dashboard" class="public-secondary mt-auto">Continue to account</Link>
            <button v-else disabled class="public-secondary mt-auto opacity-60">Checkout unavailable</button>
          </section>
        </div>
        <p class="mt-5 rounded-lg border border-zinc-700 bg-zinc-900 p-4 text-sm leading-6 text-zinc-300" role="status">{{ page.billing_note }} <span v-if="!page.billing_enabled">No payment will be taken.</span><span v-else>Manage checkout from your authenticated account. Configuration does not verify payment processing.</span></p>
        <div class="my-12 overflow-x-auto rounded-xl border border-zinc-800"><table class="w-full min-w-[600px] text-left text-sm"><caption class="p-5 text-left text-lg font-semibold">Compare managed limits</caption><thead class="border-y border-zinc-800 bg-zinc-900"><tr><th scope="col" class="p-4">Included</th><th v-for="p in page.plans" :key="p.key" scope="col" class="p-4">{{ p.name }}</th></tr></thead><tbody><tr class="border-b border-zinc-800"><th scope="row" class="p-4 font-medium">Monthly requests</th><td v-for="p in page.plans" :key="p.key" class="p-4 font-mono">{{ p.requests.toLocaleString() }}</td></tr><tr><th scope="row" class="p-4 font-medium">Maximum retention</th><td v-for="p in page.plans" :key="p.key" class="p-4">{{ p.retention }} days</td></tr></tbody></table></div>
        <section class="public-panel flex flex-wrap items-center justify-between gap-6"><div><h2 class="text-xl font-semibold">Run the open-source core</h2><p class="mt-2 max-w-2xl leading-7 text-zinc-400">All core features, on your infrastructure. You operate the database, keys, TLS, backups and monitoring.</p></div><Link href="/self-hosting" class="public-secondary">Self-hosting guide →</Link></section>
        <section class="mt-12 max-w-3xl"><h2 class="mb-5 text-2xl font-semibold">Common questions</h2><details class="border-b border-zinc-800 py-4"><summary class="min-h-11 font-medium">How is usage counted?</summary><p class="py-3 leading-7 text-zinc-400">Request quotas apply per account each calendar month. Clearing captured history does not reset usage.</p></details><details class="border-b border-zinc-800 py-4"><summary class="min-h-11 font-medium">How does retention work?</summary><p class="py-3 leading-7 text-zinc-400">Plan limits define the maximum retention. Pruning requires a functioning scheduler. Self-hosted operators configure and monitor this themselves.</p></details><details class="border-b border-zinc-800 py-4"><summary class="min-h-11 font-medium">Can I self-host?</summary><p class="py-3 leading-7 text-zinc-400">Yes. The source edition includes the core functionality without managed billing. Deployment capacity depends on your infrastructure.</p></details></section>
      </template>
      <div v-else class="grid gap-10 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-12">
        <aside><nav aria-label="On this page" class="sticky top-6 rounded-xl border border-zinc-800 p-4"><p class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">On this page</p><a v-for="s in sections" :key="s.id" :href="'#' + s.id" class="flex min-h-11 items-center rounded px-2 text-sm text-zinc-300 hover:bg-zinc-900">{{ s.h }}</a></nav></aside>
        <article class="min-w-0 max-w-3xl space-y-10">
          <div v-if="['privacy','terms'].includes(page.slug)" class="rounded-xl border border-amber-700 bg-amber-950/30 p-4 text-sm text-amber-200">Draft pending legal review. This is not a legal-readiness certification.</div>
          <div v-if="page.slug === 'docs'" class="rounded-xl border border-amber-700 bg-amber-950/30 p-4 text-sm leading-7 text-amber-200">Receiver URLs are bearer capabilities. Anyone who knows the URL can submit requests; delete and recreate an endpoint to rotate.</div>
          <section v-for="s in sections" :id="s.id" :key="s.id" class="scroll-mt-24 space-y-4"><h2 class="text-2xl font-semibold tracking-tight">{{ s.h }}</h2><p v-for="p in s.p" :key="p" class="leading-7 text-zinc-300">{{ p }}</p><CodeBlock v-if="s.code" :code="s.code" :language="s.language" /></section>
        </article>
      </div>
    </div>
  </PublicLayout>
</template>
