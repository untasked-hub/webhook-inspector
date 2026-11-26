<script setup lang="ts">
import { computed, ref } from 'vue'
const props = defineProps<{ code: string; language?: string }>()
const status = ref('')
const lang = computed(() => (props.language || 'shell').toLowerCase())

const COLORS: Record<string, string> = {
  comment: '#8b949e',
  string: '#a5d6a7',
  keyword: '#ffab70',
  command: '#7dd3fc',
  flag: '#c4b5fd',
  variable: '#fcd34d',
  number: '#f0b429',
  operator: '#fda4af',
  plain: '#e6edf3',
}

type Token = { text: string; color: string }

function highlightShell(src: string): Token[] {
  const tokens: Token[] = []
  const re = /(#[^\n]*)|("(?:[^"\\\n]|\\.)*"|'[^'\n]*')|(\$[A-Za-z_{][A-Za-z0-9_}]*|\$\{[^}]*\})|(--?[A-Za-z][A-Za-z0-9-]*)|(\b\d+(?:\.\d+)?\b)|(\|\||&&|\||;|>|>>|<)|([A-Za-z_][A-Za-z0-9_.\/-]*)/g
  let last = 0
  let m: RegExpExecArray | null
  let expectCommand = true
  while ((m = re.exec(src)) !== null) {
    if (m.index > last) tokens.push({ text: src.slice(last, m.index), color: COLORS.plain })
    const [full, comment, str, variable, flag, num, op, word] = m
    if (comment) tokens.push({ text: full, color: COLORS.comment })
    else if (str) tokens.push({ text: full, color: COLORS.string })
    else if (variable) tokens.push({ text: full, color: COLORS.variable })
    else if (flag) tokens.push({ text: full, color: COLORS.flag })
    else if (num) tokens.push({ text: full, color: COLORS.number })
    else if (op) { tokens.push({ text: full, color: COLORS.operator }); expectCommand = true }
    else if (word) {
      const isCommand = expectCommand && !/^\d/.test(word)
      tokens.push({ text: full, color: isCommand ? COLORS.command : COLORS.plain })
      expectCommand = false
    }
    last = m.index + full.length
  }
  if (last < src.length) tokens.push({ text: src.slice(last), color: COLORS.plain })
  return tokens
}

function highlightJson(src: string): Token[] {
  const tokens: Token[] = []
  const re = /("(?:[^"\\\n]|\\.)*")(\s*:)?|(-?\b\d+(?:\.\d+)?\b)|(\btrue\b|\bfalse\b|\bnull\b)/g
  let last = 0
  let m: RegExpExecArray | null
  while ((m = re.exec(src)) !== null) {
    if (m.index > last) tokens.push({ text: src.slice(last, m.index), color: COLORS.plain })
    if (m[1] !== undefined) tokens.push({ text: m[1], color: m[2] ? '#7dd3fc' : COLORS.string }, ...(m[2] ? [{ text: m[2], color: COLORS.plain }] : []))
    else if (m[3] !== undefined) tokens.push({ text: m[3], color: COLORS.number })
    else if (m[4] !== undefined) tokens.push({ text: m[4], color: COLORS.keyword })
    last = m.index + m[0].length
  }
  if (last < src.length) tokens.push({ text: src.slice(last), color: COLORS.plain })
  return tokens
}

function highlightGeneric(src: string): Token[] {
  const tokens: Token[] = []
  const re = /(#[^\n]*|\/\/[^\n]*)|("(?:[^"\\\n]|\\.)*"|'[^'\n]*')|(\b\d+(?:\.\d+)?\b)|(\b(?:const|let|var|function|return|if|else|for|while|true|false|null|new|import|from|export|await|async)\b)/g
  let last = 0
  let m: RegExpExecArray | null
  while ((m = re.exec(src)) !== null) {
    if (m.index > last) tokens.push({ text: src.slice(last, m.index), color: COLORS.plain })
    if (m[1]) tokens.push({ text: m[1], color: COLORS.comment })
    else if (m[2]) tokens.push({ text: m[2], color: COLORS.string })
    else if (m[3]) tokens.push({ text: m[3], color: COLORS.number })
    else if (m[4]) tokens.push({ text: m[4], color: COLORS.keyword })
    last = m.index + m[0].length
  }
  if (last < src.length) tokens.push({ text: src.slice(last), color: COLORS.plain })
  return tokens
}

const tokens = computed<Token[]>(() => {
  const l = lang.value
  if (['json', 'jsonc'].includes(l)) return highlightJson(props.code)
  if (['sh', 'shell', 'bash', 'zsh', 'curl', 'console', 'shell-session'].includes(l)) return highlightShell(props.code)
  return highlightGeneric(props.code)
})

async function copy() {
  try { await navigator.clipboard.writeText(props.code); status.value = 'Copied' }
  catch { status.value = 'Could not copy; select text instead.' }
}
</script>
<template><div class="min-w-0 overflow-hidden rounded-xl border border-zinc-700/80 bg-[#0b1220] shadow-[0_0_0_1px_rgba(255,255,255,0.02),0_12px_32px_-12px_rgba(0,0,0,0.7)]"><div class="flex items-center justify-between gap-3 border-b border-zinc-800 bg-[#111a2e] px-4 py-2"><span class="flex items-center gap-2"><span class="flex gap-1.5" aria-hidden="true"><span class="h-2.5 w-2.5 rounded-full bg-red-400/80"></span><span class="h-2.5 w-2.5 rounded-full bg-amber-400/80"></span><span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80"></span></span><span class="font-mono text-[11px] uppercase tracking-wider text-zinc-400">{{ language || 'Shell' }}</span></span><button type="button" class="min-h-9 rounded-md border border-zinc-700/70 bg-zinc-800/60 px-3 text-xs font-medium text-zinc-200 transition hover:border-zinc-600 hover:bg-zinc-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400" @click="copy">Copy</button></div><pre tabindex="0" class="overflow-x-auto bg-[#0b1220] p-4 font-mono text-[13px] leading-6"><code><span v-for="(t, i) in tokens" :key="i" :style="{ color: t.color }">{{ t.text }}</span></code></pre><p v-if="status" role="status" class="border-t border-zinc-800 bg-[#0b1220] px-4 py-2 text-xs text-zinc-400">{{ status }}</p></div></template>
