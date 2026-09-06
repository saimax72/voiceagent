<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;

/**
 * Pages that host the widget: the dashboard live preview / test page and a standalone embed page.
 */
final class WidgetController
{
    /** Dashboard preview: only the owning workspace (or a super admin) may open it. */
    public function preview(Request $request, string $publicId): Response
    {
        $agent = Agents::findByPublicId($publicId);
        if (!$agent) {
            abort(404);
        }
        if (!auth()->check() || ((int) $agent['tenant_id'] !== tenant_id() && !auth()->isSuperAdmin())) {
            abort(403);
        }
        $token = Crypto::sign(['preview' => $agent['public_id']], 86400);
        return view('widget/preview', [
            'agent' => $agent,
            'previewToken' => $token,
            'mode' => $request->string('mode') === 'test' ? 'test' : 'preview',
            'title' => 'Preview',
        ], null)->withHeaders(['X-Frame-Options' => 'SAMEORIGIN', 'Cache-Control' => 'no-store']);
    }

    /** Standalone full-page assistant (share link / QR code use). */
    public function embed(Request $request, string $publicId): Response
    {
        $agent = Agents::findByPublicId($publicId);
        if (!$agent || $agent['status'] !== 'active') {
            abort(404);
        }
        return view('widget/embed', ['agent' => $agent, 'title' => $agent['name']], null);
    }
}
