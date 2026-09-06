<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Http;
use App\Core\Logger;
use App\Services\Settings;

/**
 * Text embeddings via OpenAI or Voyage AI. Vectors are stored as packed little-endian floats.
 */
final class Embeddings
{
    /** Active provider name or null when no embedding provider is available. */
    public static function provider(): ?string
    {
        $setting = (string) Settings::get('embeddings_provider', 'auto');
        $hasOpenAI = (string) Settings::get('openai_api_key', '') !== '';
        $hasVoyage = (string) Settings::get('voyage_api_key', '') !== '';
        return match ($setting) {
            'openai' => $hasOpenAI ? 'openai' : null,
            'voyage' => $hasVoyage ? 'voyage' : null,
            'none' => null,
            default => $hasOpenAI ? 'openai' : ($hasVoyage ? 'voyage' : null),
        };
    }

    public static function available(): bool
    {
        return self::provider() !== null;
    }

    public static function model(): string
    {
        return match (self::provider()) {
            'openai' => (string) Settings::get('openai_embedding_model', 'text-embedding-3-small'),
            'voyage' => (string) Settings::get('voyage_embedding_model', 'voyage-3.5-lite'),
            default => '',
        };
    }

    public static function dimensions(): int
    {
        $dims = Settings::int('embedding_dimensions', 768);
        if (self::provider() === 'voyage') {
            // Voyage 3.5 models accept 256 / 512 / 1024 / 2048
            $allowed = [256, 512, 1024, 2048];
            if (!in_array($dims, $allowed, true)) {
                $dims = 1024;
            }
        }
        return max(64, min(3072, $dims));
    }

    /** Identifier stored with each chunk so mismatched vectors are never compared. */
    public static function signature(): string
    {
        return self::provider() ? self::provider() . ':' . self::model() . ':' . self::dimensions() : '';
    }

    /**
     * @param string[] $texts
     * @return float[][] one vector per input text (same order)
     */
    public static function embed(array $texts, string $inputType = 'document'): array
    {
        $texts = array_values(array_map(static fn($t) => mb_substr(trim((string) $t), 0, 8000) ?: ' ', $texts));
        if ($texts === []) {
            return [];
        }
        $provider = self::provider();
        if ($provider === null) {
            throw new \RuntimeException('No embedding provider configured.');
        }
        $vectors = [];
        foreach (array_chunk($texts, 48) as $batch) {
            $vectors = array_merge($vectors, $provider === 'openai' ? self::embedOpenAI($batch) : self::embedVoyage($batch, $inputType));
        }
        return $vectors;
    }

    /**
     * Embed a search query, served from the database cache when the same question was asked before.
     * @return float[]
     */
    public static function embedQuery(string $text): array
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
        $signature = self::signature();
        $hash = sha1($signature . '|' . $normalized);
        try {
            $cached = \App\Core\DB::instance()->fetchColumn('SELECT embedding FROM embedding_cache WHERE hash = ? LIMIT 1', [$hash]);
            if (is_string($cached) && $cached !== '') {
                return self::unpack($cached);
            }
        } catch (\Throwable) {
            // cache table missing: fall through to a live call
        }
        $vector = self::embed([$text], 'query')[0] ?? [];
        if ($vector) {
            try {
                \App\Core\DB::instance()->query('INSERT IGNORE INTO embedding_cache (hash, model, embedding, created_at) VALUES (?, ?, ?, ?)', [$hash, $signature, self::pack($vector), now()]);
            } catch (\Throwable) {
                // never fail a request because the cache could not be written
            }
        }
        return $vector;
    }

    private static function embedOpenAI(array $batch): array
    {
        $response = Http::postJson('https://api.openai.com/v1/embeddings', [
            'model' => self::model(),
            'input' => $batch,
            'dimensions' => self::dimensions(),
        ], ['Authorization' => 'Bearer ' . Settings::get('openai_api_key')], ['timeout' => 60]);
        if (!$response->ok()) {
            throw new \RuntimeException('Embedding request failed: ' . self::apiError($response->status, $response->body, $response->error));
        }
        $data = $response->json();
        $out = [];
        foreach ((array) ($data['data'] ?? []) as $item) {
            $out[(int) $item['index']] = array_map('floatval', $item['embedding']);
        }
        ksort($out);
        if (count($out) !== count($batch)) {
            throw new \RuntimeException('Embedding response was incomplete.');
        }
        self::recordTokens((int) ($data['usage']['total_tokens'] ?? 0));
        return array_values($out);
    }

    private static function embedVoyage(array $batch, string $inputType): array
    {
        $response = Http::postJson('https://api.voyageai.com/v1/embeddings', [
            'model' => self::model(),
            'input' => $batch,
            'input_type' => $inputType === 'query' ? 'query' : 'document',
            'output_dimension' => self::dimensions(),
        ], ['Authorization' => 'Bearer ' . Settings::get('voyage_api_key')], ['timeout' => 60]);
        if (!$response->ok()) {
            throw new \RuntimeException('Embedding request failed: ' . self::apiError($response->status, $response->body, $response->error));
        }
        $data = $response->json();
        $out = [];
        foreach ((array) ($data['data'] ?? []) as $item) {
            $out[(int) $item['index']] = array_map('floatval', $item['embedding']);
        }
        ksort($out);
        if (count($out) !== count($batch)) {
            throw new \RuntimeException('Embedding response was incomplete.');
        }
        self::recordTokens((int) ($data['usage']['total_tokens'] ?? 0));
        return array_values($out);
    }

    private static int $tokensUsed = 0;

    private static function recordTokens(int $tokens): void
    {
        self::$tokensUsed += $tokens;
    }

    /** Tokens consumed by embedding calls in this process (for usage metering). */
    public static function takeTokensUsed(): int
    {
        $t = self::$tokensUsed;
        self::$tokensUsed = 0;
        return $t;
    }

    private static function apiError(int $status, string $body, string $transport): string
    {
        if ($transport !== '') {
            return $transport;
        }
        $data = json_decode($body, true);
        $message = (string) ($data['error']['message'] ?? $data['detail'] ?? $body);
        Logger::error('Embedding API error ' . $status . ': ' . mb_substr($message, 0, 300));
        return $status === 401 ? 'invalid API key' : mb_substr($message, 0, 200);
    }

    public static function pack(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /** @return float[] */
    public static function unpack(string $blob): array
    {
        if ($blob === '') {
            return [];
        }
        return array_values(unpack('f*', $blob) ?: []);
    }

    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /** Normalise a vector to unit length so similarity is a plain dot product. */
    public static function normalize(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm == 0.0) {
            return $vector;
        }
        foreach ($vector as $i => $v) {
            $vector[$i] = $v / $norm;
        }
        return $vector;
    }
}
