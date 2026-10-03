@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('page-css')
    @include('yajra.css')
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
                    <li class="top-nav-btn"><a href="{{ route('approval-matrix.inbox.delegations.index') }}" class="btn btn-sm btn-info text-white"><i class="las la-user-clock"></i> Delegation</a></li>
                </ul>
            </div>
            <div class="page-content am">
                <div class="am-head">
                    <div>
                        <h2>My approvals</h2>
                        <p>Requests waiting for your decision, including ones delegated to you. Older items are highlighted.</p>
                    </div>
                    <span class="am-chip lg {{ $waiting ? 'warn' : 'ok' }}">{{ $waiting }} waiting for you</span>
                </div>
                <div class="am-card">
                    <div class="am-card-h"><h3>Waiting for my approval</h3><small>newest first</small></div>
                    <div class="am-card-b table-responsive">
                        @include('yajra.datatable')
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @include('yajra.js')
@endsection
