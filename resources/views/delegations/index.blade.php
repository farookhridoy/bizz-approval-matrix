@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('page-script')
<script>$(function () { var $ = window.jQuery; if ($.fn.select2) $('select[name=delegate_id], select[name=document_type]').select2({width: '100%'}); });</script>
@endsection

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
            <div class="am-head"><div><h2>Delegation</h2><p>Out of office? Let a colleague approve on your behalf for a period.</p></div></div>
            @if ($errors->any())
                <div class="am-note bad"><i class="las la-exclamation-circle"></i><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <div class="am-card">
                <div class="am-card-h"><h3>Going away? Let someone approve for you</h3></div>
                <div class="am-card-b">
                    <form method="post" action="{{ route('approval-matrix.inbox.delegations.store') }}">
                        @csrf
                        <div class="form-group row">
                            <div class="col-md-3"><label><strong>Delegate</strong></label>
                                <select name="delegate_id" class="form-control" required><option value="">— choose —</option>
                                    @foreach ($users as $u)<option value="{{ $u->id }}" @selected(old('delegate_id') == $u->id)>{{ $u->name }}</option>@endforeach
                                </select></div>
                            <div class="col-md-3"><label><strong>For</strong></label>
                                <select name="document_type" class="form-control"><option value="">Every approval</option>
                                    @foreach ($documentTypes as $key => $d)<option value="{{ $key }}" @selected(old('document_type') === $key)>{{ $d['label'] }} only</option>@endforeach
                                </select></div>
                            <div class="col-md-2"><label><strong>From</strong></label><input type="date" name="starts_on" class="form-control" required value="{{ old('starts_on', now()->toDateString()) }}"></div>
                            <div class="col-md-2"><label><strong>To</strong></label><input type="date" name="ends_on" class="form-control" required value="{{ old('ends_on') }}"></div>
                            <div class="col-md-2"><label><strong>Reason</strong></label><input type="text" name="reason" maxlength="255" class="form-control" value="{{ old('reason') }}"></div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="la la-check"></i> Delegate</button>
                        <small class="text-muted">While it is active your delegate sees, is notified about and can approve or reject what is assigned to you. History shows who really decided.</small>
                    </form>
                </div>
            </div>

            <div class="am-card">
                <div class="am-card-h"><h3>My delegations</h3></div>
                <div class="am-card-b table-responsive" style="padding:0">
                    <table class="table am-table">
                        <thead><tr><th>Delegate</th><th>Approvals</th><th>From</th><th>To</th><th>Reason</th><th>State</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($mine as $d)
                            @php($active = $d->starts_on->lte(today()) && $d->ends_on->gte(today()))
                            <tr>
                                <td><span class="am-name"><span class="am-avatar">{{ mb_substr($names[$d->delegate_id] ?? '?', 0, 1) }}</span>{{ $names[$d->delegate_id] ?? '—' }}</span></td>
                                <td>{{ $d->document_type ? ($documentTypes[$d->document_type]['label'] ?? $d->document_type) : 'Every approval' }}</td>
                                <td>{{ $d->starts_on->format('Y-m-d') }}</td><td>{{ $d->ends_on->format('Y-m-d') }}</td><td>{{ $d->reason }}</td>
                                <td>@if ($active)<span class="am-state active">Active</span>@elseif ($d->starts_on->gt(today()))<span class="am-state pending">Upcoming</span>@else<span class="am-state archived">Ended</span>@endif</td>
                                <td>
                                    <form method="post" action="{{ route('approval-matrix.inbox.delegations.destroy', $d->id) }}" onsubmit="return confirm('Revoke this delegation?')">
                                        @csrf @method('DELETE')<button class="btn btn-xs btn-danger"><i class="la la-trash"></i> Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="am-empty">No delegations yet.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($toMe->isNotEmpty())
                <div class="am-card">
                    <div class="am-card-h"><h3>Approving for others right now</h3></div>
                    <div class="am-card-b">
                        @foreach ($toMe as $d)
                            <p>{{ $names[$d->delegator_id] ?? '—' }} — {{ $d->document_type ? ($documentTypes[$d->document_type]['label'] ?? $d->document_type) : 'every approval' }}, until {{ $d->ends_on->format('Y-m-d') }}</p>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
