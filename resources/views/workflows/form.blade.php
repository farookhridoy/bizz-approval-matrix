@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('main-content')
<div class="main-content">
    <div class="main-content-inner">
        <div class="breadcrumbs ace-save-state" id="breadcrumbs">
            <ul class="breadcrumb">
                <li><i class="ace-icon fa fa-home home-icon"></i> <a>{{ __('Home') }}</a></li>
                <li><a href="{{ route('approval-matrix.workflows.index') }}">Approval Matrix</a></li>
                <li class="active">{{ __($title) }}</li>
                <li class="top-nav-btn">
                    <a href="{{ route('approval-matrix.workflows.index') }}" class="btn btn-sm btn-warning text-white"><i class="las la-chevron-left"></i> Back</a>
                </li>
            </ul>
        </div>

        <div class="page-content">
            @if ($errors->any())
                <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <form method="post" action="{{ $workflow ? route('approval-matrix.workflows.update', $workflow->id) : route('approval-matrix.workflows.store') }}">
                @csrf
                @if ($workflow) @method('PUT') @endif

                @if ($workflow && \Bizzsol\ApprovalMatrix\Models\ApprovalRequest::where('workflow_id', $workflow->id)->exists())
                    <div class="alert alert-warning">This workflow already has approval requests. Saving creates <strong>version {{ $workflow->version + 1 }}</strong>; the current version and its history stay untouched.</div>
                @endif

                @php($c = $workflow->conditions ?? [])
                @php($attrText = collect($c['attributes'] ?? [])->map(fn ($v, $k) => $k.'='.implode(',', $v))->implode("\n"))

                <div class="panel panel-info">
                    <div class="panel-heading"><h3 class="panel-title">1. What and where</h3></div>
                    <div class="panel-body">
                        <div class="form-group row">
                            <div class="col-md-4">
                                <label><strong>Name <span class="text-danger">*</span></strong></label>
                                <input type="text" name="name" class="form-control" required value="{{ old('name', $workflow->name ?? '') }}">
                            </div>
                            <div class="col-md-4">
                                <label><strong>Document <span class="text-danger">*</span></strong></label>
                                <select name="document_type" class="form-control" required>
                                    @foreach ($documentTypes as $key => $d)
                                        <option value="{{ $key }}" @selected(old('document_type', $workflow->document_type ?? '') === $key)>{{ ucfirst($d['module']) }} › {{ $d['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label><strong>State</strong></label>
                                <select name="state" class="form-control">
                                    @foreach (['draft', 'active', 'archived'] as $s)
                                        <option value="{{ $s }}" @selected(old('state', $workflow->state ?? 'draft') === $s)>{{ ucfirst($s) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label><strong>Priority</strong></label>
                                <input type="number" min="0" name="priority" class="form-control" value="{{ old('priority', $workflow->priority ?? 0) }}" title="Higher wins when scope is equal">
                            </div>
                        </div>
                        <div class="form-group row">
                            <div class="col-md-4">
                                <label><strong>Company</strong></label>
                                <select name="company_id" id="am-company" class="form-control">
                                    <option value="">All companies</option>
                                    @foreach ($companies as $id => $n)<option value="{{ $id }}" @selected(old('company_id', $workflow->company_id ?? '') == $id)>{{ $n }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label><strong>Unit</strong></label>
                                <select name="unit_id" id="am-unit" class="form-control" data-placeholder="All units">
                                    <option value="">All units</option>
                                    @foreach ($unitRows as $u)<option value="{{ $u->id }}" data-company="{{ $u->company_id }}" @selected(old('unit_id', $workflow->unit_id ?? '') == $u->id)>{{ $u->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label><strong>Department</strong> <small class="text-muted">master department</small></label>
                                <select name="master_department_id" id="am-master" class="form-control" data-placeholder="All departments">
                                    <option value="">All departments</option>
                                    @foreach ($masters as $id => $n)<option value="{{ $id }}" @selected(old('master_department_id', $workflow->master_department_id ?? '') == $id)>{{ $n }}</option>@endforeach
                                </select>
                                @if ($workflow && $workflow->department_id)
                                    <input type="hidden" name="department_id" value="{{ $workflow->department_id }}">
                                    <small class="text-warning">Also limited to one unit-specific department (id {{ $workflow->department_id }}).</small>
                                @endif
                            </div>
                        </div>
                        <div class="form-group row">
                            <div class="col-md-2">
                                <label><strong>Amount from</strong></label>
                                <input type="number" step="0.01" min="0" name="amount_min" class="form-control" value="{{ old('amount_min', $c['amount_min'] ?? '') }}">
                            </div>
                            <div class="col-md-2">
                                <label><strong>Amount up to</strong></label>
                                <input type="number" step="0.01" min="0" name="amount_max" class="form-control" value="{{ old('amount_max', $c['amount_max'] ?? '') }}">
                            </div>
                            <div class="col-md-2">
                                <label><strong>Effective from</strong></label>
                                <input type="date" name="effective_from" class="form-control" value="{{ old('effective_from', optional($workflow->effective_from ?? null)->format('Y-m-d')) }}">
                            </div>
                            <div class="col-md-2">
                                <label><strong>Effective to</strong></label>
                                <input type="date" name="effective_to" class="form-control" value="{{ old('effective_to', optional($workflow->effective_to ?? null)->format('Y-m-d')) }}">
                            </div>
                            <div class="col-md-4">
                                <label><strong>Attribute conditions</strong> <small class="text-muted">one per line: key=value1,value2</small></label>
                                <textarea name="attributes" rows="2" class="form-control" placeholder="purchase_type=foreign">{{ old('attributes', $attrText) }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">2. Approval steps <small>(run top to bottom)</small></h3>
                    </div>
                    <div class="panel-body">
                        <div id="steps"></div>
                        <button type="button" class="btn btn-sm btn-primary" id="add-step"><i class="la la-plus"></i> Add step</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-success rounded"><i class="la la-check"></i> Save workflow</button>
            </form>
        </div>
    </div>
</div>

{{-- Templates --}}
<template id="step-tpl">
    <div class="panel panel-default step" style="border:1px solid #ddd;margin-bottom:12px">
        <div class="panel-heading" style="padding:8px 12px;background:#f5f5f5">
            <strong class="step-title">Step</strong>
            <span class="pull-right">
                <a class="btn btn-xs btn-default step-up" title="Move up"><i class="la la-arrow-up"></i></a>
                <a class="btn btn-xs btn-default step-down" title="Move down"><i class="la la-arrow-down"></i></a>
                <a class="btn btn-xs btn-danger step-del" title="Remove"><i class="la la-trash"></i></a>
            </span>
        </div>
        <div class="panel-body">
            <div class="form-group row">
                <div class="col-md-3"><label>Step name</label><input type="text" class="form-control" data-f="name" placeholder="Department head"></div>
                <div class="col-md-2"><label>Stage key</label><input type="text" class="form-control" data-f="stage_key" placeholder="dept_head"></div>
                <div class="col-md-3"><label>Approver <span class="text-danger">*</span></label>
                    <select class="form-control" data-f="approver_type">
                        @foreach ($approverTypes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4 ap-extra">
                    <div class="ap ap-reporting_head"><label>Reporting level</label>
                        <select class="form-control" data-f="approver_ref_hops">
                            <option value="1">Direct reporting head</option><option value="2">Head’s head (2 levels)</option><option value="3">3 levels up</option>
                        </select></div>
                    <div class="ap ap-specific_user" style="display:none"><label>User</label>
                        <select class="form-control" data-f="user_id"><option value="">— select —</option>
                            @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select></div>
                    <div class="ap ap-role" style="display:none"><label>Role</label>
                        <select class="form-control" data-f="approver_ref_role"><option value="">— select —</option>
                            @foreach ($roles as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
                        </select></div>
                    <div class="ap ap-permission" style="display:none"><label>Permission</label>
                        <input class="form-control" list="perm-list" data-f="approver_ref_perm" placeholder="type to search"></div>
                </div>
            </div>
            <div class="ap ap-custom_user" style="display:none">
                <label>Custom approvers <small class="text-muted">most specific row matching the document’s unit/department is used; leave both blank for a default</small></label>
                <table class="table table-condensed table-bordered"><thead><tr><th>Unit</th><th>Department</th><th>User</th><th style="width:40px"></th></tr></thead><tbody class="custom-rows"></tbody></table>
                <a class="btn btn-xs btn-default add-custom"><i class="la la-plus"></i> Add row</a>
            </div>
            <div class="form-group row" style="margin-top:10px">
                <div class="col-md-2"><label>Mode</label>
                    <select class="form-control" data-f="mode"><option value="any">Any one approves</option><option value="all">All must approve</option><option value="n_of_m">N of the group</option></select></div>
                <div class="col-md-2 n-wrap" style="display:none"><label>N required</label><input type="number" min="1" class="form-control" data-f="min_approvals"></div>
                <div class="col-md-2"><label>Skip if amount ≤</label><input type="number" step="0.01" min="0" class="form-control" data-f="skip_amount_max" placeholder="never"></div>
                <div class="col-md-2"><label>SLA (hours)</label><input type="number" min="1" class="form-control" data-f="sla_hours"></div>
                <div class="col-md-2"><label>On reject</label>
                    <select class="form-control" data-f="on_reject"><option value="terminate">End request</option><option value="return_to_requester">Return to requester</option></select></div>
                <div class="col-md-2"><label>&nbsp;</label><div class="checkbox"><label><input type="checkbox" value="1" data-f="is_mandatory" checked> Mandatory</label></div>
                    <small class="text-muted">Optional steps are skipped when nobody can be found.</small></div>
            </div>
        </div>
    </div>
</template>

<template id="custom-row-tpl">
    <tr>
        <td><select class="form-control input-sm" data-c="unit_id"><option value="">Any unit</option>@foreach ($allUnitRows as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></td>
        <td><select class="form-control input-sm" data-c="master_department_id" data-placeholder="Any department"><option value="">Any department</option>@foreach ($allMasters as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></td>
        <td><select class="form-control input-sm" data-c="user_id"><option value="">— user —</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></td>
        <td><a class="btn btn-xs btn-danger del-custom"><i class="la la-times"></i></a></td>
    </tr>
</template>

<datalist id="perm-list">@foreach ($permissions as $p)<option value="{{ $p }}">@endforeach</datalist>
@endsection

@section('page-script')
@include('approvalmatrix::partials.cascade')
<script>
$(function () {
    var initial = @json($stepsData);
    var $steps = $('#steps');

    function reindex() {
        $steps.children('.step').each(function (i) {
            var $s = $(this);
            $s.find('.step-title').text('Step ' + (i + 1));
            $s.find('[data-f]').each(function () {
                var f = $(this).data('f');
                var name = {approver_ref_hops: 'approver_ref', approver_ref_role: 'approver_ref', approver_ref_perm: 'approver_ref'}[f] || f;
                $(this).attr('name', 'steps[' + i + '][' + name + ']');
            });
            $s.find('.custom-rows tr').each(function (j) {
                $(this).find('[data-c]').each(function () {
                    $(this).attr('name', 'steps[' + i + '][custom][' + j + '][' + $(this).data('c') + ']');
                });
            });
            toggle($s);
        });
    }

    function toggle($s) {
        var type = $s.find('[data-f=approver_type]').val();
        $s.find('.ap').hide();
        $s.find('.ap-' + type).show();
        // only the visible approver_ref input is submitted
        $s.find('[data-f^=approver_ref_]').prop('disabled', true);
        $s.find('.ap-' + type + ' [data-f^=approver_ref_]').prop('disabled', false);
        $s.find('[data-f=user_id]').prop('disabled', type !== 'specific_user');
        $s.find('.n-wrap').toggle($s.find('[data-f=mode]').val() === 'n_of_m');
        $s.find('[data-f=min_approvals]').prop('disabled', $s.find('[data-f=mode]').val() !== 'n_of_m');
        $s.find('.custom-rows [data-c]').prop('disabled', type !== 'custom_user');
    }

    function addCustom($s, row) {
        var $r = $($('#custom-row-tpl').html());
        row = row || {};
        $.each(['unit_id', 'master_department_id', 'user_id'], function (_, k) { $r.find('[data-c=' + k + ']').val(row[k] || ''); });
        $s.find('.custom-rows').append($r);
        reindex();
    }

    function addStep(data) {
        var $s = $($('#step-tpl').html());
        data = data || {};
        var type = data.approver_type || 'reporting_head';
        $s.find('[data-f=approver_type]').val(type);
        $s.find('[data-f=name]').val(data.name || '');
        $s.find('[data-f=stage_key]').val(data.stage_key || '');
        $s.find('[data-f=mode]').val(data.mode || 'any');
        $s.find('[data-f=min_approvals]').val(data.min_approvals || '');
        $s.find('[data-f=skip_amount_max]').val(data.skip_amount_max || '');
        $s.find('[data-f=sla_hours]').val(data.sla_hours || '');
        $s.find('[data-f=on_reject]').val(data.on_reject || 'terminate');
        $s.find('[data-f=is_mandatory]').prop('checked', data.is_mandatory === undefined ? true : !!parseInt(data.is_mandatory));
        $s.find('[data-f=user_id]').val(data.user_id || '');
        if (type === 'reporting_head') $s.find('[data-f=approver_ref_hops]').val(data.approver_ref || 1);
        if (type === 'role') $s.find('[data-f=approver_ref_role]').val(data.approver_ref || '');
        if (type === 'permission') $s.find('[data-f=approver_ref_perm]').val(data.approver_ref || '');
        $steps.append($s);
        $.each(data.custom || [], function (_, r) { addCustom($s, r); });
        if (type === 'custom_user' && !(data.custom || []).length) addCustom($s);
        reindex();
    }

    // Company > Unit > Master department
    amOrg.bind($('#am-company'), $('#am-unit'), $('#am-master'));
    // custom approver rows: the department list follows the row's unit
    $steps.on('change', '[data-c=unit_id]', function () {
        var $row = $(this).closest('tr');
        amOrg.loadMasters($row.find('[data-c=master_department_id]'), $(this).val(), '', true);
    });

    $('#add-step').on('click', function () { addStep({}); });
    $steps.on('change', '[data-f=approver_type]', function () {
        var $s = $(this).closest('.step');
        if ($(this).val() === 'custom_user' && !$s.find('.custom-rows tr').length) addCustom($s);
        reindex();
    });
    $steps.on('change', '[data-f=mode]', reindex);
    $steps.on('click', '.add-custom', function () { addCustom($(this).closest('.step')); });
    $steps.on('click', '.del-custom', function () { $(this).closest('tr').remove(); reindex(); });
    $steps.on('click', '.step-del', function () { if ($steps.children('.step').length > 1) { $(this).closest('.step').remove(); reindex(); } });
    $steps.on('click', '.step-up', function () { var $s = $(this).closest('.step'); $s.prev('.step').before($s); reindex(); });
    $steps.on('click', '.step-down', function () { var $s = $(this).closest('.step'); $s.next('.step').after($s); reindex(); });

    $.each(initial.length ? initial : [{}], function (_, d) { addStep(d); });
});
</script>
@endsection
