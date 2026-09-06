<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;
use App\Services\Plans;

/**
 * Visual widget customisation with live preview.
 */
final class CustomizeController
{
    private const COLORS = ['primary_color', 'header_bg', 'header_text', 'bg_color', 'text_color', 'bot_bubble_bg', 'bot_bubble_text', 'user_bubble_bg', 'user_bubble_text', 'button_color', 'button_text_color'];
    private const INTS = ['offset_x' => [0, 120], 'offset_y' => [0, 120], 'launcher_size' => [44, 90], 'popup_width' => [320, 560], 'popup_height' => [420, 860], 'border_radius' => [0, 40], 'auto_open_delay' => [1, 120]];
    private const TEXTS = ['launcher_label' => 40, 'header_title' => 60, 'header_subtitle' => 80, 'greeting_text' => 120, 'welcome_message' => 300, 'ask_me_text' => 60, 'input_placeholder' => 80, 'mic_text' => 60, 'listening_text' => 60, 'thinking_text' => 60, 'speaking_text' => 60, 'lead_form_title' => 120];
    private const BOOLS = ['launcher_pulse', 'show_lead_form', 'voice_mode_default', 'auto_open', 'sound_effects', 'show_branding'];
    private const ENUMS = ['position' => ['left', 'right'], 'launcher_shape' => ['circle', 'rounded', 'square'], 'launcher_icon' => ['chat', 'mic', 'sparkle', 'bot', 'custom'], 'avatar_style' => ['initials', 'image', 'icon'], 'theme' => ['light', 'dark']];

    public function index(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $tenant = current_tenant();
        $plan = Plans::forTenant($tenant);
        return view('customize/index', [
            'title' => 'Widget design - ' . $agent['name'],
            'agent' => $agent,
            'config' => Agents::widgetConfig($agent),
            'fonts' => Agents::FONTS,
            'canRemoveBranding' => (int) ($plan['limits']['remove_branding'] ?? 0) === 1,
            'onboarding' => $request->boolean('onboarding'),
            'previewUrl' => url('/widget/preview/' . $agent['public_id']),
        ], 'layouts/app');
    }

    public function save(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $tenant = current_tenant();
        $plan = Plans::forTenant($tenant);
        $input = $request->array('config');
        $config = Agents::widgetConfig($agent);
        foreach (self::COLORS as $key) {
            if (isset($input[$key]) && preg_match('/^#[0-9a-f]{6}$/i', (string) $input[$key])) {
                $config[$key] = strtolower((string) $input[$key]);
            }
        }
        foreach (self::INTS as $key => [$min, $max]) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $config[$key] = (int) max($min, min($max, (int) $input[$key]));
            }
        }
        foreach (self::TEXTS as $key => $max) {
            if (array_key_exists($key, $input)) {
                $config[$key] = mb_substr(trim(strip_tags((string) $input[$key])), 0, $max);
            }
        }
        foreach (self::BOOLS as $key) {
            if (array_key_exists($key, $input)) {
                $config[$key] = in_array($input[$key], [true, 1, '1', 'true', 'on'], true);
            }
        }
        foreach (self::ENUMS as $key => $allowed) {
            if (isset($input[$key]) && in_array($input[$key], $allowed, true)) {
                $config[$key] = $input[$key];
            }
        }
        if (isset($input['font']) && in_array($input['font'], Agents::FONTS, true)) {
            $config['font'] = $input['font'];
        }
        if (array_key_exists('suggested_questions', $input)) {
            $raw = is_array($input['suggested_questions']) ? $input['suggested_questions'] : explode("\n", (string) $input['suggested_questions']);
            $questions = [];
            foreach ($raw as $q) {
                $q = mb_substr(trim(strip_tags((string) $q)), 0, 120);
                if ($q !== '') {
                    $questions[] = $q;
                }
            }
            $config['suggested_questions'] = array_slice($questions, 0, 8);
        }
        foreach (['launcher_image', 'avatar_image'] as $key) {
            if (array_key_exists($key, $input)) {
                $value = trim((string) $input[$key]);
                $config[$key] = $this->safeImagePath($value, (int) $tenant['id']);
            }
        }
        if ((int) ($plan['limits']['remove_branding'] ?? 0) !== 1) {
            $config['show_branding'] = true;
        }
        Agents::saveWidgetConfig((int) $agent['id'], $config);
        return Response::json(['ok' => true, 'config' => $config]);
    }

    /** Only allow images uploaded by this tenant (or nothing). */
    private function safeImagePath(string $value, int $tenantId): string
    {
        if ($value === '') {
            return '';
        }
        $value = preg_replace('~^' . preg_quote(base_url(), '~') . '/~', '', $value) ?? $value;
        if (preg_match('~^uploads/' . $tenantId . '/[a-f0-9]{24}\.(png|jpg|jpeg|gif|webp|svg)$~', $value) && is_file(APP_ROOT . '/' . $value)) {
            return $value;
        }
        return '';
    }

    public function upload(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $tenantId = tenant_id();
        $file = $request->file('image');
        if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No image received.'], 422);
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return Response::json(['error' => 'Image must be smaller than 2 MB.'], 422);
        }
        $info = @getimagesize((string) $file['tmp_name']);
        $mime = $info['mime'] ?? (string) mime_content_type((string) $file['tmp_name']);
        $ext = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', default => null,
        };
        if ($ext === null) {
            return Response::json(['error' => 'Please upload a PNG, JPG, GIF, WebP or SVG image.'], 422);
        }
        if ($ext === 'svg') {
            $svg = (string) file_get_contents((string) $file['tmp_name']);
            if (preg_match('/<script|onload=|onerror=|javascript:/i', $svg)) {
                return Response::json(['error' => 'This SVG contains scripting and was rejected.'], 422);
            }
        }
        $dir = APP_ROOT . '/uploads/' . $tenantId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            return Response::json(['error' => 'Could not save the image.'], 500);
        }
        if ($ext !== 'svg' && function_exists('imagecreatefromstring') && $info && ($info[0] > 512 || $info[1] > 512)) {
            $this->resize($dir . '/' . $name, $ext, 512);
        }
        $path = 'uploads/' . $tenantId . '/' . $name;
        return Response::json(['ok' => true, 'path' => $path, 'url' => base_url() . '/' . $path]);
    }

    private function resize(string $path, string $ext, int $max): void
    {
        try {
            $src = imagecreatefromstring((string) file_get_contents($path));
            if (!$src) {
                return;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min($max / $w, $max / $h);
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            match ($ext) {
                'png' => imagepng($dst, $path, 8),
                'jpg' => imagejpeg($dst, $path, 88),
                'webp' => imagewebp($dst, $path, 88),
                default => imagepng($dst, $path),
            };
        } catch (\Throwable) {
            // keep original
        }
    }

    public function reset(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $config = Agents::defaultWidgetConfig();
        $config['header_title'] = $agent['name'];
        Agents::saveWidgetConfig((int) $agent['id'], $config);
        flash('success', 'Widget design reset to defaults.');
        return redirect('/agents/' . $agent['id'] . '/customize');
    }
}
