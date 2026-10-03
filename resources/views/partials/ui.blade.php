{{-- Shared look for the approval-matrix screens. Scoped under .am so nothing else on the page changes; follows the host theme (teal). --}}
<style>
.am{--am-primary:#099dae;--am-primary-dark:#07808f;--am-primary-soft:#e3f5f7;--am-ink:#26343b;--am-muted:#6b7c85;--am-line:#e3eef0;--am-bg:#f6fafb;
    --am-ok:#16995a;--am-ok-soft:#e3f6ec;--am-warn:#b87900;--am-warn-soft:#fff3d6;--am-bad:#cc3b3b;--am-bad-soft:#fde8e8;--am-info:#1c7fb5;--am-info-soft:#e4f2fa;--am-grey-soft:#eef2f4;color:var(--am-ink)}
.am *{box-sizing:border-box}
.am-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:4px 0 16px}
.am-head h2{margin:0;font-size:22px;font-weight:600;color:var(--am-ink)}
.am-head p{margin:4px 0 0;color:var(--am-muted);font-size:13px;max-width:760px}
.am-card{background:#fff;border:1px solid var(--am-line);border-radius:14px;box-shadow:0 1px 2px rgba(20,60,70,.04);margin-bottom:18px;overflow:hidden}
.am-card-h{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-bottom:1px solid var(--am-line);background:linear-gradient(#fff,var(--am-bg))}
.am-card-h h3{margin:0;font-size:15px;font-weight:600}
.am-card-h small{color:var(--am-muted);font-size:12px}
.am-card-b{padding:18px 20px}
.am-label{display:block;font-size:12px;font-weight:600;color:var(--am-muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:5px}
.am-label i{font-style:normal;font-weight:400;text-transform:none;letter-spacing:0;color:#93a3ab}
.am .form-control{border-radius:9px;border-color:#d7e5e8;min-height:38px}
.am .form-control:focus{border-color:var(--am-primary);box-shadow:0 0 0 3px rgba(9,157,174,.15)}
.am-grid{display:grid;gap:14px 16px}
.am-grid.c2{grid-template-columns:repeat(2,minmax(0,1fr))}.am-grid.c3{grid-template-columns:repeat(3,minmax(0,1fr))}.am-grid.c4{grid-template-columns:repeat(4,minmax(0,1fr))}
@media (max-width:991px){.am-grid.c3,.am-grid.c4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.am-grid.c2,.am-grid.c3,.am-grid.c4{grid-template-columns:1fr}}
.am-span2{grid-column:span 2}
/* chips, badges, avatars */
.am-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:999px;background:var(--am-primary-soft);color:var(--am-primary-dark);font-size:12px;font-weight:500;line-height:20px;white-space:nowrap;max-width:100%}
.am-chip.muted{background:var(--am-grey-soft);color:var(--am-muted)}.am-chip.warn{background:var(--am-warn-soft);color:var(--am-warn)}.am-chip.ok{background:var(--am-ok-soft);color:var(--am-ok)}.am-chip.bad{background:var(--am-bad-soft);color:var(--am-bad)}.am-chip.info{background:var(--am-info-soft);color:var(--am-info)}
.am-chip.lg{font-size:13px;padding:3px 12px;line-height:22px}
.am-state{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:3px 11px;border-radius:999px}
.am-state::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.am-state.active,.am-state.approved,.am-state.ok{background:var(--am-ok-soft);color:var(--am-ok)}
.am-state.draft,.am-state.pending{background:var(--am-warn-soft);color:var(--am-warn)}
.am-state.archived,.am-state.recalled,.am-state.skipped{background:var(--am-grey-soft);color:var(--am-muted)}
.am-state.rejected,.am-state.returned,.am-state.bad{background:var(--am-bad-soft);color:var(--am-bad)}
.am-avatar{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--am-primary);color:#fff;font-size:11px;font-weight:600;flex:none;text-transform:uppercase}
.am-avatar.lg{width:34px;height:34px;font-size:13px}.am-avatar.muted{background:#a9bbc2}
.am-person{display:inline-flex;align-items:center;gap:8px}
.am-muted{color:var(--am-muted)}.am-small{font-size:12px}
/* stat cards */
.am-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.am-stat{display:block;background:#fff;border:1px solid var(--am-line);border-radius:12px;padding:12px 16px;color:inherit;text-decoration:none;transition:.15s}
.am-stat:hover,.am-stat.on{border-color:var(--am-primary);box-shadow:0 0 0 3px rgba(9,157,174,.12);text-decoration:none;color:inherit}
.am-stat b{display:block;font-size:24px;line-height:28px;font-weight:600}.am-stat span{font-size:12px;color:var(--am-muted)}
.am-stat.ok b{color:var(--am-ok)}.am-stat.warn b{color:var(--am-warn)}.am-stat.bad b{color:var(--am-bad)}
/* toolbar */
.am-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.am-toolbar .form-control{width:auto;min-width:170px}
/* chain (list) */
.am-chain{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
.am-chain .arrow{color:#b3c4ca;font-size:12px}
.am-scope{display:flex;flex-wrap:wrap;align-items:center;gap:3px;font-size:12px}
.am-scope .sep{color:#b3c4ca}
.am-name{font-weight:600;color:var(--am-ink)}.am-name small{display:block;font-weight:400;color:var(--am-muted);font-size:12px}
.am-actions{display:flex;gap:6px;align-items:center;justify-content:center}
.am-actions .btn{border-radius:8px}
.am-kebab{width:32px;padding:0;text-align:center}
.am-menu{min-width:190px;border-radius:10px;border:1px solid var(--am-line);box-shadow:0 8px 24px rgba(20,60,70,.12);padding:6px}
.am-menu a{display:flex;align-items:center;gap:9px;padding:7px 10px;border-radius:7px;color:var(--am-ink);font-size:13px;cursor:pointer;text-decoration:none}
.am-menu a:hover{background:var(--am-primary-soft);color:var(--am-primary-dark)}.am-menu a.danger{color:var(--am-bad)}.am-menu a.danger:hover{background:var(--am-bad-soft)}
.am-menu hr{margin:5px 0;border-color:var(--am-line)}
/* empty + alerts */
.am-empty{text-align:center;padding:42px 20px;color:var(--am-muted)}
.am-empty i{font-size:42px;color:#b9d6db;display:block;margin-bottom:8px}.am-empty b{display:block;color:var(--am-ink);font-size:16px;margin-bottom:4px}
.am-note{display:flex;gap:10px;align-items:flex-start;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:14px;background:var(--am-info-soft);color:#1d587a}
.am-note.warn{background:var(--am-warn-soft);color:#7a5200}.am-note.bad{background:var(--am-bad-soft);color:#8a2626}.am-note.ok{background:var(--am-ok-soft);color:#0f6b3f}
.am-note i{font-size:18px;line-height:20px}
/* vertical stepper */
.am-steps{list-style:none;margin:0;padding:0}
.am-step{position:relative;display:flex;gap:14px;padding-bottom:18px}
.am-step:last-child{padding-bottom:0}
.am-step::before{content:"";position:absolute;left:15px;top:32px;bottom:0;width:2px;background:var(--am-line)}
.am-step:last-child::before{display:none}
.am-dot{width:32px;height:32px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;background:var(--am-primary);color:#fff;z-index:1}
.am-dot.skipped{background:#c3d0d5}.am-dot.bad{background:var(--am-bad)}.am-dot.ok{background:var(--am-ok)}.am-dot.pending{background:var(--am-warn)}.am-dot.future{background:#fff;color:var(--am-muted);border:2px solid var(--am-line)}
.am-step-body{flex:1;min-width:0}
.am-step-title{font-weight:600;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.am-step-sub{font-size:12px;color:var(--am-muted);margin:2px 0 6px}
.am-people{display:flex;flex-wrap:wrap;gap:8px 14px}
/* sticky action bar (forms) */
.am-actionbar{position:sticky;bottom:0;z-index:20;display:flex;gap:10px;align-items:center;justify-content:space-between;background:rgba(255,255,255,.96);backdrop-filter:blur(4px);border:1px solid var(--am-line);border-radius:14px;padding:12px 18px;margin:6px 0 26px;box-shadow:0 -4px 16px rgba(20,60,70,.06)}
.am-seg{display:inline-flex;border:1px solid #d7e5e8;border-radius:10px;overflow:hidden;background:#fff}
.am-seg label{margin:0;padding:7px 14px;font-size:13px;cursor:pointer;color:var(--am-muted);border-right:1px solid #d7e5e8;display:flex;align-items:center;gap:6px}
.am-seg label:last-child{border-right:0}.am-seg input{display:none}
.am-seg input:checked+span{color:var(--am-primary-dark);font-weight:600}.am-seg label:has(input:checked){background:var(--am-primary-soft)}
.am-table{width:100%;border-collapse:separate;border-spacing:0}
.am-table th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--am-muted);font-weight:600;padding:10px 14px;border-bottom:1px solid var(--am-line);background:var(--am-bg)}
.am-table td{padding:12px 14px;border-bottom:1px solid #eef4f5;vertical-align:middle}
.am-table tr:last-child td{border-bottom:0}.am-table tbody tr:hover td{background:#fbfeff}
/* datatables restyle inside .am */
.am table.dataTable thead th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--am-muted);background:var(--am-bg);border-bottom:1px solid var(--am-line)!important;white-space:nowrap}
.am table.dataTable tbody td{vertical-align:middle;padding:12px 14px}
.am .dataTables_wrapper .dataTables_filter input{border-radius:9px}
.am-name .am-avatar{margin-right:8px}.am-two{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start}@media(max-width:991px){.am-two{grid-template-columns:1fr}}.am-meta{display:flex;gap:22px;flex-wrap:wrap;font-size:13px;color:var(--am-muted)}.am-meta b{color:var(--am-ink);display:block;font-size:14px}.am-cmt{background:var(--am-bg);border-radius:8px;padding:6px 10px;font-size:13px;margin-top:4px}
</style>
