<?php

namespace App\Http\Controllers;

use App\Support\Billing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class BillingController extends Controller
{
    public function upgrade(Request $request)
    {
        $rawPlan = $request->query('plan');
        $validPlan = is_string($rawPlan) && in_array($rawPlan, ['developer', 'pro', 'team'], true) ? $rawPlan : null;

        if (! $request->user()) {
            return redirect($validPlan ? '/login?plan='.rawurlencode($validPlan) : '/login');
        }

        $userId = $request->user()->id;
        $account = DB::table('billing_accounts')->where('user_id', $userId)->first();
        $enabled = Billing::enabled();
        $status = $account->status ?? 'none';
        $paidUntil = $account && $account->paid_until ? Carbon::parse($account->paid_until)->toIso8601ZuluString() : null;

        $plans = collect(config('billing.plans'))
            ->filter(fn ($p, $key) => in_array($key, ['developer', 'pro', 'team'], true))
            ->map(fn ($p, $key) => [
                'key' => $key,
                'name' => $p['name'],
                'price' => $p['price'],
                'requests' => $p['requests'],
                'retention' => $p['retention'],
            ])
            ->values()
            ->all();

        return Inertia::render('BillingUpgrade', [
            'plans' => $plans,
            'selected_plan' => $validPlan ?? 'developer',
            'billing_enabled' => $enabled,
            'billing_mode' => $enabled ? config('billing.stripe_mode') : null,
            'managed' => (bool) config('billing.managed'),
            'current_plan' => Billing::plan($userId),
            'subscription_status' => $status,
            'cancel_at_period_end' => (bool) ($account->cancel_at_period_end ?? false),
            'renews_at' => $paidUntil,
            'has_billing_account' => (bool) ($account && $account->customer_id),
            'has_active_paid_sub' => (bool) ($account && in_array($account->status, ['active', 'trialing', 'past_due', 'unpaid', 'incomplete'], true)),
            'tos_version' => Billing::TOS_VERSION,
            'tos_version_label' => Billing::TOS_VERSION_LABEL,
            'checkout_cancelled' => $request->query('checkout') === 'cancelled',
        ]);
    }
    public function usage(Request $request)
    {
        $id = $request->user()->id;
        $limits = Billing::limits($id);
        $enabled = Billing::enabled();
        $account = DB::table('billing_accounts')->where('user_id', $id)->first();
        $plan = Billing::plan($id);
        $status = $account->status ?? 'none';
        $cancelAtPeriodEnd = (bool) ($account->cancel_at_period_end ?? false);
        $paidUntil = $account && $account->paid_until ? Carbon::parse($account->paid_until)->toIso8601ZuluString() : null;
        return response()->json([
            'plan' => $plan,
            'subscription_status' => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'renews_at' => $paidUntil,
            'has_billing_account' => (bool) ($account && $account->customer_id),
            'limits' => ['requests' => $limits['requests'], 'retention' => $limits['retention']],
            'period' => now()->format('Y-m'),
            'requests' => (int) DB::table('usage_counters')->where('user_id', $id)->where('period', now()->format('Y-m'))->value('requests_count'),
            'billing_enabled' => $enabled,
            'billing_mode' => $enabled ? config('billing.stripe_mode') : null,
            'managed' => (bool) config('billing.managed'),
        ])->header('Cache-Control', 'no-store');
    }

    private function stripe(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $client = Http::withToken(config('billing.secret'))->acceptJson()->asForm()->connectTimeout(5)->timeout(15)->withoutRedirecting()->withHeaders(['Stripe-Version' => '2025-03-31.basil']);
        if ($key) $client = $client->withHeaders(['Idempotency-Key' => $key]);
        try {
            $response = $method === 'GET' ? $client->get('https://api.stripe.com/v1/'.$path, $data) : $client->post('https://api.stripe.com/v1/'.$path, $data);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            abort(503, 'Billing provider is temporarily unavailable.');
        }
        abort_unless($response->successful(), 503, 'Billing provider is temporarily unavailable. No plan change has been applied.');
        $object = $response->json();
        abort_unless(is_array($object) && Billing::matchesProviderMode($object), 503, 'Billing provider response could not be verified.');
        return $object;
    }

    private function sessionResponse(array $session, string $host)
    {
        $url = $session['url'] ?? null;
        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : [];
        abort_unless(($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === $host
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']), 503, 'Billing provider returned an invalid session URL.');
        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    public function checkout(Request $request)
    {
        abort_unless(Billing::enabled(), 503, 'Managed billing is not configured. No payment has been taken.');
        $data = $request->validate([
            'plan' => 'required|in:developer,pro,team',
            'tos_accepted' => 'required|accepted',
        ], [
            'tos_accepted.required' => 'You must agree to the Terms of Service before starting checkout.',
            'tos_accepted.accepted' => 'You must agree to the Terms of Service before starting checkout.',
        ]);
        $userId = $request->user()->id;
        $tosAcceptedAt = now();
        DB::table('billing_accounts')->insertOrIgnore(['user_id' => $userId, 'plan' => 'free', 'status' => 'none', 'created_at' => $tosAcceptedAt, 'updated_at' => $tosAcceptedAt]);
        $account = DB::table('billing_accounts')->where('user_id', $userId)->first();
        abort_if(in_array($account->status, ['active', 'trialing', 'past_due', 'unpaid', 'incomplete']), 409, 'Use the billing portal to manage an existing subscription.');
        if (!$account->customer_id) {
            $customer = $this->stripe('POST', 'customers', ['metadata' => ['user_id' => (string) $userId]], 'customer-'.$userId);
            abort_unless(is_string($customer['id'] ?? null) && preg_match('/\Acus_[A-Za-z0-9_]+\z/', $customer['id']) === 1, 503, 'Billing provider response could not be verified.');
            DB::table('billing_accounts')->where('user_id', $userId)->update([
                'customer_id' => $customer['id'],
                'tos_accepted_at' => $tosAcceptedAt,
                'tos_version' => Billing::TOS_VERSION,
                'updated_at' => $tosAcceptedAt,
            ]);
            $customerId = $customer['id'];
        } else {
            DB::table('billing_accounts')->where('user_id', $userId)->update([
                'tos_accepted_at' => $tosAcceptedAt,
                'tos_version' => Billing::TOS_VERSION,
                'updated_at' => $tosAcceptedAt,
            ]);
            $customerId = $account->customer_id;
        }
        $tosIso = $tosAcceptedAt->toIso8601ZuluString();
        $session = $this->stripe('POST', 'checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $userId,
            'line_items' => [['price' => config('billing.plans.'.$data['plan'].'.stripe_price'), 'quantity' => 1]],
            'success_url' => url('/dashboard').'?checkout=complete&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => url('/billing/upgrade').'?plan='.rawurlencode($data['plan']).'&checkout=cancelled',
            'metadata' => ['user_id' => (string) $userId, 'tos_version' => Billing::TOS_VERSION, 'tos_accepted_at' => $tosIso],
            'subscription_data' => ['metadata' => ['user_id' => (string) $userId, 'tos_version' => Billing::TOS_VERSION, 'tos_accepted_at' => $tosIso]],
        ], 'checkout-'.$userId.'-'.$data['plan'].'-'.intdiv(time(), 1800));
        return $this->sessionResponse($session, 'checkout.stripe.com');
    }

    public function checkoutStatus(Request $request)
    {
        abort_unless(Billing::enabled(), 503, 'Managed billing is not configured.');
        $userId = $request->user()->id;
        $sessionId = $request->query('session_id');
        if (is_string($sessionId) && $sessionId !== '') {
            abort_unless(preg_match('/\Acs_(?:test|live)_[A-Za-z0-9_]{10,200}\z/', $sessionId) === 1, 422, 'Invalid checkout session identifier.');
            $account = DB::table('billing_accounts')->where('user_id', $userId)->first();
            abort_unless($account && $account->customer_id, 404, 'No billing account found for this user.');
            $session = $this->stripe('GET', 'checkout/sessions/'.rawurlencode($sessionId));
            abort_unless(
                ($session['id'] ?? null) === $sessionId
                && ($session['customer'] ?? null) === $account->customer_id
                && (($session['client_reference_id'] ?? null) === null || (string) $session['client_reference_id'] === (string) $userId),
                403,
                'Checkout session does not belong to this account.'
            );
            $sessionStatus = (string) ($session['status'] ?? '');
            $paymentStatus = (string) ($session['payment_status'] ?? '');
            $subscriptionId = $session['subscription'] ?? null;
            if ($sessionStatus === 'complete' && in_array($paymentStatus, ['paid', 'no_payment_required'], true) && is_string($subscriptionId) && preg_match('/\Asub_[A-Za-z0-9]+\z/', $subscriptionId) === 1) {
                $subscription = $this->stripe('GET', 'subscriptions/'.rawurlencode($subscriptionId));
                abort_unless(
                    ($subscription['id'] ?? null) === $subscriptionId
                    && ($subscription['customer'] ?? null) === $account->customer_id,
                    503,
                    'Billing provider response could not be verified.'
                );
                DB::transaction(fn () => $this->applySubscriptionState($subscription));
            } elseif ($sessionStatus === 'expired') {
                return response()->json(['state' => 'failed', 'message' => 'This checkout session expired before payment completed.'])->header('Cache-Control', 'no-store');
            }
        }
        $account = DB::table('billing_accounts')->where('user_id', $userId)->first();
        $plan = Billing::plan($userId);
        $status = $account->status ?? 'none';
        $renewsAt = $account && $account->paid_until ? Carbon::parse($account->paid_until)->toIso8601ZuluString() : null;
        if ($plan !== 'free' && in_array($status, ['active', 'trialing'], true)) {
            return response()->json([
                'state' => 'confirmed',
                'plan' => $plan,
                'subscription_status' => $status,
                'cancel_at_period_end' => (bool) ($account->cancel_at_period_end ?? false),
                'renews_at' => $renewsAt,
                'billing_mode' => config('billing.stripe_mode'),
            ])->header('Cache-Control', 'no-store');
        }
        return response()->json([
            'state' => 'verifying',
            'plan' => $plan,
            'subscription_status' => $status,
            'cancel_at_period_end' => (bool) ($account->cancel_at_period_end ?? false),
            'renews_at' => $renewsAt,
            'billing_mode' => config('billing.stripe_mode'),
        ])->header('Cache-Control', 'no-store');
    }

    private function applySubscriptionState(array $subscription): void
    {
        $account = DB::table('billing_accounts')->where('customer_id', $subscription['customer'] ?? '')->lockForUpdate()->first();
        if ($account && (!$account->subscription_id || $account->subscription_id === $subscription['id'] || (in_array($account->status, ['none', 'canceled', 'incomplete_expired']) && in_array($subscription['status'] ?? '', ['active', 'trialing'])))) {
            // Checkout sells exactly one plan item. Basil reports its billing period on that item.
            $items = $subscription['items']['data'] ?? [];
            $item = is_array($items) && count($items) === 1 && !($subscription['items']['has_more'] ?? false) ? ($items[0] ?? []) : [];
            $price = $item['price']['id'] ?? '';
            $plan = collect(config('billing.plans'))->search(fn ($p) => !empty($p['stripe_price']) && $p['stripe_price'] === $price);
            $periodEnd = $item['current_period_end'] ?? null;
            $paidUntil = $plan && is_int($periodEnd) && $periodEnd > 0 ? Carbon::createFromTimestampUTC($periodEnd) : null;
            DB::table('billing_accounts')->where('id', $account->id)->update([
                'subscription_id' => $subscription['id'],
                'plan' => $plan ?: 'free',
                'status' => $subscription['status'] ?? 'none',
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                'paid_until' => $paidUntil,
                'updated_at' => now(),
            ]);
        }
    }

    public function portal(Request $request)
    {
        abort_unless(Billing::enabled(), 503, 'Managed billing is not configured.');
        $customer = DB::table('billing_accounts')->where('user_id', $request->user()->id)->value('customer_id');
        abort_unless($customer, 409, 'No billing account exists yet.');
        $session = $this->stripe('POST', 'billing_portal/sessions', ['customer' => $customer, 'return_url' => url('/dashboard')]);
        return $this->sessionResponse($session, 'billing.stripe.com');
    }

    public function webhook(Request $request)
    {
        abort_unless(Billing::enabled(), 503);
        $body = $request->getContent();
        abort_if(strlen($body) > 262144, 413);
        preg_match('/(?:^|,)t=(\d+)/', $request->header('Stripe-Signature', ''), $timestamp);
        preg_match_all('/(?:^|,)v1=([a-f0-9]{64})/', $request->header('Stripe-Signature', ''), $signatures);
        $t = (int) ($timestamp[1] ?? 0);
        $expected = hash_hmac('sha256', $t.'.'.$body, config('billing.webhook_secret'));
        abort_unless(abs(time() - $t) <= 300 && collect($signatures[1] ?? [])->contains(fn ($s) => hash_equals($expected, $s)), 400, 'Invalid webhook signature.');
        $event = json_decode($body, true);
        abort_unless(is_array($event) && is_string($event['id'] ?? null) && strlen($event['id']) <= 200 && is_string($event['type'] ?? null), 400);
        abort_unless(Billing::matchesProviderMode($event), 400, 'Unexpected Stripe event mode.');
        if (DB::table('billing_events')->where('id', $event['id'])->exists()) return response()->json(['received' => true]);
        if (in_array($event['type'], ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'])) {
            $object = $event['data']['object'] ?? [];
            abort_unless(is_string($object['id'] ?? null) && preg_match('/^sub_[A-Za-z0-9]+$/D', $object['id']), 400);
            // Retrieve authoritative current state, including deletion events. Never trust an old event's plan.
            $subscription = $this->stripe('GET', 'subscriptions/'.rawurlencode($object['id']));
            abort_unless(($subscription['id'] ?? null) === $object['id'] && is_string($subscription['customer'] ?? null)
                && preg_match('/\Acus_[A-Za-z0-9_]+\z/', $subscription['customer']) === 1, 503, 'Billing provider response could not be verified.');
            DB::transaction(function () use ($event, $subscription) {
                $this->applySubscriptionState($subscription);
                DB::table('billing_events')->insertOrIgnore(['id' => $event['id'], 'created_at' => now()]);
            });
        } else DB::table('billing_events')->insertOrIgnore(['id' => $event['id'], 'created_at' => now()]);
        return response()->json(['received' => true]);
    }
}
