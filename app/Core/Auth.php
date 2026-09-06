<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const REMEMBER_COOKIE = 'va_remember';
    private static ?self $instance = null;
    private ?array $user = null;
    private ?array $tenant = null;
    private bool $resolved = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function user(): ?array
    {
        if (!$this->resolved) {
            $this->resolve();
        }
        return $this->user;
    }

    public function tenant(): ?array
    {
        $this->user();
        return $this->tenant;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): int
    {
        return (int) ($this->user()['id'] ?? 0);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) ($this->user()['is_super_admin'] ?? false);
    }

    public function isOwner(): bool
    {
        return ($this->user()['role'] ?? '') === 'owner';
    }

    public function isImpersonating(): bool
    {
        return (bool) Session::get('impersonator_id');
    }

    private function resolve(): void
    {
        $this->resolved = true;
        if (!Session::isStarted()) {
            return;
        }
        $userId = (int) Session::get('user_id', 0);
        if ($userId <= 0) {
            $userId = $this->fromRememberCookie();
        }
        if ($userId <= 0) {
            return;
        }
        $this->loadUser($userId);
    }

    private function loadUser(int $userId): void
    {
        $user = DB::instance()->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
        if (!$user) {
            $this->user = null;
            return;
        }
        $tenant = DB::instance()->fetch('SELECT * FROM tenants WHERE id = ? LIMIT 1', [(int) $user['tenant_id']]);
        if (!$tenant || $tenant['status'] === 'cancelled') {
            $this->user = null;
            return;
        }
        $this->user = $user;
        $this->tenant = $tenant;
    }

    public function refresh(): void
    {
        if ($this->user) {
            $this->loadUser((int) $this->user['id']);
        }
    }

    public function attempt(string $email, string $password, bool $remember = false): bool
    {
        $user = DB::instance()->fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower(trim($email))]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            DB::instance()->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }
        $this->login($user, $remember);
        return true;
    }

    public function login(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::forget('impersonator_id');
        DB::instance()->update('users', [
            'last_login_at' => now(),
            'last_login_ip' => App::request()->ip(),
        ], 'id = :id', ['id' => $user['id']]);
        $this->resolved = false;
        $this->user = null;
        if ($remember) {
            $this->issueRememberCookie((int) $user['id']);
        }
    }

    public function logout(): void
    {
        if ($this->user()) {
            DB::instance()->update('users', ['remember_selector' => null, 'remember_hash' => null], 'id = :id', ['id' => $this->user['id']]);
        }
        $this->clearRememberCookie();
        Session::destroy();
        $this->user = null;
        $this->tenant = null;
        $this->resolved = true;
    }

    public function impersonate(int $userId): void
    {
        $admin = $this->user();
        if (!$admin || !$this->isSuperAdmin()) {
            throw new HttpException(403);
        }
        Session::regenerate();
        Session::set('impersonator_id', (int) $admin['id']);
        Session::set('user_id', $userId);
        $this->resolved = false;
    }

    public function stopImpersonating(): void
    {
        $adminId = (int) Session::get('impersonator_id', 0);
        if ($adminId > 0) {
            Session::forget('impersonator_id');
            Session::set('user_id', $adminId);
            $this->resolved = false;
        }
    }

    private function issueRememberCookie(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        DB::instance()->update('users', [
            'remember_selector' => $selector,
            'remember_hash' => hash('sha256', $validator),
        ], 'id = :id', ['id' => $userId]);
        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => App::basePath() ?: '/',
            'secure' => App::request()->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => App::basePath() ?: '/']);
        }
    }

    private function fromRememberCookie(): int
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return 0;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        if (!preg_match('/^[a-f0-9]{18}$/', $selector)) {
            return 0;
        }
        $user = DB::instance()->fetch('SELECT id, remember_hash FROM users WHERE remember_selector = ? LIMIT 1', [$selector]);
        if (!$user || !hash_equals((string) $user['remember_hash'], hash('sha256', $validator))) {
            $this->clearRememberCookie();
            return 0;
        }
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        return (int) $user['id'];
    }
}
