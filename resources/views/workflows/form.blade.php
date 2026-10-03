@extends('dashboard::layouts.master-layout')

@section('title', session()->get('system-information')['name']. ' | '.$title)

@section('page-css')
    @include('approvalmatrix::partials.ui')
    <style>
    .am-step-card{border:1px solid var(--am-line);border-radius:12px;margin-bottom:12px;background:#fff;overflow:hidden}
    .am-step-card>.sh{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--am-bg);cursor:pointer}
    .am-step-card .sh .am-dot{width:28px;height:28px;font-size:12px}
    .am-step-card .sh .t{flex:1;min-width:0}.am-step-card .sh .t b{display:block}
    .am-step-card .sh .t small{color:var(--am-muted);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .am-step-card .sh .btn{padding:2px 7px}
    .am-step-card.collapsed>.sb{display:none}
    .am-step-card>.sb{padding:16px 18px;border-top:1px solid var(--am-line)}
    .am-step-card label{font-size:12px;font-weight:600;color:var(--am-muted);margin-bottom:3px}
    .am-sub{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--am-muted);font-weight:600;margin:4px 0 8px}
    .am-preview{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:14px;padding:10px 14px;border:1px dashed var(--am-line);border-radius:10px;background:#fff}
    .am-form label{font-size:12px;font-weight:600;color:var(--am-muted);margin-bottom:3px}
    </style>
@endsection

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

        <div class="page-content am am-form">
            <div class="am-head"><div><h2>{{ $workflow ? 'Edit workflow' : 'New workflow' }}</h2><p>Decide who approves, and for which requests. The most specific active workflow wins.</p></div></div>
            @if ($errors->any())
                <div class="am-note bad"><i class="las la-exclamation-circle"></i><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <form method="post" action="{{ $workflow ? route('approval-matrix.workflows.update', $workflow->id) : route('approval-matrix.workflows.store') }}">
                @csrf
                @if ($workflow) @method('PUT') @endif

                @if ($workflow && \Bizzsol\ApprovalMatrix\Models\ApprovalRequest::where('workflow_id', $workflow->id)->exists())
                    <div class="am-note warn"><i class="las la-code-branch"></i><div>This workflow already has approval requests. Saving creates <strong>version {{ $workflow->version + 1 }}</strong>; the current version and its history stay untouched.</div></div>
                @endif

                @php($c = $workflow->conditions ?? [])
                @php($attrText = collect($c['attributes'] ?? [])->map(fn ($v, $k) => $k.'='.implode(',', $v))->implode("\n"))

                <div class="am-card">
                    <div class="am-card-h"><h3>1 · What it approves</h3><small>name, document and priority</small></div>
                    <div class="am-card-b">
                        <div class="form-group row">
                            <div class="col-md-4">
                                <label><strong>Name <span class="text-danger">*</span></strong></label>
                                <input type="text" name="name" class="form-control" required value="{{ old('name', $workflow->name ?? '') }}"><small class="am-help">A label people will see in lists and the approval inbox, e.g. <code>Requisition – Garments unit</code>.</small>
                            </div>
                            <div class="col-md-4">
                                <label><strong>Document <span class="text-danger">*</span></strong></label>
                                <select name="document_type" class="form-control" required>
                                    @foreach ($documentTypes as $key => $d)
                                        <option value="{{ $key }}" @selected(old('document_type', $workflow->document_type ?? '') === $key)>{{ ucfirst($d['module']) }} › {{ $d['label'] }}</option>
                                    @endforeach
                                </select><small class="am-help">Which kind of request this workflow approves. A document type can have many workflows (for different units, departments or amounts).</small>
                            </div>
                            <div class="col-md-2">
                                <label><strong>State</strong></label>
                                <div class="am-seg" style="display:flex">
                                    @foreach (['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $s => $lbl)
                                        <label style="flex:1;justify-content:center;padding:7px 6px"><input type="radio" name="state" value="{{ $s }}" @checked(old('state', $workflow->state ?? 'draft') === $s)><span>{{ $lbl }}</span></label>
                                    @endforeach
                                </div>
                                <small class="am-help"><b>Draft</b> = saved, never used. <b>Active</b> = used for new requests. <b>Archived</b> = retired; requests already running keep their steps.</small>
                            </div>
                            <div class="col-md-2">
                                <label><strong>Priority</strong></label>
                                <input type="number" min="0" name="priority" class="form-control" value="{{ old('priority', $workflow->priority ?? 0) }}" title="Higher wins when scope is equal"><small class="am-help">Tie-breaker: if two workflows match equally well, the higher number wins.</small>
                            </div>
                        </div>
                        <hr style="margin:16px 0"><div class="am-sub">Applies to — leave blank for everyone</div>
                        <div class="form-group row">
                            <div class="col-md-4">
                                <label><strong>Company</strong></label>
                                <select name="company_id" id="am-company" class="form-control">
                                    <option value="">All companies</option>
                                    @foreach ($companies as $id => $n)<option value="{{ $id }}" @selected(old('company_id', $workflow->company_id ?? '') == $id)>{{ $n }}</option>@endforeach
                                </select><small class="am-help">Limit to one company. Blank = every company.</small>
                            </div>
                            <div class="col-md-4">
                                <label><strong>Unit</strong></label>
                                <select name="unit_id" id="am-unit" class="form-control" data-placeholder="All units">
                                    <option value="">All units</option>
                                    @foreach ($unitRows as $u)<option value="{{ $u->id }}" data-company="{{ $u->company_id }}" @selected(old('unit_id', $workflow->unit_id ?? '') == $u->id)>{{ $u->name }}</option>@endforeach
                                </select><small class="am-help">Limit to one unit (list follows the company). More specific than company, so it wins over a company-wide workflow.</small>
                            </div>
                            <div class="col-md-4">
                                <label><strong>Department</strong> <small class="text-muted">master department</small></label>
                                <select name="master_department_id" id="am-master" class="form-control" data-placeholder="All departments">
                                    <option value="">All departments</option>
                                    @foreach ($masters as $id => $n)<option value="{{ $id }}" @selected(old('master_department_id', $workflow->master_department_id ?? '') == $id)>{{ $n }}</option>@endforeach
                                </select><small class="am-help">Limit to one department (list follows the unit). Most specific, so it wins over unit and company workflows.</small>
                                <label class="am-help" style="margin-top:8px"><input type="checkbox" id="am-limit-users" checked> Show only people of this company / unit / department in approver lists</label>
                                @if ($workflow && $workflow->department_id)
                                    <input type="hidden" name="department_id" value="{{ $workflow->department_id }}">
                                    <small class="text-warning">Also limited to one unit-specific department (id {{ $workflow->department_id }}).</small>
                                @endif
                            </div>
                        </div>
                        <hr style="margin:16px 0"><div class="am-sub">Only when… (optional conditions)</div>
                        <div class="form-group row">
                            <div class="col-md-2">
                                <label><strong>Amount from</strong></label>
                                <input type="number" step="0.01" min="0" name="amount_min" class="form-control" value="{{ old('amount_min', $c['amount_min'] ?? '') }}"><small class="am-help">Used only for requests whose amount is at least this. Blank = no lower limit.</small>
                            </div>
                            <div class="col-md-2">
                                <label><strong>Amount up to</strong></label>
                                <input type="number" step="0.01" min="0" name="amount_max" class="form-control" value="{{ old('amount_max', $c['amount_max'] ?? '') }}"><small class="am-help">Used only up to this amount. Blank = no upper limit. Tier approvals with several workflows, e.g. 0–50,000 and 50,000+.</small>
                            </div>
                            <div class="col-md-2">
                                <label><strong>Effective from</strong></label>
                                <input type="date" name="effective_from" class="form-control" value="{{ old('effective_from', optional($workflow->effective_from ?? null)->format('Y-m-d')) }}"><small class="am-help">First day this workflow may be picked for new requests. Blank = immediately.</small>
                            </div>
                            <div class="col-md-2">
                                <label><strong>Effective to</strong></label>
                                <input type="date" name="effective_to" class="form-control" value="{{ old('effective_to', optional($workflow->effective_to ?? null)->format('Y-m-d')) }}"><small class="am-help">Last day. Blank = no end. Use for temporary rules (e.g. a festival period).</small>
                            </div>
                            <div class="col-md-4">
                                <label><strong>Attribute conditions</strong> <small class="text-muted">one per line: key=value1,value2</small></label>
                                <textarea name="attributes" rows="2" class="form-control" placeholder="purchase_type=foreign">{{ old('attributes', $attrText) }}</textarea><small class="am-help">Extra facts sent by the document, one per line, e.g. <code>purchase_type=foreign</code> or <code>priority=urgent,high</code>. The workflow applies only when all lines match. Leave empty if unsure.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="am-card">
                    <div class="am-card-h"><h3>2 · Approval steps</h3><small>run top to bottom · click a step to expand</small></div>
                    <div class="am-card-b">
                        <details class="am-guide am-note" style="display:block"><summary><i class="las la-question-circle"></i> How steps work</summary>
                            <p style="margin:8px 0 4px">Steps run <b>top to bottom</b>. When a step is approved the request moves to the next; a rejection follows the step's “On reject” rule. Open a step to set who approves and the rules.</p>
                            <table><tr><td>Reporting head</td><td>The requester's manager from HR (<code>reporting_manager_id</code>); 2 or 3 levels climbs the chain.</td></tr>
                            <tr><td>Custom user</td><td>You pick the person per unit / department; the most specific matching row is used, a row with both blank is the default.</td></tr>
                            <tr><td>Specific user</td><td>One named person, always.</td></tr>
                            <tr><td>Role / Permission</td><td>Everyone holding that role or permission can approve.</td></tr></table></details>
                        <div class="am-preview" id="chain-preview"></div>
                        <div id="steps"></div>
                        <button type="button" class="btn btn-sm btn-primary" id="add-step"><i class="la la-plus"></i> Add step</button>
                    </div>
                </div>

                <div class="am-actionbar">
                    <span class="text-muted" style="font-size:13px">Drafts never apply to real requests until set to Active.</span>
                    <span><a href="{{ route('approval-matrix.workflows.index') }}" class="btn btn-default">Cancel</a> <button type="submit" class="btn btn-primary"><i class="la la-check"></i> Save workflow</button></span>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="perm-list">@foreach ($permissions as $p)<option value="{{ $p }}">@endforeach</datalist>
@endsection

@section('page-script')
@include('approvalmatrix::partials.cascade')
<script type="text/html" id="step-tpl">
    <div class="am-step-card step">
        <div class="sh">
            <span class="am-dot step-no">1</span>
            <span class="t"><b class="step-title">Step</b><small class="step-sum"></small></span>
            <span>
                <a class="btn btn-xs btn-default step-up" title="Move up"><i class="la la-arrow-up"></i></a>
                <a class="btn btn-xs btn-default step-down" title="Move down"><i class="la la-arrow-down"></i></a>
                <a class="btn btn-xs btn-danger step-del" title="Remove"><i class="la la-trash"></i></a>
            </span>
        </div>
        <div class="sb">
            <div class="form-group row">
                <div class="col-md-3"><label>Step name</label><input type="text" class="form-control" data-f="name" placeholder="Department head"><small class="am-help">Shown to approvers and in history.</small></div>
                <div class="col-md-2"><label>Stage key</label><input type="text" class="form-control" data-f="stage_key" placeholder="dept_head"><small class="am-help">Short code (no spaces) other features can refer to. Optional.</small></div>
                <div class="col-md-3"><label>Approver <span class="text-danger">*</span></label>
                    <select class="form-control" data-f="approver_type">
                        @foreach ($approverTypes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select><small class="am-help ap-hint"></small>
                </div>
                <div class="col-md-4 ap-extra">
                    <div class="ap ap-reporting_head"><label>Reporting level</label>
                        <select class="form-control" data-f="approver_ref_hops">
                            <option value="1">Direct reporting head</option><option value="2">Head’s head (2 levels)</option><option value="3">3 levels up</option>
                        </select><small class="am-help">How far up the HR reporting line to go from the requester.</small></div>
                    <div class="ap ap-specific_user" style="display:none"><label>User</label>
                        <select class="form-control" data-f="user_id" data-userpick="1"><option value="">— select —</option>
                            @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select><small class="am-help">The list follows the company / unit / department chosen above. The saved person is always kept.</small></div>
                    <div class="ap ap-role" style="display:none"><label>Role</label>
                        <select class="form-control" data-f="approver_ref_role"><option value="">— select —</option>
                            @foreach ($roles as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
                        </select><small class="am-help">Any user with this role may approve (first to act decides in “any” mode).</small></div>
                    <div class="ap ap-permission" style="display:none"><label>Permission</label>
                        <input class="form-control" list="perm-list" data-f="approver_ref_perm" placeholder="type to search"><small class="am-help">Any user holding this permission may approve.</small></div>
                </div>
            </div>
            <div class="ap ap-custom_user" style="display:none">
                <label>Custom approvers <small class="text-muted">most specific row matching the document’s unit/department is used; leave both blank for a default</small></label>
                <table class="table table-condensed table-bordered"><thead><tr><th>Unit</th><th>Department</th><th>User</th><th style="width:40px"></th></tr></thead><tbody class="custom-rows"></tbody></table>
                <small class="am-help" style="margin-bottom:6px">Unit / department narrow where the row applies; the user list follows them.</small>
                <a class="btn btn-xs btn-default add-custom"><i class="la la-plus"></i> Add row</a>
            </div>
            <div class="am-sub" style="margin-top:6px">Rules</div>
            <div class="form-group row">
                <div class="col-md-2"><label>Mode</label>
                    <select class="form-control" data-f="mode"><option value="any">Any one approves</option><option value="all">All must approve</option><option value="n_of_m">N of the group</option></select><small class="am-help"><b>Any</b>: first person decides. <b>All</b>: everyone must approve. <b>N of group</b>: a set number.</small></div>
                <div class="col-md-2 n-wrap" style="display:none"><label>N required</label><input type="number" min="1" class="form-control" data-f="min_approvals"><small class="am-help">How many must approve.</small></div>
                <div class="col-md-2"><label>Skip if amount ≤</label><input type="number" step="0.01" min="0" class="form-control" data-f="skip_amount_max" placeholder="never"><small class="am-help">Skip this step for small requests (amount at or below this).</small></div>
                <div class="col-md-2"><label>SLA (hours)</label><input type="number" min="1" class="form-control" data-f="sla_hours"><small class="am-help">Reminder after this many hours waiting (needs reminders enabled).</small></div>
                <div class="col-md-2"><label>On reject</label>
                    <select class="form-control" data-f="on_reject"><option value="terminate">End request</option><option value="return_to_requester">Return to requester</option></select><small class="am-help"><b>End request</b> stops it. <b>Return</b> sends it back to be fixed and resubmitted, then resumes here.</small></div>
                <div class="col-md-2"><label>&nbsp;</label><div class="checkbox"><label><input type="checkbox" value="1" data-f="is_mandatory" checked> Mandatory</label></div>
                    <small class="text-muted">Optional steps are skipped when nobody can be found.</small>
                    <div class="checkbox"><label><input type="checkbox" value="1" data-f="can_finish"> Approver may finish here</label></div>
                    <small class="text-muted">“Acknowledge” completes the request; “Send to next” continues to the following steps.</small></div>
            </div>
        </div>
    </div>
</script>
<script type="text/html" id="custom-row-tpl">
    <tr>
        <td><select class="form-control input-sm" data-c="unit_id"><option value="">Any unit</option>@foreach ($allUnitRows as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></td>
        <td><select class="form-control input-sm" data-c="master_department_id" data-placeholder="Any department"><option value="">Any department</option>@foreach ($allMasters as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></td>
        <td><select class="form-control input-sm" data-c="user_id" data-userpick="1"><option value="">— user —</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></td>
        <td><a class="btn btn-xs btn-danger del-custom"><i class="la la-times"></i></a></td>
    </tr>
</script>
<script>
$(function () {
    var initial = @json($stepsData);
    var $steps = $('#steps');

    function reindex() {
        $steps.children('.step').each(function (i) {
            var $s = $(this);
            $s.find('.step-no').text(i + 1);
            summarize($s, i);
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
        preview();
    }

    function summarize($s, i) {
        var type = $s.find('[data-f=approver_type]');
        var who = type.find('option:selected').text();
        var t = type.val();
        if (t === 'specific_user') who = $s.find('[data-f=user_id] option:selected').text();
        if (t === 'role') who = 'Role: ' + ($s.find('[data-f=approver_ref_role]').val() || '…');
        if (t === 'reporting_head') who = $s.find('[data-f=approver_ref_hops] option:selected').text();
        var name = $.trim($s.find('[data-f=name]').val());
        $s.find('.step-title').text(name || ('Step ' + (i + 1)));
        var bits = [who];
        var m = $s.find('[data-f=mode]').val();
        if (m === 'all') bits.push('all must approve');
        if (m === 'n_of_m') bits.push($s.find('[data-f=min_approvals]').val() + ' of group');
        if ($s.find('[data-f=can_finish]').prop('checked')) bits.push('can finish');
        if (!$s.find('[data-f=is_mandatory]').prop('checked')) bits.push('optional');
        $s.find('.step-sum').text(bits.join(' · '));
    }

    function preview() {
        var $p = $('#chain-preview').empty(), $all = $steps.children('.step');
        if (!$all.length) { $p.text('No steps yet.'); return; }
        $all.each(function (i) {
            if (i) $p.append('<i class="las la-arrow-right" style="color:#9bb"></i>');
            $p.append($('<span class="am-chip lg"></span>').text($(this).find('.step-title').text() + ' — ' + $(this).find('.step-sum').text().split(' · ')[0]));
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
        amOrg.s2($r.find('select'));
        refreshUsers($r);
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
        $s.find('[data-f=can_finish]').prop('checked', !!parseInt(data.can_finish || 0));
        $s.find('[data-f=user_id]').val(data.user_id || '');
        if (type === 'reporting_head') $s.find('[data-f=approver_ref_hops]').val(data.approver_ref || 1);
        if (type === 'role') $s.find('[data-f=approver_ref_role]').val(data.approver_ref || '');
        if (type === 'permission') $s.find('[data-f=approver_ref_perm]').val(data.approver_ref || '');
        if (initial.length > 3) $s.addClass('collapsed');
        $steps.append($s);
        amOrg.s2($s.find('select'));
        $s.find('.ap-hint').text(hints[type] || '');
        $.each(data.custom || [], function (_, r) { addCustom($s, r); });
        if (type === 'custom_user' && !(data.custom || []).length) addCustom($s);
        reindex();
    }

    // Company > Unit > Master department
    amOrg.bind($('#am-company'), $('#am-unit'), $('#am-master'));
    amOrg.s2($('#am-company, #am-unit, #am-master, [name=document_type]'));

    var hints = {
        reporting_head: 'The requester\'s manager from HR.',
        custom_user: 'You choose the person per unit / department below.',
        specific_user: 'One named person, always.',
        role: 'Everyone with the role can approve.',
        permission: 'Everyone with the permission can approve.'
    };

    function workflowScope() {
        if (!$('#am-limit-users').prop('checked')) return {};
        return {company: $('#am-company').val(), unit: $('#am-unit').val(), master: $('#am-master').val()};
    }
    function refreshUsers($scope) {
        ($scope || $steps).find('[data-userpick]').each(function () {
            var $sel = $(this), sc = workflowScope();
            var $row = $sel.closest('tr');
            if ($row.length) {
                var u = $row.find('[data-c=unit_id]').val(), m = $row.find('[data-c=master_department_id]').val();
                if (u || m) sc = {unit: u, master: m};
            }
            amOrg.loadUsers($sel, sc);
        });
    }
    $('#am-company, #am-unit, #am-master, #am-limit-users').on('change', function () { setTimeout(function () { refreshUsers(); }, 60); });
    // custom approver rows: the department list follows the row's unit
    $steps.on('change', '[data-c=unit_id]', function () {
        var $row = $(this).closest('tr');
        amOrg.loadMasters($row.find('[data-c=master_department_id]'), $(this).val(), '', true).done(function () { refreshUsers($row); });
    });
    $steps.on('change', '[data-c=master_department_id]', function () { refreshUsers($(this).closest('tr')); });

    $('#add-step').on('click', function () { addStep({}); });
    $steps.on('change', '[data-f=approver_type]', function () {
        var $s = $(this).closest('.step');
        if ($(this).val() === 'custom_user' && !$s.find('.custom-rows tr').length) addCustom($s);
        $s.find('.ap-hint').text(hints[$(this).val()] || '');
        reindex();
    });
    $steps.on('change input', '[data-f]', reindex);
    $steps.on('click', '.sh', function (e) { if (!$(e.target).closest('a,button').length) $(this).closest('.step').toggleClass('collapsed'); });
    $steps.on('click', '.add-custom', function () { addCustom($(this).closest('.step')); });
    $steps.on('click', '.del-custom', function () { $(this).closest('tr').remove(); reindex(); });
    $steps.on('click', '.step-del', function () { if ($steps.children('.step').length > 1) { $(this).closest('.step').remove(); reindex(); } });
    $steps.on('click', '.step-up', function () { var $s = $(this).closest('.step'); $s.prev('.step').before($s); reindex(); });
    $steps.on('click', '.step-down', function () { var $s = $(this).closest('.step'); $s.next('.step').after($s); reindex(); });

    $.each(initial.length ? initial : [{}], function (_, d) { addStep(d); });
    refreshUsers();
});
</script>
@endsection
