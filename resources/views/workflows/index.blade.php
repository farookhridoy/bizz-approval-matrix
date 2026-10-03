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
                    <li class="top-nav-btn">
                        @can('approval-matrix-simulator')
                            <a href="{{ route('approval-matrix.simulator.index') }}" class="btn btn-sm btn-info text-white"><i class="las la-project-diagram"></i> Simulator</a>
                        @endcan
                        @can('approval-matrix-create')
                            <a href="{{ route('approval-matrix.workflows.create') }}" class="btn btn-sm btn-primary text-white"><i class="las la-plus"></i> Add Workflow</a>
                        @endcan
                    </li>
                </ul>
            </div>

            <div class="page-content">
                <div class="panel panel-info">
                    <div class="panel-heading"><h3 class="panel-title">Approval workflows</h3></div>
                    <div class="panel-body table-responsive">
                        <div class="form-inline mb-2">
                            <select id="f-module" class="form-control mr-2">
                                <option value="">All modules</option>
                                @foreach ($modules as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                            </select>
                            <select id="f-state" class="form-control mr-2">
                                <option value="">All states</option>
                                <option value="active">Active</option><option value="draft">Draft</option><option value="archived">Archived</option>
                            </select>
                            <small class="text-muted">Most specific scope wins: department › unit › company › all. Ties break by priority, then newest version.</small>
                        </div>
                        @include('yajra.datatable')
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @include('yajra.js')
    <script>
        $(function () {
            $('#f-module, #f-state').on('change', function () {
                var t = $('.datatable-serverside').DataTable();
                t.ajax.url(location.pathname + '?module=' + encodeURIComponent($('#f-module').val()) + '&state=' + encodeURIComponent($('#f-state').val())).load();
            });
        });
        function amPost(url) {
            $.post(url, {_token: $('meta[name="csrf-token"]').attr('content')})
                .done(function (r) { toastr.success(r.message); reloadDatatable(); })
                .fail(function (x) {
                    var m = (x.responseJSON && (x.responseJSON.errors ? Object.values(x.responseJSON.errors).join(' ') : x.responseJSON.message)) || 'Failed';
                    toastr.error(m);
                });
        }
    </script>
@endsection
