<script setup lang="ts">
import { computed, ref, watch } from 'vue'
const props = defineProps<{ endpoint: number; current: any; requests: any[]; api: (url: string, init?: RequestInit) => Promise<Response> }>()
const otherId = ref(''), other = ref<any>(null), mode = ref('body'), error = ref(''), busy = ref(false)
let version = 0
watch(() => [props.endpoint, props.current?.request?.id], () => { version++; other.value = null; otherId.value = ''; error.value = ''; busy.value = false })
async function compare() {
  if (!otherId.value) return
  const v = ++version
  other.value = null; error.value = ''; busy.value = true
  try {
    const r = await props.api(`/api/endpoints/${props.endpoint}/requests/${otherId.value}`)
    if (!r.ok) throw new Error('Request unavailable or expired. Choose another request.')
    const data = await r.json()
    if (v === version) other.value = data
  } catch (e: any) { if (v === version) error.value = e.message }
  finally { if (v === version) busy.value = false }
}
function text(data: any) {
  if (!data) return ''
  if (mode.value === 'headers') return JSON.stringify(sort(data.payload.headers), null, 2)
  try {
    const raw = new TextDecoder().decode(Uint8Array.from(atob(data.payload.body_base64 || ''), c => c.charCodeAt(0)))
    try { return JSON.stringify(sort(JSON.parse(raw)), null, 2) } catch { return raw || '(empty body)' }
  } catch { return '(binary payload)' }
}
function sort(value: any): any {
  if (Array.isArray(value)) return value.map(sort)
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(k => [k, sort(value[k])]))
  return value
}
const rows = computed(() => {
  const a = text(props.current).split('\n'), b = text(other.value).split('\n')
  return Array.from({ length: Math.max(a.length, b.length) }, (_, i) => ({ a: a[i] ?? '', b: b[i] ?? '', changed: a[i] !== b[i] }))
})
const changed = computed(() => rows.value.filter(r => r.changed).length)
</script>
<template>
  <details class="rounded-xl border border-zinc-800 p-3 space-y-3">
    <summary class="cursor-pointer text-sm font-semibold">Compare requests</summary>
    <p class="text-xs text-zinc-400">Side-by-side positional diff. JSON keys are sorted; array order is preserved. Changed lines are marked ≠. Load older history to compare older requests.</p>
    <form class="flex flex-wrap gap-2" @submit.prevent="compare">
      <select v-model="otherId" required aria-label="Compare with request" class="min-w-0 flex-1 rounded border border-zinc-700 bg-zinc-950 p-2 text-xs"><option value="">Choose another request</option><option v-for="r in requests.filter(r => r.id !== current.request.id)" :key="r.id" :value="r.id">#{{ r.id }} {{ r.method }} · {{ r.received_at }}</option></select>
      <button :disabled="busy || !otherId" class="rounded bg-zinc-800 px-3 py-2 text-xs disabled:opacity-50">{{ busy ? 'Loading…' : 'Compare' }}</button>
    </form>
    <p v-if="error" role="alert" class="text-xs text-red-300">{{ error }}</p>
    <template v-if="other">
      <select v-model="mode" aria-label="Diff content" class="rounded bg-zinc-900 p-2 text-xs"><option value="body">Payload</option><option value="headers">Headers</option></select>
      <p role="status" class="text-xs text-zinc-400">{{ changed ? changed + (changed === 1 ? ' changed line' : ' changed lines') : 'No differences' }}</p>
      <div role="region" aria-label="Request comparison" tabindex="0" class="max-h-96 overflow-auto rounded border border-zinc-800 focus-visible:outline focus-visible:outline-emerald-400"><table class="w-full table-fixed text-xs font-mono"><thead><tr class="bg-zinc-900 text-zinc-400"><th class="p-2 text-left">Current #{{ current.request.id }}</th><th class="p-2 text-left">Compared #{{ other.request.id }}</th></tr></thead><tbody><tr v-for="(row, i) in rows" :key="i" :class="row.changed ? 'bg-amber-950/40 text-amber-200' : 'text-zinc-400'"><td class="border-r border-zinc-800 p-1 align-top whitespace-pre-wrap break-all"><span class="select-none text-zinc-500">{{ row.changed ? '≠' : ' ' }} {{ i + 1 }} </span>{{ row.a }}</td><td class="p-1 align-top whitespace-pre-wrap break-all">{{ row.b }}</td></tr></tbody></table></div>
    </template>
  </details>
</template>
