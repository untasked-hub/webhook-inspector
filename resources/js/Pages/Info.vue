<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import PublicLayout from '../Components/PublicLayout.vue'
import CodeBlock from '../Components/CodeBlock.vue'
const props = defineProps({ page: Object })
const sections = computed(() => props.page.sections.map((s, i) => ({ ...s, id: `section-${i + 1}` })))
const checkoutCancelled = ref(false)
const selectedPlan = computed(() => {
  if (!props.page.plans || !props.page.selected_plan) return null
  return props.page.plans.find((p) => p.key === props.page.selected_plan && p.price > 0) || null
})
onMounted(() => {
  const params = new URLSearchParams(window.location.search)
  if (params.get('checkout') === 'cancelled') checkoutCancelled.value = true
})
</script>

<template>
  <Head :title="page.title + ' – Webhook Inspector'"><meta name="description" :content="page.description" /><meta property="og:title" :content="page.title + ' – Webhook Inspector'" /><meta property="og:description" :content="page.description" /><meta property="og:type" content="website" /><meta name="twitter:card" content="summary" /></Head>
  <PublicLayout>
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16">
      <header class="mb-10 max-w-3xl"><p class="mb-3 text-sm font-medium uppercase tracking-widest text-emerald-300">{{ page.eyebrow || (page.slug === 'pricing' ? 'Managed hosting' : 'Resources') }}</p><h1 class="text-3xl font-semibold tracking-tight sm:text-5xl">{{ page.slug === 'pricing' ? 'Plans for every stage' : page.title }}</h1><p v-if="page.version" class="mt-3 text-sm font-medium text-zinc-400">{{ page.version }}</p><p class="mt-5 text-lg leading-8 text-zinc-400">{{ page.description }}</p></header>
      <template v-if="page.plans">
        <div v-if="checkoutCancelled" role="status" class="mb-6 rounded-lg border border-zinc-700 bg-zinc-900 p-4 text-sm leading-6 text-zinc-200">Checkout was cancelled — no payment was taken and your plan has not changed. You can start checkout again from the <Link href="/billing/upgrade" class="underline text-emerald-300">upgrade page</Link>.</div>
        <div v-if="selectedPlan && page.billing_enabled" role="status" class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-emerald-500/60 bg-emerald-950/30 p-4 text-sm leading-6 text-emerald-100">
          <p><strong class="font-semibold">{{ selectedPlan.name }} plan selected.</strong> Continue to the upgrade page to agree to the Terms of Service and start Stripe checkout.</p>
          <Link :href="`/billing/upgrade?plan=${encodeURIComponent(selectedPlan.key)}`" class="public-primary">{{ `Upgrade to ${selectedPlan.name}` }}</Link>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <section v-for="plan in page.plans" :key="plan.key" :data-plan="plan.key" :class="['public-panel flex flex-col', selectedPlan?.key === plan.key ? 'border-emerald-400 ring-1 ring-emerald-400/60' : (plan.key === 'pro' ? 'border-emerald-500/70' : '')]">
            <h2 class="text-lg font-semibold">{{ plan.name }}</h2><p class="my-5"><span class="text-4xl font-semibold">${{ plan.price }}</span><span class="text-sm text-zinc-400"> / month</span></p>
            <ul class="mb-8 space-y-3 text-sm text-zinc-300"><li>{{ plan.requests.toLocaleString() }} requests / calendar month</li><li>Up to {{ plan.retention }} days retention</li><li>Capture, inspect, replay &amp; forward</li></ul>
            <Link v-if="plan.price === 0" :href="page.signed_in ? '/dashboard' : '/register'" class="public-primary mt-auto">{{ page.signed_in ? 'Open dashboard' : 'Start free' }}</Link>
            <Link v-else-if="page.billing_enabled" :href="`/billing/upgrade?plan=${encodeURIComponent(plan.key)}`" class="public-secondary mt-auto">{{ `Upgrade to ${plan.name}` }}</Link>
            <button v-else disabled class="public-secondary mt-auto opacity-60">Checkout unavailable</button>
          </section>
        </div>
        <p v-if="!page.billing_enabled" class="mt-5 rounded-lg border border-zinc-700 bg-zinc-900 p-4 text-sm leading-6 text-zinc-300" role="status">{{ page.billing_note }} No payment will be taken.</p>
        <div class="my-12 overflow-x-auto rounded-xl border border-zinc-800"><table class="w-full min-w-[600px] text-left text-sm"><caption class="p-5 text-left text-lg font-semibold">Compare managed limits</caption><thead class="border-y border-zinc-800 bg-zinc-900"><tr><th scope="col" class="p-4">Included</th><th v-for="p in page.plans" :key="p.key" scope="col" class="p-4">{{ p.name }}</th></tr></thead><tbody><tr class="border-b border-zinc-800"><th scope="row" class="p-4 font-medium">Monthly requests</th><td v-for="p in page.plans" :key="p.key" class="p-4 font-mono">{{ p.requests.toLocaleString() }}</td></tr><tr><th scope="row" class="p-4 font-medium">Maximum retention</th><td v-for="p in page.plans" :key="p.key" class="p-4">{{ p.retention }} days</td></tr><tr v-for="feature in ['Capture, inspection and replay', 'Automatic forwarding and delivery history', 'Project collaboration and roles', 'Project REST API and tokens']" :key="feature" class="border-t border-zinc-800"><th scope="row" class="p-4 font-medium">{{ feature }}</th><td v-for="p in page.plans" :key="p.key" class="p-4">Included</td></tr></tbody></table></div>
        <section class="public-panel flex flex-wrap items-center justify-between gap-6"><div><h2 class="text-xl font-semibold">Run the open-source core</h2><p class="mt-2 max-w-2xl leading-7 text-zinc-400">All core features, on your infrastructure. You operate the database, keys, TLS, backups and monitoring.</p></div><Link href="/self-hosting" class="public-secondary">Self-hosting guide →</Link></section>
        <section class="mt-12 max-w-3xl"><h2 class="mb-5 text-2xl font-semibold">Billing &amp; quota policy</h2><details v-for="policy in page.policies" :key="policy.h" class="border-b border-zinc-800 py-4"><summary class="min-h-11 font-medium">{{ policy.h }}</summary><p class="py-3 leading-7 text-zinc-400">{{ policy.p }}</p></details><details class="border-b border-zinc-800 py-4"><summary class="min-h-11 font-medium">How does retention work?</summary><p class="py-3 leading-7 text-zinc-400">Plan limits define the maximum retention. Pruning requires a functioning scheduler. Self-hosted operators configure and monitor this themselves.</p></details><details class="border-b border-zinc-800 py-4"><summary class="min-h-11 font-medium">Can I self-host?</summary><p class="py-3 leading-7 text-zinc-400">Yes. The source edition includes the core functionality without managed billing. Deployment capacity depends on your infrastructure.</p></details></section>
      </template>
      <div v-else class="grid gap-10 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-12">
        <aside><nav aria-label="On this page" class="sticky top-6 rounded-xl border border-zinc-800 p-4"><p class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">On this page</p><a v-for="s in sections" :key="s.id" :href="'#' + s.id" class="flex min-h-11 items-center rounded px-2 text-sm text-zinc-300 hover:bg-zinc-900">{{ s.h }}</a></nav></aside>
        <article class="min-w-0 max-w-3xl space-y-10">
          <div v-if="page.slug === 'docs'" class="rounded-xl border border-amber-700 bg-amber-950/30 p-4 text-sm leading-7 text-amber-200">Receiver URLs are bearer capabilities. Anyone who knows the URL can submit requests; delete and recreate an endpoint to rotate.</div>
          <div v-if="page.slug === 'self-hosting'" class="rounded-xl border border-zinc-700 bg-zinc-900 p-4 text-sm leading-7 text-zinc-300 [overflow-wrap:anywhere]"><strong>Before using real payloads:</strong> Back up PostgreSQL and store the application encryption key separately in a secure location. Test recovery with both in an isolated instance. Retention pruning affects the live database, not existing backups; set a separate expiry policy for backups. See the repository README for the Docker quickstart and recovery checklist.</div>
          <p v-if="page.slug === 'security'" class="flex flex-wrap gap-3"><Link href="/docs" class="public-secondary">Documentation</Link><a href="https://github.com/untasked-hub/webhook-inspector" class="public-secondary">Public source repository ↗</a></p>
          <section v-for="s in sections" :id="s.id" :key="s.id" class="scroll-mt-24 space-y-4"><h2 class="text-2xl font-semibold tracking-tight">{{ s.h }}</h2><p v-for="p in (s.p || [])" :key="p" class="leading-7 text-zinc-300 [overflow-wrap:anywhere]">{{ p }}</p><ul v-if="s.list && s.list.length" class="list-disc space-y-3 pl-5 leading-7 text-zinc-300 [overflow-wrap:anywhere]"><li v-for="item in s.list" :key="item.label"><strong class="font-semibold text-zinc-100">{{ item.label }}</strong> {{ item.text }}</li></ul><div v-if="s.table" class="overflow-x-auto rounded-xl border border-zinc-800"><table class="w-full text-left text-sm"><thead class="border-b border-zinc-800 bg-zinc-900"><tr><th v-for="(header, idx) in s.table.headers" :key="header" scope="col" :class="['p-4 font-semibold', idx > 0 && s.table.align !== 'left' ? 'text-right' : '']">{{ header }}</th></tr></thead><tbody><tr v-for="row in s.table.rows" :key="row[0]" class="border-t border-zinc-800 align-top"><th scope="row" class="p-4 font-medium text-zinc-200">{{ row[0] }}</th><td v-for="(cell, idx) in row.slice(1)" :key="idx" :class="['p-4 text-zinc-300', s.table.align === 'left' ? 'leading-6' : 'text-right font-mono']">{{ cell }}</td></tr></tbody></table></div><p v-for="p in (s.after || [])" :key="p" class="leading-7 text-zinc-300 [overflow-wrap:anywhere]">{{ p }}</p><p v-if="s.links && s.links.length" class="flex flex-wrap gap-4 text-sm"><a v-for="link in s.links" :key="link.href" :href="link.href" target="_blank" rel="noopener noreferrer" class="underline text-emerald-300 hover:text-emerald-200">{{ link.label }} ↗</a></p><CodeBlock v-if="s.code" :code="s.code" :language="s.language" /></section>
        </article>
      </div>
    </div>
  </PublicLayout>
</template>
