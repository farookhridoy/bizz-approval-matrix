@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('page-css')
    @include('yajra.css')
    @include('approvalmatrix::partials.ui')
    <style>
        .am-modules-head{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:baseline;margin-bottom:10px}
        .am-modules{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}
        .am-mod{display:grid;grid-template-columns:1fr auto;gap:2px 8px;align-items:center;margin:0;padding:10px 12px;border:1px solid var(--am-line);border-radius:10px;background:#fff;cursor:pointer}
        .am-mod.on{border-color:var(--am-primary);background:var(--am-primary-soft)}
        .am-mod.busy{opacity:.6;pointer-events:none}
        .am-mod-name{font-weight:600}
        .am-mod-meta{grid-column:1;font-size:12px;color:var(--am-muted)}
        .am-mod-toggle{grid-column:2;grid-row:1 / span 2;width:18px;height:18px;cursor:pointer}
    </style>
@endsection

@section('main-content')
    <div class="main-content">
        <div class="main-content-inner">
            <div class="breadcrumbs ace-save-state" id="breadcrumbs">
                <ul class="breadcrumb">
                    <li><i class="ace-icon fa fa-home home-icon"></i> <a>{{ __('Home') }}</a></li>
                    <li><a>ACL</a></li>
                    <li class="active">Approval Matrix</li>
                    <li class="top-nav-btn">
                        @can('approval-matrix-simulator')
                            <a href="{{ route('approval-matrix.coverage.index') }}" class="btn btn-sm btn-default"><i class="las la-clipboard-check"></i> Coverage</a>
                            <a href="{{ route('approval-matrix.simulator.index') }}" class="btn btn-sm btn-info text-white"><i class="las la-project-diagram"></i> Simulator</a>
                        @endcan
                        @can('approval-matrix-create')
                            <a href="{{ route('approval-matrix.workflows.create') }}" class="btn btn-sm btn-primary text-white"><i class="las la-plus"></i> New workflow</a>
                        @endcan
                    </li>
                </ul>
            </div>

            <div class="page-content am">
                <div class="am-head">
                    <div>
                        <h2>Approval workflows</h2>
                        <p>Who approves what. For each request the <strong>most specific</strong> active workflow wins (department › unit › company › everyone); ties break by priority, then by newest version.</p>
                    </div>
                </div>

                <div class="am-card" id="am-modules">
                    <div class="am-card-b">
                        <div class="am-modules-head"><b>Modules</b>
                            <span class="am-muted am-small">Switch the matrix on or off per module. <strong>Off</strong> = that module's documents use their original approval flow; its workflows are kept and take effect again when switched back on.</span>
                        </div>
                        <div class="am-modules">
                            @foreach ($moduleSwitch as $m => $on)
                                <label class="am-mod {{ $on ? 'on' : 'off' }}" data-module="{{ $m }}">
                                    <span class="am-mod-name">{{ ucfirst($m) }}</span>
                                    <span class="am-mod-meta">{{ $activeByModule[$m] ?? 0 }} active workflow(s) · <em>{{ $on ? 'matrix in use' : 'legacy flow' }}</em></span>
                                    <input type="checkbox" class="am-mod-toggle" value="{{ $m }}" @checked($on) @cannot('approval-matrix-edit') disabled @endcannot>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="am-stats" id="am-state-filter">
                    <a href="#" class="am-stat on" data-state=""><b>{{ $counts['all'] }}</b><span>All workflows</span></a>
                    <a href="#" class="am-stat ok" data-state="active"><b>{{ $counts['active'] }}</b><span>Active · in use</span></a>
                    <a href="#" class="am-stat warn" data-state="draft"><b>{{ $counts['draft'] }}</b><span>Draft · not used yet</span></a>
                    <a href="#" class="am-stat" data-state="archived"><b>{{ $counts['archived'] }}</b><span>Archived</span></a>
                </div>

                @if ($counts['all'] === 0)
                    <div class="am-card">
                        <div class="am-empty">
                            <i class="las la-stream"></i>
                            <b>No workflows yet</b>
                            Until a workflow is active, documents use their original approval flow.
                            @can('approval-matrix-create')
                                <div style="margin-top:14px"><a href="{{ route('approval-matrix.workflows.create') }}" class="btn btn-primary"><i class="las la-plus"></i> Create the first workflow</a></div>
                            @endcan
                        </div>
                    </div>
                @else
                    <div class="am-card">
                        <div class="am-card-b" style="padding-bottom:6px">
                            <div class="am-toolbar">
                                <select id="f-module" class="form-control" aria-label="Module">
                                    <option value="">All modules</option>
                                    @foreach ($modules as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                                </select>
                                <span class="am-muted am-small"><i class="las la-info-circle"></i> <strong>✓</strong> = the approver may finish the request at that step · <strong>all</strong> = every approver of the step must approve.</span>
                            </div>
                            <div class="table-responsive" style="overflow:visible">
                                @include('yajra.datatable')
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @include('yajra.js')
    @include('approvalmatrix::partials.ui-js')
    <script>
        $(function () {
            var state = '';
            function reload() {
                $('.datatable-serverside').DataTable().ajax.url(location.pathname + '?module=' + encodeURIComponent($('#f-module').val() || '') + '&state=' + encodeURIComponent(state)).load();
            }
            $('#am-modules').on('change', '.am-mod-toggle', function () {
                var box = $(this), card = box.closest('.am-mod'), on = box.is(':checked'), module = box.val();
                var msg = on ? 'Enable the approval matrix for ' + module + '?' : 'Disable the approval matrix for ' + module + '? New documents of this module will use their original approval flow.';
                if (!confirm(msg)) { box.prop('checked', !on); return; }
                card.addClass('busy');
                $.post('{{ url('approval-matrix/modules') }}/' + encodeURIComponent(module), {enabled: on ? 1 : 0})
                    .done(function (res) {
                        card.toggleClass('on', on).toggleClass('off', !on).find('em').text(on ? 'matrix in use' : 'legacy flow');
                        if (window.toastr) toastr.success(res.message);
                    })
                    .fail(function (xhr) {
                        box.prop('checked', !on);
                        if (window.toastr) toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Could not change the module setting.');
                    })
                    .always(function () { card.removeClass('busy'); });
            });
            $('#f-module').on('change', reload);
            $('#am-state-filter').on('click', '.am-stat', function (e) {
                e.preventDefault();
                state = $(this).data('state') || '';
                $('#am-state-filter .am-stat').removeClass('on');
                $(this).addClass('on');
                reload();
            });
        });
    </script>
@endsection
