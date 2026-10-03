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
                <li><a href="{{ route('approval-matrix.inbox.index') }}">My Approvals</a></li>
                <li class="active">{{ $title }}</li>
                <li class="top-nav-btn"><a href="{{ route('approval-matrix.inbox.index') }}" class="btn btn-sm btn-warning text-white"><i class="las la-chevron-left"></i> Back</a></li>
            </ul>
        </div>

        <div class="page-content am">
            <div class="am-head">
                <div>
                    <h2>{{ $docLabel }} · {{ $document['label'] ?? (class_basename($approval->approvable_type).' #'.$approval->approvable_id) }}</h2>
                    <p>
                        <span class="am-state {{ $approval->status }}">{{ ucfirst($approval->status) }}</span>
                        @if ($approval->revision) <span class="am-chip">revision {{ $approval->revision }}</span> @endif
                    </p>
                </div>
                @if (! empty($document['url']))
                    <a href="{{ $document['url'] }}" target="_blank" class="btn btn-info text-white"><i class="la la-external-link"></i> Open document</a>
                @endif
            </div>

            <div class="am-two">
                <div class="am-card">
                    <div class="am-card-h"><h3>Approval progress</h3><small>{{ count($approval->steps_snapshot) }} step(s)</small></div>
                    <div class="am-card-b">
                        <div class="am-meta" style="margin-bottom:18px">
                            <div>Requested by<b>{{ $names[$approval->requested_by] ?? '—' }}</b></div>
                            <div>Submitted<b>{{ $approval->created_at->format('Y-m-d H:i') }}</b></div>
                            @if ($approval->amount !== null)<div>Amount<b>{{ number_format($approval->amount, 2) }}</b></div>@endif
                        </div>
                        <div class="am-steps">
                        @foreach (collect($approval->steps_snapshot)->sortBy('level') as $step)
                            @php($rows = $approval->actions->where('level', $step['level']))
                            @php($state = $rows->isEmpty() ? 'future' : ($rows->contains('action', 'pending') ? 'pending' : ($rows->contains('action', 'rejected') ? 'bad' : ($rows->every('action', 'skipped') ? 'skipped' : 'ok'))))
                            <div class="am-step">
                                <span class="am-dot {{ $state }}">{{ $step['level'] }}</span>
                                <div class="am-step-body">
                                    <div class="am-step-title">{{ $step['name'] }}</div>
                                    @forelse ($rows as $a)
                                        <div style="margin-bottom:6px">
                                            <span class="am-name"><span class="am-avatar muted">{{ mb_substr($names[$a->assigned_to] ?? '?', 0, 1) }}</span>{{ $a->assigned_to ? ($names[$a->assigned_to] ?? '—') : '—' }}</span>
                                            <span class="am-state {{ $a->action }}">{{ ucfirst($a->action) }}</span>
                                            @if ($a->acted_by && $a->acted_by != $a->assigned_to) <small class="text-muted">by {{ $names[$a->acted_by] ?? '—' }}</small>@endif
                                            @if ($a->acted_at) <small class="text-muted"> · {{ $a->acted_at->format('Y-m-d H:i') }}</small>@endif
                                            @if ($a->comments)<div class="am-cmt">{{ $a->comments }}</div>@endif
                                        </div>
                                    @empty
                                        <div class="am-step-sub">Not reached</div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                        </div>
                    </div>
                </div>

                <div>
                    @if ($canAct)
                        <div class="am-card">
                            <div class="am-card-h"><h3>Your decision</h3></div>
                            <div class="am-card-b">
                                <form method="post" id="act-form">
                                    @csrf
                                    <div class="form-group"><label><strong>Comment</strong> <small class="text-muted">required when rejecting</small></label>
                                        <textarea name="comments" rows="4" class="form-control"></textarea></div>
                                    <button type="submit" formaction="{{ route('approval-matrix.inbox.approve', $approval->id) }}" class="btn btn-success"><i class="la la-check"></i> Approve</button>
                                    <button type="submit" formaction="{{ route('approval-matrix.inbox.reject', $approval->id) }}" class="btn btn-danger"><i class="la la-times"></i> Reject</button>
                                </form>
                            </div>
                        </div>
                    @endif

                    @if ($canRecall)
                        <div class="am-card">
                            <div class="am-card-h"><h3>Your request</h3></div>
                            <div class="am-card-b">
                                <p class="text-muted" style="font-size:13px">Pulling it back stops the approval; you can resubmit later.</p>
                                <form method="post" action="{{ route('approval-matrix.inbox.recall', $approval->id) }}" onsubmit="return confirm('Recall this request?')">
                                    @csrf
                                    <button type="submit" class="btn btn-warning"><i class="la la-undo"></i> Recall my request</button>
                                </form>
                            </div>
                        </div>
                    @endif

                    @unless ($canAct || $canRecall)
                        <div class="am-note"><i class="las la-info-circle"></i><div>Nothing for you to do on this request.</div></div>
                    @endunless
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
