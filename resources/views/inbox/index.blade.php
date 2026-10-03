@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('page-css')
    @include('yajra.css')
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
            <div class="page-content">
                <div class="panel panel-info">
                    <div class="panel-heading"><h3 class="panel-title">Waiting for my approval</h3></div>
                    <div class="panel-body table-responsive">
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
