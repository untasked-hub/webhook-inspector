<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import PublicLayout from '../Components/PublicLayout.vue'

interface PlanOption {
  key: string
  name: string
  price: number
  requests: number
  retention: number
}

const props = defineProps<{
  plans: PlanOption[]
  selected_plan: string
  billing_enabled: boolean
  billing_mode: string | null
  managed: boolean
  current_plan: string
  subscription_status: string
  cancel_at_period_end: boolean
  renews_at: string | null
  has_billing_account: boolean
  has_active_paid_sub: boolean
  tos_version: string
  tos_version_label: string
  checkout_cancelled: boolean
}>()

const activePlanKey = ref(props.selected_plan || 'developer')
const tosAccepted = ref(false)
const busy = ref(false)
const errorMessage = ref('')

const activePlan = computed(() =>
  props.plans.find((p) => p.key === activePlanKey.value) ?? props.plans[0] ?? null
)

const planLabels: Record<string, string> = {
  free: 'Free',
  developer: 'Developer',
  pro: 'Pro',
  team: 'Team',
}

function selectPlan(key: string) {
  activePlanKey.value = key
  errorMessage.value = ''
  if (typeof window !== 'undefined') {
    const url = new URL(window.location.href)
    url.searchParams.set('plan', key)
    window.history.replaceState({}, '', `${url.pathname}${url.search}`)
  }
}

function xsrfToken(): string {
  return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
}

async function startCheckout() {
  if (busy.value || !props.billing_enabled || props.has_active_paid_sub) return
  if (!tosAccepted.value) {
    errorMessage.value = 'You must agree to the Terms of Service before starting checkout.'
    return
  }
  const plan = activePlan.value?.key
  if (!plan) return

  busy.value = true
  errorMessage.value = ''
  try {
    const response = await fetch('/api/billing/checkout', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
      },
      body: JSON.stringify({ plan, tos_accepted: true }),
    })
    if (response.status === 401 || response.status === 419) {
      window.location.assign(`/login?plan=${encodeURIComponent(plan)}`)
      return
    }
    const payload = await response.json().catch(() => ({}))
    if (!response.ok || typeof payload.url !== 'string') {
      const tosErr = payload?.errors?.tos_accepted?.[0]
      errorMessage.value = tosErr || payload?.message || 'Unable to start checkout right now.'
      return
    }
    const destination = new URL(payload.url)
    if (destination.protocol !== 'https:' || destination.hostname !== 'checkout.stripe.com') {
      errorMessage.value = 'Billing provider returned an unexpected checkout URL.'
      return
    }
    window.location.assign(destination.href)
  } catch {
    errorMessage.value = 'Unable to reach the billing endpoint. Please try again.'
  } finally {
    busy.value = false
  }
}

async function openBillingPortal() {
  if (busy.value || !props.billing_enabled) return
  busy.value = true
  errorMessage.value = ''
  try {
    const response = await fetch('/api/billing/portal', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
      },
      body: JSON.stringify({}),
    })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok || typeof payload.url !== 'string') {
      errorMessage.value = payload?.message || 'Unable to open the billing portal right now.'
      return
    }
    const destination = new URL(payload.url)
    if (destination.protocol !== 'https:' || destination.hostname !== 'billing.stripe.com') {
      errorMessage.value = 'Billing provider returned an unexpected portal URL.'
      return
    }
    window.location.assign(destination.href)
  } catch {
    errorMessage.value = 'Unable to reach the billing portal right now.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Head title="Upgrade plan" />
  <PublicLayout>
    <div class="mx-auto max-w-5xl px-6 py-14">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-widest text-emerald-400">Managed billing</p>
          <h1 class="mt-2 text-4xl font-semibold tracking-tight">Upgrade your plan</h1>
          <p class="mt-3 max-w-2xl text-zinc-400">
            Select a plan, review the Terms of Service, and continue directly to Stripe Checkout.
          </p>
        </div>
        <div class="flex items-center gap-3 text-sm">
          <Link href="/pricing" class="rounded-lg border border-zinc-800 px-3 py-2 text-zinc-300 hover:border-zinc-700 hover:text-white">
            Compare plans
          </Link>
          <Link href="/dashboard" class="rounded-lg border border-zinc-800 px-3 py-2 text-zinc-300 hover:border-zinc-700 hover:text-white">
            Dashboard
          </Link>
        </div>
      </div>

      <div
        v-if="checkout_cancelled"
        role="status"
        class="mt-6 rounded-xl border border-zinc-700 bg-zinc-900/90 px-4 py-3 text-sm text-zinc-200"
      >
        Checkout was cancelled. No changes were made to your subscription.
      </div>

      <div
        v-if="errorMessage"
        role="alert"
        class="mt-6 rounded-xl border border-rose-500/40 bg-rose-950/40 px-4 py-3 text-sm text-rose-200"
      >
        {{ errorMessage }}
      </div>

      <div
        v-if="!billing_enabled"
        class="mt-8 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6"
      >
        <h2 class="text-lg font-semibold text-zinc-100">Paid checkout is unavailable</h2>
        <p class="mt-2 text-sm text-zinc-400">
          Managed billing is not configured on this deployment. No payment can be taken.
        </p>
        <div class="mt-5">
          <Link href="/dashboard" class="public-secondary">Return to dashboard</Link>
        </div>
      </div>

      <div
        v-else-if="has_active_paid_sub"
        class="mt-8 rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-6"
      >
        <p class="text-xs font-semibold uppercase tracking-widest text-emerald-300">Active subscription</p>
        <h2 class="mt-2 text-xl font-semibold text-white">
          Current plan: {{ planLabels[current_plan] ?? current_plan }}
        </h2>
        <p class="mt-2 text-sm text-zinc-300">
          You already have a {{ subscription_status }} subscription. Use the Stripe Billing Portal to switch plans, update payment details, or cancel.
        </p>
        <div class="mt-5 flex flex-wrap gap-3">
          <button
            type="button"
            :disabled="busy"
            class="public-primary disabled:opacity-60"
            @click="openBillingPortal"
          >
            {{ busy ? 'Opening Stripe…' : 'Manage billing in Stripe' }}
          </button>
          <Link href="/dashboard" class="public-secondary">Return to dashboard</Link>
        </div>
      </div>

      <div v-else class="mt-8 grid gap-8 lg:grid-cols-[1.2fr_1fr]">
        <section aria-label="Choose a paid plan" class="space-y-4">
          <h2 class="text-sm font-semibold uppercase tracking-widest text-zinc-400">1. Choose plan</h2>
          <div class="grid gap-3">
            <button
              v-for="plan in plans"
              :key="plan.key"
              type="button"
              :aria-pressed="activePlanKey === plan.key"
              :data-plan-option="plan.key"
              :class="[
                'flex items-center justify-between rounded-2xl border p-5 text-left transition',
                activePlanKey === plan.key
                  ? 'border-emerald-400 bg-emerald-950/25 ring-1 ring-emerald-400/50'
                  : 'border-zinc-800 bg-zinc-900/50 hover:border-zinc-700',
              ]"
              @click="selectPlan(plan.key)"
            >
              <div>
                <div class="flex items-center gap-2">
                  <span class="text-lg font-semibold text-white">{{ plan.name }}</span>
                  <span
                    v-if="activePlanKey === plan.key"
                    class="rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-xs font-medium text-emerald-300"
                  >
                    Selected
                  </span>
                </div>
                <p class="mt-1 text-sm text-zinc-400">
                  {{ plan.requests.toLocaleString() }} requests/month · {{ plan.retention }} day retention
                </p>
              </div>
              <div class="text-right">
                <p class="text-2xl font-bold text-white">${{ plan.price }}</p>
                <p class="text-xs text-zinc-400">per month</p>
              </div>
            </button>
          </div>
        </section>

        <section
          v-if="activePlan"
          aria-label="Checkout summary and Terms of Service"
          class="flex flex-col justify-between rounded-2xl border border-zinc-800 bg-zinc-900/70 p-6"
        >
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-widest text-zinc-400">2. Review & agree</h2>
            <div class="mt-4 rounded-xl border border-zinc-800 bg-zinc-950/60 p-4">
              <div class="flex items-baseline justify-between">
                <span class="font-semibold text-white">{{ activePlan.name }} plan</span>
                <span class="font-mono text-lg font-semibold text-emerald-300">${{ activePlan.price }}/mo</span>
              </div>
              <dl class="mt-3 space-y-1.5 text-sm text-zinc-400">
                <div class="flex justify-between">
                  <dt>Current plan</dt>
                  <dd class="text-zinc-200">{{ planLabels[current_plan] ?? current_plan }}</dd>
                </div>
                <div class="flex justify-between">
                  <dt>Monthly quota</dt>
                  <dd class="font-mono text-zinc-200">{{ activePlan.requests.toLocaleString() }} req/mo</dd>
                </div>
                <div class="flex justify-between">
                  <dt>Retention window</dt>
                  <dd class="font-mono text-zinc-200">{{ activePlan.retention }} days</dd>
                </div>
                <div class="flex justify-between">
                  <dt>Billing cycle</dt>
                  <dd class="text-zinc-200">Monthly, cancel anytime</dd>
                </div>
              </dl>
            </div>

            <div class="mt-5 rounded-xl border border-zinc-800 bg-zinc-950/50 p-4">
              <label for="tos-consent" class="flex cursor-pointer items-start gap-3 text-sm leading-6 text-zinc-200">
                <input
                  id="tos-consent"
                  v-model="tosAccepted"
                  type="checkbox"
                  name="tos_accepted"
                  required
                  class="mt-1 h-4 w-4 shrink-0 rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-400"
                />
                <span>
                  I agree to the
                  <a
                    href="/terms"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-emerald-300 underline hover:text-emerald-200"
                  >Terms of Service</a>
                  (dated {{ tos_version_label }}) and acknowledge the
                  <a
                    href="/privacy"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-emerald-300 underline hover:text-emerald-200"
                  >Privacy Policy</a>,
                  including recurring monthly billing until cancelled.
                </span>
              </label>
            </div>
          </div>

          <div class="mt-6 space-y-2">
            <button
              type="button"
              data-testid="start-checkout-button"
              :disabled="!tosAccepted || busy"
              class="public-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-50"
              @click="startCheckout"
            >
              {{
                busy
                  ? 'Opening Stripe…'
                  : `Continue to ${activePlan.name} Stripe Checkout`
              }}
            </button>
            <p v-if="!tosAccepted" class="text-center text-xs text-zinc-400">
              Agree to the Terms of Service above to continue to checkout.
            </p>
          </div>
        </section>
      </div>
    </div>
  </PublicLayout>
</template>
