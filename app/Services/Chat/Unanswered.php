<?php
declare(strict_types=1);

namespace App\Services\Chat;

use App\Core\DB;
use App\Services\Usage;

final class Unanswered
{
    public static function record(array $agent, ?array $conversation, ?int $messageId, string $question, ?string $answerGiven): int
    {
        $question = trim(preg_replace('/\s+/', ' ', $question) ?? $question);
        if (mb_strlen($question) < 3) {
            return 0;
        }
        $question = mb_substr($question, 0, 1000);
        $hash = sha1(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $question) ?? $question));
        $db = DB::instance();
        $now = now();
        $existing = $db->fetch('SELECT * FROM unanswered_questions WHERE agent_id = ? AND question_hash = ? AND status = \'open\' LIMIT 1', [(int) $agent['id'], $hash]);
        if ($existing) {
            $db->update('unanswered_questions', ['occurrences' => (int) $existing['occurrences'] + 1, 'updated_at' => $now, 'conversation_id' => $conversation['id'] ?? $existing['conversation_id']], 'id = :id', ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = $db->insert('unanswered_questions', [
                'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'],
                'conversation_id' => $conversation ? (int) $conversation['id'] : null, 'message_id' => $messageId,
                'question' => $question, 'question_hash' => $hash,
                'answer_given' => $answerGiven !== null ? mb_substr($answerGiven, 0, 2000) : null,
                'status' => 'open', 'occurrences' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($conversation) {
            $db->update('conversations', ['has_unanswered' => 1, 'updated_at' => $now], 'id = :id', ['id' => $conversation['id']]);
            if (empty($conversation['is_test'])) {
                Usage::recordDaily((int) $agent['tenant_id'], (int) $agent['id'], ['unanswered' => 1]);
            }
        }
        return $id;
    }
}
