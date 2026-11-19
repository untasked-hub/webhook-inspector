<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class Billing
{
    public static function enabled(): bool
    {
        return (bool) config('billing.managed') && (bool) config('billing.secret') && (bool) config('billing.webhook_secret')
            && collect(config('billing.plans'))->except('free')->every(fn ($p) => !empty($p['stripe_price']));
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
