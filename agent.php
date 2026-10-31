<?php
// The agent. This file is the whole "agent": a loop that sends the conversation to Claude,
// runs the tools Claude asks for, sends their results back, and stops when Claude answers in words.

require_once __DIR__ . '/tools.php';

const MODEL = 'claude-haiku-5-5';
const MODEL_NAME = 'Claude Haiku 5.5';

// US dollars per million tokens: https://platform.claude.com/docs/en/about-claude/pricing (October 2026).
// Haiku 5.5 charges more for a request whose prompt is over 100,000 tokens.
const PRICES = [
    'up to 100k' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
    'over 100k'  => ['input' => 0.50, 'cache_write' => 0.625, 'cache_read' => 0.05, 'output' => 2.50],
];

// One user turn. $messages is the whole conversation, ending with the user's new message.
// Whenever the conversation is complete enough to save, $save(new messages, cost of the calls since the last save) is called.
function run_agent(array &$messages, callable $save): void
{
    $saved = count($messages);
    $cost = 0.0;
    while (true) {
        $reply = claude($messages);
        $cost += price($reply['usage']);
        $stop = $reply['stop_reason'];
        if ($stop !== 'tool_use' && $stop !== 'end_turn') {
            $save(array_slice($messages, $saved), $cost);   // keep what was already paid for
            throw new RuntimeException("Claude stopped early ($stop).");
        }
        $messages[] = ['role' => 'assistant', 'content' => $reply['content']];
        if ($stop === 'end_turn') break;

        // Claude asked for one or more tools. Run each one and send all the results back together.
        $results = [];
        foreach ($reply['content'] as $block) {
            if ($block['type'] !== 'tool_use') continue;
            try {
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => json_encode(run_tool($block['name'], $block['input']))];
            } catch (Throwable $e) {
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $e->getMessage(), 'is_error' => true];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $results];
        $save(array_slice($messages, $saved), $cost);
        $saved = count($messages);
        $cost = 0.0;
    }
    $save(array_slice($messages, $saved), $cost);
}

// One HTTP request to Claude's Messages API. Returns Claude's reply.
function claude(array $messages): array
{
    $curl = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . getenv('ANTHROPIC_API_KEY')],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => MODEL,
            'max_tokens' => 4096,
            'tools' => TOOLS,
            // The tools and this prompt are the same in every request, so Claude caches them...
            'system' => [['type' => 'text', 'text' => file_get_contents(__DIR__ . '/SYSTEM_PROMPT.txt'), 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => $messages,
            // ...and the conversation so far, so the next request pays the cheap cached price for all of it.
            'cache_control' => ['type' => 'ephemeral'],
        ]),
    ]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('Could not reach Claude: ' . curl_error($curl));
    $reply = json_decode($body, true);
    if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) throw new RuntimeException('Claude API error: ' . ($reply['error']['message'] ?? $body));
    return $reply;
}

// What one request cost, from the token counts Claude reports back.
function price(array $usage): float
{
    $prompt = $usage['input_tokens'] + $usage['cache_creation_input_tokens'] + $usage['cache_read_input_tokens'];
    $rate = PRICES[$prompt > 100000 ? 'over 100k' : 'up to 100k'];
    return ($usage['input_tokens'] * $rate['input']
          + $usage['cache_creation_input_tokens'] * $rate['cache_write']
          + $usage['cache_read_input_tokens'] * $rate['cache_read']
          + $usage['output_tokens'] * $rate['output']) / 1_000_000;
}
