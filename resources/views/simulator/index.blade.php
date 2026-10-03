@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('page-css')
    @include('approvalmatrix::partials.ui')
@endsection

@section('main-content')
<div class="main-content">
    <div class="main-content-inner">
        <div class="breadcrumbs ace-save-state" id="breadcrumbs">
            <ul class="breadcrumb">
                <li><i class="ace-icon fa fa-home home-icon"></i> <a>{{ __('Home') }}</a></li>
                <li><a>ACL</a></li>
                <li class="active">{{ __($title) }}</li>
                <li class="top-nav-btn">
                    <a href="{{ route('approval-matrix.coverage.index') }}" class="btn btn-sm btn-info text-white"><i class="las la-clipboard-check"></i> Coverage</a>
                    <a href="{{ route('approval-matrix.workflows.index') }}" class="btn btn-sm btn-warning text-white"><i class="las la-chevron-left"></i> Workflows</a>
                </li>
            </ul>
        </div>

        <div class="page-content am">
            <div class="am-head"><div><h2>Approval simulator</h2><p>Dry-run a request: see which workflow matches and who would approve each step. Nothing is created.</p></div></div>
            <div class="am-card">
                <div class="am-card-h"><h3>Who would approve this?</h3></div>
                <div class="am-card-b">
                    <form method="get" action="{{ route('approval-matrix.simulator.index') }}">
                        <div class="form-group row">
                            <div class="col-md-3"><label><strong>Document</strong></label>
                                <select name="document_type" class="form-control" required>
                                    @foreach ($documentTypes as $key => $d)<option value="{{ $key }}" @selected(request('document_type') === $key)>{{ ucfirst($d['module']) }} › {{ $d['label'] }}</option>@endforeach
                                </select></div>
                            <div class="col-md-3"><label><strong>Requester</strong> <small class="text-muted">fills org &amp; resolves reporting head</small></label>
                                <select name="requester_id" class="form-control"><option value="">— none —</option>
                                    @foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('requester_id') == $u->id)>{{ $u->name }}</option>@endforeach
                                </select></div>
                            <div class="col-md-2"><label><strong>Amount</strong></label>
                                <input type="number" step="0.01" min="0" name="amount" class="form-control" value="{{ request('amount') }}"></div>
                            <div class="col-md-4"><label><strong>Attributes</strong> <small class="text-muted">key=value per line</small></label>
                                <textarea name="attributes" rows="1" class="form-control">{{ request('attributes') }}</textarea></div>
                        </div>
                        <div class="form-group row">
                            <div class="col-md-4"><label><strong>Company</strong> <small class="text-muted">overrides requester</small></label>
                                <select name="company_id" id="am-company" class="form-control"><option value="">— from requester —</option>
                                    @foreach ($companies as $id => $n)<option value="{{ $id }}" @selected(request('company_id') == $id)>{{ $n }}</option>@endforeach
                                </select></div>
                            <div class="col-md-4"><label><strong>Unit</strong></label>
                                <select name="unit_id" id="am-unit" class="form-control" data-placeholder="— from requester —"><option value="">— from requester —</option>
                                    @foreach ($unitRows as $u)<option value="{{ $u->id }}" data-company="{{ $u->company_id }}" @selected(request('unit_id') == $u->id)>{{ $u->name }}</option>@endforeach
                                </select></div>
                            <div class="col-md-4"><label><strong>Department</strong> <small class="text-muted">master department</small></label>
                                <select name="master_department_id" id="am-master" class="form-control" data-placeholder="— from requester —"><option value="">— from requester —</option>
                                    @foreach ($masters as $id => $n)<option value="{{ $id }}" @selected(request('master_department_id') == $id)>{{ $n }}</option>@endforeach
                                </select></div>
                        </div>
                        <button class="btn btn-primary" type="submit"><i class="la la-play"></i> Simulate</button>
                    </form>
                </div>
            </div>

            @if ($result)
                @foreach ($result['warnings'] as $w)<div class="am-note warn"><i class="las la-exclamation-triangle"></i><div>{{ $w }}</div></div>@endforeach

                @if ($result['workflow'])
                    <div class="am-card">
                        <div class="am-card-h"><h3>Matched: {{ $result['workflow']->name }} <span class="am-chip">v{{ $result['workflow']->version }}</span></h3>
                            <a href="{{ route('approval-matrix.workflows.edit', $result['workflow']->id) }}" class="btn btn-sm btn-default"><i class="la la-pencil"></i> Edit workflow</a></div>
                        <div class="am-card-b">
                            <div class="am-steps">
                            @foreach ($result['steps'] as $s)
                                @php($dot = ['applies' => 'ok', 'skipped' => 'skipped', 'needs-requester' => 'pending'][$s['status']] ?? 'bad')
                                <div class="am-step">
                                    <span class="am-dot {{ $dot }}">{{ $s['level'] }}</span>
                                    <div class="am-step-body">
                                        <div class="am-step-title">{{ $s['name'] }}
                                            @unless($s['mandatory'])<span class="am-chip muted">optional</span>@endunless
                                            @if($s['can_finish'])<span class="am-chip info">can finish</span>@endif
                                            @switch($s['status'])
                                                @case('applies')<span class="am-state approved">Approval needed</span>@break
                                                @case('skipped')<span class="am-state skipped">Skipped</span>@break
                                                @case('needs-requester')<span class="am-state pending">Pick a requester</span>@break
                                                @default<span class="am-state rejected">No approver!</span>
                                            @endswitch
                                        </div>
                                        <div class="am-step-sub">{{ \Bizzsol\ApprovalMatrix\Services\WorkflowService::APPROVER_TYPES[$s['type']] ?? $s['type'] }}@if($s['ref']) ({{ $s['ref'] }})@endif · {{ str_replace('_', ' ', $s['mode']) }}</div>
                                        @forelse ($s['approvers'] as $n)
                                            <span class="am-name" style="margin-right:12px"><span class="am-avatar">{{ mb_substr($n, 0, 1) }}</span>{{ $n }}</span>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach
                            </div>
                            @if ($result['others']->isNotEmpty())
                                <p class="text-muted" style="margin:14px 0 0;font-size:12px">Also matched but outranked: {{ $result['others']->pluck('name')->implode(', ') }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection

@section('page-script')
@include('approvalmatrix::partials.cascade')
<script>$(function () { amOrg.bind($('#am-company'), $('#am-unit'), $('#am-master')); });</script>
@endsection
