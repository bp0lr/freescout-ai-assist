@extends('layouts.app')

@section('title', 'AI Settings')

@section('content')
<div class="container" style="max-width:760px;padding:20px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;font-size:22px;color:#333;">
            <i class="glyphicon glyphicon-cog" style="margin-right:8px;color:#777;"></i>
            AI Settings
            <small style="font-size:13px;color:#999;margin-left:8px;">OpenRouter</small>
        </h2>
        <div style="display:flex;gap:8px;">
            <a href="{{ url('/hexaweb/ai/templates') }}" class="btn btn-default btn-sm">
                <i class="glyphicon glyphicon-list-alt"></i> AI Templates
            </a>
            <a href="{{ url('/hexaweb/ai/logs') }}" class="btn btn-default btn-sm">
                <i class="glyphicon glyphicon-list-alt"></i> AI Logs
            </a>
        </div>
    </div>

    @if(session('flash_success_floating'))
        <div class="alert alert-success">{{ session('flash_success_floating') }}</div>
    @endif
    @if(session('flash_error_floating'))
        <div class="alert alert-danger">{{ session('flash_error_floating') }}</div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><strong>OpenRouter Configuration</strong></div>
        <div class="panel-body">
            <form method="POST" action="{{ route('hexaweb.ai.settings.save') }}">
                {{ csrf_field() }}

                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">API Key</label>
                    <input type="password" name="api_key" class="form-control" autocomplete="new-password"
                           placeholder="{{ $hasKey ? 'configured ✓ — leave blank to keep current key' : 'sk-or-...' }}">
                    <span class="help-block" style="font-size:12px;">
                        @if($hasKey)
                            A key is already configured. Leave this blank to keep it, or enter a new key to replace it.
                        @else
                            Enter your OpenRouter API key (starts with <code>sk-or-</code>). Stored encrypted.
                        @endif
                    </span>
                </div>

                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">Default Model</label>
                    <select name="model" class="form-control">
                        @foreach($models as $m)
                            <option value="{{ $m['id'] }}" {{ $m['id'] === $model ? 'selected' : '' }}>
                                {{ $m['name'] }} — ${{ number_format($m['prompt_price_per_mtok'], 2) }}/${{ number_format($m['completion_price_per_mtok'], 2) }} per 1M
                            </option>
                        @endforeach
                    </select>
                    <span class="help-block" style="font-size:12px;">Used when no per-request model is chosen. Prices shown are input/output per 1M tokens.</span>
                </div>

                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">Max Tokens</label>
                    <input type="number" name="max_tokens" class="form-control" min="1" max="32768" value="{{ $maxTokens }}">
                    <span class="help-block" style="font-size:12px;">Maximum tokens to generate per reply.</span>
                </div>

                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">System Prompt</label>
                    <textarea name="system_prompt" class="form-control" rows="4"
                              placeholder="Optional — instructions sent as the system message on every request.">{{ $systemPrompt }}</textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="glyphicon glyphicon-floppy-disk"></i> Save Settings
                </button>
            </form>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Model List</strong>
            <small style="color:#999;margin-left:8px;">{{ count($models) }} models cached (refreshed every 24h).</small>
        </div>
        <div class="panel-body">
            <p style="font-size:13px;color:#666;margin:0 0 12px;">
                The model selector is populated from OpenRouter's live model list with real per-1M pricing.
                Refresh to pull the latest models and prices now.
            </p>
            <form method="POST" action="{{ route('hexaweb.ai.settings.refresh-models') }}" style="display:inline;">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-default">
                    <i class="glyphicon glyphicon-refresh"></i> Refresh Models
                </button>
            </form>
        </div>
    </div>

</div>
@endsection
