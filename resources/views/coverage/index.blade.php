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
                <li class="active">{{ $title }}</li>
                <li class="top-nav-btn"><a href="{{ route('approval-matrix.workflows.index') }}" class="btn btn-sm btn-warning text-white"><i class="las la-chevron-left"></i> Workflows</a></li>
            </ul>
        </div>
        <div class="page-content am">
            <div class="am-head"><div><h2>Approval coverage</h2></div></div>
            <div class="am-note"><i class="las la-info-circle"></i><div>Simulates the workflow for <strong>every employee with a user account</strong>, at their own unit and department, and flags the people for whom no workflow applies or a mandatory step has nobody to approve — those requests would be blocked or fall back to the old flow. Run it before activating a workflow or switching a document type to the matrix.</div></div>

            <div class="am-card">
                <div class="am-card-b">
                    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <select name="document_type" class="form-control" style="flex:1;min-width:240px;width:auto" required>
                            <option value="">— document —</option>
                            @foreach ($documentTypes as $key => $d)<option value="{{ $key }}" @selected(request('document_type') === $key)>{{ ucfirst($d['module']) }} › {{ $d['label'] }}</option>@endforeach
                        </select>
                        <input type="number" step="0.01" min="0" name="amount" class="form-control" style="width:160px" placeholder="Typical amount" value="{{ request('amount') }}">
                        <label class="mr-2"><input type="checkbox" name="only_problems" value="1" @checked($onlyProblems)> only problems</label>
                        <button class="btn btn-primary" type="submit"><i class="la la-play"></i> Check</button>
                    </form>
                </div>
            </div>

            @if ($result)
                @php($s = $result['summary'])
                <div class="am-note {{ ($s['no-workflow'] + $s['no-approver']) ? 'warn' : 'ok' }}"><i class="las la-clipboard-check"></i><div>
                    {{ $s['total'] }} requesters: <strong>{{ $s['ok'] }}</strong> ok · <strong>{{ $s['no-workflow'] }}</strong> without a workflow · <strong>{{ $s['no-approver'] }}</strong> with a step nobody can approve
                </div></div>
                <div class="am-card"><div class="am-card-b table-responsive" style="padding:0"><table class="table am-table">
                    <thead><tr><th>Requester</th><th>Unit</th><th>Department</th><th>Workflow</th><th>Chain</th><th>Result</th></tr></thead>
                    <tbody>
                    @foreach ($result['rows'] as $row)
                        @continue($onlyProblems && $row['status'] === 'ok')
                        <tr>
                            <td>{{ $row['name'] }}</td><td>{{ $row['unit'] }}</td><td>{{ $row['department'] }}</td><td>{{ $row['workflow'] ?? '—' }}</td><td><small>{{ $row['chain'] }}</small></td>
                            <td>@if ($row['status'] === 'ok')<span class="am-state ok">ok</span>@else<span class="am-state rejected">{{ $row['problem'] }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
            @endif
        </div>
    </div>
</div>
@endsection
