<?php

return [
    /*
     * Which app does what (override in the host app's config/approvalmatrix.php):
     *   admin_ui        - register the workflow builder / simulator / inbox routes (needs the host layout
     *                     `dashboard::layouts.master-layout`, `yajra.*` partials and Spatie permissions).
     *   load_migrations - run this package's migrations. Enable in exactly ONE app per shared database.
     * Defaults are off so a consuming app gets the services only.
     */
    'admin_ui' => env('APPROVAL_MATRIX_ADMIN_UI', false),
    'load_migrations' => env('APPROVAL_MATRIX_MIGRATIONS', false),

    'modules' => ['core', 'procurement', 'finance', 'hrms', 'production', 'pmd'],

    /*
     * Approvable document types, keyed `<module>.<document>`. Host apps may add their own by
     * overriding this key in their config/approvalmatrix.php.
     */
    'document_types' => [
        'procurement.requisition' => ['module' => 'procurement', 'label' => 'Requisition'],
        // CS approval keeps the legacy rule that every approver of a level must approve, so only mode "all"
        // (a one-person level is the same thing) and no early finish are allowed.
        'procurement.cs' => ['module' => 'procurement', 'label' => 'Comparative Statement', 'modes' => ['all'], 'allow_finish' => false],
        'procurement.purchase_order' => ['module' => 'procurement', 'label' => 'Purchase Order'],
        // Cash approval of direct-purchase POs (releases the PO to be sent). No early finish: every level must approve.
        'procurement.po_cash' => ['module' => 'procurement', 'label' => 'PO Cash Approval', 'allow_finish' => false],
    ],
];
