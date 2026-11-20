<script setup lang="ts">
import { ref, watch } from 'vue'
const props = defineProps<{ project: number; endpoint?: number | null; role: string; api: (url: string, init?: RequestInit) => Promise<Response> }>()
const tokens = ref<any[]>([]), name = ref(''), scope = ref('read'), secret = ref(''), error = ref(''), busy = ref(false), loaded = ref(false)
let version = 0
watch(() => props.project, () => { version++; tokens.value = []; secret.value = ''; error.value = ''; name.value = ''; loaded.value = false; busy.value = false })
async function load() {
  const v = version
  try {
    const r = await props.api(`/api/projects/${props.project}/tokens`)
    const data = await r.json()
    if (v !== version) return
    if (!r.ok) throw new Error(data.message || 'Could not load tokens.')
    tokens.value = data.tokens; loaded.value = true
  } catch (e: any) { if (v === version) error.value = e.message }
}
async function mutate(path: string, method: string, body?: object) {
  if (busy.value) return
  const v = version; busy.value = true; error.value = ''; secret.value = ''
  try {
    const r = await props.api(`/api/projects/${props.project}/tokens${path}`, { method, body: body ? JSON.stringify(body) : undefined })
    const data = r.status === 204 ? {} : await r.json()
    if (v !== version) return
    if (!r.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Token action failed.')
    secret.value = data.token || ''; name.value = ''; await load()
  } catch (e: any) { if (v === version) error.value = e.message }
  finally { if (v === version) busy.value = false }
}
const copied = ref(false), confirmRevoke = ref<number | null>(null)
watch(secret, () => { copied.value = false })
watch(() => props.project, () => { confirmRevoke.value = null })
const copiedEnv = ref(false)
watch(secret, () => { copiedEnv.value = false })
// Built only on demand from the in-memory secret; never rendered into the DOM.
function agentEnv(token: string) {
  const lines = [`export WI_BASE_URL="${window.location.origin}"`, `export WI_PROJECT_ID="${props.project}"`]
  if (props.endpoint) lines.push(`export WI_ENDPOINT_ID="${props.endpoint}"`)
  lines.push(`export WI_API_TOKEN="${token}"`)
  return lines.join('\n') + '\n'
}
async function copy(env = false) {
  const current = secret.value
  if (!current) return
  try {
    await navigator.clipboard.writeText(env ? agentEnv(current) : current)
    if (current === secret.value) { if (env) copiedEnv.value = true; else copied.value = true }
  } catch { if (current === secret.value) error.value = 'Copy unavailable. Select and copy the token manually.' }
}
function dismiss() { secret.value = ''; copied.value = false; copiedEnv.value = false }
</script>
<template>
  <details v-if="['owner','member'].includes(role)" class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-3 space-y-3" @toggle="($event.target as HTMLDetailsElement).open ? load() : (dismiss(), confirmRevoke = null)">
    <summary class="cursor-pointer text-sm font-semibold">Project API tokens <span class="ml-1 rounded bg-zinc-800 px-1.5 py-0.5 font-mono text-xs font-normal text-zinc-300" data-testid="project-id">Project ID: {{ project }}</span></summary>
    <p class="text-xs text-zinc-400">Tokens access only this project. Owners manage all tokens; members manage their own. Removing membership revokes effective access. Treat tokens as passwords.</p>
    <p v-if="error" role="alert" class="text-xs text-red-300">{{ error }} <button class="underline" @click="load">Retry list</button></p>
    <form class="space-y-2" @submit.prevent="mutate('', 'POST', { name, scope })">
      <label class="block text-xs text-zinc-300" for="project-token-name">Token name</label>
      <input id="project-token-name" v-model="name" required maxlength="80" aria-label="Token name" placeholder="CI test runner" class="w-full rounded border border-zinc-700 bg-zinc-950 p-2 text-sm" />
      <label class="block text-xs text-zinc-300" for="project-token-scope">Token scope</label>
      <select id="project-token-scope" v-model="scope" aria-label="Token scope" class="w-full rounded border border-zinc-700 bg-zinc-950 p-2 text-sm"><option value="read">Read only</option><option value="read+replay">Read + replay</option></select>
      <button :disabled="busy || !name.trim()" class="rounded bg-emerald-400 px-3 py-2 text-xs font-semibold text-zinc-950 disabled:opacity-50">Generate API token</button>
    </form>
    <div v-if="secret" class="rounded border border-amber-700 p-3 space-y-2"><p class="text-xs text-amber-300">Shown once. Store privately now; it cannot be retrieved.</p><code class="block break-all select-all text-xs">{{ secret }}</code><div class="flex flex-wrap gap-3"><button class="text-xs text-emerald-300" @click="copy()">{{ copied ? 'Copied' : 'Copy token' }}</button><button class="text-xs text-emerald-300" @click="copy(true)">{{ copiedEnv ? 'Copied env' : 'Copy agent env' }}</button><button class="text-xs text-zinc-400" @click="dismiss">Dismiss secret</button></div><p class="text-xs text-zinc-400">Agent env sets WI_BASE_URL, WI_PROJECT_ID<span v-if="endpoint">, WI_ENDPOINT_ID</span> and WI_API_TOKEN. Copy the receiver URL separately. <a href="/docs#section-2" class="underline">Agent &amp; CLI quickstart</a></p></div>
    <p v-if="loaded && !tokens.length" class="text-xs text-zinc-400">No API tokens yet.</p>
    <div v-for="token in tokens" :key="token.id" class="border-t border-zinc-800 pt-2 text-xs space-y-1"><strong>{{ token.name }}</strong><code class="block">{{ token.prefix }}… · {{ token.scope }}</code><p class="text-zinc-400">Last used: {{ token.last_used_at || 'Never' }}</p><div v-if="confirmRevoke === token.id" class="flex flex-wrap items-center gap-3"><span>Clients using this token will lose access immediately.</span><button :disabled="busy" class="rounded border border-red-700 px-2 py-1 text-red-300" @click="mutate('/' + token.id, 'DELETE')">Confirm revoke</button><button class="px-2 py-1 text-zinc-300" @click="confirmRevoke = null">Cancel</button></div><button v-else :disabled="busy" class="px-2 py-1 text-red-300 disabled:opacity-50" @click="confirmRevoke = token.id">Revoke {{ token.name }}</button></div>
  </details>
</template>
