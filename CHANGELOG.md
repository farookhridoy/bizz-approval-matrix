# Changelog - bizzsol/approval-matrix

Consumers: erp-main (owns schema + admin screens), erp-pms (services + inbox) (`"bizzsol/approval-matrix": "^0.2"`). Guidebook: `erp-main-v11/docs/handover/05-APPROVAL-MATRIX.md`.

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
