<?php

namespace App\Http\Controllers;

use App\Support\Billing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class BillingController extends Controller
{
    public function usage(Request $request)
    {
        $id = $request->user()->id;
        $limits = Billing::limits($id);
        return response()->json(['plan' => Billing::plan($id), 'limits' => ['requests' => $limits['requests'], 'retention' => $limits['retention']], 'period' => now()->format('Y-m'), 'requests' => (int) DB::table('usage_counters')->where('user_id', $id)->where('period', now()->format('Y-m'))->value('requests_count'), 'billing_enabled' => Billing::enabled(), 'managed' => (bool) config('billing.managed')])->header('Cache-Control', 'no-store');
    }

    private function stripe(string $method, string $path, array $data = [], ?string $key = null): array
    {
        $client = Http::withToken(config('billing.secret'))->acceptJson()->asForm()->timeout(15)->withHeaders(['Stripe-Version' => '2024-06-20']);
        if ($key) $client = $client->withHeaders(['Idempotency-Key' => $key]);
        try {
            $response = $method === 'GET' ? $client->get('https://api.stripe.com/v1/'.$path, $data) : $client->post('https://api.stripe.com/v1/'.$path, $data);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            abort(503, 'Billing provider is temporarily unavailable.');
        }
        abort_unless($response->successful(), 503, 'Billing provider is temporarily unavailable. No plan change has been applied.');
        return $response->json();
    }

    public function checkout(Request $request)
    {
        abort_unless(Billing::enabled(), 503, 'Managed billing is not configured. No payment has been taken.');
        $data = $request->validate(['plan' => 'required|in:developer,pro,team']);
        $userId = $request->user()->id;
        DB::table('billing_accounts')->insertOrIgnore(['user_id' => $userId, 'plan' => 'free', 'status' => 'none', 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('billing_accounts')->where('user_id', $userId)->first();
        abort_if(in_array($account->status, ['active', 'trialing', 'past_due', 'unpaid', 'incomplete']), 409, 'Use the billing portal to manage an existing subscription.');
        if (!$account->customer_id) {
            $customer = $this->stripe('POST', 'customers', ['metadata' => ['user_id' => (string) $userId]], 'customer-'.$userId);
            DB::table('billing_accounts')->where('user_id', $userId)->update(['customer_id' => $customer['id'], 'updated_at' => now()]);
            $customerId = $customer['id'];
        } else $customerId = $account->customer_id;
        $session = $this->stripe('POST', 'checkout/sessions', ['mode' => 'subscription', 'customer' => $customerId, 'client_reference_id' => (string) $userId, 'line_items' => [['price' => config('billing.plans.'.$data['plan'].'.stripe_price'), 'quantity' => 1]], 'success_url' => url('/dashboard').'?checkout=complete', 'cancel_url' => url('/pricing').'?checkout=cancelled', 'subscription_data' => ['metadata' => ['user_id' => (string) $userId]]], 'checkout-'.$userId.'-'.$data['plan'].'-'.intdiv(time(), 1800));
        return response()->json(['url' => $session['url']]);
    }

    public function portal(Request $request)
    {
        abort_unless(Billing::enabled(), 503, 'Managed billing is not configured.');
        $customer = DB::table('billing_accounts')->where('user_id', $request->user()->id)->value('customer_id');
        abort_unless($customer, 409, 'No billing account exists yet.');
        $session = $this->stripe('POST', 'billing_portal/sessions', ['customer' => $customer, 'return_url' => url('/dashboard')]);
        return response()->json(['url' => $session['url']]);
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
        if (DB::table('billing_events')->where('id', $event['id'])->exists()) return response()->json(['received' => true]);
        if (in_array($event['type'], ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'])) {
            $object = $event['data']['object'] ?? [];
            abort_unless(is_string($object['id'] ?? null) && preg_match('/^sub_[A-Za-z0-9]+$/D', $object['id']), 400);
            // Retrieve authoritative current state, including deletion events. Never trust an old event's plan.
            $subscription = $this->stripe('GET', 'subscriptions/'.rawurlencode($object['id']));
            DB::transaction(function () use ($event, $subscription) {
                $account = DB::table('billing_accounts')->where('customer_id', $subscription['customer'] ?? '')->lockForUpdate()->first();
                if ($account && (!$account->subscription_id || $account->subscription_id === $subscription['id'] || (in_array($account->status, ['none', 'canceled', 'incomplete_expired']) && in_array($subscription['status'] ?? '', ['active', 'trialing'])))) {
                    $price = $subscription['items']['data'][0]['price']['id'] ?? '';
                    $plan = collect(config('billing.plans'))->search(fn ($p) => !empty($p['stripe_price']) && $p['stripe_price'] === $price);
                    DB::table('billing_accounts')->where('id', $account->id)->update(['subscription_id' => $subscription['id'], 'plan' => $plan ?: 'free', 'status' => $subscription['status'] ?? 'none', 'paid_until' => isset($subscription['current_period_end']) ? Carbon::createFromTimestampUTC($subscription['current_period_end']) : null, 'updated_at' => now()]);
                }
                DB::table('billing_events')->insertOrIgnore(['id' => $event['id'], 'created_at' => now()]);
            });
        } else DB::table('billing_events')->insertOrIgnore(['id' => $event['id'], 'created_at' => now()]);
        return response()->json(['received' => true]);
    }
}
