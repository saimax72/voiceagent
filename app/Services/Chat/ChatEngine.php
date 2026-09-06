<?php
declare(strict_types=1);

namespace App\Services\Chat;

use App\Core\DB;
use App\Core\Logger;
use App\Services\AI\LLM;
use App\Services\AI\PromptBuilder;
use App\Services\Knowledge\Retriever;
use App\Services\Settings;
use App\Services\Usage;

/**
 * Orchestrates one assistant turn: retrieval, prompt, streaming model call, tools, persistence, metering.
 */
final class ChatEngine
{
    private const HISTORY_LIMIT = 14;
    private const MAX_TOOL_ROUNDS = 3;

    /**
     * @param callable(string $event, array $data): void $emit  streams events to the client
     * @return array{message_id:int, text:string, sources:array, lead_id:int, unanswered:bool, error:?string}
     */
    public static function respond(array $agent, array $tenant, array $conversation, string $userText, string $modality, callable $emit, array $context = []): array
    {
        $start = microtime(true);
        $db = DB::instance();
        $userText = trim($userText);
        $modality = $modality === 'voice' ? 'voice' : 'text';

        $userMessageId = Conversations::addMessage($conversation, 'user', $userText, $modality);
        $conversation['message_count'] = (int) $conversation['message_count'] + 1;
        if ($conversation['title'] === null || $conversation['title'] === '') {
            $conversation['title'] = mb_substr($userText, 0, 120);
        }

        // Conversation history (before this message)
        $history = $db->fetchAll(
            'SELECT role, content FROM messages WHERE conversation_id = ? AND id < ? AND role IN (\'user\',\'assistant\') ORDER BY id DESC LIMIT ' . self::HISTORY_LIMIT,
            [(int) $conversation['id'], $userMessageId]
        );
        $history = array_reverse($history);

        // Retrieval query: add the previous user turn for short follow-ups ("how much is it?", "yes")
        $retrievalQuery = $userText;
        if (mb_strlen($userText) < 30) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if ($history[$i]['role'] === 'user') {
                    $retrievalQuery = $history[$i]['content'] . ' ' . $userText;
                    break;
                }
            }
        }
        $chunks = [];
        $retrievalStart = microtime(true);
        try {
            $chunks = Retriever::search((int) $agent['id'], $retrievalQuery, Settings::int('retrieval_top_k', 6));
        } catch (\Throwable $e) {
            Logger::error('Retrieval failed: ' . $e->getMessage());
        }
        $retrievalMs = (int) round((microtime(true) - $retrievalStart) * 1000);
        $emit('meta', ['sources_found' => count($chunks), 'retrieval_ms' => $retrievalMs]);

        $messages = [];
        $lastRole = null;
        foreach ($history as $h) {
            $content = trim((string) $h['content']);
            if ($content === '') {
                continue;
            }
            if ($h['role'] === $lastRole && $messages) {
                $messages[count($messages) - 1]['content'] .= "\n\n" . $content;
                continue;
            }
            $messages[] = ['role' => $h['role'], 'content' => $content];
            $lastRole = $h['role'];
        }
        if ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        if ($lastRole === 'user' && $messages) {
            $messages[count($messages) - 1]['content'] .= "\n\n" . $userText;
        } else {
            $messages[] = ['role' => 'user', 'content' => $userText];
        }

        $system = PromptBuilder::systemBlocks($agent, $chunks, ['modality' => $modality, 'page_url' => $context['page_url'] ?? null]);
        $tools = PromptBuilder::tools($agent);
        $effort = in_array($agent['effort'] ?? '', ['low', 'medium', 'high'], true) ? $agent['effort'] : (string) Settings::get('llm_effort', 'low');
        $request = [
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools,
            'max_tokens' => max(256, Settings::int('llm_max_tokens', 1024)),
            'effort' => $effort,
            // "Fast" = no extended thinking: first words arrive much sooner, which matters for voice
            'thinking' => $effort === 'low' ? 'disabled' : 'adaptive',
        ];
        $model = LLM::modelFor($agent);
        if ($model) {
            $request['model'] = $model;
        }

        $fullText = '';
        $leadId = 0;
        $unanswered = false;
        $tokensIn = 0;
        $tokensOut = 0;
        $error = null;
        $modelUsed = '';
        $toolLog = [];
        $firstTokenMs = 0;

        try {
            $provider = LLM::providerFor($agent);
            $round = 0;
            while (true) {
                $round++;
                $result = $provider->stream($request, static function (string $delta) use ($emit, &$fullText, &$firstTokenMs, $start): void {
                    if ($firstTokenMs === 0) {
                        $firstTokenMs = (int) round((microtime(true) - $start) * 1000);
                    }
                    $fullText .= $delta;
                    $emit('delta', ['text' => $delta]);
                });
                $tokensIn += $result->inputTokens;
                $tokensOut += $result->outputTokens;
                $modelUsed = $result->model ?: $modelUsed;
                if (!$result->ok()) {
                    $error = $result->error;
                    break;
                }
                if ($result->refused) {
                    $fallback = "I'm sorry, I can't help with that request. Is there anything about our products or services I can help you with?";
                    if ($fullText === '') {
                        $fullText = $fallback;
                        $emit('delta', ['text' => $fallback]);
                    }
                    break;
                }
                if (!$result->wantsTools() || $round >= self::MAX_TOOL_ROUNDS) {
                    break;
                }
                // Execute tools and continue the turn
                $toolResults = [];
                foreach ($result->toolUses as $tool) {
                    $output = 'ok';
                    if ($tool['name'] === 'save_lead') {
                        $leadId = Leads::create($agent, $conversation, $tool['input'], $modality === 'voice' ? 'voice' : 'chat') ?: $leadId;
                        $output = $leadId > 0 ? 'Lead saved. Confirm to the visitor that the team will get back to them.' : 'Lead not saved: a name plus an email or phone number is required. Ask the visitor for the missing details.';
                        if ($leadId > 0) {
                            $emit('lead', ['saved' => true]);
                        }
                    } elseif ($tool['name'] === 'log_unanswered_question') {
                        $question = (string) ($tool['input']['question'] ?? $userText);
                        Unanswered::record($agent, $conversation, $userMessageId, $question, null);
                        $unanswered = true;
                        $output = 'Logged. Continue helping the visitor.';
                    } else {
                        $output = 'Unknown tool.';
                    }
                    $toolLog[] = ['name' => $tool['name'], 'input' => $tool['input']];
                    $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $tool['id'], 'content' => $output];
                }
                $request['messages'][] = ['role' => 'assistant', 'content' => $result->content];
                $request['messages'][] = ['role' => 'user', 'content' => $toolResults];
                if ($fullText !== '' && !str_ends_with($fullText, "\n")) {
                    $fullText .= ' ';
                    $emit('delta', ['text' => ' ']);
                }
            }
        } catch (\Throwable $e) {
            Logger::exception($e);
            $error = $e->getMessage();
        }

        if ($error !== null && $fullText === '') {
            $fullText = (string) ($agent['fallback_message'] ?: "I'm sorry, something went wrong on my side. Please try again in a moment.");
            $emit('delta', ['text' => $fullText]);
        }
        $fullText = trim($fullText);

        // Heuristic unanswered detection when the tool was not called
        if (!$unanswered && ($chunks === [] || self::looksUnanswered($fullText))) {
            if (self::looksUnanswered($fullText) && !self::isSmallTalk($userText)) {
                Unanswered::record($agent, $conversation, $userMessageId, $userText, $fullText);
                $unanswered = true;
            }
        }

        $sources = [];
        foreach ($chunks as $c) {
            $key = $c['document_id'];
            if (!isset($sources[$key])) {
                $sources[$key] = ['document_id' => $c['document_id'], 'title' => $c['title'], 'url' => $c['url'], 'score' => $c['score']];
            }
        }
        $sources = array_values($sources);
        $latency = (int) round((microtime(true) - $start) * 1000);
        $assistantId = Conversations::addMessage($conversation, 'assistant', $fullText, $modality, [
            'sources' => json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'meta' => json_encode(['model' => $modelUsed, 'tools' => $toolLog, 'error' => $error, 'retrieved' => count($chunks), 'retrieval_ms' => $retrievalMs, 'first_token_ms' => $firstTokenMs], JSON_UNESCAPED_UNICODE),
            'tokens_input' => $tokensIn, 'tokens_output' => $tokensOut, 'latency_ms' => $latency, 'is_unanswered' => $unanswered ? 1 : 0,
        ]);

        if (empty($conversation['is_test'])) {
            Usage::incrementMany((int) $tenant['id'], (int) $agent['id'], [
                'messages' => 1, 'voice_messages' => $modality === 'voice' ? 1 : 0, 'tokens_input' => $tokensIn, 'tokens_output' => $tokensOut,
            ]);
            Usage::recordDaily((int) $tenant['id'], (int) $agent['id'], ['messages' => 1, 'voice_messages' => $modality === 'voice' ? 1 : 0, 'tokens_input' => $tokensIn, 'tokens_output' => $tokensOut]);
            $db->query('UPDATE agents SET messages_count = messages_count + 1 WHERE id = ?', [(int) $agent['id']]);
        }

        return ['message_id' => $assistantId, 'text' => $fullText, 'sources' => $sources, 'lead_id' => $leadId, 'unanswered' => $unanswered, 'error' => $error, 'timing' => ['retrieval_ms' => $retrievalMs, 'first_token_ms' => $firstTokenMs, 'total_ms' => $latency]];
    }

    private static function looksUnanswered(string $text): bool
    {
        $t = mb_strtolower($text);
        $patterns = ["don't have that information", 'do not have that information', "don't have information", "don't have details", 'not able to find', "couldn't find", 'could not find', "i'm not sure", 'i am not sure', 'no information about', "don't have any information", 'not something i can', 'unable to find', "i don't know", 'i do not know', 'not in my knowledge', "doesn't cover", "don't have specifics"];
        foreach ($patterns as $p) {
            if (str_contains($t, $p)) {
                return true;
            }
        }
        return false;
    }

    private static function isSmallTalk(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        return mb_strlen($t) < 4 || (bool) preg_match('/^(hi|hello|hey|thanks|thank you|ok|okay|yes|no|bye|goodbye|good morning|good afternoon|good evening)\b[.!]*$/', $t);
    }
}
