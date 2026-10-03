@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

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

        <div class="page-content">
            <div class="panel panel-info">
                <div class="panel-heading"><h3 class="panel-title">Who would approve this?</h3></div>
                <div class="panel-body">
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
                        <button class="btn btn-primary rounded" type="submit"><i class="la la-play"></i> Simulate</button>
                    </form>
                </div>
            </div>

            @if ($result)
                @foreach ($result['warnings'] as $w)<div class="alert alert-warning">{{ $w }}</div>@endforeach

                @if ($result['workflow'])
                    <div class="panel panel-success">
                        <div class="panel-heading">
                            <h3 class="panel-title">Matched: {{ $result['workflow']->name }} <small>v{{ $result['workflow']->version }}</small></h3>
                        </div>
                        <div class="panel-body">
                            <table class="table table-bordered">
                                <thead><tr><th>#</th><th>Step</th><th>Approver rule</th><th>Mode</th><th>Resolved approvers</th><th>Result</th></tr></thead>
                                <tbody>
                                @foreach ($result['steps'] as $s)
                                    <tr>
                                        <td>{{ $s['level'] }}</td>
                                        <td>{{ $s['name'] }} @unless($s['mandatory'])<small class="text-muted">(optional)</small>@endunless @if($s['can_finish'])<span class="badge badge-info">can finish</span>@endif</td>
                                        <td>{{ \Bizzsol\ApprovalMatrix\Services\WorkflowService::APPROVER_TYPES[$s['type']] ?? $s['type'] }}@if($s['ref']) <small>({{ $s['ref'] }})</small>@endif</td>
                                        <td>{{ str_replace('_', ' ', $s['mode']) }}</td>
                                        <td>{{ implode(', ', $s['approvers']) ?: '—' }}</td>
                                        <td>
                                            @switch($s['status'])
                                                @case('applies')<span class="badge badge-success">Approval needed</span>@break
                                                @case('skipped')<span class="badge badge-secondary">Skipped</span>@break
                                                @case('needs-requester')<span class="badge badge-warning">Pick a requester</span>@break
                                                @default<span class="badge badge-danger">No approver!</span>
                                            @endswitch
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if ($result['others']->isNotEmpty())
                                <small class="text-muted">Also matched but outranked: {{ $result['others']->pluck('name')->implode(', ') }}</small>
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
