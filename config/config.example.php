<?php
/**
 * VoiceAgent configuration.
 * Copy to config/config.php (the web installer does this for you).
 */
return [
    'app' => [
        'name'      => 'VoiceAgent',
        'url'       => 'https://yourdomain.com',   // no trailing slash; leave empty to auto-detect
        'key'       => 'CHANGE_ME_32_BYTE_RANDOM_KEY',  // used for encryption & signing
        'env'       => 'production',                // production | local
        'debug'     => false,
        'timezone'  => 'UTC',
        'session_name' => 'va_session',
        'cron_token'   => 'CHANGE_ME_CRON_TOKEN',   // for web-triggered cron: /webcron/run?token=...
    ],
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'u123456_voiceagent',
        'user'     => 'u123456_voiceagent',
        'pass'     => 'secret',
        'charset'  => 'utf8mb4',
        'prefix'   => '',
    ],
    'mail' => [
        'driver'     => 'mail',      // mail | smtp | log
        'from_email' => 'no-reply@yourdomain.com',
        'from_name'  => 'VoiceAgent',
        'smtp' => [
            'host'       => 'smtp.hostinger.com',
            'port'       => 465,
            'encryption' => 'ssl',   // ssl | tls | none
            'username'   => '',
            'password'   => '',
        ],
    ],
];
