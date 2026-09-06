<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\Billing\StripeService;
use App\Services\Plans;
use App\Services\Settings;
use App\Services\Usage;

final class BillingController
{
    public function index(Request $request): Response
    {
        $tenant = current_tenant();
        $tenantId = (int) $tenant['id'];
        $db = DB::instance();
        $plan = Plans::forTenant($tenant);
        $usage = Usage::summary($tenantId);
        $meters = [
            ['Messages this month', (int) $usage['messages'], (int) ($plan['limits']['messages_per_month'] ?? 0)],
            ['AI agents', $db->count('agents', 'tenant_id = ?', [$tenantId]), (int) ($plan['limits']['agents'] ?? 0)],
            ['Documents (max per agent)', (int) ($db->fetchColumn('SELECT MAX(n) FROM (SELECT COUNT(*) n FROM knowledge_sources WHERE tenant_id = ? AND type <> \'website\' GROUP BY agent_id) t', [$tenantId]) ?? 0), (int) ($plan['limits']['documents_per_agent'] ?? 0)],
            ['Storage (MB)', (int) round(((int) $db->fetchColumn('SELECT COALESCE(SUM(file_size),0) FROM knowledge_sources WHERE tenant_id = ?', [$tenantId])) / 1048576), (int) ($plan['limits']['storage_mb'] ?? 0)],
        ];
        return view('billing/index', [
            'title' => 'Plan & billing',
            'tenant' => $tenant, 'plan' => $plan, 'plans' => Plans::all(true), 'meters' => $meters,
            'stripe' => StripeService::enabled(),
            'supportEmail' => (string) Settings::get('support_email', ''),
            'history' => Usage::history($tenantId, 'messages', 6),
        ], 'layouts/app');
    }

    public function checkout(Request $request): Response
    {
        $tenant = current_tenant();
        if (!auth()->isOwner() && !auth()->isSuperAdmin()) {
            flash('error', 'Only the workspace owner can change the plan.');
            return redirect('/billing');
        }
        $plan = Plans::get($request->string('plan'));
        $cycle = $request->string('cycle') === 'yearly' ? 'yearly' : 'monthly';
        if (!$plan || (int) $plan['is_active'] !== 1) {
            flash('error', 'Unknown plan.');
            return redirect('/billing');
        }
        if (!StripeService::enabled()) {
            flash('info', 'Online payments are not enabled yet. Please contact us to upgrade your plan.');
            return redirect('/billing');
        }
        try {
            $url = StripeService::checkoutUrl($tenant, current_user(), $plan, $cycle, url('/billing/success'), url('/billing/cancel'));
        } catch (\Throwable $e) {
            Logger::exception($e);
            flash('error', 'Could not start checkout: ' . $e->getMessage());
            return redirect('/billing');
        }
        return Response::redirect($url);
    }

    public function portal(Request $request): Response
    {
        $tenant = current_tenant();
        if (!StripeService::enabled()) {
            return redirect('/billing');
        }
        try {
            return Response::redirect(StripeService::portalUrl($tenant, url('/billing')));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            return redirect('/billing');
        }
    }

    public function success(Request $request): Response
    {
        flash('success', 'Thank you! Your subscription is being activated. It can take a few seconds for the plan to update.');
        return redirect('/billing');
    }

    public function cancel(Request $request): Response
    {
        flash('info', 'Checkout cancelled. Your plan was not changed.');
        return redirect('/billing');
    }

    /** Stripe webhook endpoint (public, signature-verified). */
    public function webhook(Request $request): Response
    {
        try {
            $event = StripeService::verifyWebhook($request->raw(), (string) ($request->header('Stripe-Signature') ?? ''));
            StripeService::handleEvent($event);
        } catch (\Throwable $e) {
            Logger::error('Stripe webhook rejected: ' . $e->getMessage());
            return Response::json(['error' => $e->getMessage()], 400);
        }
        return Response::json(['received' => true]);
    }
}
