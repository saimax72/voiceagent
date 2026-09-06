<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Services\AI\Embeddings;
use App\Services\Jobs\JobQueue;
use App\Services\Settings;
use App\Services\Usage;

/**
 * Stores documents, splits them into chunks and embeds them.
 */
final class Indexer
{
    /**
     * Insert or update a document for a source. Returns ['id' => int, 'changed' => bool].
     */
    public static function upsertDocument(array $source, string $title, ?string $url, string $content, array $meta = []): array
    {
        $db = DB::instance();
        $content = trim($content);
        $hash = sha1($content);
        $urlHash = $url !== null ? sha1($url) : null;
        $title = mb_substr(trim($title) !== '' ? trim($title) : 'Untitled', 0, 490);
        $existing = null;
        if ($urlHash !== null) {
            $existing = $db->fetch('SELECT * FROM knowledge_documents WHERE agent_id = ? AND url_hash = ? AND source_id = ? LIMIT 1', [(int) $source['agent_id'], $urlHash, (int) $source['id']]);
        }
        $now = now();
        if ($existing) {
            if ($existing['content_hash'] === $hash && $existing['status'] === 'indexed') {
                $db->update('knowledge_documents', ['title' => $title, 'updated_at' => $now], 'id = :id', ['id' => $existing['id']]);
                return ['id' => (int) $existing['id'], 'changed' => false];
            }
            $db->delete('knowledge_chunks', 'document_id = ?', [(int) $existing['id']]);
            $db->update('knowledge_documents', [
                'title' => $title, 'content' => $content, 'content_hash' => $hash, 'char_count' => mb_strlen($content),
                'chunk_count' => 0, 'status' => 'pending', 'error_message' => null,
                'meta' => json_encode(array_merge(json_field($existing['meta']), $meta)), 'updated_at' => $now,
            ], 'id = :id', ['id' => $existing['id']]);
            return ['id' => (int) $existing['id'], 'changed' => true];
        }
        $id = $db->insert('knowledge_documents', [
            'tenant_id' => (int) $source['tenant_id'], 'agent_id' => (int) $source['agent_id'], 'source_id' => (int) $source['id'],
            'title' => $title, 'url' => $url !== null ? mb_substr($url, 0, 1000) : null, 'url_hash' => $urlHash,
            'content' => $content, 'content_hash' => $hash, 'char_count' => mb_strlen($content), 'chunk_count' => 0,
            'status' => 'pending', 'meta' => json_encode($meta), 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['id' => $id, 'changed' => true];
    }

    /** Chunk and embed a single document. */
    public static function indexDocument(array $doc): void
    {
        $db = DB::instance();
        $db->delete('knowledge_chunks', 'document_id = ?', [(int) $doc['id']]);
        $chunks = Chunker::split((string) $doc['content'], Settings::int('chunk_size', 1600), Settings::int('chunk_overlap', 200));
        if ($chunks === []) {
            $db->update('knowledge_documents', ['status' => 'skipped', 'chunk_count' => 0, 'error_message' => 'No text content', 'updated_at' => now()], 'id = :id', ['id' => $doc['id']]);
            return;
        }
        $chunks = array_slice($chunks, 0, 400);
        $vectors = [];
        $signature = '';
        if (Embeddings::available()) {
            $texts = [];
            foreach ($chunks as $chunk) {
                $prefix = $doc['title'] . ($chunk['heading'] ? ' - ' . $chunk['heading'] : '');
                $texts[] = $prefix . "\n" . $chunk['content'];
            }
            $vectors = Embeddings::embed($texts, 'document');
            $signature = Embeddings::signature();
            $tokens = Embeddings::takeTokensUsed();
            if ($tokens > 0) {
                Usage::increment((int) $doc['tenant_id'], (int) $doc['agent_id'], 'embedding_tokens', $tokens);
            }
        }
        $now = now();
        $stmt = $db->pdo()->prepare('INSERT INTO knowledge_chunks (tenant_id, agent_id, document_id, chunk_index, heading, content, token_count, embedding, embedding_model, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($chunks as $i => $chunk) {
            $embedding = isset($vectors[$i]) ? Embeddings::pack(Embeddings::normalize($vectors[$i])) : null;
            $stmt->bindValue(1, (int) $doc['tenant_id'], \PDO::PARAM_INT);
            $stmt->bindValue(2, (int) $doc['agent_id'], \PDO::PARAM_INT);
            $stmt->bindValue(3, (int) $doc['id'], \PDO::PARAM_INT);
            $stmt->bindValue(4, $i, \PDO::PARAM_INT);
            $stmt->bindValue(5, $chunk['heading']);
            $stmt->bindValue(6, $chunk['content']);
            $stmt->bindValue(7, (int) ceil(mb_strlen($chunk['content']) / 4), \PDO::PARAM_INT);
            $stmt->bindValue(8, $embedding, $embedding === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            $stmt->bindValue(9, $signature !== '' ? $signature : null);
            $stmt->bindValue(10, $now);
            $stmt->execute();
        }
        $db->update('knowledge_documents', ['status' => 'indexed', 'chunk_count' => count($chunks), 'error_message' => null, 'updated_at' => $now], 'id = :id', ['id' => $doc['id']]);
    }

    /**
     * Index pending documents for an agent until the deadline. Returns true when nothing is left.
     * @param callable(int,int):void|null $progress
     */
    public static function indexPending(int $agentId, float $deadline, ?callable $progress = null, ?int $sourceId = null): bool
    {
        $db = DB::instance();
        $where = 'agent_id = ? AND status = \'pending\'' . ($sourceId ? ' AND source_id = ' . (int) $sourceId : '');
        $total = $db->count('knowledge_documents', str_replace('status = \'pending\'', '1=1', $where), [$agentId]);
        while (microtime(true) < $deadline - 3) {
            $doc = $db->fetch('SELECT * FROM knowledge_documents WHERE ' . $where . ' ORDER BY id ASC LIMIT 1', [$agentId]);
            if (!$doc) {
                if ($progress) {
                    $progress($total, $total);
                }
                return true;
            }
            try {
                self::indexDocument($doc);
            } catch (\Throwable $e) {
                Logger::error('Indexing failed for document ' . $doc['id'] . ': ' . $e->getMessage());
                $db->update('knowledge_documents', ['status' => 'error', 'error_message' => mb_substr($e->getMessage(), 0, 490), 'updated_at' => now()], 'id = :id', ['id' => $doc['id']]);
                if (str_contains(strtolower($e->getMessage()), 'api key') || str_contains($e->getMessage(), 'rate')) {
                    throw $e; // configuration / quota problems should fail the job visibly
                }
            }
            if ($progress) {
                $pending = $db->count('knowledge_documents', $where, [$agentId]);
                $progress(max(0, $total - $pending), $total);
            }
        }
        return $db->count('knowledge_documents', $where, [$agentId]) === 0;
    }

    /** Job: process an uploaded file / pasted text / FAQ / single URL source. */
    public static function processSourceJob(array $job, float $deadline): bool
    {
        $db = DB::instance();
        $jobId = (int) $job['id'];
        $sourceId = (int) ($job['payload']['source_id'] ?? 0);
        $source = $db->fetch('SELECT * FROM knowledge_sources WHERE id = ?', [$sourceId]);
        if (!$source) {
            JobQueue::fail($jobId, 'Source no longer exists.');
            return true;
        }
        $state = json_field($job['result'] ?? null);
        if (empty($state['extracted'])) {
            $db->update('knowledge_sources', ['status' => 'processing', 'error_message' => null, 'updated_at' => now()], 'id = :id', ['id' => $sourceId]);
            JobQueue::progress($jobId, 10, 'Reading content...');
            $settings = json_field($source['settings']);
            try {
                switch ($source['type']) {
                    case 'file':
                        $path = APP_ROOT . '/storage/documents/' . $source['file_path'];
                        if (!is_file($path)) {
                            throw new \RuntimeException('Uploaded file is missing.');
                        }
                        $text = DocumentExtractor::extract($path, (string) $source['mime'], (string) $source['file_name']);
                        if (mb_strlen(trim($text)) < 20) {
                            throw new \RuntimeException('No readable text could be extracted from this file. If it is a scanned PDF, enable AI document reading in the admin settings.');
                        }
                        self::replaceSourceDocuments($source, [[ 'title' => $source['title'], 'url' => null, 'content' => $text ]]);
                        break;
                    case 'text':
                        self::replaceSourceDocuments($source, [[ 'title' => $source['title'], 'url' => null, 'content' => (string) ($settings['content'] ?? '') ]]);
                        break;
                    case 'faq':
                        $docs = [];
                        foreach ((array) ($settings['items'] ?? []) as $item) {
                            $q = trim((string) ($item['question'] ?? ''));
                            $a = trim((string) ($item['answer'] ?? ''));
                            if ($q === '' || $a === '') {
                                continue;
                            }
                            $docs[] = ['title' => 'FAQ: ' . mb_substr($q, 0, 200), 'url' => null, 'content' => "Question: {$q}\nAnswer: {$a}"];
                        }
                        self::replaceSourceDocuments($source, $docs);
                        break;
                    case 'url':
                        $res = Http::get((string) $source['url'], ['timeout' => 20, 'public_only' => true, 'max_bytes' => 5_000_000, 'user_agent' => (string) Settings::get('crawler_user_agent', 'VoiceAgentBot/1.0')]);
                        if (!$res->ok()) {
                            throw new \RuntimeException('Could not fetch the page (' . ($res->error ?: 'HTTP ' . $res->status) . ').');
                        }
                        if ($res->contentType() === 'application/pdf') {
                            $tmp = tempnam(APP_ROOT . '/storage/tmp', 'pdf');
                            file_put_contents($tmp, $res->body);
                            $text = DocumentExtractor::extract($tmp, 'application/pdf', basename((string) $source['url']));
                            @unlink($tmp);
                            $title = $source['title'];
                        } else {
                            $page = HtmlExtractor::extract($res->body, (string) $source['url']);
                            $text = $page['text'];
                            $title = $page['title'] ?: $source['title'];
                        }
                        if (mb_strlen(trim($text)) < 40) {
                            throw new \RuntimeException('The page has no readable text.');
                        }
                        $db->update('knowledge_sources', ['title' => mb_substr($title, 0, 250)], 'id = :id', ['id' => $sourceId]);
                        self::replaceSourceDocuments($source, [[ 'title' => $title, 'url' => (string) $source['url'], 'content' => $text ]]);
                        break;
                    default:
                        throw new \RuntimeException('Unsupported source type.');
                }
            } catch (\Throwable $e) {
                $db->update('knowledge_sources', ['status' => 'error', 'error_message' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()], 'id = :id', ['id' => $sourceId]);
                JobQueue::fail($jobId, $e->getMessage());
                return true;
            }
            $state['extracted'] = true;
            $db->update('jobs', ['result' => json_encode($state)], 'id = :id', ['id' => $jobId]);
            JobQueue::progress($jobId, 40, 'Training the assistant...');
        }
        $finished = self::indexPending((int) $source['agent_id'], $deadline, static function (int $done, int $total) use ($jobId): void {
            JobQueue::progress($jobId, 40 + (int) round(($total > 0 ? $done / $total : 1) * 58), "Training the assistant ({$done}/{$total})...");
        }, $sourceId);
        if (!$finished) {
            return false;
        }
        $stats = self::sourceStats($sourceId);
        $db->update('knowledge_sources', [
            'status' => $stats['documents'] > 0 ? 'ready' : 'error',
            'error_message' => $stats['documents'] > 0 ? null : 'No content could be indexed.',
            'stats' => json_encode($stats), 'last_synced_at' => now(), 'updated_at' => now(),
        ], 'id = :id', ['id' => $sourceId]);
        $db->update('agents', ['last_trained_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $source['agent_id']]);
        JobQueue::complete($jobId, $stats, 'Ready: ' . $stats['chunks'] . ' knowledge chunks.');
        return true;
    }

    private static function replaceSourceDocuments(array $source, array $docs): void
    {
        $db = DB::instance();
        foreach ($db->fetchAll('SELECT id FROM knowledge_documents WHERE source_id = ?', [(int) $source['id']]) as $row) {
            self::deleteDocument((int) $row['id']);
        }
        foreach ($docs as $doc) {
            self::upsertDocument($source, (string) $doc['title'], $doc['url'], (string) $doc['content']);
        }
    }

    public static function indexPendingJob(array $job, float $deadline): bool
    {
        $agentId = (int) $job['agent_id'];
        $finished = self::indexPending($agentId, $deadline, static function (int $done, int $total) use ($job): void {
            JobQueue::progress((int) $job['id'], (int) round(($total > 0 ? $done / $total : 1) * 98), "Training ({$done}/{$total})...");
        });
        if ($finished) {
            DB::instance()->update('agents', ['last_trained_at' => now()], 'id = :id', ['id' => $agentId]);
            JobQueue::complete((int) $job['id'], [], 'Training complete.');
        }
        return $finished;
    }

    /** Job: re-embed everything for an agent (after changing the embedding model or on demand). */
    public static function reindexJob(array $job, float $deadline): bool
    {
        $db = DB::instance();
        $agentId = (int) $job['agent_id'];
        $state = json_field($job['result'] ?? null);
        if (empty($state['reset'])) {
            $db->query("UPDATE knowledge_documents SET status = 'pending', chunk_count = 0 WHERE agent_id = ? AND status IN ('indexed','error','skipped')", [$agentId]);
            $db->delete('knowledge_chunks', 'agent_id = ?', [$agentId]);
            $state['reset'] = true;
            $db->update('jobs', ['result' => json_encode($state)], 'id = :id', ['id' => (int) $job['id']]);
        }
        return self::indexPendingJob($job, $deadline);
    }

    public static function deleteDocument(int $documentId): void
    {
        $db = DB::instance();
        $db->delete('knowledge_chunks', 'document_id = ?', [$documentId]);
        $db->delete('knowledge_documents', 'id = ?', [$documentId]);
    }

    public static function deleteSource(array $source): void
    {
        $db = DB::instance();
        foreach ($db->fetchAll('SELECT id FROM knowledge_documents WHERE source_id = ?', [(int) $source['id']]) as $row) {
            self::deleteDocument((int) $row['id']);
        }
        if (!empty($source['file_path'])) {
            $path = APP_ROOT . '/storage/documents/' . $source['file_path'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $db->query("UPDATE jobs SET status = 'cancelled', finished_at = ? WHERE status IN ('queued','running') AND agent_id = ? AND payload LIKE ?", [now(), (int) $source['agent_id'], '%"source_id":' . (int) $source['id'] . '%']);
        $db->delete('knowledge_sources', 'id = ?', [(int) $source['id']]);
    }

    public static function sourceStats(int $sourceId): array
    {
        $db = DB::instance();
        $row = $db->fetch('SELECT COUNT(*) AS documents, COALESCE(SUM(chunk_count), 0) AS chunks, COALESCE(SUM(char_count), 0) AS chars FROM knowledge_documents WHERE source_id = ? AND status = \'indexed\'', [$sourceId]);
        return ['documents' => (int) ($row['documents'] ?? 0), 'chunks' => (int) ($row['chunks'] ?? 0), 'chars' => (int) ($row['chars'] ?? 0)];
    }

    public static function agentStats(int $agentId): array
    {
        $db = DB::instance();
        $row = $db->fetch('SELECT COUNT(*) AS documents, COALESCE(SUM(chunk_count), 0) AS chunks, COALESCE(SUM(char_count), 0) AS chars FROM knowledge_documents WHERE agent_id = ? AND status = \'indexed\'', [$agentId]);
        $pending = $db->count('knowledge_documents', 'agent_id = ? AND status = \'pending\'', [$agentId]);
        $sources = $db->count('knowledge_sources', 'agent_id = ?', [$agentId]);
        return ['documents' => (int) ($row['documents'] ?? 0), 'chunks' => (int) ($row['chunks'] ?? 0), 'chars' => (int) ($row['chars'] ?? 0), 'pending' => $pending, 'sources' => $sources];
    }
}
