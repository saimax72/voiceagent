<?php
declare(strict_types=1);

/**
 * VoiceAgent web installer.
 * Open https://yourdomain.com/install.php in the browser, fill in the form, done.
 * After installation this file refuses to run again until config/config.php is removed.
 */

define('APP_ROOT', __DIR__);
define('APP_PATH', __DIR__ . '/app');
define('APP_VERSION', '1.4.0');
error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require APP_PATH . '/helpers.php';

use App\Core\DB;
use App\Services\Migrator;
use App\Services\Plans;
use App\Services\Settings;
use App\Services\Tenants;

$configFile = APP_ROOT . '/config/config.php';
$installed = is_file($configFile);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$detectedUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$requirements = [
    ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), ''],
    ['cURL extension', extension_loaded('curl'), ''],
    ['Multibyte string (mbstring)', extension_loaded('mbstring'), ''],
    ['OpenSSL extension', extension_loaded('openssl'), ''],
    ['DOM / libxml extension', extension_loaded('dom'), ''],
    ['JSON extension', function_exists('json_encode'), ''],
    ['Fileinfo extension', extension_loaded('fileinfo'), ''],
    ['Zip extension (for .docx uploads)', extension_loaded('zip'), 'optional'],
    ['GD extension (image resizing)', extension_loaded('gd'), 'optional'],
    ['config/ directory writable', is_writable(APP_ROOT . '/config'), ''],
    ['storage/ directory writable', is_writable(APP_ROOT . '/storage'), ''],
    ['uploads/ directory writable', is_writable(APP_ROOT . '/uploads'), ''],
];
$blocking = false;
foreach ($requirements as $req) {
    if (!$req[1] && $req[2] !== 'optional') {
        $blocking = true;
    }
}

$errors = [];
$success = null;
$values = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'app_url' => $detectedUrl, 'app_name' => 'VoiceAgent',
    'admin_name' => '', 'admin_email' => '', 'admin_password' => '',
    'anthropic_api_key' => '', 'openai_api_key' => '',
];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && !$blocking) {
    foreach ($values as $k => $v) {
        $values[$k] = trim((string) ($_POST[$k] ?? $v));
    }
    if ($values['db_name'] === '' || $values['db_user'] === '') {
        $errors[] = 'Database name and user are required.';
    }
    if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid admin email is required.';
    }
    if (strlen($values['admin_password']) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }
    if ($values['admin_name'] === '') {
        $errors[] = 'Admin name is required.';
    }
    if (!preg_match('~^https?://~i', $values['app_url'])) {
        $errors[] = 'Application URL must start with http:// or https://.';
    }

    $db = null;
    if (!$errors) {
        try {
            $db = DB::connect([
                'host' => $values['db_host'], 'port' => (int) $values['db_port'], 'name' => $values['db_name'],
                'user' => $values['db_user'], 'pass' => $values['db_pass'], 'charset' => 'utf8mb4',
            ]);
        } catch (\Throwable $e) {
            $errors[] = 'Could not connect to the database: ' . $e->getMessage();
        }
    }

    if (!$errors && $db) {
        try {
            $appKey = bin2hex(random_bytes(32));
            $cronToken = bin2hex(random_bytes(16));
            $config = [
                'app' => [
                    'name' => $values['app_name'] ?: 'VoiceAgent',
                    'url' => rtrim($values['app_url'], '/'),
                    'key' => $appKey,
                    'env' => 'production',
                    'debug' => false,
                    'timezone' => 'UTC',
                    'session_name' => 'va_session',
                    'cron_token' => $cronToken,
                ],
                'db' => [
                    'host' => $values['db_host'], 'port' => (int) $values['db_port'], 'name' => $values['db_name'],
                    'user' => $values['db_user'], 'pass' => $values['db_pass'], 'charset' => 'utf8mb4',
                ],
                'mail' => [
                    'driver' => 'mail',
                    'from_email' => 'no-reply@' . preg_replace('/^www\./', '', (string) parse_url($values['app_url'], PHP_URL_HOST)),
                    'from_name' => $values['app_name'] ?: 'VoiceAgent',
                    'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => ''],
                ],
            ];
            // Config must exist before Crypto (settings encryption) can run
            file_put_contents($configFile, "<?php\n// Generated by install.php on " . gmdate('c') . "\nreturn " . var_export($config, true) . ";\n");
            \App\Core\App::init($config);
            DB::setInstance($db);

            Migrator::run($db);
            Plans::seedDefaults($db);

            [$tenant, $user] = Tenants::create($values['app_name'] ?: 'Admin', $values['admin_name'], $values['admin_email'], $values['admin_password'], [
                'super_admin' => true, 'verified' => true, 'no_trial' => true,
            ]);
            $db->update('tenants', ['plan_key' => 'business'], 'id = :id', ['id' => $tenant['id']]);

            Settings::set('app_name', $values['app_name'] ?: 'VoiceAgent');
            Settings::set('support_email', $values['admin_email']);
            Settings::set('mail_from_email', $config['mail']['from_email']);
            Settings::set('mail_from_name', $config['mail']['from_name']);
            if ($values['anthropic_api_key'] !== '') {
                Settings::set('anthropic_api_key', $values['anthropic_api_key']);
            }
            if ($values['openai_api_key'] !== '') {
                Settings::set('openai_api_key', $values['openai_api_key']);
            }
            foreach (['storage/documents', 'storage/cache/tts', 'storage/logs', 'storage/tmp'] as $dir) {
                if (!is_dir(APP_ROOT . '/' . $dir)) {
                    @mkdir(APP_ROOT . '/' . $dir, 0755, true);
                }
            }
            $success = ['url' => rtrim($values['app_url'], '/'), 'cron_token' => $cronToken, 'email' => $values['admin_email']];
            $installed = true;
        } catch (\Throwable $e) {
            @unlink($configFile);
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install VoiceAgent</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;font-family:'DM Sans',Inter,system-ui,sans-serif;background:#f6f7f9;color:#141b25;-webkit-font-smoothing:antialiased}
.wrap{max-width:760px;margin:40px auto;padding:0 20px}.card{background:#fff;border:1px solid #e6e8ee;border-radius:10px;padding:32px;box-shadow:0 10px 40px rgba(15,23,42,.06);margin-bottom:20px}
h1,h2{font-family:Outfit,'DM Sans',sans-serif;letter-spacing:-.02em;font-weight:600}h1{font-size:26px;margin:0 0 6px}h2{font-size:17px;margin:28px 0 12px}p{color:#475569;line-height:1.6}.brand{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.logo{width:44px;height:44px;border-radius:10px;background:#141b25;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
input{width:100%;padding:11px 13px;border:1px solid #d0d5dd;border-radius:6px;font:inherit;font-size:14px}input:focus{outline:none;border-color:#0052fc;box-shadow:0 0 0 3px rgba(0,82,252,.15)}
.field{margin-bottom:14px}.hint{font-size:12px;color:#64748b;margin-top:4px}.btn{background:#0052fc;color:#fff;border:0;padding:13px 22px;border-radius:5px;font:inherit;font-weight:700;font-size:15px;cursor:pointer}
.btn:hover{background:#0043d9}.req{list-style:none;padding:0;margin:0}.req li{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f1f3f8;font-size:14px}
.ok{color:#059669;font-weight:600}.bad{color:#dc2626;font-weight:600}.opt{color:#d97706;font-weight:600}.alert{padding:12px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.alert-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
code{background:#f1f3f8;padding:2px 6px;border-radius:6px;font-size:13px;word-break:break-all}pre{background:#141b25;color:#e2e8f0;padding:14px;border-radius:8px;font-size:13px;overflow:auto}
@media(max-width:640px){.grid{grid-template-columns:1fr}.card{padding:22px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="logo"><svg width="26" height="26" viewBox="0 0 64 64" fill="#0052FC"><rect x="3" y="4" width="9" height="34" rx="4.5"/><rect x="16" y="15" width="9" height="34" rx="4.5"/><rect x="29" y="26" width="9" height="34" rx="4.5"/><rect x="42" y="15" width="9" height="34" rx="4.5"/><rect x="55" y="4" width="9" height="34" rx="4.5"/></svg></div><div><strong>VoiceAgent</strong><div style="font-size:13px;color:#64748b">Installer v<?= APP_VERSION ?></div></div></div>

<?php if ($success): ?>
  <div class="card">
    <div class="alert alert-success">Installation complete. Your platform is ready.</div>
    <h1>You're all set</h1>
    <p>Sign in with <strong><?= $e($success['email']) ?></strong> and the password you chose. You are the platform administrator.</p>
    <p><a class="btn" style="display:inline-block;text-decoration:none" href="<?= $e($success['url']) ?>/login">Open the dashboard</a></p>
    <h2>1. Add your AI provider keys</h2>
    <p>Go to <strong>Admin &rarr; Settings &rarr; AI providers</strong> to add or change API keys (Anthropic for the assistant, OpenAI or Voyage for embeddings and premium voice).</p>
    <h2>2. Set up the background worker (recommended)</h2>
    <p>Website scans and document processing run in the background. In Hostinger hPanel open <strong>Advanced &rarr; Cron Jobs</strong> and add a job that runs every minute:</p>
    <pre>php <?= $e(APP_ROOT) ?>/cron/worker.php</pre>
    <p>Or, if you prefer a web cron, call this URL every minute:</p>
    <pre><?= $e($success['url']) ?>/webcron/run?token=<?= $e($success['cron_token']) ?></pre>
    <p class="hint">Without a cron job the dashboard still processes jobs while you keep the page open, just more slowly.</p>
    <h2>3. Security</h2>
    <p>For safety, delete <code>install.php</code> from your server now. Your configuration is stored in <code>config/config.php</code>.</p>
  </div>
<?php elseif ($installed): ?>
  <div class="card">
    <h1>Already installed</h1>
    <p>VoiceAgent is already installed. To run the installer again, delete <code>config/config.php</code> first.</p>
    <p><a class="btn" style="display:inline-block;text-decoration:none" href="<?= $e($detectedUrl) ?>/login">Go to login</a></p>
  </div>
<?php else: ?>
  <div class="card">
    <h1>Install VoiceAgent</h1>
    <p>This wizard checks your server, creates the database tables and your administrator account.</p>
    <h2>Server requirements</h2>
    <ul class="req">
      <?php foreach ($requirements as [$label, $ok, $note]): ?>
        <li><span><?= $e($label) ?><?= $note && $note !== 'optional' ? ' <span class="hint">(' . $e($note) . ')</span>' : '' ?></span>
          <?php if ($ok): ?><span class="ok">OK</span><?php elseif ($note === 'optional'): ?><span class="opt">Missing (optional)</span><?php else: ?><span class="bad">Missing</span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($blocking): ?>
    <div class="card"><div class="alert alert-error">Please fix the missing requirements above, then reload this page. On Hostinger you can enable PHP extensions under <strong>Advanced &rarr; PHP Configuration</strong>.</div></div>
  <?php else: ?>
  <form method="post" class="card" autocomplete="off">
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= $e($err) ?></div><?php endforeach; ?>
    <h2 style="margin-top:0">Database (MySQL)</h2>
    <p class="hint" style="margin-bottom:14px">Create a database in hPanel &rarr; Databases &rarr; MySQL Databases, then enter the details here.</p>
    <div class="grid">
      <div class="field"><label>Host</label><input name="db_host" value="<?= $e($values['db_host']) ?>" required></div>
      <div class="field"><label>Port</label><input name="db_port" value="<?= $e($values['db_port']) ?>" required></div>
      <div class="field"><label>Database name</label><input name="db_name" value="<?= $e($values['db_name']) ?>" required placeholder="u123456_voiceagent"></div>
      <div class="field"><label>Database user</label><input name="db_user" value="<?= $e($values['db_user']) ?>" required placeholder="u123456_voiceagent"></div>
      <div class="field"><label>Database password</label><input name="db_pass" type="password" value="<?= $e($values['db_pass']) ?>"></div>
    </div>
    <h2>Application</h2>
    <div class="grid">
      <div class="field"><label>Application URL</label><input name="app_url" value="<?= $e($values['app_url']) ?>" required><div class="hint">Used in embed codes and emails. No trailing slash.</div></div>
      <div class="field"><label>Platform name</label><input name="app_name" value="<?= $e($values['app_name']) ?>" required></div>
    </div>
    <h2>Administrator account</h2>
    <div class="grid">
      <div class="field"><label>Your name</label><input name="admin_name" value="<?= $e($values['admin_name']) ?>" required></div>
      <div class="field"><label>Email</label><input name="admin_email" type="email" value="<?= $e($values['admin_email']) ?>" required></div>
      <div class="field"><label>Password</label><input name="admin_password" type="password" minlength="8" required><div class="hint">At least 8 characters.</div></div>
    </div>
    <h2>AI provider keys (optional, can be added later in Admin &rarr; Settings)</h2>
    <div class="grid">
      <div class="field"><label>Anthropic API key</label><input name="anthropic_api_key" value="<?= $e($values['anthropic_api_key']) ?>" placeholder="sk-ant-..."><div class="hint">Powers the assistant (Claude).</div></div>
      <div class="field"><label>OpenAI API key</label><input name="openai_api_key" value="<?= $e($values['openai_api_key']) ?>" placeholder="sk-..."><div class="hint">Embeddings, speech-to-text and premium voices.</div></div>
    </div>
    <button class="btn" type="submit">Install VoiceAgent</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
