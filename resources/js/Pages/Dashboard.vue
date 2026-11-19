<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import CodeBlock from '../Components/CodeBlock.vue'
import ProjectTokens from '../Components/ProjectTokens.vue'
import RequestDiff from '../Components/RequestDiff.vue'

defineProps<{ account: { name: string } }>()
const batchIds = ref<number[]>([]), batchMode = ref('selected'), batchUrl = ref(''), batchConfirmed = ref(false), batchBusy = ref(false)
async function bulkReplay() {
  if (!canEdit.value || batchBusy.value || !batchConfirmed.value) return
  const version = contextVersion
  batchBusy.value = true
  const body: any = { url: batchUrl.value, confirm: true }
  if (batchMode.value === 'selected') body.ids = batchIds.value
  try {
    if (await action(`/api/endpoints/${activeEndpointId.value}/bulk-replay?${batchMode.value === 'filtered' ? filters() : ''}`, 'POST', body, 'Batch queued. See delivery history for individual results.')) await loadSettings(false)
  } finally { if (version === contextVersion) { batchBusy.value = false; batchConfirmed.value = false } }
}
const projects = ref<any[]>([]), endpoints = ref<any[]>([]), requests = ref<any[]>([])
const activeEndpointId = ref<number | null>(null), selectedId = ref<number | null>(null), detail = ref<any>(null)
const newProject = ref(''), newEndpoint = ref(''), selectedProjectId = ref<number | null>(null)
const methodFilter = ref(''), search = ref(''), copied = ref('')
const fromDate = ref(''), toDate = ref(''), contentType = ref(''), minSize = ref(''), maxSize = ref(''), nextBefore = ref<number | null>(null)
const usage = ref<any>(null), replayConfirmed = ref(false), loading = ref(false), billingBusy = ref(false)
const visibleEndpoints = computed(() => endpoints.value.filter(e => !selectedProjectId.value || e.project_id === selectedProjectId.value))
const defaultReceiver = () => ({ response_status: 202, response_content_type: 'application/json', response_body: '{"received":true}', signature_provider: 'auto' })
const receiver = ref<any>(defaultReceiver()), signingSecret = ref(''), clearSecret = ref(false)
let contextVersion = 0, teamVersion = 0, historyVersion = 0
const settingsReady = ref(false)
const receiverBusy = ref(false), teamBusy = ref(false), teamState = ref('idle')
async function saveReceiver() {
  if (receiverBusy.value || !settingsReady.value || !activeEndpointId.value) return
  receiverBusy.value = true
  const version = contextVersion
  const body = { ...receiver.value }
  delete body.has_signing_secret
  if (clearSecret.value || signingSecret.value) body.signing_secret = clearSecret.value ? null : signingSecret.value
  try {
    if (await action(`/api/endpoints/${activeEndpointId.value}/receiver-settings`, 'PUT', body, 'Receiver settings saved.') && version === contextVersion) { signingSecret.value = ''; clearSecret.value = false; await loadSettings() }
  } finally { if (version === contextVersion) receiverBusy.value = false }
}
const forwardFields = { method: 'HTTP method', header: 'Header name', header_value: 'Header value', json_path: 'JSON dot path', json_value: 'JSON string value' }
const forwardFilter = ref<Partial<Record<keyof typeof forwardFields, string>>>({})
const healthLabels = {off: 'Forwarding off', paused: 'Temporarily paused', degraded: 'Delivery issues', healthy: 'Healthy', unverified: 'Awaiting first delivery'}
const healthColors = {off: 'text-zinc-400', paused: 'text-amber-300', degraded: 'text-red-300', healthy: 'text-emerald-300', unverified: 'text-zinc-300'}
const forwardHealth = ref<keyof typeof healthLabels | null>(null)
const savedForwardDestination = ref('')
const forwardingUrl = ref(''), forwardingEnabled = ref(false), replayUrl = ref(''), retentionDays = ref(7)
const deliveries = ref<any[]>([]), attempts = ref<Record<string, any[]>>({}), notice = ref(''), error = ref('')
const projectState = ref('loading'), historyState = ref('idle')
const background = ref(document.hidden), refreshFailed = ref(false), lastRefresh = ref('')
const refreshStatus = computed(() => !live.value ? 'Paused' : background.value ? 'Paused in background' : refreshFailed.value ? 'Refresh failed · retrying' : 'Auto-refresh on · 2s')
const visibilityChanged = () => { background.value = document.hidden; if (!document.hidden) pollRequests() }
const live = ref(true), polling = ref(false), newRequestIds = ref<number[]>([]), arrivals = ref(0)
// Poll only the first page and merge it into loaded history; never discard older pages.
async function pollRequests() {
  if (!live.value || polling.value || document.hidden || !activeEndpointId.value) return
  polling.value = true
  const endpoint = activeEndpointId.value, query = filters().toString(), version = contextVersion
  try {
    const response = await api(`/api/endpoints/${endpoint}/requests?${query}`)
    if (!response.ok) throw new Error('Refresh failed')
    const data = await response.json()
    if (version !== contextVersion || endpoint !== activeEndpointId.value || query !== filters().toString()) return
    const known = new Set(requests.value.map(r => r.id))
    const fresh = data.requests.filter((r: any) => !known.has(r.id))
    if (fresh.length) {
      newRequestIds.value = fresh.map((r: any) => r.id)
      arrivals.value = fresh.length
      requests.value = [...fresh, ...requests.value].sort((a, b) => b.id - a.id)
      if (!selectedId.value) await selectRequest(fresh[0].id)
    }
    historyState.value = 'ready'
    refreshFailed.value = false; lastRefresh.value = new Date().toLocaleTimeString()
  } catch { if (version === contextVersion) refreshFailed.value = true /* Preserve current captures and selection. */ }
  finally { polling.value = false }
}
const hasFilters = computed(() => filters().toString() !== '')
const formatQuota = (value: number) => !Number.isSafeInteger(value) || value >= Number.MAX_SAFE_INTEGER ? 'Unlimited' : value.toLocaleString()
const usagePercent = computed(() => Math.min(100, Math.max(0, 100 * (usage.value?.requests ?? 0) / Math.max(1, usage.value?.limits?.requests ?? 1))))
const exampleRequest = `curl -X POST "$RECEIVER_URL" -H 'Content-Type: application/json' -d '{"hello":"world"}'`
function clearFilters() { methodFilter.value = ''; historyQuery.value = ''; deliveryStatus.value = ''; fromDate.value = ''; toDate.value = ''; contentType.value = ''; minSize.value = ''; maxSize.value = ''; loadRequests(false) }
let timer: number | undefined

const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
const api = (url: string, init: RequestInit = {}) =>
  fetch(url, { credentials: 'same-origin', ...init, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), ...(init.headers || {}) } })

const activeEndpoint = computed(() => endpoints.value.find(e => e.id === activeEndpointId.value))
const projectRole = computed(() => projects.value.find(p => p.id === selectedProjectId.value)?.role)
const canEdit = computed(() => !!activeEndpoint.value && ['owner', 'member'].includes(projectRole.value))
const team = ref<any>({ members: [], invitations: [] }), showTeam = ref(false), inviteRole = ref('viewer'), inviteUrl = ref('')
const pendingInvite = ref(new URLSearchParams(location.hash.slice(1)).get('invite') || '')
if (pendingInvite.value) history.replaceState(null, '', location.pathname + location.search)
async function acceptInvite() {
  if (await action('/api/invitations/accept', 'POST', { token: pendingInvite.value }, 'Invitation accepted.')) { pendingInvite.value = ''; await loadProjects() }
}
function resetTeam() {
  teamVersion++; showTeam.value = false; team.value = { members: [], invitations: [] }
  inviteUrl.value = ''; inviteRole.value = 'viewer'; teamBusy.value = false; teamState.value = 'idle'
}
async function loadTeam() {
  if (!showTeam.value || projectRole.value !== 'owner') return
  const project = selectedProjectId.value, version = ++teamVersion
  teamState.value = 'loading'; team.value = { members: [], invitations: [] }
  try {
    const r = await api(`/api/projects/${project}/team`)
    if (!r.ok) throw new Error('Team unavailable')
    const data = await r.json()
    if (version !== teamVersion || project !== selectedProjectId.value || !showTeam.value) return
    team.value = data; teamState.value = 'ready'
  } catch { if (version === teamVersion) { teamState.value = 'error'; error.value = 'Could not load project team. Please retry.' } }
}
async function createInvite() {
  if (teamBusy.value || projectRole.value !== 'owner') return
  const project = selectedProjectId.value, version = contextVersion, teamContext = teamVersion
  teamBusy.value = true; error.value = ''; notice.value = ''; inviteUrl.value = ''
  try {
    const r = await api(`/api/projects/${project}/invitations`, { method: 'POST', body: JSON.stringify({ role: inviteRole.value }) })
    const data = await r.json().catch(() => ({}))
    if (version !== contextVersion || project !== selectedProjectId.value || teamContext !== teamVersion || !showTeam.value) return
    if (!r.ok) { error.value = data.message || 'Could not create invitation. Revoke unused invitations and try again.'; return }
    inviteUrl.value = data.url; notice.value = 'Invitation created. Copy the private link now.'; await loadTeam()
  } catch { if (version === contextVersion) error.value = 'Network error. Invitation could not be created. Please retry.' }
  finally { if (version === contextVersion) teamBusy.value = false }
}
async function teamAction(path: string, method: string, body = {}) {
  if (teamBusy.value) return
  const version = contextVersion
  teamBusy.value = true
  try { if (await action(`/api/projects/${selectedProjectId.value}/${path}`, method, body, 'Team access updated.') && version === contextVersion) await loadTeam() }
  finally { if (version === contextVersion) teamBusy.value = false }
}
const captureUrl = computed(() => activeEndpoint.value ? `${location.origin}/c/${activeEndpoint.value.token}` : '')
const decodedBody = computed(() => {
  const b64 = detail.value?.payload?.body_base64
  if (!b64) return ''
  try { return new TextDecoder().decode(Uint8Array.from(atob(b64), c => c.charCodeAt(0))) } catch { return atob(b64) }
})
const prettyBody = computed(() => {
  if (!decodedBody.value) return '(empty body)'
  try { return JSON.stringify(JSON.parse(decodedBody.value), null, 2) } catch { return decodedBody.value }
})
const filteredBody = computed(() => !search.value ? prettyBody.value : prettyBody.value.split('\n').filter(l => l.toLowerCase().includes(search.value.toLowerCase())).join('\n'))

const historyQuery = ref(''), deliveryStatus = ref(''), analytics = ref<any>(null), showAnalytics = ref(false), renameValue = ref('')
const escapeHtml = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
function highlightJson(text: string): string {
  let parsed = true
  try { JSON.parse(decodedBody.value) } catch { parsed = false }
  let html = escapeHtml(text)
  if (parsed) {
    html = html.replace(/(&quot;(?:[^&]|&(?!quot;))*?&quot;)(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g, (m, str, colon, lit, num) => {
      if (str) return colon ? `<span class="text-sky-300">${str}</span>${colon}` : `<span class="text-emerald-300">${str}</span>`
      if (lit) return `<span class="text-fuchsia-300">${lit}</span>`
      return `<span class="text-amber-300">${num}</span>`
    })
  }
  if (search.value) {
    const needle = escapeHtml(search.value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    html = html.split(/(<[^>]+>)/).map(part => part.startsWith('<') ? part : part.replace(new RegExp(needle, 'gi'), x => `<mark class="bg-amber-400 text-zinc-950">${x}</mark>`)).join('')
  }
  return html
}
const highlightedBody = computed(() => highlightJson(prettyBody.value))
const searchMatches = computed(() => !search.value ? 0 : prettyBody.value.toLowerCase().split(search.value.toLowerCase()).length - 1)
const curlCommand = computed(() => {
  if (!detail.value) return ''
  const shq = (v: string) => `'${String(v).replace(/'/g, `'\\''`)}'`
  const skip = /^(host|content-length|connection|accept-encoding|x-forwarded-.*|x-untasked-.*|fly-.*|via|x-request-start)$/i
  const headers = Object.entries(detail.value.payload.headers || {}).filter(([k, v]) => !skip.test(k) && !(Array.isArray(v) && v[0] === '[redacted]'))
    .map(([k, v]) => ` \\\n  -H ${shq(`${k}: ${Array.isArray(v) ? v[0] : v}`)}`).join('')
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(detail.value.payload.query || {})) q.set(k, String(v))
  const url = captureUrl.value + (q.toString() ? `?${q}` : '')
  const body = decodedBody.value ? ` \\\n  --data-raw ${shq(decodedBody.value)}` : ''
  return `curl -X ${detail.value.request.method} ${shq(url)}${headers}${body}`
})
async function loadAnalytics() { const r = await api('/api/analytics?days=14'); if (r.ok) analytics.value = await r.json() }
const maxDaily = computed(() => Math.max(1, ...(analytics.value?.daily || []).map((d: any) => d.requests)))
async function renameProject() {
  if (!selectedProjectId.value || !renameValue.value.trim()) return
  if (await action(`/api/projects/${selectedProjectId.value}`, 'PATCH', { name: renameValue.value }, 'Project renamed.')) { renameValue.value = ''; await loadProjects() }
}
async function deleteProject() {
  if (!selectedProjectId.value || !confirm('Delete this project, its endpoints and all captured requests? This cannot be undone.')) return
  if (await action(`/api/projects/${selectedProjectId.value}`, 'DELETE', {}, 'Project deleted.')) { await chooseProject(null); await loadProjects(); notice.value = 'Project deleted.' }
}

async function loadProjects() {
  try {
  const r = await api('/api/projects')
  if (!r.ok) { projectState.value = 'error'; return }
  const d = await r.json()
  projects.value = d.projects; endpoints.value = d.endpoints
  const project = projects.value.find(p => p.id === selectedProjectId.value) ?? projects.value[0]
  const endpoint = endpoints.value.find(e => e.id === activeEndpointId.value && e.project_id === project?.id)
  if (selectedProjectId.value !== (project?.id ?? null) || !endpoint) await chooseProject(project?.id ?? null)
  projectState.value = 'ready'
  } catch { projectState.value = 'error' }
}
function filters() {
  const q = new URLSearchParams()
  for (const [key, value] of Object.entries({method: methodFilter.value, q: historyQuery.value, status: deliveryStatus.value, from: fromDate.value, to: toDate.value, content_type: contentType.value, min_size: minSize.value, max_size: maxSize.value})) if (value) q.set(key, value)
  return q
}
async function loadUsage() { const r = await api('/api/usage'); if (r.ok) usage.value = await r.json() }
async function loadRequests(append = false) {
  if (!activeEndpointId.value) return
  const endpoint = activeEndpointId.value, q = filters(), version = ++historyVersion, context = contextVersion
  if (append && nextBefore.value) q.set('before', String(nextBefore.value))
  if (!requests.value.length) historyState.value = 'loading'
  try {
  const r = await api(`/api/endpoints/${endpoint}/requests?${q}`)
  if (version !== historyVersion || context !== contextVersion) return
  if (!r.ok) { historyState.value = 'error'; return }
  const d = await r.json()
  if (version !== historyVersion || context !== contextVersion) return
  requests.value = append ? [...requests.value, ...d.requests] : d.requests
  nextBefore.value = d.next_before
  if (!requests.value.some(x => x.id === selectedId.value)) { detail.value = null; selectedId.value = null; if (requests.value[0]) await selectRequest(requests.value[0].id) }
  historyState.value = 'ready'
  } catch { if (version === historyVersion && context === contextVersion) historyState.value = 'error' }
}
async function selectRequest(id: number) {
  selectedId.value = id
  detail.value = null
  const endpoint = activeEndpointId.value, version = contextVersion
  try {
    const r = await api(`/api/endpoints/${endpoint}/requests/${id}`)
    if (!r.ok) throw new Error('Request unavailable')
    const data = await r.json()
    if (version === contextVersion && selectedId.value === id) detail.value = data
  } catch { if (version === contextVersion && selectedId.value === id) error.value = 'Could not load request details. Please select it again.' }
}
async function addProject() {
  if (!newProject.value.trim()) return
  try {
    const r = await api('/api/projects', { method: 'POST', body: JSON.stringify({ name: newProject.value }) })
    const data = await r.json().catch(() => ({}))
    if (!r.ok) { error.value = data.message || 'Could not create project.'; return }
    await chooseProject(data.id); newProject.value = ''; await loadProjects(); notice.value = 'Project created.'
  } catch { error.value = 'Network error. Could not create project.' }
}
async function addEndpoint() {
  if (!newEndpoint.value.trim() || !selectedProjectId.value) return
  const project = selectedProjectId.value, version = contextVersion
  try {
    const r = await api('/api/endpoints', { method: 'POST', body: JSON.stringify({ project_id: project, name: newEndpoint.value }) })
    const data = await r.json().catch(() => ({}))
    if (version !== contextVersion) return
    if (!r.ok) { error.value = data.message || 'Could not create endpoint.'; return }
    newEndpoint.value = ''; await loadProjects()
    if (selectedProjectId.value === project) { await chooseEndpoint(data.id); notice.value = 'Endpoint created.' }
  } catch { if (version === contextVersion) error.value = 'Network error. Could not create endpoint.' }
}
async function loadSettings(refreshForm = true) {
  if (!activeEndpointId.value) return
  const endpoint = activeEndpointId.value, version = contextVersion
  try {
    if (refreshForm) {
      const settings = await api(`/api/endpoints/${endpoint}/receiver-settings`)
      if (!settings.ok) throw new Error('Settings unavailable')
      const data = await settings.json()
      if (version !== contextVersion) return
      retentionDays.value = activeEndpoint.value?.retention_days ?? 7
      receiver.value = data; settingsReady.value = true
    }
    const [f, d] = await Promise.all([api(`/api/endpoints/${endpoint}/forward`), api(`/api/endpoints/${endpoint}/deliveries`)])
    const forward = f.ok ? await f.json() : null
    const delivery = d.ok ? await d.json() : null
    if (version !== contextVersion) return
    if (forward) {
      forwardHealth.value = forward.target?.health ?? null
      if (refreshForm) { savedForwardDestination.value = forward.target?.url ?? ''; forwardingUrl.value = ''; forwardingEnabled.value = !!forward.target?.enabled; forwardFilter.value = forward.target?.filter ?? {} }
    }
    if (delivery) { deliveries.value = delivery.deliveries; attempts.value = delivery.attempts }
  } catch { if (version === contextVersion && refreshForm) error.value = 'Could not load endpoint settings. Select the endpoint again to retry.' }
}
function resetEndpoint() {
  contextVersion++; historyVersion++
  batchIds.value = []; batchMode.value = 'selected'; batchUrl.value = ''; batchConfirmed.value = false; batchBusy.value = false
  arrivals.value = 0; newRequestIds.value = []; nextBefore.value = null
  detail.value = null; selectedId.value = null; requests.value = []; error.value = ''; notice.value = ''
  activeEndpointId.value = null; settingsReady.value = false; receiver.value = defaultReceiver(); signingSecret.value = ''; clearSecret.value = false
  forwardHealth.value = null; savedForwardDestination.value = ''; forwardingUrl.value = ''; forwardingEnabled.value = false; forwardFilter.value = {}; replayUrl.value = ''; replayConfirmed.value = false
  deliveries.value = []; attempts.value = {}; receiverBusy.value = false; loading.value = false
  historyState.value = 'idle'; refreshFailed.value = false; lastRefresh.value = ''; search.value = ''; copied.value = ''
}
async function chooseProject(id: number | null) {
  resetTeam(); resetEndpoint(); selectedProjectId.value = id; renameValue.value = ''; newEndpoint.value = ''
  methodFilter.value = ''; historyQuery.value = ''; deliveryStatus.value = ''; fromDate.value = ''; toDate.value = ''; contentType.value = ''; minSize.value = ''; maxSize.value = ''
  const endpoint = endpoints.value.find(e => e.project_id === id)
  if (endpoint) await chooseEndpoint(endpoint.id)
}
async function chooseEndpoint(id: number) {
  const endpoint = endpoints.value.find(e => e.id === id && e.project_id === selectedProjectId.value)
  if (!endpoint) return
  resetEndpoint()
  activeEndpointId.value = id
  await Promise.all([loadRequests(), loadSettings()])
}
async function action(url: string, method: string, body: object, success: string) {
  error.value = ''; notice.value = ''
  const version = contextVersion
  try {
    const r = await api(url, { method, body: JSON.stringify(body) })
    const d = r.ok ? null : await r.json().catch(() => ({}))
    if (version !== contextVersion) return false
    if (!r.ok) { error.value = Object.values(d.errors ?? {}).flat().join(' ') || d.message || `Request failed (${r.status}).`; return false }
    notice.value = success; return true
  } catch { if (version === contextVersion) error.value = 'Network error. Please try again.'; return false }
}
async function saveForward() {
  if (await action(`/api/endpoints/${activeEndpointId.value}/forward`, 'PUT', {url: forwardingUrl.value, enabled: forwardingEnabled.value, filter: forwardFilter.value}, 'Forwarding settings saved.')) await loadSettings()
}
async function saveRetention() {
  if (await action(`/api/endpoints/${activeEndpointId.value}/retention`, 'PUT', {retention_days: Number(retentionDays.value)}, 'Retention updated.')) await loadProjects()
}
async function retryDelivery(id: number) {
  if (!window.confirm('Retry this delivery? This may trigger real side effects at the destination.')) return
  if (await action(`/api/endpoints/${activeEndpointId.value}/deliveries/${id}/retry`, 'POST', { confirm: true }, 'Retry queued as a new delivery.')) await loadSettings(false)
}
async function replay() {
  if (!selectedId.value || !replayConfirmed.value || loading.value) return
  loading.value = true
  if (await action(`/api/endpoints/${activeEndpointId.value}/requests/${selectedId.value}/replay`, 'POST', {url: replayUrl.value, confirm: replayConfirmed.value}, 'Replay queued. Check delivery history for its result.')) await loadSettings()
  loading.value = false; replayConfirmed.value = false
}
async function openBilling(plan?: string) {
  if (!usage.value?.billing_enabled || billingBusy.value) return
  billingBusy.value = true; error.value = ''; notice.value = ''
  try {
    const r = await api(plan ? '/api/billing/checkout' : '/api/billing/portal', { method: 'POST', body: JSON.stringify(plan ? { plan } : {}) })
    const data = await r.json().catch(() => ({}))
    if (!r.ok) { error.value = data.message || 'Billing is unavailable. Please try again.'; return }
    const destination = new URL(data.url)
    if (destination.protocol !== 'https:' || !['checkout.stripe.com', 'billing.stripe.com'].includes(destination.hostname)) throw new Error('Unexpected billing destination')
    window.location.assign(destination.href)
  } catch { error.value = 'Billing could not be opened. Please try again.' }
  finally { billingBusy.value = false }
}
async function clearHistory() {
  if (!activeEndpointId.value || !window.confirm('Delete this endpoint’s captured requests and delivery history? This cannot be undone. Monthly usage will not reset.')) return
  if (await action(`/api/endpoints/${activeEndpointId.value}/requests`, 'DELETE', {}, 'Request history cleared.')) { requests.value = []; detail.value = null; selectedId.value = null; deliveries.value = []; attempts.value = {} }
}
async function copyText(text: string, label: string) {
  try { await navigator.clipboard.writeText(text); copied.value = label; setTimeout(() => (copied.value = ''), 1500) }
  catch { error.value = 'Clipboard access unavailable. Select and copy the displayed value manually.' }
}
function exportJson(format = 'json') {
  const q = filters(); q.set('format', format)
  const a = document.createElement('a'); a.href = `/api/endpoints/${activeEndpointId.value}/requests?${q}`; a.download = `webhooks.${format}`; a.click()
}
onMounted(() => { document.addEventListener('visibilitychange', visibilityChanged); loadProjects(); loadUsage(); timer = window.setInterval(() => { pollRequests(); if (!document.hidden && live.value) { loadSettings(false); loadUsage(); if (showAnalytics.value) loadAnalytics() } }, 2000) })
onUnmounted(() => { clearInterval(timer); document.removeEventListener('visibilitychange', visibilityChanged) })
</script>

<template>
  <Head title="Dashboard – Webhook Inspector" />
  <main class="min-h-screen bg-zinc-950 text-zinc-100 flex flex-col">
    <h1 class="sr-only">Webhook Inspector dashboard</h1>
    <header class="border-b border-zinc-800 px-4 sm:px-6 py-3 flex flex-wrap gap-3 items-center justify-between">
      <div class="font-semibold tracking-tight">Webhook Inspector <span class="text-xs text-zinc-400 ml-2">{{ account.name }}</span></div>
      <nav aria-label="Account navigation" class="flex flex-wrap items-center gap-4 text-sm"><button class="text-zinc-400 hover:text-zinc-100" :aria-pressed="showAnalytics" @click="showAnalytics = !showAnalytics; if (showAnalytics) loadAnalytics()">Analytics</button><Link href="/docs" class="inline-flex min-h-11 items-center text-zinc-300">Docs</Link><Link href="/pricing" class="inline-flex min-h-11 items-center text-zinc-300">Plans</Link><button class="text-zinc-400 hover:text-zinc-200" @click="router.post('/logout')">Log out</button></nav>
    </header>
    <div v-if="notice || error" aria-label="Workspace feedback" class="border-b border-zinc-800 px-6 py-3"><p v-if="notice" role="status" class="text-sm text-emerald-300">{{ notice }}</p><p v-if="error" role="alert" class="text-sm text-red-300">{{ error }}</p></div>
    <section v-if="pendingInvite" class="border-b border-emerald-800 p-4 space-y-2"><h2 class="font-semibold">Project invitation</h2><p class="text-sm text-zinc-400">Accept only invitations from people you trust. Joining grants access to their captured webhook data.</p><button class="public-primary" @click="acceptInvite">Accept invitation</button><button class="ml-3 text-sm" @click="pendingInvite = ''">Dismiss</button></section>
    <section v-if="showTeam && projectRole === 'owner'" class="border-b border-zinc-800 p-4 space-y-3" aria-label="Project team">
      <h2 class="font-semibold">Project team</h2><p class="text-sm text-zinc-400">Members can edit endpoints and replay requests. Viewers can inspect and export only. Only owners manage projects and access. Invitations expire in 24 hours and work once; share privately.</p>
      <form class="flex gap-3" @submit.prevent="createInvite"><select v-model="inviteRole" aria-label="Invitation role" class="rounded bg-zinc-900 p-2"><option value="viewer">Viewer</option><option value="member">Member</option></select><button class="public-primary" :disabled="teamBusy || teamState !== 'ready'">{{ teamBusy ? 'Creating invitation…' : 'Create invitation link' }}</button></form>
      <div v-if="inviteUrl" class="rounded border border-amber-800 p-3 text-sm"><p>Copy this private link now. It cannot be retrieved again.</p><input :value="inviteUrl" readonly aria-label="Private invitation link" class="w-full bg-zinc-900 p-2 my-2" /><button @click="copyText(inviteUrl, 'invite')">{{ copied === 'invite' ? 'Copied' : 'Copy invitation link' }}</button></div>
      <div v-for="m in team.members" :key="m.id" class="flex gap-3 items-center text-sm"><span>Membership #{{ m.id }}</span><select :value="m.role" aria-label="Member role" class="bg-zinc-900 p-2" @change="teamAction(`members/${m.id}`, 'PATCH', { role: ($event.target as HTMLSelectElement).value })"><option value="member">Member</option><option value="viewer">Viewer</option></select><button class="text-red-300" @click="teamAction(`members/${m.id}`, 'DELETE')">Remove member</button></div>
      <p v-if="teamState === 'loading'" role="status" class="text-sm text-zinc-400">Loading project team…</p>
      <button v-if="teamState === 'error'" @click="loadTeam" class="text-sm underline">Retry team</button>
      <p v-if="teamState === 'ready' && !team.members.length" class="text-sm text-zinc-400">No members yet.</p>
      <div v-for="i in team.invitations" :key="i.id" class="flex gap-3 text-sm"><span>Pending {{ i.role }} invitation · expires {{ i.expires_at }}</span><button class="text-red-300" @click="teamAction(`invitations/${i.id}`, 'DELETE')">Revoke invitation</button></div>
    </section>
    <section v-if="showAnalytics" class="border-b border-zinc-800 px-6 py-4" aria-label="Usage analytics">
      <div v-if="analytics" class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
          <h2 class="text-xs font-semibold uppercase text-zinc-400">Requests per day (last {{ analytics.days }} days, retained captures)</h2>
          <div class="mt-2 flex h-28 items-end gap-1">
            <div v-for="d in analytics.daily" :key="d.day" class="flex-1 rounded-t bg-emerald-500/80" :style="{ height: `${Math.max(2, (d.requests / maxDaily) * 100)}%` }" :title="`${d.day}: ${d.requests}`"></div>
          </div>
          <div class="mt-1 flex justify-between text-[10px] text-zinc-400"><span>{{ analytics.daily[0]?.day }}</span><span>{{ analytics.daily[analytics.daily.length - 1]?.day }}</span></div>
        </div>
        <div class="space-y-2 text-sm">
          <h2 class="text-xs font-semibold uppercase text-zinc-400">Plan usage {{ analytics.usage.period }}</h2>
          <p>{{ analytics.usage.requests.toLocaleString() }} / {{ formatQuota(analytics.usage.limit) }} requests</p>
          <div v-if="Number.isSafeInteger(analytics.usage.limit)" class="h-2 rounded bg-zinc-800"><div class="h-2 rounded bg-emerald-500" :style="{ width: `${Math.min(100, (analytics.usage.requests / Math.max(1, analytics.usage.limit)) * 100)}%` }"></div></div>
          <h2 class="pt-2 text-xs font-semibold uppercase text-zinc-400">Per endpoint</h2>
          <ul class="space-y-1 text-xs"><li v-for="e in analytics.endpoints" :key="e.id" class="flex justify-between"><span>{{ e.project }} / {{ e.name }} <span class="text-zinc-400">({{ e.retention_days }}d retention)</span></span><span class="font-mono">{{ e.requests }}</span></li></ul>
          <p v-if="Object.keys(analytics.deliveries).length" class="text-xs text-zinc-400">Deliveries: <span v-for="(n, k) in analytics.deliveries" :key="k" class="mr-2">{{ k }} {{ n }}</span></p>
          <p class="text-[11px] text-zinc-400">{{ analytics.note }}</p>
        </div>
      </div>
      <p v-else class="text-sm text-zinc-400">Loading analytics...</p>
    </section>
    <div v-if="usage" class="border-b border-zinc-800 px-6 py-2 text-xs text-zinc-400">{{ usage.managed ? usage.plan + ' plan' : 'Self-hosted' }} · {{ usage.requests.toLocaleString() }} requests this month <span v-if="usage.managed">/ {{ formatQuota(usage.limits.requests) }}</span> · Retention up to {{ usage.limits.retention }} days <span v-if="!usage.billing_enabled">· Paid checkout not enabled</span></div>
    <details v-if="usage && usage.managed" class="border-b border-zinc-800 px-6 py-2 text-sm">
      <summary class="cursor-pointer min-h-11 text-zinc-300">Usage and billing</summary>
      <div role="progressbar" aria-label="Monthly request quota used" :aria-valuenow="Math.min(usage.requests, usage.limits.requests)" :aria-valuemin="0" :aria-valuemax="usage.limits.requests" class="my-3 h-2 rounded bg-zinc-800"><div class="h-2 rounded bg-emerald-400" :style="{ width: usagePercent + '%' }"></div></div>
      <p class="mt-2 text-zinc-400">{{ usage.requests.toLocaleString() }} / {{ formatQuota(usage.limits.requests) }} requests in {{ usage.period }} UTC. Clearing history does not reset usage.</p>
      <p v-if="!usage.billing_enabled" class="mt-2 text-amber-300">Paid checkout and the billing portal are unavailable on this deployment. No payment can be taken.</p>
      <div v-else class="mt-2 flex flex-wrap gap-2"><button v-for="plan in ['developer','pro','team']" :key="plan" :disabled="billingBusy" class="rounded bg-zinc-800 px-3 py-1 capitalize disabled:opacity-50" @click="openBilling(plan)">{{ plan }} checkout</button><button :disabled="billingBusy" class="rounded bg-zinc-800 px-3 py-1 disabled:opacity-50" @click="openBilling()">Manage subscription</button></div>
      
    </details>
    <div class="grid flex-1 grid-cols-1 lg:grid-cols-12">
      <aside class="min-w-0 border-b lg:col-span-3 lg:border-r border-zinc-800 p-4 space-y-4">
        <h2 class="text-sm font-semibold">Projects &amp; endpoints</h2>
        <p v-if="projectState === 'loading'" role="status" class="text-sm text-zinc-400">Loading workspace…</p>
        <p v-else-if="projectState === 'error'" role="alert" class="text-sm text-red-300">Could not load workspace. <button @click="loadProjects" class="underline">Retry</button></p>
        <p v-else-if="!projects.length" class="text-sm text-zinc-400">Create a project to get started.</p>
        <form class="flex gap-2" @submit.prevent="addProject">
          <input v-model="newProject" placeholder="New project" aria-label="New project" class="flex-1 min-w-0 rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-sm" />
          <button class="rounded bg-emerald-500 px-3 py-1 text-sm font-medium text-zinc-950">Add</button>
        </form>
        <div v-if="projects.length">
          <select :value="selectedProjectId" @change="chooseProject(Number(($event.target as HTMLSelectElement).value))" aria-label="Project" class="w-full rounded bg-zinc-900 border border-zinc-700 p-1 text-sm mb-2">
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <p class="text-xs text-zinc-400 mb-2">Project role: {{ projectRole }}</p>
          <ProjectTokens v-if="selectedProjectId" :key="selectedProjectId" :project="selectedProjectId" :role="projectRole || ''" :api="api" />
          <button v-if="projectRole === 'owner'" class="mb-3 text-xs text-emerald-300" @click="resetTeam(); showTeam = true; loadTeam()">Manage project team</button>
          <form v-if="projectRole === 'owner'" class="flex gap-1 mb-2" @submit.prevent="renameProject">
            <input v-model="renameValue" placeholder="Rename project" aria-label="Rename project" class="flex-1 min-w-0 rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-xs" />
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs">Rename</button>
            <button type="button" class="rounded bg-zinc-800 px-2 py-1 text-xs text-red-400" @click="deleteProject">Delete</button>
          </form>
          <form v-if="projectRole !== 'viewer'" class="flex gap-2" @submit.prevent="addEndpoint">
            <input v-model="newEndpoint" placeholder="New endpoint" aria-label="New endpoint" class="flex-1 min-w-0 rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-sm" />
            <button class="rounded bg-zinc-800 px-3 py-1 text-sm">Create</button>
          </form>
        </div>
        <p v-if="projectState === 'ready' && projects.length && !visibleEndpoints.length" class="text-sm text-zinc-400">Create an endpoint to receive webhooks.</p>
        <div class="space-y-1">
          <button v-for="e in visibleEndpoints" :key="e.id" :aria-pressed="e.id === activeEndpointId" class="w-full text-left rounded px-2 py-1.5 text-sm" :class="e.id === activeEndpointId ? 'bg-zinc-800 text-emerald-400' : 'text-zinc-300'" @click="showTeam = false; chooseEndpoint(e.id)">{{ e.name }}</button>
        </div>
      </aside>
      <section class="min-w-0 border-b lg:col-span-4 lg:border-r border-zinc-800 p-4 space-y-3">
        <h2 class="text-sm font-semibold">Request history</h2>
        <p class="text-xs text-zinc-400"><span role="status">{{ refreshStatus }}</span><span v-if="lastRefresh"> · Last successful refresh {{ lastRefresh }}</span> <button class="underline" @click="live = true; pollRequests()">Retry refresh</button></p>
        <div v-if="activeEndpoint" class="rounded-xl border border-zinc-800 bg-zinc-900 p-3 space-y-2">
          <div class="text-xs text-zinc-400">Receive URL · keep this secret</div>
          <code class="block break-all text-xs text-emerald-400">{{ captureUrl }}</code>
          <div class="flex flex-wrap gap-2">
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="copyText(captureUrl, 'url')">{{ copied === 'url' ? 'Copied' : 'Copy URL' }}</button>
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="exportJson('json')">Export JSON</button>
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="exportJson('ndjson')">NDJSON</button><button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="exportJson('curl')">cURL</button><button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="exportJson('csv')">CSV</button>
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs text-red-400" @click="clearHistory">Clear</button>
          </div>
          <p class="text-xs text-zinc-400">Exports contain the newest 100 matching retained requests, not the whole history. Payloads can contain secrets; store exports securely. For older pages use the API's before cursor.</p>
        </div>
        <div v-if="activeEndpoint" class="rounded border border-zinc-800 p-3 space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">Endpoint settings</h2>
            <span v-if="forwardHealth" class="rounded-full border border-zinc-700 px-2 py-1 text-xs" :class="healthColors[forwardHealth]">{{ healthLabels[forwardHealth] }}</span>
          </div>
          <form v-if="canEdit" class="space-y-2" @submit.prevent="saveForward">
            <label class="block text-xs text-zinc-400" for="forward-url">Automatic forwarding destination</label>
            <input id="forward-url" v-model="forwardingUrl" type="url" :placeholder="savedForwardDestination || 'https://example.com/webhook'" class="w-full rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-sm" :required="!savedForwardDestination" />
            <p v-if="savedForwardDestination" class="text-xs text-zinc-400">Leave the URL blank to keep the saved destination.</p>
            <label class="flex gap-2 text-xs"><input v-model="forwardingEnabled" type="checkbox" /> Enable forwarding</label>
            <fieldset class="space-y-2">
              <legend class="text-xs font-semibold">Forwarding conditions (optional)</legend>
              <p class="text-xs text-zinc-400">All configured conditions must match. Leave fields blank to remove conditions.</p>
              <label v-for="(label, key) in forwardFields" :key="key" class="block text-xs">
                {{ label }}
                <select v-if="key === 'method'" v-model="forwardFilter.method" class="mt-1 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1">
                  <option :value="undefined">Any method</option>
                  <option v-for="verb in ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']" :key="verb">{{ verb }}</option>
                </select>
                <input v-else v-model="forwardFilter[key]" class="mt-1 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1" />
              </label>
            </fieldset>
            <p class="text-xs text-zinc-400">Only public destinations; credentials, redirects and private networks are blocked. Sensitive headers are omitted.</p>
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs">Save forwarding</button>
          </form>
          <form v-if="canEdit" class="flex items-center gap-2" @submit.prevent="saveRetention">
            <label for="retention" class="text-xs text-zinc-400">Retain for</label>
            <select id="retention" v-model.number="retentionDays" class="rounded bg-zinc-900 border border-zinc-700 p-1 text-xs"><option v-for="d in Math.min(365, usage?.limits?.retention ?? 7)" :key="d" :value="d">{{ d }} {{ d === 1 ? 'day' : 'days' }}</option></select>
            <button class="rounded bg-zinc-800 px-2 py-1 text-xs">Save</button>
          </form>
          
        </div>
        <input v-model="historyQuery" placeholder="Search bodies, headers, query..." aria-label="Search history" class="w-full rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-sm" @keyup.enter="loadRequests(false)" @change="loadRequests(false)" />
        <select v-model="deliveryStatus" aria-label="Delivery status filter" class="w-full rounded bg-zinc-900 border border-zinc-700 p-1 text-sm" @change="loadRequests(false)">
          <option value="">Any delivery status</option><option value="none">Never delivered</option><option value="succeeded">Delivered</option><option value="failed">Failed</option><option value="retrying">Retrying</option><option value="blocked">Blocked</option><option value="queued">Queued</option>
        </select>
        <select v-model="methodFilter" aria-label="Method filter" class="w-full rounded bg-zinc-900 border border-zinc-700 p-1 text-sm" @change="loadRequests">
          <option value="">All methods</option><option>POST</option><option>GET</option><option>PUT</option><option>PATCH</option><option>DELETE</option>
        </select>
        <details class="text-xs text-zinc-400"><summary>More filters</summary><div class="grid grid-cols-2 gap-2 mt-2"><label>From<input v-model="fromDate" type="datetime-local" class="w-full bg-zinc-900" /></label><label>To<input v-model="toDate" type="datetime-local" class="w-full bg-zinc-900" /></label><input v-model="contentType" placeholder="Content type" aria-label="Content type filter" class="bg-zinc-900 p-1" /><input v-model="minSize" type="number" placeholder="Min bytes" aria-label="Minimum size" class="bg-zinc-900 p-1" /><input v-model="maxSize" type="number" placeholder="Max bytes" aria-label="Maximum size" class="bg-zinc-900 p-1" /><button @click="loadRequests(false)" class="rounded bg-zinc-800">Apply</button></div></details>
        <details v-if="canEdit" class="rounded border border-zinc-800 p-3 space-y-2">
          <summary class="cursor-pointer text-sm font-semibold">Bulk replay</summary>
          <p class="text-xs text-zinc-400">Maximum 20 requests per batch. All selected or filtered matches must fit; nothing is silently truncated. Public destinations only. Replays may cause real side effects.</p>
          <form class="space-y-2" @submit.prevent="bulkReplay"><fieldset :disabled="batchBusy" class="space-y-2">
            <select v-model="batchMode" aria-label="Bulk replay mode" class="w-full rounded bg-zinc-900 p-2 text-sm"><option value="selected">Selected requests ({{ batchIds.length }})</option><option value="filtered">Current filter matches (up to 20)</option></select>
            <input v-model="batchUrl" required type="url" aria-label="Bulk replay destination" placeholder="https://example.com/webhook" class="w-full rounded border border-zinc-700 bg-zinc-900 p-2 text-sm" />
            <p class="text-xs text-zinc-400">{{ batchMode === 'selected' ? `Selected: ${batchIds.length} of 20 maximum.` : 'Replays up to 20 of the newest requests matching the current filters.' }}</p>
            <label class="flex gap-2 text-xs"><input v-model="batchConfirmed" type="checkbox" required /> I authorize every replay in this batch and its side effects.</label>
            <button :disabled="!batchConfirmed || batchBusy || (batchMode === 'selected' && (!batchIds.length || batchIds.length > 20))" class="rounded bg-emerald-400 px-3 py-2 text-xs font-semibold text-zinc-950 disabled:opacity-50">{{ batchBusy ? 'Queueing…' : 'Queue bulk replay' }}</button>
          </fieldset></form>
          <div v-if="batchMode === 'selected'" class="max-h-48 overflow-auto space-y-2"><label v-for="r in requests" :key="r.id" class="flex gap-2 text-xs"><input v-model="batchIds" type="checkbox" :value="r.id" :aria-label="'Select request ' + r.id" /> #{{ r.id }} {{ r.method }} · {{ r.received_at }}</label></div>
        </details>
        <div class="space-y-1">
          <button v-for="r in requests" :key="r.id" :aria-pressed="r.id === selectedId" class="w-full text-left rounded border border-zinc-800 p-2 text-xs" :class="r.id === selectedId ? 'bg-zinc-900 border-emerald-500' : newRequestIds.includes(r.id) ? 'bg-emerald-950 border-emerald-800' : ''" @click="selectRequest(r.id)">
            <span class="font-mono font-semibold text-emerald-400">{{ r.method }}</span> <span class="text-zinc-400">{{ r.size }}B - {{ r.received_at }}</span>
          </button>
          <button v-if="nextBefore" class="w-full text-xs text-emerald-400 p-2" @click="loadRequests(true)">Load older requests</button>
          <p v-if="historyState === 'loading'" role="status" class="py-4 text-sm text-zinc-400">Loading requests…</p>
          <p v-else-if="historyState === 'error'" role="alert" class="py-4 text-sm text-red-300">Could not refresh requests. <button class="underline" @click="loadRequests(false)">Retry</button></p>
          <div v-else-if="historyState === 'ready' && !requests.length" class="public-panel space-y-3"><template v-if="hasFilters"><h3 class="font-medium">No requests match these filters</h3><button class="public-secondary" @click="clearFilters">Clear filters</button></template><template v-else><h3 class="font-medium">Send a test request</h3><p class="text-sm leading-6 text-zinc-400">Set RECEIVER_URL privately to the URL above. Anyone who knows this URL can submit requests.</p><CodeBlock :code="exampleRequest" /></template></div>
        </div>
      </section>
      <section class="min-w-0 lg:col-span-5 p-4 space-y-3">
        <div class="flex items-center justify-between gap-3"><h2 class="text-sm font-semibold">Request inspector</h2><button class="rounded-full border px-3 py-1 text-xs" :class="live ? 'border-emerald-700 text-emerald-300' : 'border-zinc-600 text-zinc-400'" :aria-pressed="live" @click="live = !live; if (live) pollRequests()">{{ refreshStatus }}</button></div><p v-if="arrivals" role="status" class="text-xs text-emerald-300">{{ arrivals }} new request{{ arrivals === 1 ? '' : 's' }} arrived. <button class="underline" @click="arrivals = 0; newRequestIds = []">Dismiss</button></p>
        <details v-if="activeEndpointId" class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 space-y-3">
          <summary class="cursor-pointer text-sm font-semibold">Receiver response &amp; signature verification</summary>
          <p class="text-xs text-zinc-400">Custom errors still capture requests. Verification is diagnostic, not an ingestion gate. Timestamped signatures allow five minutes of clock skew.</p>
          <p v-if="!settingsReady" role="status" class="text-xs text-zinc-400">Receiver settings not loaded yet. Select the endpoint again if loading fails.</p>
          <form class="grid gap-3 text-sm" @submit.prevent="saveReceiver"><fieldset :disabled="!canEdit || receiverBusy || !settingsReady" class="grid gap-3 disabled:opacity-60">
            <label>HTTP response status<select v-model.number="receiver.response_status" class="block w-full rounded bg-zinc-950 p-2"><option v-for="code in [200,201,202,204,400,401,403,404,409,422,429,500,502,503]" :key="code" :value="code">{{ code }}</option></select></label>
            <label>Content-Type<select v-model="receiver.response_content_type" class="block w-full rounded bg-zinc-950 p-2"><option>application/json</option><option>text/plain</option></select></label>
            <label>Response body (16 KiB maximum; ignored for 204)<textarea v-model="receiver.response_body" maxlength="16384" class="block w-full rounded bg-zinc-950 p-2 font-mono" /></label>
            <label>Signature provider<select v-model="receiver.signature_provider" class="block w-full rounded bg-zinc-950 p-2"><option v-for="mode in ['auto','hmac-sha256','stripe','github','slack']" :key="mode">{{ mode }}</option></select></label>
            <label>Signing secret <span class="text-zinc-400">{{ receiver.has_signing_secret ? '(configured; blank keeps existing)' : '(optional)' }}</span><input v-model="signingSecret" type="password" autocomplete="new-password" class="block w-full rounded bg-zinc-950 p-2" /></label>
            <label v-if="receiver.has_signing_secret"><input v-model="clearSecret" type="checkbox" /> Remove signing secret</label>
            <button class="rounded bg-emerald-400 px-3 py-2 font-semibold text-zinc-950">{{ receiverBusy ? 'Saving receiver…' : 'Save receiver settings' }}</button>
          </fieldset></form><p v-if="!canEdit" class="text-xs text-zinc-400">Viewer access: settings are read-only.</p>
        </details>
        <template v-if="detail">
          <RequestDiff :endpoint="activeEndpointId!" :current="detail" :requests="requests" :api="api" />
          <p v-if="detail.payload.signature" class="rounded border border-zinc-700 p-2 text-xs" :class="detail.payload.signature.status === 'verified' ? 'text-emerald-300' : 'text-amber-300'">Signature: {{ detail.payload.signature.status }} <span v-if="detail.payload.signature.provider">· {{ detail.payload.signature.provider }}</span></p>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <input v-model="search" placeholder="Search payload..." aria-label="Search payload" class="rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-sm" />
            <span v-if="search" class="text-xs text-zinc-400" aria-live="polite">{{ searchMatches }} match{{ searchMatches === 1 ? '' : 'es' }}</span>
            <div class="flex flex-wrap gap-1">
              <button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="copyText(prettyBody, 'body')">{{ copied === 'body' ? 'Copied' : 'Copy body' }}</button>
              <button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="copyText(JSON.stringify(detail.payload.headers, null, 2), 'headers')">{{ copied === 'headers' ? 'Copied' : 'Copy headers' }}</button>
              <button class="rounded bg-zinc-800 px-2 py-1 text-xs" @click="copyText(curlCommand, 'curl')">{{ copied === 'curl' ? 'Copied' : 'Copy as cURL' }}</button>
            </div>
          </div>
          <form v-if="canEdit" class="space-y-2 rounded border border-zinc-800 p-3" @submit.prevent="replay">
            <label class="block text-xs text-zinc-400" for="replay-url">Replay this request to a public URL</label>
            <div class="flex gap-2"><input id="replay-url" v-model="replayUrl" type="url" required placeholder="https://example.com/webhook" class="flex-1 min-w-0 rounded bg-zinc-900 border border-zinc-700 px-2 py-1 text-sm" /><button :disabled="loading || !replayConfirmed" class="rounded bg-emerald-500 disabled:opacity-40 px-3 py-1 text-xs font-medium text-zinc-950">Queue replay</button></div>
            <label class="flex gap-2 text-xs text-amber-300"><input v-model="replayConfirmed" type="checkbox" required /> I confirm this may trigger real side effects at the destination.</label><p class="text-xs text-zinc-400">Replay sends the captured body, non-sensitive headers and query parameters. Secrets and redirects are excluded.</p>
          </form>
          <div><h2 class="text-xs font-semibold uppercase text-zinc-400">Payload</h2><pre class="mt-1 max-h-72 overflow-auto rounded bg-zinc-900 p-3 text-xs text-zinc-200 whitespace-pre-wrap break-all" v-html="highlightedBody"></pre></div>
          <div><h2 class="text-xs font-semibold uppercase text-zinc-400">Headers (secrets redacted)</h2><pre class="mt-1 max-h-48 overflow-auto rounded bg-zinc-900 p-3 text-xs">{{ JSON.stringify(detail.payload.headers, null, 2) }}</pre></div>
          <div><h2 class="text-xs font-semibold uppercase text-zinc-400">Query</h2><pre class="mt-1 overflow-x-auto rounded bg-zinc-900 p-3 text-xs">{{ JSON.stringify(detail.payload.query, null, 2) }}</pre></div>
        </template>
        <p v-else class="text-sm text-zinc-400">Select a captured webhook to inspect headers, query parameters, and payload.</p>
        <section v-if="activeEndpoint" class="space-y-2 border-t border-zinc-800 pt-4" aria-label="Delivery history">
          <h2 class="text-sm font-semibold">Delivery history</h2>
          <p v-if="!deliveries.length" class="text-xs text-zinc-400">No deliveries yet.</p>
          <div v-for="d in deliveries" :key="d.id" class="rounded bg-zinc-900 p-3 text-xs space-y-1">
            <div class="flex justify-between"><strong>{{ d.kind }} · {{ d.status }}</strong><span>{{ d.created_at }}</span></div>
            <p>Attempts: {{ d.attempt_count }} <span v-if="d.last_status_code">· HTTP {{ d.last_status_code }}</span></p>
            <button v-if="canEdit && d.status === 'failed'" class="rounded border border-zinc-600 px-2 py-1 text-emerald-300" @click="retryDelivery(d.id)">Retry delivery</button>
            <p v-if="d.last_error" class="text-red-400">{{ d.last_error }}</p><p v-if="d.next_attempt_at">Next attempt: {{ d.next_attempt_at }}</p>
            <div v-for="a in attempts[d.id] || []" :key="a.id" class="text-zinc-400">Attempt {{ a.attempt_number }} · {{ a.status_code ? `HTTP ${a.status_code}` : 'No response' }} · {{ a.duration_ms }}ms <span v-if="a.error">· {{ a.error }}</span></div>
          </div>
        </section>
      </section>
    </div>
  </main>
</template>
