<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Subscription plans and per-tenant limits.
 */
final class Plans
{
    private static ?array $cache = null;

    public const DEFAULT_LIMITS = [
        'agents' => 1,
        'messages_per_month' => 100,
        'pages_per_agent' => 30,
        'documents_per_agent' => 3,
        'storage_mb' => 20,
        'premium_voice' => 0,
        'remove_branding' => 0,
        'lead_capture' => 1,
        'analytics_days' => 30,
    ];

    public static function seedDefaults(DB $db): void
    {
        if ($db->count('plans') > 0) {
            return;
        }
        $now = now();
        $plans = [
            ['free', 'Free', 'Try the platform with a single agent.', 0, 0, ['agents' => 1, 'messages_per_month' => 100, 'pages_per_agent' => 30, 'documents_per_agent' => 3, 'storage_mb' => 20, 'premium_voice' => 0, 'remove_branding' => 0, 'analytics_days' => 30],
                ['1 AI agent', '100 messages / month', '30 website pages', '3 documents', 'Browser voice (free)', 'Lead capture'], 0, 0],
            ['starter', 'Starter', 'For small businesses getting started with AI support.', 29, 290, ['agents' => 3, 'messages_per_month' => 2000, 'pages_per_agent' => 200, 'documents_per_agent' => 25, 'storage_mb' => 200, 'premium_voice' => 1, 'remove_branding' => 0, 'analytics_days' => 90],
                ['3 AI agents', '2,000 messages / month', '200 website pages per agent', '25 documents per agent', 'Premium neural voices', 'Lead capture & email alerts', '90-day analytics'], 0, 1],
            ['pro', 'Pro', 'For growing teams that need more agents and volume.', 79, 790, ['agents' => 10, 'messages_per_month' => 10000, 'pages_per_agent' => 1000, 'documents_per_agent' => 100, 'storage_mb' => 1000, 'premium_voice' => 1, 'remove_branding' => 1, 'analytics_days' => 365],
                ['10 AI agents', '10,000 messages / month', '1,000 website pages per agent', '100 documents per agent', 'Premium neural voices', 'Remove branding', 'Priority support', '1-year analytics'], 1, 2],
            ['business', 'Business', 'High volume, unlimited agents, dedicated support.', 199, 1990, ['agents' => 0, 'messages_per_month' => 50000, 'pages_per_agent' => 5000, 'documents_per_agent' => 500, 'storage_mb' => 5000, 'premium_voice' => 1, 'remove_branding' => 1, 'analytics_days' => 730],
                ['Unlimited AI agents', '50,000 messages / month', '5,000 website pages per agent', '500 documents per agent', 'Premium neural voices', 'Remove branding', 'Dedicated support', '2-year analytics'], 0, 3],
        ];
        foreach ($plans as [$key, $name, $desc, $pm, $py, $limits, $features, $featured, $sort]) {
            $db->insert('plans', [
                'plan_key' => $key, 'name' => $name, 'description' => $desc,
                'price_monthly' => $pm, 'price_yearly' => $py, 'currency' => 'USD',
                'limits' => json_encode($limits), 'features' => json_encode($features),
                'is_active' => 1, 'is_featured' => $featured, 'sort_order' => $sort,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public static function all(bool $activeOnly = false): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::instance()->fetchAll('SELECT * FROM plans ORDER BY sort_order ASC, price_monthly ASC') as $row) {
                $row['limits'] = array_merge(self::DEFAULT_LIMITS, json_field($row['limits']));
                $row['features'] = json_field($row['features']);
                self::$cache[$row['plan_key']] = $row;
            }
        }
        if ($activeOnly) {
            return array_filter(self::$cache, static fn($p) => (int) $p['is_active'] === 1);
        }
        return self::$cache;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** True while the tenant is in an active trial period. */
    public static function onTrial(array $tenant): bool
    {
        if (empty($tenant['trial_ends_at'])) {
            return false;
        }
        if (!empty($tenant['stripe_subscription_id']) && in_array($tenant['subscription_status'] ?? '', ['active', 'trialing'], true)) {
            return false;
        }
        return strtotime((string) $tenant['trial_ends_at'] . ' UTC') > time();
    }

    /** Effective plan for a tenant (trial plan while on trial). */
    public static function forTenant(array $tenant): array
    {
        $key = (string) ($tenant['plan_key'] ?? 'free');
        if (self::onTrial($tenant)) {
            $trialPlan = (string) Settings::get('trial_plan', 'pro');
            if ($trialPlan !== '' && self::get($trialPlan)) {
                $key = $trialPlan;
            }
        }
        $plan = self::get($key) ?? self::get('free');
        if (!$plan) {
            $plan = ['plan_key' => 'free', 'name' => 'Free', 'limits' => self::DEFAULT_LIMITS, 'features' => [], 'price_monthly' => 0, 'price_yearly' => 0];
        }
        $plan['is_trial'] = self::onTrial($tenant);
        return $plan;
    }

    public static function limit(array $tenant, string $name): int
    {
        $plan = self::forTenant($tenant);
        return (int) ($plan['limits'][$name] ?? self::DEFAULT_LIMITS[$name] ?? 0);
    }

    public static function allows(array $tenant, string $feature): bool
    {
        return self::limit($tenant, $feature) > 0;
    }

    /** A quantity limit of 0 (or less) means unlimited. */
    public static function isUnlimited(int $limit): bool
    {
        return $limit <= 0;
    }

    /** True when the tenant has used up a quantity limit. Unlimited plans never hit it. */
    public static function atLimit(array $tenant, string $name, int $current): bool
    {
        $limit = self::limit($tenant, $name);
        return !self::isUnlimited($limit) && $current >= $limit;
    }

    /** Human label for a quantity limit. */
    public static function limitLabel(int $limit): string
    {
        return self::isUnlimited($limit) ? 'unlimited' : format_number($limit);
    }
}
