<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Tenants;

final class SettingsController
{
    public function index(Request $request): Response
    {
        $tenant = current_tenant();
        return view('settings/index', [
            'title' => 'Settings',
            'user' => current_user(),
            'tenant' => $tenant,
            'tenantSettings' => Tenants::settings($tenant),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ], 'layouts/app');
    }

    public function profile(Request $request): Response
    {
        $user = current_user();
        $v = Validator::make($request->all(), ['name' => 'required|min:2|max:120', 'email' => 'required|email', 'timezone' => 'nullable|max:60']);
        if ($v->fails()) {
            flash('error', $v->firstError() ?? 'Invalid input.');
            return redirect('/settings');
        }
        $d = $v->validated();
        $email = strtolower((string) $d['email']);
        if ($email !== $user['email'] && DB::instance()->exists('users', 'email = ? AND id <> ?', [$email, (int) $user['id']])) {
            flash('error', 'That email address is already in use.');
            return redirect('/settings');
        }
        $tz = in_array($d['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true) ? $d['timezone'] : 'UTC';
        DB::instance()->update('users', ['name' => $d['name'], 'email' => $email, 'timezone' => $tz, 'updated_at' => now()], 'id = :id', ['id' => (int) $user['id']]);
        flash('success', 'Profile updated.');
        return redirect('/settings');
    }

    public function password(Request $request): Response
    {
        $user = current_user();
        if (!password_verify((string) $request->input('current_password'), (string) $user['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
            return redirect('/settings');
        }
        $v = Validator::make($request->all(), ['password' => 'required|min:8|confirmed']);
        if ($v->fails()) {
            flash('error', $v->firstError() ?? 'Invalid password.');
            return redirect('/settings');
        }
        DB::instance()->update('users', ['password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT), 'updated_at' => now()], 'id = :id', ['id' => (int) $user['id']]);
        flash('success', 'Password changed.');
        return redirect('/settings');
    }

    public function workspace(Request $request): Response
    {
        $tenant = current_tenant();
        $v = Validator::make($request->all(), ['name' => 'required|min:2|max:160', 'notification_email' => 'nullable|email']);
        if ($v->fails()) {
            flash('error', $v->firstError() ?? 'Invalid input.');
            return redirect('/settings');
        }
        $d = $v->validated();
        DB::instance()->update('tenants', ['name' => $d['name'], 'updated_at' => now()], 'id = :id', ['id' => (int) $tenant['id']]);
        Tenants::updateSettings((int) $tenant['id'], [
            'notification_email' => $d['notification_email'] ?: current_user()['email'],
            'lead_emails_disabled' => !$request->boolean('lead_emails', true),
        ]);
        flash('success', 'Workspace settings saved.');
        return redirect('/settings');
    }

    public function deleteAccount(Request $request): Response
    {
        $user = current_user();
        $tenant = current_tenant();
        if (!auth()->isOwner() || auth()->isSuperAdmin()) {
            flash('error', 'Only the workspace owner can delete the account.');
            return redirect('/settings');
        }
        if (!password_verify((string) $request->input('password'), (string) $user['password_hash'])) {
            flash('error', 'Password is incorrect.');
            return redirect('/settings');
        }
        auth()->logout();
        Tenants::delete((int) $tenant['id']);
        return redirect('/login');
    }
}
