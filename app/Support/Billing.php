<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class Billing
{
    public const TOS_VERSION = '2026-11-23';
    public const TOS_VERSION_LABEL = '23 November 2026';

    public static function enabled(): bool
    {
        return !in_array(false, self::configurationChecks(), true);
    }

    /** Local preflight only: never expose provider IDs or secret values. */
    public static function configurationChecks(): array
    {
        $mode = config('billing.stripe_mode');
        $validMode = in_array($mode, ['test', 'live'], true);
        $key = (string) config('billing.secret');
        $checks = [
            'managed hosting' => (bool) config('billing.managed'),
            'billing checkout enabled' => (bool) config('billing.checkout_enabled'),
            'explicit Stripe mode' => $validMode,
            'API key matches Stripe mode' => $validMode && preg_match('/\A(?:sk|rk)_'.$mode.'_[A-Za-z0-9_]+\z/', $key) === 1,
            'webhook signing secret format' => preg_match('/\Awhsec_[A-Za-z0-9_]+\z/', (string) config('billing.webhook_secret')) === 1,
        ];
        $prices = [];
        foreach (['developer', 'pro', 'team'] as $plan) {
            $price = (string) config('billing.plans.'.$plan.'.stripe_price');
            $checks[$plan.' price format'] = preg_match('/\Aprice_[A-Za-z0-9_]+\z/', $price) === 1;
            $prices[] = $price;
        }
        $checks['distinct price IDs'] = count(array_unique($prices)) === count($prices);

        return $checks;
    }

    public static function matchesProviderMode(array $object): bool
    {
        return ($object['livemode'] ?? null) === (config('billing.stripe_mode') === 'live');
    }

    public static function plan(int $userId): string
    {
        $account = DB::table('billing_accounts')->where('user_id', $userId)->first();
        if (!$account || !in_array($account->status, ['active', 'trialing']) || !$account->paid_until || Carbon::parse($account->paid_until)->isPast()) return 'free';
        return array_key_exists($account->plan, config('billing.plans')) ? $account->plan : 'free';
    }

    public static function limits(int $userId): array
    {
        if (!config('billing.managed')) return ['requests' => PHP_INT_MAX, 'retention' => 365];
        return config('billing.plans.'.self::plan($userId));
    }

    // Caller holds the user's row lock, serializing captures across all their endpoints.
    public static function meter(int $userId): bool
    {
        $period = now()->format('Y-m');
        $limit = self::limits($userId)['requests'];
        $query = DB::table('usage_counters')->where('user_id', $userId)->where('period', $period);
        $row = $query->first();
        if (($row->requests_count ?? 0) >= $limit) return false;
        if (!$row) {
            DB::table('usage_counters')->insert(['user_id' => $userId, 'period' => $period, 'requests_count' => 1, 'quota_limit' => min($limit, 2147483647), 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $query->update(['requests_count' => $row->requests_count + 1, 'quota_limit' => min($limit, 2147483647), 'updated_at' => now()]);
        }
        return true;
    }
}
