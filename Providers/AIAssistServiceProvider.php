<?php

namespace Modules\HexawebAIAssist\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AIAssistServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'hexawebaiassist');

        $this->registerMigration();
        $this->registerHooks();
    }

    public function register()
    {
        //
    }

    private function registerMigration()
    {
        try {
            if (!Schema::hasTable('ai_assist_templates')) {
                Schema::create('ai_assist_templates', function ($table) {
                    $table->increments('id');
                    $table->string('provider', 20)->default('any');
                    $table->string('name', 255);
                    $table->string('description', 500)->nullable();
                    $table->text('prompt');
                    $table->tinyInteger('is_builtin')->default(0);
                    $table->integer('sort_order')->default(0);
                    $table->unsignedInteger('created_by')->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            }

            // Seed built-in templates if none exist with provider='any'
            $anyCount = \DB::table('ai_assist_templates')
                ->where('provider', 'any')
                ->where('is_builtin', 1)
                ->count();

            if ($anyCount === 0) {
                $now = now();
                \DB::table('ai_assist_templates')->insert([
                    ['provider' => 'any', 'name' => 'Professional Follow-Up',    'description' => 'A concise, friendly follow-up addressing the customer concern.',        'prompt' => 'Write a professional follow-up email response to the customer. Be concise, friendly, and helpful. Address their concern directly.',                                    'is_builtin' => 1, 'sort_order' => 1, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
                    ['provider' => 'any', 'name' => 'Apology & Resolution',      'description' => 'Sincere apology with clear resolution or next steps.',                   'prompt' => 'Write a sincere apology email to the customer acknowledging their issue and providing a clear resolution or next steps.',                                         'is_builtin' => 1, 'sort_order' => 2, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
                    ['provider' => 'any', 'name' => 'Request More Information',  'description' => 'Politely ask for details needed to resolve the issue.',                   'prompt' => 'Write a polite email asking the customer for more information needed to resolve their issue. Be specific about what is needed.',                                 'is_builtin' => 1, 'sort_order' => 3, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
                    ['provider' => 'any', 'name' => 'Closing / Thank You',       'description' => 'Professional closing confirming issue has been resolved.',                'prompt' => 'Write a professional closing email thanking the customer for their patience and confirming their issue has been resolved.',                                        'is_builtin' => 1, 'sort_order' => 4, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
                    ['provider' => 'any', 'name' => 'Escalation Notice',         'description' => 'Inform customer of escalation with response time expectations.',          'prompt' => 'Write an email informing the customer their issue is being escalated to a specialist. Set expectations for response time.',                                       'is_builtin' => 1, 'sort_order' => 5, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
                ]);
            }
            // ai_request_logs table
            if (!Schema::hasTable('ai_request_logs')) {
                Schema::create('ai_request_logs', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('user_id')->nullable();
                    $table->string('user_email', 255)->nullable();
                    $table->string('provider', 20)->nullable();
                    $table->string('model', 100)->nullable();
                    $table->string('action', 50)->nullable();
                    $table->unsignedInteger('conversation_id')->nullable();
                    $table->string('page_url', 1000)->nullable();
                    $table->string('client_ip', 45)->nullable();
                    $table->string('api_url', 255)->nullable();
                    $table->longText('request_json')->nullable();
                    $table->longText('response_json')->nullable();
                    $table->string('status', 20)->nullable();
                    $table->text('error_message')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->unsignedInteger('input_tokens')->default(0);
                    $table->unsignedInteger('output_tokens')->default(0);
                    $table->decimal('cost_usd', 10, 6)->default(0);
                    $table->timestamp('created_at')->nullable();
                });
            } else {
                // Add cost columns to existing table if missing
                if (!Schema::hasColumn('ai_request_logs', 'input_tokens')) {
                    Schema::table('ai_request_logs', function ($table) {
                        $table->unsignedInteger('input_tokens')->default(0)->after('duration_ms');
                        $table->unsignedInteger('output_tokens')->default(0)->after('input_tokens');
                        $table->decimal('cost_usd', 10, 6)->default(0)->after('output_tokens');
                    });
                }
            }
        } catch (\Exception $e) {
            // Silently fail during migrations
        }
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

    private function registerHooks()
    {
        // ── AI logs panel inside conversation thread ──────────────────
        \Eventy::addAction('conversation.after_threads', function ($conversation) {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return;
            }

            try {
                $logs = \DB::table('ai_request_logs')
                    ->where('conversation_id', $conversation->id)
                    ->orderBy('id', 'desc')
                    ->limit(50)
                    ->get();
            } catch (\Exception $e) {
                return;
            }

            if ($logs->isEmpty()) {
                return;
            }

            $est      = new \DateTimeZone('America/New_York');
            $logsUrl  = url('/hexaweb/ai/logs');
            $hasError = $logs->contains('status', 'error');

            // Total cost for this conversation
            $totalCost = $logs->sum('cost_usd');

            ?>
<div id="hbt-conv-logs" style="margin:16px 0;border:1px solid <?php echo $hasError ? '#e74c3c' : '#d0bfee'; ?>;border-radius:6px;background:#fff;">
  <div id="hbt-conv-logs-header"
       style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;cursor:pointer;background:<?php echo $hasError ? '#fff5f5' : '#faf7ff'; ?>;border-radius:6px;"
       onclick="(function(){var b=document.getElementById('hbt-conv-logs-body');var i=document.getElementById('hbt-conv-logs-chevron');var v=b.style.display==='none';b.style.display=v?'block':'none';i.style.transform=v?'rotate(180deg)':'rotate(0deg)';})()">
    <span style="font-size:12px;font-weight:700;color:<?php echo $hasError ? '#e74c3c' : '#8e59c3'; ?>;text-transform:uppercase;letter-spacing:.6px;">
      <?php echo $hasError ? '⚠ ' : ''; ?>AI Requests
      <span style="font-weight:400;color:#aaa;margin-left:6px;"><?php echo count($logs); ?> request<?php echo count($logs) === 1 ? '' : 's'; ?></span>
      <?php if ($totalCost > 0): ?>
      <span style="font-weight:400;color:#888;font-size:11px;margin-left:10px;text-transform:none;letter-spacing:0;">
        Session cost: <span style="color:#555;font-weight:600;">$<?php echo number_format($totalCost, 5); ?></span>
      </span>
      <?php endif; ?>
    </span>
    <div style="display:flex;align-items:center;gap:10px;" onclick="event.stopPropagation();">
      <a href="<?php echo $logsUrl; ?>" target="_blank" style="font-size:11px;color:#aaa;">
        View All <i class="glyphicon glyphicon-new-window" style="font-size:10px;"></i>
      </a>
      <i id="hbt-conv-logs-chevron" class="glyphicon glyphicon-chevron-up" style="color:#aaa;font-size:11px;transition:transform .2s;"></i>
    </div>
  </div>

  <div id="hbt-conv-logs-body" style="border-top:1px solid #eee;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead>
        <tr style="background:#f9f9f9;border-bottom:1px solid #eee;">
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;white-space:nowrap;">Time (EST)</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">User</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">Provider</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">Action</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">Model</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">Status</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">Duration</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:left;">Cost</th>
          <th style="padding:6px 10px;font-weight:600;color:#888;text-align:center;">Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <?php
            $isErr = $log->status === 'error';
            $dt    = $log->created_at
                ? (new \DateTime($log->created_at, new \DateTimeZone('UTC')))->setTimezone($est)->format('M j, Y g:ia T')
                : '—';
            $rowBg = $isErr ? '#fff5f5' : '#fff';
            $clipId = 'hbt-clog-clip-' . $log->id;
            $detId  = 'hbt-clog-det-'  . $log->id;

            $clipText = "=== AI Request Log #{$log->id} ===\n"
                . "Time (EST):   {$dt}\n"
                . "User:         {$log->user_email} (ID: {$log->user_id})\n"
                . "Provider:     {$log->provider}\n"
                . "Model:        {$log->model}\n"
                . "Action:       {$log->action}\n"
                . "Status:       {$log->status}\n"
                . "Duration:     {$log->duration_ms}ms\n"
                . "IP:           {$log->client_ip}\n"
                . "API Endpoint: {$log->api_url}\n"
                . "Page URL:     {$log->page_url}\n";
            if ($isErr) {
                $clipText .= "\nERROR MESSAGE:\n{$log->error_message}\n";
            }
            $responseFormatted = $log->response_json
                ? json_encode(json_decode($log->response_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '(empty)';
            $clipText .= "\n--- REQUEST PAYLOAD ---\n{$log->request_json}\n\n--- RESPONSE JSON ---\n{$responseFormatted}";
        ?>
        <tr style="background:<?php echo $rowBg; ?>;border-bottom:1px solid #f0f0f0;">
          <td style="padding:7px 10px;white-space:nowrap;<?php echo $isErr ? 'color:#a94442;font-weight:600;' : 'color:#555;'; ?>"><?php echo htmlspecialchars($dt); ?></td>
          <td style="padding:7px 10px;color:#666;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($log->user_email ?? ''); ?>"><?php echo htmlspecialchars($log->user_email ?? '—'); ?></td>
          <td style="padding:7px 10px;">
            <?php if ($log->provider === 'openrouter'): ?>
              <span style="background:#6467f2;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;">OpenRouter</span>
            <?php else: echo htmlspecialchars($log->provider ?? '—'); endif; ?>
          </td>
          <td style="padding:7px 10px;color:#555;"><?php echo htmlspecialchars($log->action ?? '—'); ?></td>
          <td style="padding:7px 10px;color:#888;font-size:11px;"><?php echo htmlspecialchars($log->model ?? '—'); ?></td>
          <td style="padding:7px 10px;">
            <?php if ($isErr): ?>
              <span style="background:#e74c3c;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;">error</span>
            <?php else: ?>
              <span style="background:#27ae60;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;">ok</span>
            <?php endif; ?>
          </td>
          <td style="padding:7px 10px;color:<?php echo ($log->duration_ms ?? 0) > 10000 ? '#e67e22' : '#888'; ?>;white-space:nowrap;"><?php echo $log->duration_ms ? number_format($log->duration_ms) . 'ms' : '—'; ?></td>
          <td style="padding:7px 10px;color:#555;white-space:nowrap;font-family:monospace;font-size:11px;"><?php
            $costVal = isset($log->cost_usd) ? (float)$log->cost_usd : 0;
            echo $costVal > 0 ? '$' . number_format($costVal, 5) : '—';
            if ($costVal > 0 && isset($log->input_tokens, $log->output_tokens) && ($log->input_tokens || $log->output_tokens)):
          ?><br><span style="color:#aaa;font-size:10px;"><?php echo number_format($log->input_tokens); ?>↑ <?php echo number_format($log->output_tokens); ?>↓</span><?php endif; ?></td>
          <td style="padding:7px 10px;text-align:center;white-space:nowrap;">
            <button class="btn btn-xs btn-default"
              onclick="(function(){var d=document.getElementById('<?php echo $detId; ?>');var visible=d.style.display!=='none';d.style.display=visible?'none':'table-row';this.querySelector('i').className=visible?'glyphicon glyphicon-expand':'glyphicon glyphicon-collapse-up';}).call(this)"
              title="Expand">
              <i class="glyphicon glyphicon-expand"></i>
            </button>
            <button class="btn btn-xs btn-default" title="Copy to clipboard"
              onclick="(function(){var t=document.getElementById('<?php echo $clipId; ?>');if(!t)return;if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).then(function(){showFloatingAlert('success','Copied to clipboard.');});}else{t.style.cssText='position:fixed;left:0;top:0;';t.select();try{document.execCommand('copy');showFloatingAlert('success','Copied.');}catch(e){}t.style.cssText='position:absolute;left:-9999px;';}}).call(this)">
              <i class="glyphicon glyphicon-copy"></i>
            </button>
            <textarea id="<?php echo $clipId; ?>" style="position:absolute;left:-9999px;"><?php echo htmlspecialchars($clipText); ?></textarea>
          </td>
        </tr>
        <?php if ($isErr && $log->error_message): ?>
        <tr style="background:#fff0f0;border-bottom:1px solid #f0e0e0;">
          <td colspan="9" style="padding:4px 10px 6px 28px;color:#a94442;font-size:12px;">
            <i class="glyphicon glyphicon-exclamation-sign" style="margin-right:4px;"></i>
            <strong>Error:</strong> <?php echo htmlspecialchars($log->error_message); ?>
          </td>
        </tr>
        <?php endif; ?>
        <tr id="<?php echo $detId; ?>" style="display:none;">
          <td colspan="9" style="padding:0;background:#fafafa;">
            <div style="padding:12px 14px;border-top:2px solid <?php echo $isErr ? '#e74c3c' : '#8e59c3'; ?>;">
              <div style="font-size:11px;color:#888;margin-bottom:8px;">
                <strong>IP:</strong> <?php echo htmlspecialchars($log->client_ip ?? '—'); ?> &nbsp;·&nbsp;
                <strong>API:</strong> <code style="font-size:11px;"><?php echo htmlspecialchars($log->api_url ?? '—'); ?></code> &nbsp;·&nbsp;
                <strong>Page:</strong> <span style="word-break:break-all;"><?php echo htmlspecialchars($log->page_url ?? '—'); ?></span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:4px;">Request Payload</div>
                  <pre style="background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:4px;font-size:11px;max-height:250px;overflow:auto;margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo htmlspecialchars($log->request_json ?: '(empty)'); ?></pre>
                </div>
                <div>
                  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:<?php echo $isErr ? '#e74c3c' : '#888'; ?>;margin-bottom:4px;">Response JSON <?php echo $isErr ? '⚠ ERROR' : ''; ?></div>
                  <pre style="background:#1e1e1e;color:<?php echo $isErr ? '#ff8080' : '#d4d4d4'; ?>;padding:10px;border-radius:4px;font-size:11px;max-height:250px;overflow:auto;margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo htmlspecialchars($responseFormatted); ?></pre>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
            <?php
        }, 20, 1);

        // ── Templates link in Manage menu ────────────────────────────
        \Eventy::addAction('menu.manage.append', function () {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return;
            }
            $activeTpl  = request()->is('hexaweb/ai/templates*') ? ' class="active"' : '';
            $activeLogs = request()->is('hexaweb/ai/logs*')      ? ' class="active"' : '';
            echo '<li' . $activeTpl  . '><a href="' . url('/hexaweb/ai/templates') . '"><i class="glyphicon glyphicon-list-alt"></i> AI Templates</a></li>';
            echo '<li' . $activeLogs . '><a href="' . url('/hexaweb/ai/logs')      . '"><i class="glyphicon glyphicon-list-alt"></i> AI Logs</a></li>';
        }, 22);

        // ── Unified AI panel in reply form ────────────────────────────
        \Eventy::addAction('reply_form.after', function ($conversation) {
            $key = $this->getApiKey('openrouter_assist.api_key_encrypted');

            // No key — show setup notice
            if (!$key) {
                echo '<div style="margin-top:10px;padding:8px 12px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;font-size:13px;color:#795548;">'
                    . '<i class="glyphicon glyphicon-info-sign"></i> AI Assist not configured. '
                    . 'Add your <a href="' . url('/hexaweb/ai/settings') . '">OpenRouter API key</a> to get started.'
                    . '</div>';
                return;
            }

            // Model options from the cached OpenRouter model list (shared with the settings page)
            $currentModel  = \Option::get('openrouter_assist.model', \Modules\HexawebAIAssist\Http\Controllers\AIAssistController::DEFAULT_MODEL);
            $modelOptsHtml = '';
            try {
                $models = (new \Modules\HexawebAIAssist\Http\Controllers\AIAssistController)->getModels();
            } catch (\Exception $e) {
                $models = [];
            }
            foreach ($models as $m) {
                $label = $m['name'] . ' — $' . number_format($m['prompt_price_per_mtok'], 2) . '/$' . number_format($m['completion_price_per_mtok'], 2) . ' per 1M';
                $modelOptsHtml .= '<option value="' . e($m['id']) . '"' . ($m['id'] === $currentModel ? ' selected' : '') . '>' . e($label) . '</option>';
            }

            // Template options
            $templates = collect();
            try {
                $templates = \DB::table('ai_assist_templates')
                    ->orderBy('is_builtin', 'desc')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->unique('name'); // deduplicate by name (in case old claude/gpt seeded rows exist)
            } catch (\Exception $e) {
            }

            $tplOptsHtml    = '<option value="">— Select template —</option>';
            $builtinGroup   = '';
            $customGroup    = '';
            foreach ($templates as $tpl) {
                $opt = '<option value="' . (int)$tpl->id . '">' . e($tpl->name) . '</option>';
                if ($tpl->is_builtin) {
                    $builtinGroup .= $opt;
                } else {
                    $customGroup .= $opt;
                }
            }
            if ($builtinGroup) {
                $tplOptsHtml .= '<optgroup label="Built-in">' . $builtinGroup . '</optgroup>';
            }
            if ($customGroup) {
                $tplOptsHtml .= '<optgroup label="Custom">' . $customGroup . '</optgroup>';
            }

            ?>
<div id="hbt-ai-wrap" style="margin-top:20px;border-top:2px solid #e8e0f5;">

  <!-- AI Assistant header -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0 6px;">
    <div style="display:flex;align-items:center;gap:8px;">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#6467f2;">
        &#9889; AI Assistant
      </span>
      <span style="font-size:10px;font-weight:700;background:#6467f2;color:#fff;padding:2px 6px;border-radius:3px;">OpenRouter</span>
      <!-- Model selector -->
      <select class="hbt-model" style="font-size:11px;border:1px solid #c7c9f7;border-radius:4px;padding:2px 4px;color:#555;max-width:340px;">
        <?php echo $modelOptsHtml; ?>
      </select>
    </div>
    <!-- Settings links -->
    <div style="display:flex;align-items:center;gap:10px;">
      <a href="<?php echo url('/hexaweb/ai/settings'); ?>" target="_blank" style="font-size:11px;color:#6467f2;text-decoration:none;white-space:nowrap;">
        Settings <i class="glyphicon glyphicon-new-window" style="font-size:10px;"></i>
      </a>
      <a href="<?php echo url('/hexaweb/ai/templates'); ?>" target="_blank" style="font-size:11px;color:#888;text-decoration:none;white-space:nowrap;">
        Templates <i class="glyphicon glyphicon-new-window" style="font-size:10px;"></i>
      </a>
    </div>
  </div>

  <!-- Action tabs -->
  <div style="display:flex;gap:2px;margin-bottom:-1px;">
    <button type="button" class="hbt-action-tab" data-action="compose"
      style="border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer;background:#fff;color:#555;">
      Compose
    </button>
    <button type="button" class="hbt-action-tab" data-action="templates"
      style="border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer;background:#f7f7f7;color:#999;">
      Templates
    </button>
    <button type="button" class="hbt-action-tab" data-action="polish"
      style="border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer;background:#f7f7f7;color:#999;">
      Polish
    </button>
  </div>

  <!-- Panel: Compose -->
  <div id="hbt-panel-compose" class="hbt-ai-panel" style="border:1px solid #ddd;border-radius:0 4px 4px 4px;padding:14px;background:#fff;">
    <textarea class="form-control hbt-compose-input" rows="6"
      placeholder="e.g. Write a polite reply saying we need 2 more business days"
      style="font-size:13px;resize:vertical;"></textarea>
    <div style="margin-top:8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <label style="font-size:12px;margin:0;cursor:pointer;color:#666;">
        <input type="checkbox" class="hbt-include-context" checked> Include conversation context
        <span title="When checked, the last 5 messages in this conversation are sent to the AI so it can write a contextually relevant reply. Uncheck to send only your instructions." style="display:inline-block;width:15px;height:15px;background:#ccc;color:#fff;border-radius:50%;text-align:center;line-height:15px;font-size:10px;font-weight:700;margin-left:4px;cursor:help;">?</span>
      </label>
      <button type="button" class="btn btn-primary btn-sm hbt-compose-btn">Generate</button>
    </div>
    <div class="hbt-request-log" style="display:none;margin-top:8px;"></div>
    <div class="hbt-output-wrap" style="display:none;margin-top:12px;border-top:1px solid #eee;padding-top:12px;">
      <textarea class="form-control hbt-output" rows="5" style="font-size:13px;background:#fafafa;"></textarea>
      <div style="margin-top:8px;display:flex;gap:6px;">
        <button type="button" class="btn btn-success btn-sm hbt-import-btn" data-mode="replace">&#8595; Use as Reply</button>
        <button type="button" class="btn btn-default btn-sm hbt-import-btn" data-mode="append">&#8595; Append</button>
        <button type="button" class="btn btn-link btn-sm hbt-clear-btn" style="color:#aaa;">Clear</button>
      </div>
    </div>
  </div>

  <!-- Panel: Templates -->
  <div id="hbt-panel-templates" class="hbt-ai-panel" style="display:none;border:1px solid #ddd;border-radius:0 4px 4px 4px;padding:14px;background:#fff;">
    <div style="margin-bottom:8px;">
      <select class="form-control hbt-template-select" style="font-size:13px;">
        <?php echo $tplOptsHtml; ?>
      </select>
    </div>
    <div class="hbt-tpl-preview" style="display:none;margin-bottom:8px;padding:8px 10px;background:#f8f5ff;border:1px solid #d0bfee;border-radius:4px;">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#8e59c3;margin-bottom:4px;">Template Prompt</div>
      <pre class="hbt-tpl-preview-text" style="margin:0;font-size:12px;white-space:pre-wrap;word-break:break-word;color:#444;max-height:120px;overflow:auto;background:transparent;border:none;padding:0;"></pre>
    </div>
    <textarea class="form-control hbt-template-extra" rows="4"
      placeholder="Additional instructions (optional) — e.g. Keep it under 3 sentences, address the customer by name"
      style="font-size:13px;resize:vertical;margin-bottom:8px;"></textarea>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <label style="font-size:12px;margin:0;cursor:pointer;color:#666;">
        <input type="checkbox" class="hbt-include-context-tpl" checked> Include conversation context
        <span title="When checked, the last 5 messages in this conversation are sent to the AI so it can apply the template in context. Uncheck to apply the template without conversation history." style="display:inline-block;width:15px;height:15px;background:#ccc;color:#fff;border-radius:50%;text-align:center;line-height:15px;font-size:10px;font-weight:700;margin-left:4px;cursor:help;">?</span>
      </label>
      <button type="button" class="btn btn-primary btn-sm hbt-template-btn">Apply Template</button>
    </div>
    <div class="hbt-request-log-tpl" style="display:none;margin-top:8px;"></div>
    <div class="hbt-output-tpl-wrap" style="display:none;margin-top:12px;border-top:1px solid #eee;padding-top:12px;">
      <textarea class="form-control hbt-output-tpl" rows="5" style="font-size:13px;background:#fafafa;"></textarea>
      <div style="margin-top:8px;display:flex;gap:6px;">
        <button type="button" class="btn btn-success btn-sm hbt-import-tpl-btn" data-mode="replace">&#8595; Use as Reply</button>
        <button type="button" class="btn btn-default btn-sm hbt-import-tpl-btn" data-mode="append">&#8595; Append</button>
        <button type="button" class="btn btn-link btn-sm hbt-clear-tpl-btn" style="color:#aaa;">Clear</button>
      </div>
    </div>
  </div>

  <!-- Panel: Polish -->
  <div id="hbt-panel-polish" class="hbt-ai-panel" style="display:none;border:1px solid #ddd;border-radius:0 4px 4px 4px;padding:14px;background:#fff;">
    <p style="font-size:13px;color:#666;margin:0 0 12px;">Reads your current reply draft and returns an improved version with better grammar, tone, and clarity.</p>
    <button type="button" class="btn btn-primary btn-sm hbt-polish-btn">Polish Reply</button>
    <div class="hbt-request-log-polish" style="display:none;margin-top:8px;"></div>
    <div class="hbt-polish-output-wrap" style="display:none;margin-top:12px;border-top:1px solid #eee;padding-top:12px;">
      <textarea class="form-control hbt-polish-output" rows="5" style="font-size:13px;background:#fafafa;"></textarea>
      <div style="margin-top:8px;display:flex;gap:6px;">
        <button type="button" class="btn btn-success btn-sm hbt-polish-replace-btn">&#8595; Use as Reply</button>
        <button type="button" class="btn btn-default btn-sm hbt-polish-append-btn">&#8595; Append</button>
        <button type="button" class="btn btn-link btn-sm hbt-polish-clear-btn" style="color:#aaa;">Clear</button>
      </div>
    </div>
  </div>

</div>
<?php
            // Store default provider for JS
            // Build templates map for JS preview
            $tplsForJs = [];
            foreach ($templates as $tpl) {
                $tplsForJs[(int)$tpl->id] = ['name' => $tpl->name, 'prompt' => $tpl->prompt, 'desc' => $tpl->description ?? ''];
            }

            echo '<script type="text/x-hbt-data" id="hbt-ai-config">'
                . json_encode([
                    'composeUrl'      => url('/hexaweb/ai/compose'),
                    'templateUrl'     => url('/hexaweb/ai/template'),
                    'polishUrl'       => url('/hexaweb/ai/polish'),
                    'templates'       => $tplsForJs,
                ])
                . '</script>';

        }, 20, 1);

        // ── JavaScript (nonce-safe) ────────────────────────────────────
        \Eventy::addAction('javascript', function () {
            ?>
// ── HexawebAIAssist ──
(function() {
if (window._hbtAiBound) return;
window._hbtAiBound = true;

    var _hbtCfgEl = document.getElementById('hbt-ai-config');
    if (!_hbtCfgEl) return; // panel not present on this page
    var _hbtCfg = JSON.parse(_hbtCfgEl.textContent || _hbtCfgEl.innerHTML || '{}');
    var _hbtProvider = 'openrouter';
    var _hbtToken = jQuery('meta[name="csrf-token"]').attr('content');

    // Helper: get/set reply body (FreeScout uses Summernote on #body)
    function hbtGetBody() {
        var $b = jQuery('#body');
        if ($b.length && $b.data('summernote')) {
            return $b.summernote('code') || '';
        }
        return $b.val() || '';
    }
    function hbtSetBody(text, mode) {
        var $b = jQuery('#body');
        // Convert plain text to HTML (preserve newlines)
        var html = '<p>' + text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n\n/g,'</p><p>').replace(/\n/g,'<br>') + '</p>';
        if ($b.length && $b.data('summernote')) {
            if (mode === 'replace') {
                // Keep signature block if present, put AI text before it
                var current = $b.summernote('code') || '';
                var sigMatch = current.match(/(<(?:div|p)[^>]*id=["']hbt-signature-block["'][^>]*>[\s\S]*$)/i);
                if (sigMatch) {
                    $b.summernote('code', html + sigMatch[1]);
                } else {
                    $b.summernote('code', html);
                }
            } else {
                var existing = $b.summernote('code') || '';
                var sigMatch2 = existing.match(/([\s\S]*?)(<(?:div|p)[^>]*id=["']hbt-signature-block["'][^>]*>[\s\S]*$)/i);
                if (sigMatch2) {
                    $b.summernote('code', sigMatch2[1] + html + sigMatch2[2]);
                } else {
                    $b.summernote('code', existing + html);
                }
            }
        } else {
            if (mode === 'replace') {
                $b.val(text);
            } else {
                var cur = $b.val();
                $b.val(cur ? cur + '\n\n' + text : text);
            }
            $b.trigger('change');
        }
        showFloatingAlert('success', 'Imported to reply.');
    }

    // Action tab switch — active tab: white bg, dark text; inactive: grey bg, muted text
    function hbtSetActiveTab(action) {
        jQuery('.hbt-action-tab').each(function() {
            var isActive = jQuery(this).data('action') === action;
            jQuery(this).css({ background: isActive ? '#fff' : '#f7f7f7', color: isActive ? '#333' : '#999', fontWeight: isActive ? '700' : '600' });
        });
        jQuery('#hbt-panel-compose, #hbt-panel-templates, #hbt-panel-polish').hide();
        jQuery('#hbt-panel-' + action).show();
    }
    // Set initial active tab
    hbtSetActiveTab('compose');

    jQuery(document).on('click', '.hbt-action-tab', function() {
        hbtSetActiveTab(jQuery(this).data('action'));
    });

    // Helper: render a compact request log entry into a container
    function hbtShowLog(containerSel, r, durationMs, provider, model) {
        var $el = jQuery(containerSel);
        var isErr = r.status !== 'success';
        var now = new Date().toLocaleString('en-US', { timeZone: 'America/New_York', hour12: true,
            year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' EST';
        var provColor = '#6467f2';
        var borderColor = isErr ? '#e74c3c' : '#27ae60';
        var bg = isErr ? '#fff5f5' : '#f6fff8';

        var costStr = '';
        if (r._cost !== undefined && r._cost !== null) {
            costStr = ' &nbsp;·&nbsp; $' + parseFloat(r._cost).toFixed(5);
        }
        var tokStr = '';
        if (r._tokens && (r._tokens.in || r._tokens.out)) {
            tokStr = ' &nbsp;·&nbsp; ' + (r._tokens.in||0) + ' in / ' + (r._tokens.out||0) + ' out';
        }

        var html = '<div style="border:1px solid ' + borderColor + ';border-radius:4px;background:' + bg + ';font-size:11px;font-family:monospace;">'
            + '<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 8px;border-bottom:1px solid ' + borderColor + ';background:' + (isErr ? '#fff0f0' : '#edfff3') + ';flex-wrap:wrap;gap:4px;">'
            +   '<span style="font-weight:700;color:' + borderColor + ';">' + (isErr ? '✗ ERROR' : '✓ OK') + '</span>'
            +   '<span style="color:#888;font-size:10px;">'
            +     '<span style="background:' + provColor + ';color:#fff;padding:1px 5px;border-radius:2px;margin-right:4px;">' + provider.toUpperCase() + '</span>'
            +     model + ' &nbsp;·&nbsp; ' + durationMs + 'ms' + costStr + tokStr + ' &nbsp;·&nbsp; ' + now
            +   '</span>'
            +   '<button type="button" class="hbt-log-copy-btn" style="border:none;background:transparent;cursor:pointer;color:#aaa;font-size:11px;padding:0 4px;" title="Copy to clipboard">&#128203;</button>'
            + '</div>';

        if (isErr) {
            html += '<div style="padding:6px 8px;color:#a94442;font-weight:700;">' + hbtEscape(r.msg || 'Unknown error') + '</div>';
        }

        html += '<div class="hbt-log-detail" style="display:none;padding:6px 8px;">'
            + '<div style="margin-bottom:4px;color:#888;">Response JSON:</div>'
            + '<pre style="background:#1e1e1e;color:' + (isErr ? '#ff8080' : '#d4d4d4') + ';padding:8px;border-radius:3px;font-size:10px;max-height:180px;overflow:auto;margin:0;white-space:pre-wrap;word-break:break-all;">'
            + hbtEscape(r._raw || '(no raw response captured)')
            + '</pre></div>';

        html += '<div style="padding:3px 8px;border-top:1px solid ' + (isErr ? '#fac0c0' : '#c3f0cc') + ';">'
            +   '<button type="button" class="hbt-log-toggle-btn" style="border:none;background:transparent;cursor:pointer;color:#aaa;font-size:10px;padding:2px 0;">&#9660; show response JSON</button>'
            + '</div></div>';

        $el.html(html).show();

        // store for copy
        $el.data('clip', '=== AI Request ===\nTime: ' + now + '\nProvider: ' + provider + '\nModel: ' + model
            + '\nStatus: ' + (isErr ? 'ERROR' : 'OK') + '\nDuration: ' + durationMs + 'ms'
            + (r._cost !== undefined ? '\nCost: $' + parseFloat(r._cost).toFixed(6) : '')
            + (r._tokens ? '\nTokens: ' + (r._tokens.in||0) + ' in / ' + (r._tokens.out||0) + ' out' : '')
            + (isErr ? '\nError: ' + (r.msg || '') : '')
            + '\n\nResponse JSON:\n' + (r._raw || ''));
    }

    function hbtEscape(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    jQuery(document).on('click', '.hbt-log-toggle-btn', function() {
        var $detail = jQuery(this).closest('div[style*="border:1px"]').find('.hbt-log-detail');
        var shown = $detail.is(':visible');
        $detail.toggle(!shown);
        jQuery(this).text(shown ? '\u25bc show response JSON' : '\u25b2 hide response JSON');
    });

    jQuery(document).on('click', '.hbt-log-copy-btn', function() {
        var clip = jQuery(this).closest('.hbt-request-log, .hbt-request-log-tpl, .hbt-request-log-polish').data('clip') || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(clip).then(function() { showFloatingAlert('success', 'Copied.'); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = clip; ta.style.cssText = 'position:fixed;left:0;top:0;';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); showFloatingAlert('success', 'Copied.'); } catch(e) {}
            document.body.removeChild(ta);
        }
    });

    // Helper: get the selected OpenRouter model (single global select in the panel header)
    function hbtGetModel(panelId) {
        return jQuery('.hbt-model').val() || '';
    }

    // ── Template preview on select ──
    jQuery(document).on('change', '.hbt-template-select', function() {
        var id = parseInt(jQuery(this).val(), 10);
        var $preview = jQuery('.hbt-tpl-preview');
        if (id && _hbtCfg.templates && _hbtCfg.templates[id]) {
            var tpl = _hbtCfg.templates[id];
            jQuery('.hbt-tpl-preview-text').text(tpl.prompt);
            $preview.show();
        } else {
            $preview.hide();
        }
    });

    // ── Compose ──
    jQuery(document).on('click', '.hbt-compose-btn', function() {
        var $btn = jQuery(this);
        var prompt = jQuery('.hbt-compose-input').val().trim();
        if (!prompt) { showFloatingAlert('error', 'Please enter instructions.'); return; }
        var model = hbtGetModel('#hbt-panel-compose');
        var convId = getGlobalAttr('conversation_id');
        var t0 = Date.now();
        $btn.prop('disabled', true).text('Generating...');
        jQuery('.hbt-request-log').hide();
        jQuery.ajax({
            url: _hbtCfg.composeUrl, type: 'POST',
            data: { _token: _hbtToken, provider: _hbtProvider, prompt: prompt, model: model,
                    include_context: jQuery('.hbt-include-context').is(':checked') ? 1 : 0,
                    conversation_id: convId, page_url: window.location.href },
            success: function(r) {
                $btn.prop('disabled', false).text('Generate');
                hbtShowLog('.hbt-request-log', r, Date.now() - t0, _hbtProvider, model);
                if (r.status === 'success') {
                    jQuery('.hbt-output').val(r.text);
                    jQuery('.hbt-output-wrap').show();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Generate');
                hbtShowLog('.hbt-request-log', {status:'error', msg:'HTTP request failed — no response from server.'}, Date.now() - t0, _hbtProvider, model);
            }
        });
    });

    // ── Templates ──
    jQuery(document).on('click', '.hbt-template-btn', function() {
        var $btn = jQuery(this);
        var tplId = jQuery('.hbt-template-select').val();
        if (!tplId) { showFloatingAlert('error', 'Please select a template.'); return; }
        var model = hbtGetModel('#hbt-panel-templates');
        var convId = getGlobalAttr('conversation_id');
        var t0 = Date.now();
        $btn.prop('disabled', true).text('Processing...');
        jQuery('.hbt-request-log-tpl').hide();
        jQuery.ajax({
            url: _hbtCfg.templateUrl, type: 'POST',
            data: { _token: _hbtToken, provider: _hbtProvider, template_id: tplId, model: model,
                    extra: jQuery('.hbt-template-extra').val().trim(),
                    include_context: jQuery('.hbt-include-context-tpl').is(':checked') ? 1 : 0,
                    conversation_id: convId, page_url: window.location.href },
            success: function(r) {
                $btn.prop('disabled', false).text('Apply Template');
                hbtShowLog('.hbt-request-log-tpl', r, Date.now() - t0, _hbtProvider, model);
                if (r.status === 'success') {
                    jQuery('.hbt-output-tpl').val(r.text);
                    jQuery('.hbt-output-tpl-wrap').show();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Apply Template');
                hbtShowLog('.hbt-request-log-tpl', {status:'error', msg:'HTTP request failed — no response from server.'}, Date.now() - t0, _hbtProvider, model);
            }
        });
    });

    // ── Polish ──
    jQuery(document).on('click', '.hbt-polish-btn', function() {
        var $btn = jQuery(this);
        var text = hbtGetBody();
        if (!text.trim()) { showFloatingAlert('error', 'Reply body is empty.'); return; }
        var model = hbtGetModel('#hbt-panel-polish');
        var convId = getGlobalAttr('conversation_id');
        var t0 = Date.now();
        $btn.prop('disabled', true).html('<i class="glyphicon glyphicon-education"></i> Polishing...');
        jQuery('.hbt-request-log-polish').hide();
        jQuery.ajax({
            url: _hbtCfg.polishUrl, type: 'POST',
            data: { _token: _hbtToken, provider: _hbtProvider, model: model, text: text, conversation_id: convId, page_url: window.location.href },
            success: function(r) {
                $btn.prop('disabled', false).html('<i class="glyphicon glyphicon-education"></i> Polish Reply');
                hbtShowLog('.hbt-request-log-polish', r, Date.now() - t0, _hbtProvider, model);
                if (r.status === 'success') {
                    jQuery('.hbt-polish-output').val(r.text);
                    jQuery('.hbt-polish-output-wrap').show();
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="glyphicon glyphicon-education"></i> Polish Reply');
                hbtShowLog('.hbt-request-log-polish', {status:'error', msg:'HTTP request failed — no response from server.'}, Date.now() - t0, _hbtProvider, model);
            }
        });
    });

    // ── Import buttons ──
    jQuery(document).on('click', '.hbt-import-btn', function() {
        hbtSetBody(jQuery('.hbt-output').val(), jQuery(this).data('mode'));
    });
    jQuery(document).on('click', '.hbt-import-tpl-btn', function() {
        hbtSetBody(jQuery('.hbt-output-tpl').val(), jQuery(this).data('mode'));
    });
    jQuery(document).on('click', '.hbt-polish-replace-btn', function() {
        hbtSetBody(jQuery('.hbt-polish-output').val(), 'replace');
    });
    jQuery(document).on('click', '.hbt-polish-append-btn', function() {
        hbtSetBody(jQuery('.hbt-polish-output').val(), 'append');
    });

    // ── Clear buttons ──
    jQuery(document).on('click', '.hbt-clear-btn', function() {
        jQuery('.hbt-output').val(''); jQuery('.hbt-output-wrap').hide();
    });
    jQuery(document).on('click', '.hbt-clear-tpl-btn', function() {
        jQuery('.hbt-output-tpl').val(''); jQuery('.hbt-output-tpl-wrap').hide();
    });
    jQuery(document).on('click', '.hbt-polish-clear-btn', function() {
        jQuery('.hbt-polish-output').val(''); jQuery('.hbt-polish-output-wrap').hide();
    });
})();
            <?php
        }, 20);
    }
}
