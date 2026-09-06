<?php
declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Services\Plans;
use App\Services\Settings;

/**
 * Stripe subscriptions over the REST API (no SDK). Checkout Sessions + Billing Portal + webhooks.
 */
final class StripeService
{
    private const API = 'https://api.stripe.com/v1';

    public static function enabled(): bool
    {
        return Settings::bool('stripe_enabled') && (string) Settings::get('stripe_secret_key', '') !== '';
    }

    private static function request(string $method, string $path, array $params = []): array
    {
        $response = Http::request($method, self::API . $path, [
            'headers' => ['Authorization' => 'Bearer ' . Settings::get('stripe_secret_key'), 'Stripe-Version' => '2024-06-20'],
            'form' => $method === 'POST' ? self::flatten($params) : null,
            'timeout' => 30,
        ]);
        $data = $response->json();
        if (!$response->ok()) {
            $message = (string) ($data['error']['message'] ?? $response->error ?: 'Stripe request failed');
            Logger::error('Stripe error: ' . $message);
            throw new \RuntimeException($message);
        }
        return $data;
    }

    /** Convert nested arrays to Stripe's bracket notation (a[b][c]=v). */
    private static function flatten(array $params, string $prefix = ''): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $out = array_merge($out, self::flatten($value, $name));
            } elseif ($value !== null) {
                $out[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        return $out;
    }

    public static function ensureCustomer(array $tenant, array $user): string
    {
        if (!empty($tenant['stripe_customer_id'])) {
            return (string) $tenant['stripe_customer_id'];
        }
        $customer = self::request('POST', '/customers', ['email' => $user['email'], 'name' => $tenant['name'], 'metadata' => ['tenant_id' => $tenant['id']]]);
        DB::instance()->update('tenants', ['stripe_customer_id' => $customer['id'], 'updated_at' => now()], 'id = :id', ['id' => (int) $tenant['id']]);
        return (string) $customer['id'];
    }

    public static function checkoutUrl(array $tenant, array $user, array $plan, string $cycle, string $successUrl, string $cancelUrl): string
    {
        $priceId = (string) ($cycle === 'yearly' ? ($plan['stripe_price_yearly'] ?? '') : ($plan['stripe_price_monthly'] ?? ''));
        if ($priceId === '') {
            throw new \RuntimeException('This plan is not available for online checkout yet.');
        }
        $customer = self::ensureCustomer($tenant, $user);
        $session = self::request('POST', '/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $tenant['id'],
            'allow_promotion_codes' => true,
            'subscription_data' => ['metadata' => ['tenant_id' => $tenant['id'], 'plan_key' => $plan['plan_key'], 'cycle' => $cycle]],
            'metadata' => ['tenant_id' => $tenant['id'], 'plan_key' => $plan['plan_key'], 'cycle' => $cycle],
        ]);
        return (string) $session['url'];
    }

    public static function portalUrl(array $tenant, string $returnUrl): string
    {
        if (empty($tenant['stripe_customer_id'])) {
            throw new \RuntimeException('No billing account exists yet.');
        }
        $session = self::request('POST', '/billing_portal/sessions', ['customer' => $tenant['stripe_customer_id'], 'return_url' => $returnUrl]);
        return (string) $session['url'];
    }

    /** Verify a webhook signature and return the decoded event. */
    public static function verifyWebhook(string $payload, string $signatureHeader): array
    {
        $secret = (string) Settings::get('stripe_webhook_secret', '');
        if ($secret === '') {
            throw new \RuntimeException('Webhook secret not configured');
        }
        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === '' || !$signatures || abs(time() - (int) $timestamp) > 300) {
            throw new \RuntimeException('Invalid webhook timestamp');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new \RuntimeException('Invalid webhook signature');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id'])) {
            throw new \RuntimeException('Invalid webhook payload');
        }
        return $event;
    }

    /** Apply a Stripe event to the local tenant. Returns true when handled. */
    public static function handleEvent(array $event): bool
    {
        $db = DB::instance();
        try {
            $db->insert('billing_events', ['stripe_event_id' => $event['id'], 'type' => (string) $event['type'], 'payload' => json_encode($event['data'] ?? []), 'created_at' => now()]);
        } catch (\Throwable) {
            return true; // duplicate delivery
        }
        $object = $event['data']['object'] ?? [];
        $type = (string) $event['type'];
        $tenantId = 0;
        if ($type === 'checkout.session.completed') {
            $tenantId = (int) ($object['client_reference_id'] ?? $object['metadata']['tenant_id'] ?? 0);
            $planKey = (string) ($object['metadata']['plan_key'] ?? '');
            $cycle = (string) ($object['metadata']['cycle'] ?? 'monthly');
            if ($tenantId > 0) {
                $data = ['stripe_customer_id' => $object['customer'] ?? null, 'stripe_subscription_id' => $object['subscription'] ?? null, 'subscription_status' => 'active', 'billing_cycle' => $cycle, 'status' => 'active', 'updated_at' => now()];
                if ($planKey !== '' && Plans::get($planKey)) {
                    $data['plan_key'] = $planKey;
                }
                $db->update('tenants', $data, 'id = :id', ['id' => $tenantId]);
            }
        } elseif (in_array($type, ['customer.subscription.updated', 'customer.subscription.created', 'customer.subscription.deleted'], true)) {
            $tenant = $db->fetch('SELECT * FROM tenants WHERE stripe_customer_id = ? OR stripe_subscription_id = ? LIMIT 1', [(string) ($object['customer'] ?? ''), (string) ($object['id'] ?? '')]);
            if ($tenant) {
                $tenantId = (int) $tenant['id'];
                $status = (string) ($object['status'] ?? 'active');
                $data = ['stripe_subscription_id' => $object['id'] ?? $tenant['stripe_subscription_id'], 'subscription_status' => $status, 'updated_at' => now()];
                if (!empty($object['current_period_end'])) {
                    $data['current_period_end'] = gmdate('Y-m-d H:i:s', (int) $object['current_period_end']);
                }
                $priceId = (string) ($object['items']['data'][0]['price']['id'] ?? '');
                if ($priceId !== '') {
                    foreach (Plans::all() as $plan) {
                        if ($plan['stripe_price_monthly'] === $priceId || $plan['stripe_price_yearly'] === $priceId) {
                            $data['plan_key'] = $plan['plan_key'];
                            $data['billing_cycle'] = $plan['stripe_price_yearly'] === $priceId ? 'yearly' : 'monthly';
                        }
                    }
                }
                if ($type === 'customer.subscription.deleted' || in_array($status, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
                    $data['plan_key'] = (string) Settings::get('default_plan', 'free');
                    $data['subscription_status'] = 'canceled';
                    $data['stripe_subscription_id'] = null;
                }
                $db->update('tenants', $data, 'id = :id', ['id' => $tenantId]);
            }
        } elseif ($type === 'invoice.payment_failed') {
            $tenant = $db->fetch('SELECT id FROM tenants WHERE stripe_customer_id = ? LIMIT 1', [(string) ($object['customer'] ?? '')]);
            if ($tenant) {
                $tenantId = (int) $tenant['id'];
                $db->update('tenants', ['subscription_status' => 'past_due', 'updated_at' => now()], 'id = :id', ['id' => $tenantId]);
            }
        }
        $db->update('billing_events', ['tenant_id' => $tenantId ?: null, 'processed_at' => now()], 'stripe_event_id = :e', ['e' => $event['id']]);
        return true;
    }
}
