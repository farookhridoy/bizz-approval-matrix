{{-- Confirm + AJAX action helper shared by the approval-matrix screens (include inside @section('page-script')). --}}
<script>
/** Confirm (SweetAlert when present, else the browser dialog), POST/DELETE via AJAX, toast the answer, then reload the table or page. */
window.amAct = function (opts) {
    var run = function () {
        $.ajax({url: opts.url, type: opts.method || 'POST', data: {_token: $('meta[name="csrf-token"]').attr('content'), _method: opts.method || 'POST'}, dataType: 'json'})
            .done(function (r) {
                (r.success === false ? toastr.error : toastr.success)(r.message || 'Done');
                if (r.success !== false) { if ($('.datatable-serverside').length) { reloadDatatable(); } else { location.reload(); } }
            })
            .fail(function (x) {
                var j = x.responseJSON || {};
                toastr.error((j.errors ? Object.values(j.errors).join(' ') : j.message) || 'Something went wrong');
            });
    };
    if (opts.confirm === false) { return run(); }
    if (window.swal) {
        swal({title: opts.title || 'Are you sure?', text: opts.text || '', icon: opts.icon || 'warning', dangerMode: !!opts.danger,
            buttons: {cancel: true, confirm: {text: opts.button || 'Yes', value: true, closeModal: true}}}).then(function (ok) { if (ok) { run(); } });
    } else if (confirm((opts.title || 'Are you sure?') + (opts.text ? '\n' + opts.text : ''))) { run(); }
};
</script>
