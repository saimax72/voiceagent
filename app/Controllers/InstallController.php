<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;

final class InstallController
{
    public function index(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $snippet = '<script src="' . base_url() . '/widget.js" data-agent-id="' . $agent['public_id'] . '"></script>';
        return view('install/index', [
            'title' => 'Install - ' . $agent['name'],
            'agent' => $agent,
            'snippet' => $snippet,
            'onboarding' => $request->boolean('onboarding'),
            'embedUrl' => url('/widget/embed/' . $agent['public_id']),
        ], 'layouts/app');
    }
}
