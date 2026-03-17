<?php

namespace Modules\HexawebAIAssist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AIAssistController extends Controller
{
    // ─── Templates Manager ──────────────────────────────────────────

    public function templates()
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $templates = \DB::table('ai_assist_templates')
            ->orderBy('is_builtin', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('hexawebaiassist::templates_manager', compact('templates'));
    }

    public function templateSave(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $name   = trim($request->input('name', ''));
        $prompt = trim($request->input('prompt', ''));
        $desc   = trim($request->input('description', ''));
        $id     = (int)$request->input('id', 0);

        if (!$name || !$prompt) {
            \Session::flash('flash_error_floating', 'Name and prompt are required.');
            return redirect()->route('hexaweb.ai.templates');
        }

        $data = [
            'name'        => $name,
            'description' => $desc ?: null,
            'prompt'      => $prompt,
            'provider'    => 'any',
            'updated_at'  => now(),
        ];

        if ($id) {
            $tpl = \DB::table('ai_assist_templates')->where('id', $id)->first();
            if (!$tpl || $tpl->is_builtin) {
                \Session::flash('flash_error_floating', 'Cannot edit built-in templates.');
                return redirect()->route('hexaweb.ai.templates');
            }
            \DB::table('ai_assist_templates')->where('id', $id)->update($data);
            \Session::flash('flash_success_floating', 'Template updated.');
        } else {
            $data['is_builtin'] = 0;
            $data['sort_order'] = 99;
            $data['created_by'] = $user->id;
            $data['created_at'] = now();
            \DB::table('ai_assist_templates')->insert($data);
            \Session::flash('flash_success_floating', 'Template created.');
        }

        return redirect()->route('hexaweb.ai.templates');
    }

    public function templateDelete(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $id  = (int)$request->input('id', 0);
        $tpl = \DB::table('ai_assist_templates')->where('id', $id)->first();

        if (!$tpl || $tpl->is_builtin) {
            \Session::flash('flash_error_floating', 'Cannot delete built-in templates.');
            return redirect()->route('hexaweb.ai.templates');
        }

        \DB::table('ai_assist_templates')->where('id', $id)->delete();
        \Session::flash('flash_success_floating', 'Template deleted.');
        return redirect()->route('hexaweb.ai.templates');
    }

    // ─── Logs ────────────────────────────────────────────────────────

    public function logs(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $query = \DB::table('ai_request_logs')->orderBy('id', 'desc');

        if ($request->input('provider')) {
            $query->where('provider', $request->input('provider'));
        }
        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->input('action')) {
            $query->where('action', $request->input('action'));
        }

        $logs = $query->limit(200)->get();

        return view('hexawebaiassist::logs', compact('logs'));
    }

    public function logsClear(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        \DB::table('ai_request_logs')->truncate();
        \Session::flash('flash_success_floating', 'AI request logs cleared.');
        return redirect()->route('hexaweb.ai.logs');
    }

    // ─── AI Actions ─────────────────────────────────────────────────

    public function compose(Request $request)
    {
        $prompt = trim($request->input('prompt', ''));
        if (!$prompt) {
            return response()->json(['status' => 'error', 'msg' => 'Please enter instructions.']);
        }

        $provider = $request->input('provider', 'claude');
        [$apiKey, $model, $maxTokens, $systemPrompt] = $this->getProviderConfig($provider, $request);

        if (!$apiKey) {
            return response()->json(['status' => 'error', 'msg' => 'API key not configured. Go to ' . ucfirst($provider) . ' settings.']);
        }

        $fullPrompt = trim($prompt ?? '');
        if ($request->input('include_context', 0) && $request->input('conversation_id')) {
            $ctx = $this->buildContext((int)$request->input('conversation_id'));
            if ($ctx) {
                $fullPrompt = $ctx . "\nInstructions: " . $prompt;
            }
        }

        $result = $this->callAI($provider, $fullPrompt, $model, $apiKey, $maxTokens, $systemPrompt);
        $this->writeLog($provider, 'compose', $model, $request, $result);

        if ($result['success']) {
            return response()->json(['status' => 'success', 'text' => $result['text'], '_raw' => $result['raw'], '_cost' => $result['cost_usd'], '_tokens' => ['in' => $result['input_tokens'], 'out' => $result['output_tokens']]]);
        }
        return response()->json(['status' => 'error', 'msg' => $result['error'], '_raw' => $result['raw'], '_cost' => $result['cost_usd'], '_tokens' => ['in' => $result['input_tokens'], 'out' => $result['output_tokens']]]);
    }

    public function template(Request $request)
    {
        $templateId = (int)$request->input('template_id', 0);
        $tpl = \DB::table('ai_assist_templates')->where('id', $templateId)->first();
        if (!$tpl) {
            return response()->json(['status' => 'error', 'msg' => 'Template not found.']);
        }

        $provider = $request->input('provider', 'claude');
        [$apiKey, $model, $maxTokens, $systemPrompt] = $this->getProviderConfig($provider, $request);

        if (!$apiKey) {
            return response()->json(['status' => 'error', 'msg' => 'API key not configured for ' . ucfirst($provider) . '.']);
        }

        $prompt = $tpl->prompt ?? '';
        $extra  = trim($request->input('extra') ?? '');
        if ($extra) {
            $prompt .= "\n\nAdditional instructions: " . $extra;
        }

        if ($request->input('include_context', 0) && $request->input('conversation_id')) {
            $ctx = $this->buildContext((int)$request->input('conversation_id'));
            if ($ctx) {
                $prompt = $ctx . "\n" . $prompt;
            }
        }

        $result = $this->callAI($provider, $prompt, $model, $apiKey, $maxTokens, $systemPrompt);
        $this->writeLog($provider, 'template', $model, $request, $result);

        if ($result['success']) {
            return response()->json(['status' => 'success', 'text' => $result['text'], '_raw' => $result['raw'], '_cost' => $result['cost_usd'], '_tokens' => ['in' => $result['input_tokens'], 'out' => $result['output_tokens']]]);
        }
        return response()->json(['status' => 'error', 'msg' => $result['error'], '_raw' => $result['raw'], '_cost' => $result['cost_usd'], '_tokens' => ['in' => $result['input_tokens'], 'out' => $result['output_tokens']]]);
    }

    public function polish(Request $request)
    {
        $text = trim($request->input('text') ?? '');
        if (!$text) {
            return response()->json(['status' => 'error', 'msg' => 'Reply body is empty — nothing to polish.']);
        }

        $provider = $request->input('provider', 'claude');
        [$apiKey, $model, $maxTokens, $systemPrompt] = $this->getProviderConfig($provider, $request);

        if (!$apiKey) {
            return response()->json(['status' => 'error', 'msg' => 'API key not configured for ' . ucfirst($provider) . '.']);
        }

        $prompt = "Polish and improve the grammar, tone, and clarity of the following customer support email reply. "
                . "Preserve the original meaning and intent. Keep the same approximate length. "
                . "Return only the improved text — no commentary, no explanation, no quotes:\n\n"
                . $text;

        $result = $this->callAI($provider, $prompt, $model, $apiKey, $maxTokens, $systemPrompt);
        $this->writeLog($provider, 'polish', $model, $request, $result);

        if ($result['success']) {
            return response()->json(['status' => 'success', 'text' => $result['text'], '_raw' => $result['raw'], '_cost' => $result['cost_usd'], '_tokens' => ['in' => $result['input_tokens'], 'out' => $result['output_tokens']]]);
        }
        return response()->json(['status' => 'error', 'msg' => $result['error'], '_raw' => $result['raw'], '_cost' => $result['cost_usd'], '_tokens' => ['in' => $result['input_tokens'], 'out' => $result['output_tokens']]]);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function getProviderConfig(string $provider, Request $request): array
    {
        if ($provider === 'claude') {
            return [
                $this->getApiKey('claude_assist.api_key_encrypted'),
                $request->input('model') ?: (\Option::get('claude_assist.model') ?: 'claude-sonnet-4-6'),
                (int)(\Option::get('claude_assist.max_tokens') ?: 1024),
                \Option::get('claude_assist.system_prompt', '') ?? '',
            ];
        }
        return [
            $this->getApiKey('gpt_assist.api_key_encrypted'),
            $request->input('model') ?: (\Option::get('gpt_assist.model') ?: 'gpt-4o'),
            (int)(\Option::get('gpt_assist.max_tokens') ?: 1024),
            \Option::get('gpt_assist.system_prompt', '') ?? '',
        ];
    }

    private function getApiKey(string $optionKey): ?string
    {
        $encrypted = \Option::get($optionKey, '');
        if (!$encrypted) {
            return null;
        }
        try {
            $key = \Crypt::decryptString($encrypted);
            return $key ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // Pricing per million tokens [input, output] in USD
    private static $PRICING = [
        // Claude
        'claude-opus-4-6'            => [15.00, 75.00],
        'claude-sonnet-4-6'          => [3.00,  15.00],
        'claude-haiku-4-5-20251001'  => [0.80,  4.00],
        'claude-3-7-sonnet-20250219' => [3.00,  15.00],
        'claude-3-5-sonnet-20241022' => [3.00,  15.00],
        'claude-3-5-haiku-20241022'  => [0.80,  4.00],
        'claude-3-opus-20240229'     => [15.00, 75.00],
        // GPT
        'gpt-4o'        => [2.50,  10.00],
        'gpt-4o-mini'   => [0.15,  0.60],
        'gpt-4-turbo'   => [10.00, 30.00],
        'gpt-4'         => [30.00, 60.00],
        'gpt-3.5-turbo' => [0.50,  1.50],
    ];

    private function calcCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $rates = self::$PRICING[$model] ?? [3.00, 15.00];
        return round(($inputTokens * $rates[0] + $outputTokens * $rates[1]) / 1_000_000, 6);
    }

    private function callAI(string $provider, string $prompt, string $model, string $apiKey, int $maxTokens, string $systemPrompt): array
    {
        return $provider === 'claude'
            ? $this->callClaude($prompt, $model, $apiKey, $maxTokens, $systemPrompt)
            : $this->callGpt($prompt, $model, $apiKey, $maxTokens, $systemPrompt);
    }

    private function callClaude(string $prompt, string $model, string $apiKey, int $maxTokens, string $systemPrompt): array
    {
        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nx-api-key: {$apiKey}\r\nanthropic-version: 2023-06-01",
                'content'       => json_encode($payload),
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $apiUrl   = 'https://api.anthropic.com/v1/messages';
        $start    = microtime(true);
        $raw      = @file_get_contents($apiUrl, false, $ctx);
        $duration = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) {
            return ['success' => false, 'error' => 'Failed to connect to Anthropic API (file_get_contents returned false).', 'raw' => '', 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration];
        }

        $data        = json_decode($raw, true);
        $inputTok    = (int)($data['usage']['input_tokens']  ?? 0);
        $outputTok   = (int)($data['usage']['output_tokens'] ?? 0);
        $cost        = $this->calcCost($model, $inputTok, $outputTok);

        if (!empty($data['content'][0]['text'])) {
            return ['success' => true, 'text' => trim($data['content'][0]['text']), 'raw' => $raw, 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => $inputTok, 'output_tokens' => $outputTok, 'cost_usd' => $cost];
        }

        $errMsg = 'Anthropic error: ' . ($data['error']['message'] ?? $raw);
        return ['success' => false, 'error' => $errMsg, 'raw' => $raw, 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => $inputTok, 'output_tokens' => $outputTok, 'cost_usd' => $cost];
    }

    private function callGpt(string $prompt, string $model, string $apiKey, int $maxTokens, string $systemPrompt): array
    {
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload     = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $messages];
        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}",
                'content'       => json_encode($payload),
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $apiUrl   = 'https://api.openai.com/v1/chat/completions';
        $start    = microtime(true);
        $raw      = @file_get_contents($apiUrl, false, $ctx);
        $duration = (int)((microtime(true) - $start) * 1000);

        if ($raw === false) {
            return ['success' => false, 'error' => 'Failed to connect to OpenAI API (file_get_contents returned false).', 'raw' => '', 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration];
        }

        $data      = json_decode($raw, true);
        $inputTok  = (int)($data['usage']['prompt_tokens']     ?? 0);
        $outputTok = (int)($data['usage']['completion_tokens'] ?? 0);
        $cost      = $this->calcCost($model, $inputTok, $outputTok);

        if (!empty($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'text' => trim($data['choices'][0]['message']['content']), 'raw' => $raw, 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => $inputTok, 'output_tokens' => $outputTok, 'cost_usd' => $cost];
        }

        $errMsg = 'OpenAI error: ' . ($data['error']['message'] ?? $raw);
        return ['success' => false, 'error' => $errMsg, 'raw' => $raw, 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => $inputTok, 'output_tokens' => $outputTok, 'cost_usd' => $cost];
    }

    private function buildContext(int $conversationId): string
    {
        try {
            $threads = \DB::table('threads')
                ->where('conversation_id', $conversationId)
                ->whereIn('type', [1, 2])
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get();

            if ($threads->isEmpty()) {
                return '';
            }

            $parts = [];
            foreach ($threads->reverse() as $thread) {
                $role = $thread->type == 1 ? 'Customer' : 'Agent';
                $body = substr(trim(strip_tags($thread->body ?? '')), 0, 600);
                if ($body) {
                    $parts[] = $role . ': ' . $body;
                }
            }

            if (empty($parts)) {
                return '';
            }

            return "--- Conversation context ---\n" . implode("\n\n", $parts) . "\n--- End context ---\n\n";
        } catch (\Exception $e) {
            return '';
        }
    }

    private function writeLog(string $provider, string $action, string $model, Request $request, array $result): void
    {
        try {
            $user = auth()->user();
            \DB::table('ai_request_logs')->insert([
                'user_id'         => $user ? $user->id : null,
                'user_email'      => $user ? $user->email : null,
                'provider'        => $provider,
                'model'           => $model,
                'action'          => $action,
                'conversation_id' => $request->input('conversation_id') ?: null,
                'page_url'        => $request->input('page_url', $request->header('Referer', '')),
                'client_ip'       => $request->ip(),
                'api_url'         => $result['api_url'] ?? '',
                'request_json'    => $result['request_json'] ?? '',
                'response_json'   => $result['raw'] ?? '',
                'status'          => $result['success'] ? 'success' : 'error',
                'error_message'   => $result['success'] ? null : ($result['error'] ?? ''),
                'duration_ms'     => $result['duration_ms'] ?? 0,
                'input_tokens'    => $result['input_tokens'] ?? 0,
                'output_tokens'   => $result['output_tokens'] ?? 0,
                'cost_usd'        => $result['cost_usd'] ?? 0,
                'created_at'      => now(),
            ]);
        } catch (\Exception $e) {
            // Silent — never break a user-facing request over logging
        }
    }
}
