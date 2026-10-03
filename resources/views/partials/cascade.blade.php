{{-- Company > Unit > Master department cascade (same Unit -> Master Department idea as the PMS store-requisition filter). --}}
<script>
window.amOrg = (function () {
    var urls = {units: @json(route('approval-matrix.org.units')), masters: @json(route('approval-matrix.org.master-departments')), users: @json(route('approval-matrix.org.users'))};
    var userCache = {};

    function fill($sel, rows, placeholder, keep, withCompany) {
        var cur = keep ? $sel.val() : '';
        $sel.empty().append(new Option(placeholder, ''));
        $.each(rows, function (_, r) {
            var o = new Option(r.name, r.id);
            if (withCompany) $(o).attr('data-company', r.company_id);
            $sel.append(o);
        });
        if (cur && $sel.find('option[value="' + cur + '"]').length) $sel.val(cur); else $sel.val('');
        $sel.trigger('change.select2');
    }

    /** Searchable dropdowns (select2 ships with the host layout). */
    function s2($els) {
        $els.each(function () {
            var $e = $(this);
            if (!$.fn.select2 || $e.data('select2')) return;
            $e.select2({width: '100%', placeholder: $e.data('placeholder') || undefined, dropdownAutoWidth: false});
        });
    }

    /**
     * Refill a user dropdown with the people of a company / unit / master department.
     * The currently chosen user always stays (an approver may sit outside the unit).
     */
    function loadUsers($sel, scope) {
        var params = {company_id: scope.company || '', unit_id: scope.unit || '', master_department_id: scope.master || ''};
        var key = JSON.stringify(params);
        userCache[key] = userCache[key] || $.getJSON(urls.users, params);
        return userCache[key].done(function (rows) {
            var cur = $sel.val(), curText = $sel.find('option:selected').text();
            var first = $sel.find('option[value=""]').first().text() || '— select —';
            $sel.empty().append(new Option(first, ''));
            var seen = {};
            $.each(rows, function (_, r) { seen[r.id] = 1; $sel.append(new Option(r.name, r.id)); });
            if (cur && !seen[cur]) $sel.append(new Option(curText + ' (outside this scope)', cur));
            $sel.val(cur || '').trigger('change.select2');
        });
    }

    function loadUnits($unit, companyId, keep) {
        return $.getJSON(urls.units, {company_id: companyId || ''}).done(function (rows) { fill($unit, rows, $unit.data('placeholder') || 'All units', keep, true); });
    }
    function loadMasters($master, unitId, companyId, keep) {
        return $.getJSON(urls.masters, {unit_id: unitId || '', company_id: companyId || ''}).done(function (rows) { fill($master, rows, $master.data('placeholder') || 'All departments', keep, false); });
    }

    return {
        loadMasters: loadMasters,
        loadUsers: loadUsers,
        s2: s2,
        /** Wire company -> unit -> master department. A unit also fills in its company. */
        bind: function ($company, $unit, $master) {
            $company.on('change', function () {
                loadUnits($unit, $company.val(), true).done(function () {
                    loadMasters($master, $unit.val(), $company.val(), true);
                });
            });
            $unit.on('change', function () {
                var c = $unit.find(':selected').data('company');
                if (c && String($company.val()) !== String(c)) $company.val(c).trigger('change.select2');
                loadMasters($master, $unit.val(), $company.val(), true);
            });
        }
    };
})();
</script>
