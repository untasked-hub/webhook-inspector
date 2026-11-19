<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
const selected = ref(0), tab = ref('JSON'), search = ref(''), copied = ref(false), copyError = ref(false)
const samples = [
  { source: 'Stripe', event: 'invoice.paid', body: { id: 'evt_demo', type: 'invoice.paid', data: { object: { id: 'in_demo', amount_paid: 2900, currency: 'usd', paid: true } } } },
  { source: 'GitHub', event: 'push', body: { ref: 'refs/heads/main', repository: { name: 'demo-app', private: false }, commits: [{ id: 'demo_commit', message: 'Ship webhook integration' }] } },
  { source: 'Slack', event: 'event_callback', body: { type: 'event_callback', event: { type: 'app_mention', text: 'Demo notification', channel: 'demo-channel' } } },
]
const sample = computed(() => samples[selected.value])
const text = computed(() => tab.value === 'Headers' ? JSON.stringify({ 'content-type': 'application/json', 'x-demo-source': sample.value.source.toLowerCase(), authorization: '[redacted]' }, null, 2) : tab.value === 'cURL' ? `curl -X POST https://example.com/webhook \\\n  -H 'Content-Type: application/json' \\\n  --data-raw '${JSON.stringify(sample.value.body)}'` : JSON.stringify(sample.value.body, null, 2))
// Render tokens as Vue text nodes, not HTML: even hostile webhook strings cannot execute.
const tokens = computed(() => (search.value ? [text.value] : text.value.split(/("(?:\\.|[^"\\])*"\s*:|"(?:\\.|[^"\\])*"|\btrue\b|\bfalse\b|\bnull\b|\b\d+\b)/g)).map(value => ({ value, color: value.endsWith(':') ? 'text-sky-300' : value.startsWith('"') ? 'text-emerald-300' : /^(true|false|null)$/.test(value) ? 'text-fuchsia-300' : /^\d+$/.test(value) ? 'text-amber-300' : '' })))
const matches = computed(() => search.value ? text.value.toLowerCase().split(search.value.toLowerCase()).length - 1 : 0)
async function copy() { copyError.value = false; try { await navigator.clipboard.writeText(text.value); copied.value = true; setTimeout(() => copied.value = false, 1500) } catch { copied.value = false; copyError.value = true } }
</script>
<template>
  <figure class="min-w-0"><div class="overflow-hidden rounded-xl border border-zinc-700 bg-zinc-900 shadow-2xl">
    <div class="border-b border-zinc-800 p-4 flex justify-between text-xs"><span>Interactive inspector</span><span class="text-amber-300">Sample data</span></div>
    <div class="flex flex-wrap gap-2 p-3" role="group" aria-label="Sample webhooks"><button v-for="(item, i) in samples" :key="item.source" :aria-pressed="selected === i" class="rounded px-3 py-2 text-xs border" :class="selected === i ? 'border-emerald-500 text-emerald-300 font-bold underline' : 'border-zinc-700 text-zinc-400'" @click="selected = i; copied = false">{{ item.source }}</button></div>
    <p class="px-4 py-2 font-mono text-sm"><span class="text-emerald-300">POST</span> {{ sample.event }}</p>
    <div class="flex gap-3 border-y border-zinc-800 px-4" role="group" aria-label="Inspector view"><button v-for="view in ['JSON', 'Headers', 'cURL']" :key="view" :aria-pressed="tab === view" class="py-3 text-xs" :class="tab === view ? 'text-emerald-300' : 'text-zinc-400'" @click="tab = view; copied = false">{{ view }}</button><button class="ml-auto text-xs text-zinc-300" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button></div>
    <div class="px-4 pt-3"><input v-model="search" aria-label="Search demo payload" placeholder="Search sample…" class="w-full rounded border border-zinc-700 bg-zinc-950 px-3 py-2 text-xs"/><p v-if="search" role="status" class="mt-1 text-xs text-zinc-400">{{ matches }} {{ matches === 1 ? 'match' : 'matches' }}</p></div>
    <p v-if="copyError" role="status" class="px-4 text-xs text-amber-300">Could not copy; select text instead.</p><pre tabindex="0" aria-label="Sample payload" class="h-72 overflow-auto p-4 text-xs leading-6"><template v-for="(token, i) in tokens" :key="i"><span :class="token.color"><template v-for="(part, j) in (search ? token.value.split(new RegExp('(' + search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi')) : [token.value])" :key="j"><mark v-if="search && part.toLowerCase() === search.toLowerCase()" class="bg-amber-300 text-zinc-950">{{ part }}</mark><template v-else>{{ part }}</template></template></span></template></pre>
    <Link href="/register" class="block border-t border-zinc-800 px-4 py-4 text-sm text-emerald-300">Capture your own webhook →</Link>
  </div><figcaption class="mt-3 text-center text-xs text-zinc-400">Explore synthetic examples. No requests are sent to external services.</figcaption></figure>
</template>
