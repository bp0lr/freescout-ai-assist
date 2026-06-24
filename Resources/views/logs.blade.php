@extends('layouts.app')

@section('title', 'AI Request Logs')

@section('content')
<div class="container-fluid" style="padding:20px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;font-size:22px;color:#333;">
            <i class="glyphicon glyphicon-list-alt" style="margin-right:8px;color:#8e59c3;"></i>
            AI Request Logs
            <small style="font-size:13px;color:#999;margin-left:8px;">Last 200 requests</small>
        </h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button id="hbt-copy-last-error" class="btn btn-warning btn-sm" style="display:none;">
                <i class="glyphicon glyphicon-copy"></i> Copy Last Error
            </button>
            <a href="{{ url('/hexaweb/ai/templates') }}" class="btn btn-default btn-sm">
                <i class="glyphicon glyphicon-list-alt"></i> Templates
            </a>
            <form method="POST" action="{{ url('/hexaweb/ai/logs/clear') }}" style="display:inline;"
                  onsubmit="return confirm('Clear all AI request logs? This cannot be undone.');">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="glyphicon glyphicon-trash"></i> Clear Logs
                </button>
            </form>
        </div>
    </div>

    @if(session('flash_success_floating'))
        <div class="alert alert-success">{{ session('flash_success_floating') }}</div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ url('/hexaweb/ai/logs') }}" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <select name="provider" class="form-control input-sm" style="width:auto;">
            <option value="">All Providers</option>
            <option value="openrouter" {{ request('provider') === 'openrouter' ? 'selected' : '' }}>OpenRouter</option>
        </select>
        <select name="status" class="form-control input-sm" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
            <option value="error"   {{ request('status') === 'error'   ? 'selected' : '' }}>Error</option>
        </select>
        <select name="action" class="form-control input-sm" style="width:auto;">
            <option value="">All Actions</option>
            <option value="compose"  {{ request('action') === 'compose'  ? 'selected' : '' }}>Compose</option>
            <option value="template" {{ request('action') === 'template' ? 'selected' : '' }}>Template</option>
            <option value="polish"   {{ request('action') === 'polish'   ? 'selected' : '' }}>Polish</option>
        </select>
        <button type="submit" class="btn btn-default btn-sm">Filter</button>
        <a href="{{ url('/hexaweb/ai/logs') }}" class="btn btn-default btn-sm">Reset</a>
        <span style="font-size:12px;color:#aaa;margin-left:4px;">{{ count($logs) }} records</span>
    </form>

    @if($logs->isEmpty())
        <div class="alert alert-info">No log entries found.</div>
    @else
        <div style="overflow-x:auto;">
        <table class="table table-condensed table-bordered" style="font-size:12px;background:#fff;">
            <thead style="background:#f5f5f5;">
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="width:160px;">Time (EST)</th>
                    <th style="width:90px;">User</th>
                    <th style="width:70px;">Provider</th>
                    <th style="width:60px;">Action</th>
                    <th>Model</th>
                    <th style="width:55px;">Status</th>
                    <th style="width:60px;">Duration</th>
                    <th style="width:80px;">IP</th>
                    <th>Page URL</th>
                    <th style="width:80px;text-align:center;">Details</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $est = new \DateTimeZone('America/New_York');
                    $lastErrorId = null;
                    foreach ($logs as $log) {
                        if ($log->status === 'error') { $lastErrorId = $log->id; break; }
                    }
                @endphp
                @foreach($logs as $log)
                @php
                    $isError = $log->status === 'error';
                    $dt = $log->created_at
                        ? (new \DateTime($log->created_at, new \DateTimeZone('UTC')))->setTimezone($est)->format('Y-m-d H:i:s T')
                        : '—';
                    $convUrl = $log->conversation_id ? url('/conversation/' . $log->conversation_id) : null;
                @endphp
                <tr style="{{ $isError ? 'background:#fff5f5;' : '' }}" data-log-id="{{ $log->id }}">
                    <td style="color:#aaa;">{{ $log->id }}</td>
                    <td style="white-space:nowrap;{{ $isError ? 'color:#a94442;font-weight:600;' : '' }}">{{ $dt }}</td>
                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $log->user_email }}">
                        {{ $log->user_email ?: '—' }}
                    </td>
                    <td>
                        @if($log->provider === 'openrouter')
                            <span class="label" style="background:#6467f2;">OpenRouter</span>
                        @else
                            {{ $log->provider }}
                        @endif
                    </td>
                    <td>{{ $log->action }}</td>
                    <td style="font-size:11px;color:#666;">{{ $log->model }}</td>
                    <td>
                        @if($isError)
                            <span class="label label-danger">error</span>
                        @else
                            <span class="label label-success">ok</span>
                        @endif
                    </td>
                    <td style="{{ ($log->duration_ms ?? 0) > 10000 ? 'color:#e67e22;font-weight:600;' : '' }}">
                        {{ $log->duration_ms ? number_format($log->duration_ms) . 'ms' : '—' }}
                    </td>
                    <td style="font-family:monospace;font-size:11px;">{{ $log->client_ip ?: '—' }}</td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        @if($convUrl)
                            <a href="{{ $convUrl }}" target="_blank" style="font-size:11px;">#{{ $log->conversation_id }}</a>
                            &nbsp;
                        @endif
                        <span title="{{ $log->page_url }}" style="font-size:11px;color:#888;">{{ $log->page_url ? basename(parse_url($log->page_url, PHP_URL_PATH)) : '—' }}</span>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <button class="btn btn-xs btn-default hbt-log-expand" data-id="{{ $log->id }}" title="Expand">
                            <i class="glyphicon glyphicon-expand"></i>
                        </button>
                        <button class="btn btn-xs btn-default hbt-log-copy" data-id="{{ $log->id }}" title="Copy to clipboard">
                            <i class="glyphicon glyphicon-copy"></i>
                        </button>
                    </td>
                </tr>
                {{-- Error message row (always visible for errors) --}}
                @if($isError && $log->error_message)
                <tr style="background:#fff0f0;">
                    <td colspan="11" style="padding:4px 12px 6px 50px;color:#a94442;font-size:12px;">
                        <i class="glyphicon glyphicon-exclamation-sign" style="margin-right:4px;"></i>
                        <strong>Error:</strong> {{ $log->error_message }}
                    </td>
                </tr>
                @endif
                {{-- Expanded detail row (hidden by default) --}}
                <tr id="hbt-detail-{{ $log->id }}" style="display:none;">
                    <td colspan="11" style="padding:0;background:#fafafa;">
                        <div style="padding:14px;border-top:2px solid {{ $isError ? '#e74c3c' : '#8e59c3' }};">

                            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;">
                                <div><strong>API Endpoint:</strong> <code>{{ $log->api_url }}</code></div>
                                <div><strong>IP:</strong> {{ $log->client_ip }}</div>
                                <div><strong>User:</strong> {{ $log->user_email }} (ID: {{ $log->user_id }})</div>
                                <div><strong>Duration:</strong> {{ $log->duration_ms }}ms</div>
                                @if($convUrl)
                                <div><strong>Conversation:</strong> <a href="{{ $convUrl }}" target="_blank">#{{ $log->conversation_id }}</a></div>
                                @endif
                                <div style="word-break:break-all;"><strong>Page:</strong> {{ $log->page_url }}</div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div>
                                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:4px;">Request Payload</div>
                                    <pre style="background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:4px;font-size:11px;max-height:300px;overflow:auto;margin:0;white-space:pre-wrap;word-break:break-all;">{{ $log->request_json ?: '(empty)' }}</pre>
                                </div>
                                <div>
                                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:{{ $isError ? '#e74c3c' : '#888' }};margin-bottom:4px;">
                                        Response JSON {{ $isError ? '⚠ ERROR' : '' }}
                                    </div>
                                    <pre style="background:#1e1e1e;color:{{ $isError ? '#ff8080' : '#d4d4d4' }};padding:10px;border-radius:4px;font-size:11px;max-height:300px;overflow:auto;margin:0;white-space:pre-wrap;word-break:break-all;">{{ $log->response_json ? json_encode(json_decode($log->response_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(empty)' }}</pre>
                                </div>
                            </div>

                        </div>
                    </td>
                </tr>

                {{-- Hidden clipboard data --}}
                <tr id="hbt-clip-{{ $log->id }}" style="display:none;">
                    <td colspan="11">
                        <textarea class="hbt-clip-data" style="position:absolute;left:-9999px;">=== AI Request Log #{{ $log->id }} ===
Time (EST):   {{ $dt }}
User:         {{ $log->user_email }} (ID: {{ $log->user_id }})
Provider:     {{ $log->provider }}
Model:        {{ $log->model }}
Action:       {{ $log->action }}
Status:       {{ $log->status }}
Duration:     {{ $log->duration_ms }}ms
IP:           {{ $log->client_ip }}
API Endpoint: {{ $log->api_url }}
Page URL:     {{ $log->page_url }}
Conversation: {{ $log->conversation_id ? '#'.$log->conversation_id : 'N/A' }}
@if($isError)

ERROR MESSAGE:
{{ $log->error_message }}
@endif

--- REQUEST PAYLOAD ---
{{ $log->request_json }}

--- RESPONSE JSON ---
{{ $log->response_json ? json_encode(json_decode($log->response_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(empty)' }}
</textarea>
                    </td>
                </tr>

                @endforeach
            </tbody>
        </table>
        </div>
    @endif

</div>

<script>
(function() {
    // Find last error ID for "Copy Last Error" button
    var lastErrorId = {{ $lastErrorId ?? 'null' }};
    var copyBtn = document.getElementById('hbt-copy-last-error');
    if (lastErrorId && copyBtn) {
        copyBtn.style.display = 'inline-block';
        copyBtn.addEventListener('click', function() {
            copyLogToClipboard(lastErrorId);
        });
    }

    function copyLogToClipboard(id) {
        var el = document.querySelector('#hbt-clip-' + id + ' .hbt-clip-data');
        if (!el) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(el.value).then(function() {
                showFloatingAlert('success', 'Copied to clipboard.');
            }).catch(function() {
                fallbackCopy(el);
            });
        } else {
            fallbackCopy(el);
        }
    }

    function fallbackCopy(el) {
        el.style.position = 'fixed';
        el.style.left = '0';
        el.style.top = '0';
        el.select();
        try {
            document.execCommand('copy');
            showFloatingAlert('success', 'Copied to clipboard.');
        } catch(e) {
            showFloatingAlert('error', 'Copy failed — please copy manually.');
        }
        el.style.position = 'absolute';
        el.style.left = '-9999px';
    }

    // Expand/collapse detail rows
    document.addEventListener('click', function(e) {
        var expandBtn = e.target.closest('.hbt-log-expand');
        if (expandBtn) {
            var id = expandBtn.dataset.id;
            var row = document.getElementById('hbt-detail-' + id);
            if (!row) return;
            var visible = row.style.display !== 'none';
            row.style.display = visible ? 'none' : 'table-row';
            expandBtn.querySelector('i').className = visible ? 'glyphicon glyphicon-expand' : 'glyphicon glyphicon-collapse-up';
        }

        var copyBtn = e.target.closest('.hbt-log-copy');
        if (copyBtn) {
            copyLogToClipboard(copyBtn.dataset.id);
        }
    });
})();
</script>
@endsection
