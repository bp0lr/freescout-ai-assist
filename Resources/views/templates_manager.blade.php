@extends('layouts.app')

@section('title', 'AI Templates')

@section('content')
<div class="container" style="max-width:1000px;padding:20px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h2 style="margin:0;font-size:22px;color:#333;">
            <i class="glyphicon glyphicon-list-alt" style="margin-right:8px;color:#777;"></i>
            AI Templates
            <small style="font-size:13px;color:#999;margin-left:8px;">Used by OpenRouter</small>
        </h2>
        <div style="display:flex;gap:8px;">
            <a href="{{ url('/hexaweb/ai/settings') }}" class="btn btn-default btn-sm">
                <i class="glyphicon glyphicon-cog"></i> AI Settings
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
        <div class="panel-heading"><strong>All Templates</strong>
            <small style="color:#999;margin-left:8px;">Built-in templates are locked. Add your own below.</small>
        </div>
        <div class="panel-body" style="padding:0;">
            @if($templates->isEmpty())
                <div style="padding:20px;color:#999;text-align:center;">No templates found.</div>
            @else
            <table class="table table-hover" style="margin:0;font-size:13px;">
                <thead>
                    <tr style="background:#f5f5f5;">
                        <th style="width:200px;">Name</th>
                        <th>Description</th>
                        <th style="width:80px;text-align:center;">Type</th>
                        <th style="width:130px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $tpl)
                    <tr style="{{ $tpl->is_builtin ? 'background:#f9f9f9;color:#777;' : '' }}" id="tpl-row-{{ $tpl->id }}">
                        <td>
                            @if($tpl->is_builtin)
                                <i class="glyphicon glyphicon-lock" style="color:#bbb;margin-right:4px;"></i>
                            @endif
                            {{ $tpl->name }}
                        </td>
                        <td style="color:#888;font-size:12px;">{{ $tpl->description ?: '—' }}</td>
                        <td style="text-align:center;">
                            @if($tpl->is_builtin)
                                <span class="label label-default">Built-in</span>
                            @else
                                <span class="label label-info">Custom</span>
                            @endif
                        </td>
                        <td style="text-align:center;">
                            @if(!$tpl->is_builtin)
                                <button type="button" class="btn btn-xs btn-default hbt-edit-btn" data-id="{{ $tpl->id }}">
                                    <i class="glyphicon glyphicon-pencil"></i> Edit
                                </button>
                                <form method="POST" action="{{ route('hexaweb.ai.templates.delete') }}" style="display:inline;"
                                      onsubmit="return confirm('Delete this template?');">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="id" value="{{ $tpl->id }}">
                                    <button type="submit" class="btn btn-xs btn-danger">
                                        <i class="glyphicon glyphicon-trash"></i>
                                    </button>
                                </form>
                            @else
                                <span style="color:#ccc;font-size:12px;">—</span>
                            @endif
                        </td>
                    </tr>
                    @if(!$tpl->is_builtin)
                    <tr id="tpl-edit-{{ $tpl->id }}" style="display:none;background:#fffdf0;">
                        <td colspan="4" style="padding:16px;">
                            <form method="POST" action="{{ route('hexaweb.ai.templates.save') }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="id" value="{{ $tpl->id }}">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label style="font-size:12px;font-weight:600;">Name</label>
                                    <input type="text" name="name" class="form-control input-sm" value="{{ e($tpl->name) }}" required>
                                </div>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label style="font-size:12px;font-weight:600;">Description</label>
                                    <input type="text" name="description" class="form-control input-sm" value="{{ e($tpl->description) }}">
                                </div>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label style="font-size:12px;font-weight:600;">Prompt</label>
                                    <textarea name="prompt" class="form-control input-sm" rows="4" required>{{ e($tpl->prompt) }}</textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                <button type="button" class="btn btn-default btn-sm hbt-cancel-btn" data-id="{{ $tpl->id }}">Cancel</button>
                            </form>
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Add New Template</strong></div>
        <div class="panel-body">
            <form method="POST" action="{{ route('hexaweb.ai.templates.save') }}">
                {{ csrf_field() }}
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">Name <span style="color:#d9534f;">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Billing Inquiry Response" required>
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">Description</label>
                    <input type="text" name="description" class="form-control" placeholder="Short description (optional)">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;">Prompt <span style="color:#d9534f;">*</span></label>
                    <textarea name="prompt" class="form-control" rows="5"
                              placeholder="Write your prompt instructions. Conversation context will be prepended automatically if enabled." required></textarea>
                    <span class="help-block" style="font-size:12px;">Works with any OpenRouter model.</span>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="glyphicon glyphicon-plus"></i> Create Template
                </button>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
(function() {
    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.hbt-edit-btn');
        if (editBtn) {
            var id = editBtn.dataset.id;
            var row = document.getElementById('tpl-edit-' + id);
            if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
            return;
        }
        var cancelBtn = e.target.closest('.hbt-cancel-btn');
        if (cancelBtn) {
            var id = cancelBtn.dataset.id;
            var row = document.getElementById('tpl-edit-' + id);
            if (row) row.style.display = 'none';
            return;
        }
    });
})();
</script>
@endpush
