<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\Settings;
use App\Services\Tenants;

final class AuthController
{
    public function showLogin(Request $request): Response
    {
        return view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $v = Validator::make($request->all(), ['email' => 'required|email', 'password' => 'required']);
        if ($v->fails()) {
            Session::setOldInput($request->all());
            flash('error', $v->firstError() ?? 'Invalid input.');
            return redirect('/login');
        }
        if (!auth()->attempt($request->string('email'), (string) $request->input('password'), $request->boolean('remember'))) {
            Session::setOldInput($request->all());
            flash('error', 'Incorrect email or password.');
            return redirect('/login');
        }
        $intended = (string) Session::get('intended', '');
        Session::forget('intended');
        $tenant = auth()->tenant();
        if ($tenant && !(int) $tenant['onboarding_completed'] && !auth()->isSuperAdmin()) {
            return redirect('/onboarding');
        }
        return redirect($intended !== '' && str_starts_with($intended, '/') ? $intended : '/dashboard');
    }

    public function showRegister(Request $request): Response
    {
        if (!Settings::bool('registration_enabled')) {
            flash('error', 'Registration is currently closed.');
            return redirect('/login');
        }
        return view('auth/register', ['title' => 'Create your account'], 'layouts/auth');
    }

    public function register(Request $request): Response
    {
        if (!Settings::bool('registration_enabled')) {
            abort(403, 'Registration is closed.');
        }
        $data = $request->all();
        $v = Validator::make($data, [
            'name' => 'required|min:2|max:120',
            'company' => 'nullable|max:160',
            'website' => 'nullable|url|max:500',
            'email' => 'required|email',
            'password' => 'required|min:8|max:200',
            'terms' => 'required',
        ], ['terms' => 'Terms acceptance']);
        if ($v->fails()) {
            Session::setOldInput($data);
            flash('error', $v->firstError() ?? 'Please check the form.');
            return redirect('/register');
        }
        $email = strtolower(trim((string) $data['email']));
        if (DB::instance()->exists('users', 'email = ?', [$email])) {
            Session::setOldInput($data);
            flash('error', 'An account with this email already exists. Try signing in.');
            return redirect('/register');
        }
        $website = trim((string) ($data['website'] ?? ''));
        if ($website !== '' && !preg_match('~^https?://~i', $website)) {
            $website = 'https://' . $website;
        }
        [$tenant, $user] = Tenants::create(trim((string) ($data['company'] ?? '')), trim((string) $data['name']), $email, (string) $data['password'], [
            'website_url' => $website ?: null,
        ]);
        Tenants::log((int) $tenant['id'], (int) $user['id'], 'account.registered');
        $this->sendVerification($user);
        auth()->login($user);
        flash('success', 'Welcome aboard! Let\'s set up your first AI agent.');
        return redirect('/onboarding');
    }

    public function logout(Request $request): Response
    {
        auth()->logout();
        return redirect('/login');
    }

    public function showForgot(Request $request): Response
    {
        return view('auth/forgot', ['title' => 'Reset your password'], 'layouts/auth');
    }

    public function forgot(Request $request): Response
    {
        $email = strtolower($request->string('email'));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? DB::instance()->fetch('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user) {
            $token = bin2hex(random_bytes(32));
            DB::instance()->delete('password_resets', 'email = ?', [$email]);
            DB::instance()->insert('password_resets', [
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                'created_at' => now(),
            ]);
            $link = url('/reset-password/' . $token);
            Mailer::send($email, 'Reset your ' . app_name() . ' password', Mailer::render('password_reset', ['name' => $user['name'], 'link' => $link]));
        }
        flash('success', 'If an account exists for that email, we have sent a password reset link.');
        return redirect('/forgot-password');
    }

    public function showReset(Request $request, string $token): Response
    {
        $row = $this->findReset($token);
        if (!$row) {
            flash('error', 'This reset link is invalid or has expired.');
            return redirect('/forgot-password');
        }
        return view('auth/reset', ['title' => 'Choose a new password', 'token' => $token, 'email' => $row['email']], 'layouts/auth');
    }

    public function reset(Request $request): Response
    {
        $token = $request->string('token');
        $row = $this->findReset($token);
        if (!$row) {
            flash('error', 'This reset link is invalid or has expired.');
            return redirect('/forgot-password');
        }
        $v = Validator::make($request->all(), ['password' => 'required|min:8|confirmed']);
        if ($v->fails()) {
            flash('error', $v->firstError() ?? 'Invalid password.');
            return redirect('/reset-password/' . $token);
        }
        DB::instance()->update('users', [
            'password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
            'remember_selector' => null, 'remember_hash' => null, 'updated_at' => now(),
        ], 'email = :email', ['email' => $row['email']]);
        DB::instance()->delete('password_resets', 'email = ?', [$row['email']]);
        flash('success', 'Your password has been updated. Please sign in.');
        return redirect('/login');
    }

    public function verify(Request $request, string $token): Response
    {
        if (preg_match('/^[a-f0-9]{40}$/', $token)) {
            $user = DB::instance()->fetch('SELECT * FROM users WHERE verification_token = ?', [$token]);
            if ($user) {
                DB::instance()->update('users', ['email_verified_at' => now(), 'verification_token' => null], 'id = :id', ['id' => $user['id']]);
                flash('success', 'Your email address has been verified.');
                return redirect(auth()->check() ? '/dashboard' : '/login');
            }
        }
        flash('error', 'This verification link is invalid.');
        return redirect(auth()->check() ? '/dashboard' : '/login');
    }

    public function resendVerification(Request $request): Response
    {
        $user = current_user();
        if ($user && empty($user['email_verified_at'])) {
            if (empty($user['verification_token'])) {
                $user['verification_token'] = bin2hex(random_bytes(20));
                DB::instance()->update('users', ['verification_token' => $user['verification_token']], 'id = :id', ['id' => $user['id']]);
            }
            $this->sendVerification($user);
            flash('success', 'Verification email sent.');
        }
        return back();
    }

    private function sendVerification(array $user): void
    {
        if (empty($user['verification_token'])) {
            return;
        }
        try {
            Mailer::send((string) $user['email'], 'Welcome to ' . app_name(), Mailer::render('welcome', [
                'name' => $user['name'],
                'link' => url('/verify-email/' . $user['verification_token']),
            ]));
        } catch (\Throwable $e) {
            Logger::error('Verification email failed: ' . $e->getMessage());
        }
    }

    private function findReset(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return DB::instance()->fetch('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ? LIMIT 1', [hash('sha256', $token), now()]);
    }
}
