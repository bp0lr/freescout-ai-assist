<?php

namespace Modules\HexawebAIAssist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AIAssistController extends Controller
{
    const DEFAULT_MODEL  = 'google/gemini-2.5-flash';
    const MODELS_CACHE_TTL = 86400; // 24h

    // Short fallback list so the model selector is never empty before the first /models fetch.
    private static $FALLBACK_MODELS = [
        ['id' => 'google/gemini-2.5-flash',      'name' => 'Google: Gemini 2.5 Flash',      'prompt_price_per_mtok' => 0.30, 'completion_price_per_mtok' => 2.50, 'context_length' => 1048576],
        ['id' => 'google/gemini-2.5-flash-lite', 'name' => 'Google: Gemini 2.5 Flash Lite', 'prompt_price_per_mtok' => 0.10, 'completion_price_per_mtok' => 0.40, 'context_length' => 1048576],
        ['id' => 'openai/gpt-4o-mini',           'name' => 'OpenAI: GPT-4o-mini',           'prompt_price_per_mtok' => 0.15, 'completion_price_per_mtok' => 0.60, 'context_length' => 128000],
        ['id' => 'anthropic/claude-sonnet-4.5',  'name' => 'Anthropic: Claude Sonnet 4.5',  'prompt_price_per_mtok' => 3.00, 'completion_price_per_mtok' => 15.00, 'context_length' => 200000],
    ];

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

        $name   = trim($request->input('name') ?? '');
        $prompt = trim($request->input('prompt') ?? '');
        $desc   = trim($request->input('description') ?? '');
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

    // ─── Settings ────────────────────────────────────────────────────

    public function settings()
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $models       = $this->getModels();
        $hasKey       = $this->getApiKey('openrouter_assist.api_key_encrypted') !== null;
        $model        = \Option::get('openrouter_assist.model', self::DEFAULT_MODEL);
        $maxTokens    = (int)(\Option::get('openrouter_assist.max_tokens') ?: 1024);
        $systemPrompt = \Option::get('openrouter_assist.system_prompt', '') ?? '';

        return view('hexawebaiassist::settings', compact('models', 'hasKey', 'model', 'maxTokens', 'systemPrompt'));
    }

    public function settingsSave(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $model        = trim($request->input('model') ?? '') ?: self::DEFAULT_MODEL;
        $maxTokens    = max(1, (int) ($request->input('max_tokens') ?: 1024));
        $systemPrompt = trim($request->input('system_prompt') ?? '');

        \Option::set('openrouter_assist.model', $model);
        \Option::set('openrouter_assist.max_tokens', $maxTokens);
        \Option::set('openrouter_assist.system_prompt', $systemPrompt);

        // Only overwrite the stored key when a real value is submitted (blank field = keep existing).
        $key = trim($request->input('api_key') ?? '');
        if ($key !== '') {
            \Option::set('openrouter_assist.api_key_encrypted', \Crypt::encryptString($key));
        }

        \Session::flash('flash_success_floating', 'AI settings saved.');
        return redirect()->route('hexaweb.ai.settings');
    }

    public function refreshModels(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $models = $this->getModels(true);
        \Session::flash('flash_success_floating', 'Model list refreshed — ' . count($models) . ' models loaded.');
        return redirect()->route('hexaweb.ai.settings');
    }

    // ─── AI Actions ─────────────────────────────────────────────────

    public function compose(Request $request)
    {
        $prompt = trim($request->input('prompt') ?? '');
        if (!$prompt) {
            return response()->json(['status' => 'error', 'msg' => 'Please enter instructions.']);
        }

        $provider = $request->input('provider', 'openrouter');
        [$apiKey, $model, $maxTokens, $systemPrompt] = $this->getProviderConfig($provider, $request);

        if (!$apiKey) {
            return response()->json(['status' => 'error', 'msg' => 'OpenRouter API key not configured. Set it in Manage → AI Settings.']);
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

        $provider = $request->input('provider', 'openrouter');
        [$apiKey, $model, $maxTokens, $systemPrompt] = $this->getProviderConfig($provider, $request);

        if (!$apiKey) {
            return response()->json(['status' => 'error', 'msg' => 'OpenRouter API key not configured. Set it in Manage → AI Settings.']);
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

        $provider = $request->input('provider', 'openrouter');
        [$apiKey, $model, $maxTokens, $systemPrompt] = $this->getProviderConfig($provider, $request);

        if (!$apiKey) {
            return response()->json(['status' => 'error', 'msg' => 'OpenRouter API key not configured. Set it in Manage → AI Settings.']);
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

    // Single OpenRouter path. $provider kept for signature stability (callers unchanged); ignored.
    private function getProviderConfig(string $provider, Request $request): array
    {
        return [
            $this->getApiKey('openrouter_assist.api_key_encrypted'),
            $request->input('model') ?: (\Option::get('openrouter_assist.model') ?: self::DEFAULT_MODEL),
            (int)(\Option::get('openrouter_assist.max_tokens') ?: 1024),
            \Option::get('openrouter_assist.system_prompt', '') ?? '',
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

    // Cost fallback when OpenRouter doesn't return usage.cost — derived from cached model pricing.
    private function calcCost(string $model, int $inputTokens, int $outputTokens): float
    {
        foreach ($this->getModels() as $m) {
            if (($m['id'] ?? null) === $model) {
                return round(
                    ($inputTokens * (float)$m['prompt_price_per_mtok'] + $outputTokens * (float)$m['completion_price_per_mtok']) / 1_000_000,
                    6
                );
            }
        }
        return 0.0; // Unknown model — report an honest 0 rather than a fabricated rate.
    }

    // Kept as the single dispatch seam (callers unchanged); $provider is ignored.
    private function callAI(string $provider, string $prompt, string $model, string $apiKey, int $maxTokens, string $systemPrompt): array
    {
        return $this->callOpenRouter($prompt, $model, $apiKey, $maxTokens, $systemPrompt);
    }

    private function callOpenRouter(string $prompt, string $model, string $apiKey, int $maxTokens, string $systemPrompt): array
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
            'usage'      => ['include' => true],
        ];
        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);

        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
        $start  = microtime(true);

        try {
            $client   = new \GuzzleHttp\Client();
            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    // OpenRouter-recommended attribution. Optional env overrides; defaults to this FreeScout's APP_URL.
                    'HTTP-Referer'  => env('OPENROUTER_HTTP_REFERER') ?: config('app.url', 'https://freescout.local'),
                    'X-Title'       => env('OPENROUTER_X_TITLE') ?: 'FreeScout AI Assist',
                ],
                'body'            => json_encode($payload),
                'timeout'         => 30,
                'connect_timeout' => 10,
                'http_errors'     => false,
            ]);
            $raw = (string)$response->getBody();
        } catch (\Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            return ['success' => false, 'error' => 'Failed to connect to OpenRouter API: ' . $e->getMessage(), 'raw' => '', 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0];
        }

        $duration  = (int)((microtime(true) - $start) * 1000);
        $data      = json_decode($raw, true);
        $inputTok  = (int)($data['usage']['prompt_tokens']     ?? 0);
        $outputTok = (int)($data['usage']['completion_tokens'] ?? 0);
        $cost      = (isset($data['usage']['cost']) && is_numeric($data['usage']['cost']))
            ? round((float)$data['usage']['cost'], 6)
            : $this->calcCost($model, $inputTok, $outputTok);

        if (!empty($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'text' => trim($data['choices'][0]['message']['content']), 'raw' => $raw, 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => $inputTok, 'output_tokens' => $outputTok, 'cost_usd' => $cost];
        }

        $errMsg = 'OpenRouter error: ' . ($data['error']['message'] ?? $raw);
        return ['success' => false, 'error' => $errMsg, 'raw' => $raw, 'api_url' => $apiUrl, 'request_json' => $payloadJson, 'duration_ms' => $duration, 'input_tokens' => $inputTok, 'output_tokens' => $outputTok, 'cost_usd' => $cost];
    }

    // Cached OpenRouter model list with per-1M pricing. Public so the reply panel (ServiceProvider) can reuse it.
    public function getModels(bool $force = false): array
    {
        if (!$force) {
            $fresh = $this->readModelsCache(true);
            if ($fresh !== null) {
                return $fresh;
            }
        }

        try {
            $client   = new \GuzzleHttp\Client();
            $response = $client->get('https://openrouter.ai/api/v1/models', [
                'headers'         => ['Content-Type' => 'application/json'],
                'timeout'         => 15,
                'connect_timeout' => 10,
                'http_errors'     => false,
            ]);
            $data = json_decode((string)$response->getBody(), true);

            if (!empty($data['data']) && is_array($data['data'])) {
                $list = [];
                foreach ($data['data'] as $m) {
                    if (empty($m['id'])) {
                        continue;
                    }
                    $list[] = [
                        'id'                        => $m['id'],
                        'name'                      => $m['name'] ?? $m['id'],
                        'prompt_price_per_mtok'     => round((float)($m['pricing']['prompt'] ?? 0) * 1_000_000, 4),
                        'completion_price_per_mtok' => round((float)($m['pricing']['completion'] ?? 0) * 1_000_000, 4),
                        'context_length'            => (int)($m['context_length'] ?? 0),
                    ];
                }
                if (!empty($list)) {
                    usort($list, function ($a, $b) {
                        return strcasecmp($a['name'], $b['name']);
                    });
                    // FreeScout's Option facade JSON-encodes/decodes on its own — store the array directly.
                    \Option::set('openrouter_assist.models_cache', ['fetched_at' => time(), 'list' => $list]);
                    return $list;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to whatever is cached (even if stale), else the hardcoded fallback.
        }

        $stale = $this->readModelsCache(false);
        return $stale !== null ? $stale : self::$FALLBACK_MODELS;
    }

    // Returns the cached model list, or null. $freshOnly enforces the 24h TTL.
    private function readModelsCache(bool $freshOnly): ?array
    {
        $decoded = \Option::get('openrouter_assist.models_cache', null);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true); // tolerate older Option versions that return a raw string
        }
        if (empty($decoded['list']) || !is_array($decoded['list'])) {
            return null;
        }
        if ($freshOnly) {
            $age = time() - (int)($decoded['fetched_at'] ?? 0);
            if ($age >= self::MODELS_CACHE_TTL) {
                return null;
            }
        }
        return $decoded['list'];
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
