<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Plans;
use App\Services\Settings;

final class SiteController
{
    public function home(Request $request): Response
    {
        return view('site/home', [
            'title' => app_name() . ' - AI voice & chat agents for your website',
            'plans' => Plans::all(true),
            'demoAgent' => (string) Settings::get('demo_agent_public_id', ''),
        ], 'layouts/site');
    }

    public function pricing(Request $request): Response
    {
        return view('site/pricing', [
            'title' => 'Pricing - ' . app_name(),
            'plans' => Plans::all(true),
        ], 'layouts/site');
    }

    public function bot(Request $request): Response
    {
        return view('site/bot', ['title' => 'About our crawler - ' . app_name()], 'layouts/site');
    }

    public function suspended(Request $request): Response
    {
        return view('site/suspended', ['title' => 'Workspace suspended'], 'layouts/minimal');
    }
}
