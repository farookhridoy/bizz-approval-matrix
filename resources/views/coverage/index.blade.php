@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

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
        <div class="page-content">
            <div class="alert alert-info">Simulates the workflow for <strong>every employee with a user account</strong>, at their own unit and department, and flags the people for whom no workflow applies or a mandatory step has nobody to approve — those requests would be blocked or fall back to the old flow. Run it before activating a workflow or switching a document type to the matrix.</div>

            <div class="panel panel-info">
                <div class="panel-body">
                    <form method="get" class="form-inline">
                        <select name="document_type" class="form-control mr-2" required>
                            <option value="">— document —</option>
                            @foreach ($documentTypes as $key => $d)<option value="{{ $key }}" @selected(request('document_type') === $key)>{{ ucfirst($d['module']) }} › {{ $d['label'] }}</option>@endforeach
                        </select>
                        <input type="number" step="0.01" min="0" name="amount" class="form-control mr-2" placeholder="Typical amount" value="{{ request('amount') }}">
                        <label class="mr-2"><input type="checkbox" name="only_problems" value="1" @checked($onlyProblems)> only problems</label>
                        <button class="btn btn-primary" type="submit"><i class="la la-play"></i> Check</button>
                    </form>
                </div>
            </div>

            @if ($result)
                @php($s = $result['summary'])
                <div class="alert alert-{{ ($s['no-workflow'] + $s['no-approver']) ? 'warning' : 'success' }}">
                    {{ $s['total'] }} requesters: <strong>{{ $s['ok'] }}</strong> ok · <strong>{{ $s['no-workflow'] }}</strong> without a workflow · <strong>{{ $s['no-approver'] }}</strong> with a step nobody can approve
                </div>
                <table class="table table-bordered table-striped">
                    <thead><tr><th>Requester</th><th>Unit</th><th>Department</th><th>Workflow</th><th>Chain</th><th>Result</th></tr></thead>
                    <tbody>
                    @foreach ($result['rows'] as $row)
                        @continue($onlyProblems && $row['status'] === 'ok')
                        <tr>
                            <td>{{ $row['name'] }}</td><td>{{ $row['unit'] }}</td><td>{{ $row['department'] }}</td><td>{{ $row['workflow'] ?? '—' }}</td><td><small>{{ $row['chain'] }}</small></td>
                            <td>@if ($row['status'] === 'ok')<span class="badge badge-success">ok</span>@else<span class="badge badge-danger">{{ $row['problem'] }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
@endsection
