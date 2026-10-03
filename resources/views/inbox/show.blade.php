@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('main-content')
<div class="main-content">
    <div class="main-content-inner">
        <div class="breadcrumbs ace-save-state" id="breadcrumbs">
            <ul class="breadcrumb">
                <li><i class="ace-icon fa fa-home home-icon"></i> <a>{{ __('Home') }}</a></li>
                <li><a href="{{ route('approval-matrix.inbox.index') }}">My Approvals</a></li>
                <li class="active">{{ $title }}</li>
                <li class="top-nav-btn"><a href="{{ route('approval-matrix.inbox.index') }}" class="btn btn-sm btn-warning text-white"><i class="las la-chevron-left"></i> Back</a></li>
            </ul>
        </div>

        <div class="page-content">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">{{ $docLabel }} · {{ class_basename($approval->approvable_type) }} #{{ $approval->approvable_id }}
                        <span class="badge badge-{{ ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'returned' => 'info', 'recalled' => 'secondary'][$approval->status] }}">{{ ucfirst($approval->status) }}</span>
                        @if ($approval->revision) <small>revision {{ $approval->revision }}</small> @endif
                    </h3>
                </div>
                <div class="panel-body">
                    <p>
                        Requested by <strong>{{ $names[$approval->requested_by] ?? '—' }}</strong> on {{ $approval->created_at->format('Y-m-d H:i') }}
                        @if ($approval->amount !== null) · Amount <strong>{{ number_format($approval->amount, 2) }}</strong> @endif
                    </p>

                    <table class="table table-bordered">
                        <thead><tr><th>Step</th><th>Approver</th><th>Decision</th><th>When</th><th>Comment</th></tr></thead>
                        <tbody>
                        @foreach (collect($approval->steps_snapshot)->sortBy('level') as $step)
                            @php($rows = $approval->actions->where('level', $step['level']))
                            @forelse ($rows as $a)
                                <tr class="{{ $a->action === 'pending' ? 'warning' : '' }}">
                                    <td>{{ $step['level'] }}. {{ $step['name'] }}</td>
                                    <td>{{ $a->assigned_to ? ($names[$a->assigned_to] ?? '—') : '—' }}</td>
                                    <td>{{ ucfirst($a->action) }}@if ($a->acted_by && $a->acted_by != $a->assigned_to) <small>by {{ $names[$a->acted_by] ?? '—' }}</small>@endif</td>
                                    <td>{{ optional($a->acted_at)->format('Y-m-d H:i') }}</td>
                                    <td>{{ $a->comments }}</td>
                                </tr>
                            @empty
                                <tr class="text-muted"><td>{{ $step['level'] }}. {{ $step['name'] }}</td><td colspan="4">Not reached</td></tr>
                            @endforelse
                        @endforeach
                        </tbody>
                    </table>

                    @if ($canAct)
                        <form method="post" id="act-form">
                            @csrf
                            <div class="form-group"><label><strong>Comment</strong> <small class="text-muted">required when rejecting</small></label>
                                <textarea name="comments" rows="3" class="form-control"></textarea></div>
                            <button type="submit" formaction="{{ route('approval-matrix.inbox.approve', $approval->id) }}" class="btn btn-success rounded"><i class="la la-check"></i> Approve</button>
                            <button type="submit" formaction="{{ route('approval-matrix.inbox.reject', $approval->id) }}" class="btn btn-danger rounded"><i class="la la-times"></i> Reject</button>
                        </form>
                    @endif

                    @if ($canRecall)
                        <form method="post" action="{{ route('approval-matrix.inbox.recall', $approval->id) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-warning rounded"><i class="la la-undo"></i> Recall my request</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
