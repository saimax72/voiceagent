<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Http;
use App\Core\Logger;
use App\Services\Settings;

/**
 * Speech-to-text and text-to-speech. Falls back to browser-native speech when no premium provider applies.
 */
final class Speech
{
    public const OPENAI_VOICES = [
        'alloy' => 'Alloy - neutral, balanced',
        'ash' => 'Ash - confident, clear (male)',
        'ballad' => 'Ballad - expressive, warm (male)',
        'coral' => 'Coral - warm, friendly (female)',
        'echo' => 'Echo - calm, steady (male)',
        'fable' => 'Fable - storyteller, British accent',
        'nova' => 'Nova - bright, upbeat (female)',
        'onyx' => 'Onyx - deep, authoritative (male)',
        'sage' => 'Sage - soft, calm (female)',
        'shimmer' => 'Shimmer - clear, energetic (female)',
        'verse' => 'Verse - versatile, natural',
    ];

    public const ELEVENLABS_VOICES = [
        '21m00Tcm4TlvDq8ikWAM' => 'Rachel - calm, young (female)',
        'EXAVITQu4vr4xnSDxMaL' => 'Sarah - soft, news (female)',
        'FGY2WhTYpPnrIDTdsKH5' => 'Laura - upbeat (female)',
        'IKne3meq5aSn9XLyUdCD' => 'Charlie - natural, Australian (male)',
        'JBFqnCBsd6RMkjVDRZzb' => 'George - warm, British (male)',
        'TX3LPaxmHKxFdv7VOQHJ' => 'Liam - articulate (male)',
        'XB0fDUlXrJqFGnnbcTvk' => 'Charlotte - seductive, Swedish (female)',
        'Xb7hH8MSUJpSbSDYk0k2' => 'Alice - confident, British (female)',
        'cgSgspJ2msm6clMCkdW9' => 'Jessica - expressive (female)',
        'iP95p4xoKVk53GoZ742B' => 'Chris - casual (male)',
        'nPczCjzI2devNBz1zQrb' => 'Brian - deep, narration (male)',
        'onwK4e9ZLuTAKqWW03F9' => 'Daniel - authoritative, British (male)',
        'pFZP5JQG7iQjIQuC4Bku' => 'Lily - warm, British (female)',
    ];

    public static function hasOpenAI(): bool
    {
        return (string) Settings::get('openai_api_key', '') !== '';
    }

    public static function hasElevenLabs(): bool
    {
        return (string) Settings::get('elevenlabs_api_key', '') !== '';
    }

    /** Resolve the STT mode for an agent: 'server' (OpenAI transcription) or 'browser'. */
    public static function sttMode(array $agent, bool $premiumAllowed): string
    {
        $pref = (string) ($agent['stt_provider'] ?? 'auto');
        $platform = (string) Settings::get('stt_provider', 'auto');
        if ($pref === 'browser' || $platform === 'browser' || !$premiumAllowed) {
            return 'browser';
        }
        return self::hasOpenAI() ? 'server' : 'browser';
    }

    /** Resolve the TTS provider for an agent: 'openai', 'elevenlabs' or 'browser'. */
    public static function ttsMode(array $agent, bool $premiumAllowed): string
    {
        $pref = (string) ($agent['tts_provider'] ?? 'auto');
        $platform = (string) Settings::get('tts_provider', 'auto');
        if ($pref === 'browser' || $platform === 'browser' || !$premiumAllowed) {
            return 'browser';
        }
        if ($pref === 'elevenlabs' && self::hasElevenLabs()) {
            return 'elevenlabs';
        }
        if ($pref === 'openai' && self::hasOpenAI()) {
            return 'openai';
        }
        if ($pref === 'auto') {
            if ($platform === 'elevenlabs' && self::hasElevenLabs()) {
                return 'elevenlabs';
            }
            if (self::hasOpenAI()) {
                return 'openai';
            }
            if (self::hasElevenLabs()) {
                return 'elevenlabs';
            }
        }
        return 'browser';
    }

    /** Voice options per provider for the agent settings form. */
    public static function voiceOptions(): array
    {
        return [
            'openai' => self::OPENAI_VOICES,
            'elevenlabs' => self::ELEVENLABS_VOICES,
        ];
    }

    /**
     * Transcribe an audio file with OpenAI. Returns ['text' => string].
     */
    public static function transcribe(string $path, string $mime, ?string $language = null): array
    {
        $key = (string) Settings::get('openai_api_key', '');
        if ($key === '') {
            throw new \RuntimeException('Speech-to-text is not configured.');
        }
        $ext = match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'mp4') || str_contains($mime, 'm4a') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            default => 'webm',
        };
        $multipart = [
            'file' => new \CURLFile($path, $mime ?: 'audio/webm', 'audio.' . $ext),
            'model' => (string) Settings::get('openai_stt_model', 'gpt-4o-mini-transcribe'),
            'response_format' => 'json',
        ];
        if ($language && $language !== 'auto' && preg_match('/^[a-z]{2}$/', $language)) {
            $multipart['language'] = $language;
        }
        $response = Http::post('https://api.openai.com/v1/audio/transcriptions', [
            'multipart' => $multipart,
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 60,
        ]);
        if (!$response->ok()) {
            $data = $response->json();
            Logger::error('STT error ' . $response->status . ': ' . mb_substr((string) ($data['error']['message'] ?? $response->body ?: $response->error), 0, 300));
            throw new \RuntimeException('Could not transcribe the audio. Please try again.');
        }
        $data = $response->json();
        return ['text' => trim((string) ($data['text'] ?? ''))];
    }

    /**
     * Synthesize speech. Returns ['audio' => binary, 'mime' => 'audio/mpeg', 'cached' => bool].
     */
    public static function synthesize(string $text, string $provider, string $voice, float $speed = 1.0): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $text = mb_substr($text, 0, 4000);
        if ($text === '') {
            throw new \RuntimeException('Nothing to speak.');
        }
        $speed = max(0.5, min(2.0, $speed));
        $cacheDir = APP_ROOT . '/storage/cache/tts';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/' . hash('sha256', $provider . '|' . $voice . '|' . $speed . '|' . $text) . '.mp3';
        if (is_file($cacheFile) && filesize($cacheFile) > 0) {
            @touch($cacheFile);
            return ['audio' => (string) file_get_contents($cacheFile), 'mime' => 'audio/mpeg', 'cached' => true];
        }
        $audio = $provider === 'elevenlabs' ? self::elevenLabs($text, $voice, $speed) : self::openAI($text, $voice, $speed);
        @file_put_contents($cacheFile, $audio);
        return ['audio' => $audio, 'mime' => 'audio/mpeg', 'cached' => false];
    }

    private static function openAI(string $text, string $voice, float $speed): string
    {
        $key = (string) Settings::get('openai_api_key', '');
        if ($key === '') {
            throw new \RuntimeException('Text-to-speech is not configured.');
        }
        if (!isset(self::OPENAI_VOICES[$voice])) {
            $voice = 'alloy';
        }
        $model = (string) Settings::get('openai_tts_model', 'gpt-4o-mini-tts');
        $body = ['model' => $model, 'input' => $text, 'voice' => $voice, 'response_format' => 'mp3', 'speed' => $speed];
        if (str_contains($model, 'gpt-4o')) {
            $body['instructions'] = 'Speak naturally and clearly, like a friendly customer support assistant.';
        }
        $response = Http::postJson('https://api.openai.com/v1/audio/speech', $body, ['Authorization' => 'Bearer ' . $key], ['timeout' => 60]);
        if (!$response->ok()) {
            $data = $response->json();
            Logger::error('TTS error ' . $response->status . ': ' . mb_substr((string) ($data['error']['message'] ?? $response->error), 0, 300));
            throw new \RuntimeException('Could not generate speech audio.');
        }
        return $response->body;
    }

    private static function elevenLabs(string $text, string $voiceId, float $speed): string
    {
        $key = (string) Settings::get('elevenlabs_api_key', '');
        if ($key === '') {
            throw new \RuntimeException('ElevenLabs is not configured.');
        }
        if (!preg_match('/^[A-Za-z0-9]{10,40}$/', $voiceId)) {
            $voiceId = '21m00Tcm4TlvDq8ikWAM';
        }
        $response = Http::postJson('https://api.elevenlabs.io/v1/text-to-speech/' . $voiceId . '?output_format=mp3_44100_128', [
            'text' => $text,
            'model_id' => (string) Settings::get('elevenlabs_model', 'eleven_flash_v2_5'),
            'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75, 'speed' => max(0.7, min(1.2, $speed))],
        ], ['xi-api-key' => $key, 'Accept' => 'audio/mpeg'], ['timeout' => 60]);
        if (!$response->ok()) {
            Logger::error('ElevenLabs error ' . $response->status . ': ' . mb_substr($response->body ?: $response->error, 0, 300));
            throw new \RuntimeException('Could not generate speech audio.');
        }
        return $response->body;
    }
}
