<?php
declare(strict_types=1);

/**
 * VoiceAgent - AI Voice & Chat Agent SaaS
 * Front controller: every non-static request is routed through here.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\App;

App::run();
