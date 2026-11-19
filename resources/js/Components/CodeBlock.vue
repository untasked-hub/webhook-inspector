<script setup lang="ts">
import { ref } from 'vue'
const props = defineProps<{ code: string; language?: string }>()
const status = ref('')
async function copy() {
  try { await navigator.clipboard.writeText(props.code); status.value = 'Copied' }
  catch { status.value = 'Could not copy; select text instead.' }
}
</script>
<template><div class="min-w-0 overflow-hidden rounded-xl border border-zinc-700 bg-zinc-950"><div class="flex items-center justify-between gap-3 border-b border-zinc-800 px-4"><span class="text-xs text-zinc-400">{{ language || 'Shell' }}</span><button type="button" class="min-h-11 px-3 text-sm text-emerald-300" @click="copy">Copy</button></div><pre tabindex="0" class="overflow-x-auto p-4 text-sm leading-6 text-zinc-200"><code>{{ code }}</code></pre><p v-if="status" role="status" class="px-4 pb-3 text-xs text-zinc-300">{{ status }}</p></div></template>
