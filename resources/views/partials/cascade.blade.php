{{-- Company > Unit > Master department cascade (same Unit -> Master Department idea as the PMS store-requisition filter). --}}
<script>
window.amOrg = (function () {
    var urls = {units: @json(route('approval-matrix.org.units')), masters: @json(route('approval-matrix.org.master-departments'))};

    function fill($sel, rows, placeholder, keep, withCompany) {
        var cur = keep ? $sel.val() : '';
        $sel.empty().append(new Option(placeholder, ''));
        $.each(rows, function (_, r) {
            var o = new Option(r.name, r.id);
            if (withCompany) $(o).attr('data-company', r.company_id);
            $sel.append(o);
        });
        if (cur && $sel.find('option[value="' + cur + '"]').length) $sel.val(cur); else $sel.val('');
    }

    function loadUnits($unit, companyId, keep) {
        return $.getJSON(urls.units, {company_id: companyId || ''}).done(function (rows) { fill($unit, rows, $unit.data('placeholder') || 'All units', keep, true); });
    }
    function loadMasters($master, unitId, companyId, keep) {
        return $.getJSON(urls.masters, {unit_id: unitId || '', company_id: companyId || ''}).done(function (rows) { fill($master, rows, $master.data('placeholder') || 'All departments', keep, false); });
    }

    return {
        loadMasters: loadMasters,
        /** Wire company -> unit -> master department. A unit also fills in its company. */
        bind: function ($company, $unit, $master) {
            $company.on('change', function () {
                loadUnits($unit, $company.val(), true).done(function () {
                    loadMasters($master, $unit.val(), $company.val(), true);
                });
            });
            $unit.on('change', function () {
                var c = $unit.find(':selected').data('company');
                if (c && String($company.val()) !== String(c)) $company.val(c);
                loadMasters($master, $unit.val(), $company.val(), true);
            });
        }
    };
})();
</script>
