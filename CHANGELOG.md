# Changelog - bizzsol/approval-matrix

Consumers: erp-main (owns schema + admin screens), erp-pms (services + inbox) (`"bizzsol/approval-matrix": "^0.2"`). Guidebook: `erp-main-v11/docs/handover/05-APPROVAL-MATRIX.md`.

## v0.2.7 - 2026-10-11
- Fix: `ModuleSwitch::moduleOf()` resolved the module with a dotted config path, so a document type whose prefix is not its module (erp-pmd `tender.*` -> module `pmd`) fell back to the prefix and ignored the module switch. Also fixes the label in the document-switch toast.

## v0.2.6 - 2026-10-11
- **Document-type ("workflow") on/off switch.** New table `approval_document_settings`; dashboard card "Workflows on / off" (`POST approval-matrix/documents/{type}`, `approval-matrix-edit`) with On / Off / Not set per document type. An explicit setting beats the consuming app's `.env` mode; "Not set" lets the app decide. The resolver returns nothing for a document type switched off.
- `ModuleSwitch::active($documentType, $appDefault)` is the one call for apps that have their own mode: module on AND (dashboard setting ?? app default). erp-pms (`*_MODE`) and erp-pmd (`TENDER_*_MODE`) use it. Run `php artisan migrate` in erp-main.

## v0.2.5 - 2026-10-11
- **Module on/off switch.** New table `approval_module_settings` (migration runs in the schema-owning app only) and `Services\ModuleSwitch`. A module switched off resolves no workflow in `WorkflowResolver`, so its documents use their legacy approval flow; workflows are kept. No row = `approvalmatrix.default_module_enabled` (`APPROVAL_MATRIX_DEFAULT_ENABLED`, default **true**, so nothing changes on upgrade).
- Dashboard: a "Modules" strip on ACL > Approval Matrix (`POST approval-matrix/modules/{module}`, permission `approval-matrix-edit`). The simulator warns when the module is off.
- Run `php artisan migrate` in the app that owns the schema (erp-main) after `composer update`.

## v0.2.3 - 2026-10-05
- Document type `procurement.spot_price` (spot / direct-purchase bill price approval: management then accounts). `allow_finish` false.

## v0.2.2 - 2026-10-05
- Document types `procurement.po_bill` (supplier bill / invoice audit) and `procurement.po_advance` (PO advance audit). `allow_finish` false. Rule test in `DocumentTypeRulesTest`.

## v0.2.1 and earlier
- `inventory.adjustment`, master-department scope, `can_finish`, delegations, coverage report, sidebar menus for coverage and delegation, engine/inbox/simulator.

## Notes for integrators
- The engine keeps **one pending request per approvable (model class + id)**. Two document types on the same row need two model classes (see pms `SpotPriceApproval extends PurchaseOrderAttachment`).
- Adding a document type = a line in `config/approvalmatrix.php` `document_types` + a test + a patch tag; see guidebook page 05 for the full recipe.
- The package `main` branch contains everything above but is not pushed to GitHub yet (tags are).
