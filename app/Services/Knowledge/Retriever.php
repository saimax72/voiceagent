<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\DB;
use App\Core\Logger;
use App\Services\AI\Embeddings;
use App\Services\Settings;

/**
 * Hybrid retrieval: vector similarity (cosine on normalised embeddings) combined with MySQL full-text search.
 * Works with full-text only when no embedding provider is configured.
 */
final class Retriever
{
    private const FULL_SCAN_LIMIT = 6000;

    /**
     * @return array<int, array{chunk_id:int, document_id:int, title:string, url:?string, heading:?string, content:string, score:float}>
     */
    public static function search(int $agentId, string $query, int $topK = 6): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $db = DB::instance();
        $signature = Embeddings::signature();
        $vectorScores = [];
        $textScores = [];

        if ($signature !== '') {
            try {
                $vectorScores = self::vectorSearch($db, $agentId, $query, $signature, max(20, $topK * 3));
            } catch (\Throwable $e) {
                Logger::error('Vector search failed: ' . $e->getMessage());
            }
        }
        try {
            $textScores = self::fullTextSearch($db, $agentId, $query, max(20, $topK * 3));
        } catch (\Throwable $e) {
            Logger::error('Full-text search failed: ' . $e->getMessage());
        }

        // Combine
        $combined = [];
        $hasVector = $vectorScores !== [];
        foreach ($vectorScores as $id => $score) {
            $combined[$id] = 0.8 * $score;
        }
        foreach ($textScores as $id => $score) {
            $combined[$id] = ($combined[$id] ?? ($hasVector ? 0.0 : 0.0)) + ($hasVector ? 0.2 : 1.0) * $score;
        }
        if ($combined === []) {
            return [];
        }
        arsort($combined);
        $minScore = $hasVector ? (float) Settings::get('retrieval_min_score', 0.30) * 0.8 : 0.08;
        $ids = [];
        foreach ($combined as $id => $score) {
            if ($score < $minScore && count($ids) > 0) {
                continue;
            }
            if ($score < $minScore * 0.6) {
                continue;
            }
            $ids[] = (int) $id;
            if (count($ids) >= $topK) {
                break;
            }
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->fetchAll(
            'SELECT c.id, c.document_id, c.heading, c.content, d.title, d.url FROM knowledge_chunks c
             INNER JOIN knowledge_documents d ON d.id = c.document_id
             WHERE c.id IN (' . $placeholders . ') AND d.is_enabled = 1',
            $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $row = $byId[$id];
            $out[] = [
                'chunk_id' => $id, 'document_id' => (int) $row['document_id'], 'title' => (string) $row['title'], 'url' => $row['url'],
                'heading' => $row['heading'], 'content' => (string) $row['content'], 'score' => round((float) $combined[$id], 4),
            ];
        }
        return $out;
    }

    /** @return array<int, float> chunk id => cosine similarity */
    private static function vectorSearch(DB $db, int $agentId, string $query, string $signature, int $limit): array
    {
        $total = (int) $db->fetchColumn('SELECT COUNT(*) FROM knowledge_chunks WHERE agent_id = ? AND embedding_model = ?', [$agentId, $signature]);
        if ($total === 0) {
            return [];
        }
        $vectors = Embeddings::embed([$query], 'query');
        $q = Embeddings::normalize($vectors[0] ?? []);
        if ($q === []) {
            return [];
        }
        $dims = count($q);
        $scores = [];
        $candidateFilter = '';
        $params = [$agentId, $signature];
        if ($total > self::FULL_SCAN_LIMIT) {
            // Very large knowledge base: rerank the full-text candidates only
            $candidates = array_keys(self::fullTextSearch($db, $agentId, $query, 300));
            if ($candidates === []) {
                return [];
            }
            $candidateFilter = ' AND c.id IN (' . implode(',', array_map('intval', $candidates)) . ')';
        }
        $sql = 'SELECT c.id, c.embedding FROM knowledge_chunks c INNER JOIN knowledge_documents d ON d.id = c.document_id
                WHERE c.agent_id = ? AND c.embedding_model = ? AND d.is_enabled = 1 AND c.embedding IS NOT NULL' . $candidateFilter . ' ORDER BY c.id ASC LIMIT ? OFFSET ?';
        $batch = 400;
        $offset = 0;
        $best = [];
        while (true) {
            $stmt = $db->pdo()->prepare($sql);
            $stmt->bindValue(1, $agentId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $signature);
            $stmt->bindValue(3, $batch, \PDO::PARAM_INT);
            $stmt->bindValue(4, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            if (!$rows) {
                break;
            }
            foreach ($rows as [$id, $blob]) {
                $v = unpack('f*', (string) $blob);
                if (!$v || count($v) !== $dims) {
                    continue;
                }
                $dot = 0.0;
                $i = 1;
                foreach ($q as $qv) {
                    $dot += $qv * $v[$i++];
                }
                $best[(int) $id] = $dot;
            }
            if (count($best) > $limit * 4) {
                arsort($best);
                $best = array_slice($best, 0, $limit * 2, true);
            }
            if (count($rows) < $batch) {
                break;
            }
            $offset += $batch;
        }
        arsort($best);
        return array_slice($best, 0, $limit, true);
    }

    /** @return array<int, float> chunk id => normalised relevance (0..1) */
    private static function fullTextSearch(DB $db, int $agentId, string $query, int $limit): array
    {
        $terms = self::keywords($query);
        if ($terms === []) {
            return [];
        }
        $boolean = implode(' ', array_map(static fn($t) => (mb_strlen($t) >= 4 ? $t . '*' : $t), $terms));
        $rows = $db->fetchAll(
            'SELECT c.id, MATCH(c.content) AGAINST (? IN BOOLEAN MODE) AS rel FROM knowledge_chunks c
             INNER JOIN knowledge_documents d ON d.id = c.document_id
             WHERE c.agent_id = ? AND d.is_enabled = 1 AND MATCH(c.content) AGAINST (? IN BOOLEAN MODE)
             ORDER BY rel DESC LIMIT ' . (int) $limit,
            [$boolean, $agentId, $boolean]
        );
        if (!$rows) {
            $natural = implode(' ', $terms);
            $rows = $db->fetchAll(
                'SELECT c.id, MATCH(c.content) AGAINST (?) AS rel FROM knowledge_chunks c
                 INNER JOIN knowledge_documents d ON d.id = c.document_id
                 WHERE c.agent_id = ? AND d.is_enabled = 1 AND MATCH(c.content) AGAINST (?)
                 ORDER BY rel DESC LIMIT ' . (int) $limit,
                [$natural, $agentId, $natural]
            );
        }
        if (!$rows) {
            return [];
        }
        $max = max(array_map(static fn($r) => (float) $r['rel'], $rows)) ?: 1.0;
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (float) $row['rel'] / $max;
        }
        return $out;
    }

    private static function keywords(string $query): array
    {
        $stop = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has', 'have', 'what', 'when', 'where', 'which', 'who', 'why', 'how', 'does', 'do', 'is', 'it', 'its', 'this', 'that', 'with', 'from', 'your', 'about', 'there', 'their', 'they', 'them', 'than', 'then', 'will', 'would', 'could', 'should', 'please', 'tell', 'me', 'my', 'we', 'us', 'an', 'a', 'to', 'of', 'in', 'on', 'at', 'by', 'be', 'or', 'as', 'if', 'so', 'get', 'want', 'like', 'know', 'need', 'some', 'more', 'much', 'many', 'into', 'also', 'just', 'hi', 'hello', 'thanks', 'thank'];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 3 || in_array($w, $stop, true)) {
                continue;
            }
            $out[$w] = true;
            if (count($out) >= 12) {
                break;
            }
        }
        return array_keys($out);
    }
}
