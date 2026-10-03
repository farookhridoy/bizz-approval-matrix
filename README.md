# bizzsol/approval-matrix

Global approval matrix for the ERP suite (erp-main, erp-pms, hrms, finance, ...). One shared MySQL database,
so **exactly one app owns the schema**; every other app consumes the services.

Hierarchy: Module (core, procurement, finance, hrms, production, pmd) > Company > Unit > Department.
Most specific workflow wins, then `priority`, then newest `version`. Amount / attribute conditions and
effective dates narrow it. Approvers: reporting head (employee `reporting_manager_id`, N hops), custom user per
unit/department, specific user, role, permission.

## Install (any app)
```
composer config repositories.approval-matrix path ../bizz-approval-matrix   # or a vcs url once pushed
composer require bizzsol/approval-matrix:@dev
```
The provider is auto-discovered. By default it registers **services only**.

## The owning app (erp-main)
`config/approvalmatrix.php`:
```php
return ['admin_ui' => true, 'load_migrations' => true];
```
Back up the DB (`mysqldump`) before `php artisan migrate`: the menu migration writes the shared `menus`,
`sub_menus`, `permissions` and `role_has_permissions` tables.

## Using it
```php
use Bizzsol\ApprovalMatrix\Traits\HasApproval;
use Bizzsol\ApprovalMatrix\Services\{ApprovalEngine, ApprovalSimulator};
```
- Model: `use HasApproval;` implement `approvalDocumentType()`, optionally `approvalAmount()` / `approvalAttributes()`.
- `$model->submitForApproval($requesterId)`; `ApprovalEngine::approve/reject/recall/inbox`.
- Listen to `Events\ApprovalFinished` to advance your business state.
- `ApprovalSimulator::simulate([...])` is a dry run that writes nothing.

## Tests
Tests run inside a host app (they need its `users`, `hrms_*`, `hr_unit` ... tables), always against a
dedicated `*_testing` database. In erp-main: `vendor/bin/phpunit --testsuite ApprovalMatrix`.
