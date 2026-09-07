<?php
declare(strict_types=1);

/** @var \App\Core\Router $router */

use App\Controllers\Admin\AdminController;
use App\Controllers\AgentController;
use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\BookingController;
use App\Controllers\ConversationController;
use App\Controllers\CronController;
use App\Controllers\CustomizeController;
use App\Controllers\DashboardController;
use App\Controllers\FileController;
use App\Controllers\InstallController;
use App\Controllers\JobController;
use App\Controllers\KnowledgeController;
use App\Controllers\LeadController;
use App\Controllers\OnboardingController;
use App\Controllers\SettingsController;
use App\Controllers\SiteController;
use App\Controllers\UnansweredController;
use App\Controllers\WidgetApiController;
use App\Controllers\WidgetController;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

// ---------------------------------------------------------------------------
// Middleware
// ---------------------------------------------------------------------------

$router->middleware('auth', static function (Request $request, \Closure $next): Response {
    if (!auth()->check()) {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Authentication required'], 401);
        }
        Session::set('intended', $request->path());
        return redirect('/login');
    }
    $tenant = auth()->tenant();
    if (($tenant['status'] ?? '') === 'suspended' && !auth()->isSuperAdmin()) {
        $allowed = ['/billing', '/settings', '/logout', '/suspended'];
        $path = $request->path();
        $ok = false;
        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $ok = true;
            }
        }
        if (!$ok) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Workspace suspended'], 403);
            }
            return redirect('/suspended');
        }
    }
    return $next($request);
});

$router->middleware('guest', static function (Request $request, \Closure $next): Response {
    if (auth()->check()) {
        return redirect('/dashboard');
    }
    return $next($request);
});

$router->middleware('admin', static function (Request $request, \Closure $next): Response {
    if (!auth()->isSuperAdmin()) {
        abort(403, 'Administrator access required.');
    }
    return $next($request);
});

$router->middleware('csrf', static function (Request $request, \Closure $next): Response {
    if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && !Csrf::verify($request)) {
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Your session has expired. Please reload the page and try again.'], 419);
        }
        flash('error', 'Your session has expired. Please try again.');
        return back();
    }
    return $next($request);
});

$router->middleware('cors', static function (Request $request, \Closure $next): Response {
    $headers = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, X-Widget-Token, X-Requested-With',
        'Access-Control-Max-Age' => '86400',
        'Vary' => 'Origin',
    ];
    if ($request->method() === 'OPTIONS') {
        return Response::noContent(204, $headers);
    }
    return $next($request)->withHeaders($headers);
});

$router->middleware('throttle', static function (Request $request, \Closure $next, string $max = '60', string $window = '60'): Response {
    $key = 'throttle:' . $request->path() . ':' . $request->ip();
    if (!RateLimiter::hit($key, (int) $max, (int) $window)) {
        $retry = RateLimiter::retryAfter($key);
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Too many requests. Please slow down.'], 429, ['Retry-After' => (string) $retry]);
        }
        abort(429, 'Too many requests. Please wait a moment and try again.');
    }
    return $next($request);
});

// ---------------------------------------------------------------------------
// Public site
// ---------------------------------------------------------------------------

$router->get('/', [SiteController::class, 'home']);
$router->get('/pricing', [SiteController::class, 'pricing']);
$router->get('/bot', [SiteController::class, 'bot']);
$router->get('/suspended', [SiteController::class, 'suspended'])->middleware('auth');

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

$router->get('/login', [AuthController::class, 'showLogin'])->middleware('guest');
$router->post('/login', [AuthController::class, 'login'])->middleware('guest', 'csrf', 'throttle:10,60');
$router->get('/register', [AuthController::class, 'showRegister'])->middleware('guest');
$router->post('/register', [AuthController::class, 'register'])->middleware('guest', 'csrf', 'throttle:5,60');
$router->post('/logout', [AuthController::class, 'logout'])->middleware('csrf');
$router->get('/forgot-password', [AuthController::class, 'showForgot'])->middleware('guest');
$router->post('/forgot-password', [AuthController::class, 'forgot'])->middleware('guest', 'csrf', 'throttle:5,300');
$router->get('/reset-password/{token}', [AuthController::class, 'showReset'])->middleware('guest');
$router->post('/reset-password', [AuthController::class, 'reset'])->middleware('guest', 'csrf', 'throttle:5,300');
$router->get('/verify-email/{token}', [AuthController::class, 'verify']);
$router->post('/resend-verification', [AuthController::class, 'resendVerification'])->middleware('auth', 'csrf', 'throttle:3,300');

// ---------------------------------------------------------------------------
// Customer dashboard
// ---------------------------------------------------------------------------

$router->group(['middleware' => ['auth', 'csrf']], static function ($r): void {
    $r->get('/dashboard', [DashboardController::class, 'index']);

    // Onboarding wizard
    $r->get('/onboarding', [OnboardingController::class, 'index']);
    $r->post('/onboarding/website', [OnboardingController::class, 'website']);
    $r->post('/onboarding/agent', [OnboardingController::class, 'agent']);
    $r->post('/onboarding/skip', [OnboardingController::class, 'skip']);
    $r->post('/onboarding/complete', [OnboardingController::class, 'complete']);

    // Agents
    $r->get('/agents', [AgentController::class, 'index']);
    $r->get('/agents/new', [AgentController::class, 'create']);
    $r->post('/agents', [AgentController::class, 'store']);
    $r->get('/agents/{id:\d+}', [AgentController::class, 'show']);
    $r->get('/agents/{id:\d+}/settings', [AgentController::class, 'edit']);
    $r->post('/agents/{id:\d+}/settings', [AgentController::class, 'update']);
    $r->post('/agents/{id:\d+}/toggle', [AgentController::class, 'toggle']);
    $r->post('/agents/{id:\d+}/delete', [AgentController::class, 'destroy']);
    $r->post('/agents/{id:\d+}/duplicate', [AgentController::class, 'duplicate']);
    $r->get('/agents/{id:\d+}/test', [AgentController::class, 'test']);
    $r->post('/agents/{id:\d+}/prompt-preview', [AgentController::class, 'promptPreview']);
    $r->post('/agents/{id:\d+}/generate-prompt', [AgentController::class, 'generatePrompt'])->middleware('throttle:10,60');
    $r->post('/agents/{id:\d+}/voice-preview', [AgentController::class, 'voicePreview'])->middleware('throttle:20,60');

    // Knowledge base
    $r->get('/agents/{id:\d+}/knowledge', [KnowledgeController::class, 'index']);
    $r->post('/agents/{id:\d+}/knowledge/website', [KnowledgeController::class, 'addWebsite']);
    $r->post('/agents/{id:\d+}/knowledge/url', [KnowledgeController::class, 'addUrl']);
    $r->post('/agents/{id:\d+}/knowledge/upload', [KnowledgeController::class, 'upload']);
    $r->post('/agents/{id:\d+}/knowledge/faq', [KnowledgeController::class, 'addFaq']);
    $r->post('/agents/{id:\d+}/knowledge/text', [KnowledgeController::class, 'addText']);
    $r->post('/agents/{id:\d+}/knowledge/reindex', [KnowledgeController::class, 'reindex']);
    $r->get('/agents/{id:\d+}/knowledge/sources/{sourceId:\d+}', [KnowledgeController::class, 'showSource']);
    $r->post('/agents/{id:\d+}/knowledge/sources/{sourceId:\d+}/rescan', [KnowledgeController::class, 'rescan']);
    $r->post('/agents/{id:\d+}/knowledge/sources/{sourceId:\d+}/delete', [KnowledgeController::class, 'deleteSource']);
    $r->get('/agents/{id:\d+}/knowledge/documents/{docId:\d+}', [KnowledgeController::class, 'showDocument']);
    $r->post('/agents/{id:\d+}/knowledge/documents/{docId:\d+}', [KnowledgeController::class, 'updateDocument']);
    $r->post('/agents/{id:\d+}/knowledge/documents/{docId:\d+}/toggle', [KnowledgeController::class, 'toggleDocument']);
    $r->post('/agents/{id:\d+}/knowledge/documents/{docId:\d+}/delete', [KnowledgeController::class, 'deleteDocument']);

    // Widget customisation & installation
    $r->get('/agents/{id:\d+}/customize', [CustomizeController::class, 'index']);
    $r->post('/agents/{id:\d+}/customize', [CustomizeController::class, 'save']);
    $r->post('/agents/{id:\d+}/customize/upload', [CustomizeController::class, 'upload']);
    $r->post('/agents/{id:\d+}/customize/reset', [CustomizeController::class, 'reset']);
    $r->get('/agents/{id:\d+}/install', [InstallController::class, 'index']);

    // Conversations
    $r->get('/conversations', [ConversationController::class, 'index']);
    $r->get('/conversations/{id:\d+}', [ConversationController::class, 'show']);
    $r->post('/conversations/{id:\d+}/delete', [ConversationController::class, 'destroy']);
    $r->get('/conversations/export/csv', [ConversationController::class, 'export']);

    // Leads
    $r->get('/leads', [LeadController::class, 'index']);
    $r->get('/leads/export', [LeadController::class, 'export']);
    $r->post('/leads/{id:\d+}', [LeadController::class, 'update']);
    $r->post('/leads/{id:\d+}/delete', [LeadController::class, 'destroy']);

    // Bookings
    $r->get('/bookings', [BookingController::class, 'index']);
    $r->post('/bookings/{id:\d+}/cancel', [BookingController::class, 'cancel']);
    $r->get('/agents/{id:\d+}/booking', [BookingController::class, 'edit']);
    $r->post('/agents/{id:\d+}/booking', [BookingController::class, 'update']);
    $r->post('/agents/{id:\d+}/booking/calendars', [BookingController::class, 'addCalendar']);
    $r->post('/agents/{id:\d+}/booking/calendars/{linkId:\d+}/refresh', [BookingController::class, 'refreshCalendar']);
    $r->post('/agents/{id:\d+}/booking/calendars/{linkId:\d+}/delete', [BookingController::class, 'deleteCalendar']);

    // Unanswered questions
    $r->get('/unanswered', [UnansweredController::class, 'index']);
    $r->post('/unanswered/{id:\d+}/resolve', [UnansweredController::class, 'resolve']);
    $r->post('/unanswered/{id:\d+}/ignore', [UnansweredController::class, 'ignore']);
    $r->post('/unanswered/{id:\d+}/delete', [UnansweredController::class, 'destroy']);

    // Analytics
    $r->get('/analytics', [AnalyticsController::class, 'index']);
    $r->get('/api/analytics', [AnalyticsController::class, 'data']);

    // Settings
    $r->get('/settings', [SettingsController::class, 'index']);
    $r->post('/settings/profile', [SettingsController::class, 'profile']);
    $r->post('/settings/password', [SettingsController::class, 'password']);
    $r->post('/settings/workspace', [SettingsController::class, 'workspace']);
    $r->post('/settings/delete-account', [SettingsController::class, 'deleteAccount']);

    // Billing
    $r->get('/billing', [BillingController::class, 'index']);
    $r->post('/billing/checkout', [BillingController::class, 'checkout']);
    $r->post('/billing/portal', [BillingController::class, 'portal']);
    $r->get('/billing/success', [BillingController::class, 'success']);
    $r->get('/billing/cancel', [BillingController::class, 'cancel']);

    // Background jobs
    $r->get('/api/jobs/{id:\d+}', [JobController::class, 'show']);
    $r->post('/api/jobs/{id:\d+}/tick', [JobController::class, 'tick']);
    $r->post('/api/jobs/{id:\d+}/cancel', [JobController::class, 'cancel']);
    $r->get('/api/agents/{id:\d+}/jobs', [JobController::class, 'forAgent']);

    // Private files
    $r->get('/files/documents/{id:\d+}', [FileController::class, 'document']);

    // Stop impersonating (super admin)
    $r->post('/admin/stop-impersonating', [AdminController::class, 'stopImpersonating']);
});

// ---------------------------------------------------------------------------
// Platform admin
// ---------------------------------------------------------------------------

$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'admin', 'csrf']], static function ($r): void {
    $r->get('', [AdminController::class, 'index']);
    $r->get('/tenants', [AdminController::class, 'tenants']);
    $r->get('/tenants/{id:\d+}', [AdminController::class, 'tenant']);
    $r->post('/tenants/{id:\d+}', [AdminController::class, 'updateTenant']);
    $r->post('/tenants/{id:\d+}/impersonate', [AdminController::class, 'impersonate']);
    $r->post('/tenants/{id:\d+}/delete', [AdminController::class, 'deleteTenant']);
    $r->get('/plans', [AdminController::class, 'plans']);
    $r->post('/plans/{id:\d+}', [AdminController::class, 'updatePlan']);
    $r->get('/settings', [AdminController::class, 'settings']);
    $r->post('/settings', [AdminController::class, 'saveSettings']);
    $r->post('/settings/test', [AdminController::class, 'testProvider']);
    $r->get('/jobs', [AdminController::class, 'jobs']);
    $r->post('/jobs/{id:\d+}/retry', [AdminController::class, 'retryJob']);
    $r->post('/jobs/run', [AdminController::class, 'runJobs']);
    $r->get('/logs', [AdminController::class, 'logs']);
    $r->post('/system/migrate', [AdminController::class, 'migrate']);
});

// ---------------------------------------------------------------------------
// Widget (public, embeddable)
// ---------------------------------------------------------------------------

$router->get('/widget/preview/{publicId:[a-f0-9]{32}}', [WidgetController::class, 'preview']);
$router->get('/widget/embed/{publicId:[a-f0-9]{32}}', [WidgetController::class, 'embed']);

$router->group(['prefix' => '/api/widget', 'middleware' => ['cors']], static function ($r): void {
    $r->get('/config', [WidgetApiController::class, 'config'])->middleware('throttle:120,60');
    $r->post('/conversations', [WidgetApiController::class, 'startConversation'])->middleware('throttle:30,60');
    $r->post('/messages', [WidgetApiController::class, 'sendMessage'])->middleware('throttle:40,60');
    $r->get('/history', [WidgetApiController::class, 'history'])->middleware('throttle:60,60');
    $r->post('/stt', [WidgetApiController::class, 'stt'])->middleware('throttle:40,60');
    $r->post('/tts', [WidgetApiController::class, 'tts'])->middleware('throttle:60,60');
    $r->post('/leads', [WidgetApiController::class, 'lead'])->middleware('throttle:10,60');
    $r->post('/feedback', [WidgetApiController::class, 'feedback'])->middleware('throttle:30,60');
    $r->post('/end', [WidgetApiController::class, 'end'])->middleware('throttle:30,60');
    $r->any('/{any:.*}', static fn() => Response::json(['error' => 'Not found'], 404));
});

// Booking pages a visitor or the business owner opens directly (no login)
$router->get('/booking/{token:[a-f0-9]{48}}', [BookingController::class, 'manage']);
$router->post('/booking/{token:[a-f0-9]{48}}/cancel', [BookingController::class, 'cancelPublic']);
$router->get('/booking/{token:[a-f0-9]{48}}.ics', [BookingController::class, 'appointmentIcs']);
$router->get('/calendar/{publicId:[a-f0-9]{32}}/{token:[a-f0-9]{32}}.ics', [BookingController::class, 'feed']);

// ---------------------------------------------------------------------------
// Webhooks & cron
// ---------------------------------------------------------------------------

$router->post('/webhooks/stripe', [BillingController::class, 'webhook']);
$router->any('/webcron/run', [CronController::class, 'run']);
