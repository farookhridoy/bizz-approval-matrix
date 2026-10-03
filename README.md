# bizzsol/approval-matrix

Global approval matrix for the ERP suite (erp-main, erp-pms, hrms, finance, ...). One shared MySQL database,
so **exactly one app owns the schema** (erp-main); every other app consumes the services.

Hierarchy: Module (core, procurement, finance, hrms, production, pmd) > Company > Unit > Master department.
The most specific workflow wins, then `priority`, then newest `version`. Amount / attribute conditions and effective
dates narrow it. Approvers: reporting head (employee `reporting_manager_id`, N hops), custom user per unit/department,
specific user, role, permission. Steps can need any / all / N-of-M approvers, be skipped by amount, be optional, let the
approver finish the whole request ("Acknowledge") or forward it, and return a rejection to the requester.

## Install (any app)
```
composer config repositories.approval-matrix path ../bizz-approval-matrix   # or the vcs url once pushed
composer update bizzsol/approval-matrix --minimal-changes --no-security-blocking --no-scripts   # (plain `require` can fail on security-advisory blocking)
```
The provider is auto-discovered. Defaults register **services only**; screens are opt-in per app (`config/approvalmatrix.php`):

| Key | Default | Meaning |
|---|---|---|
| `admin_ui` | false | workflow builder, simulator, org feeds **and** the inbox/delegation screens (erp-main) |
| `inbox_ui` | false | only the approver inbox + delegation screens (apps that host approvals without the builder, e.g. erp-pms) |
| `load_migrations` | false | run this package's migrations - enable in exactly ONE app per shared database |

Screens need the host's layout (`dashboard::layouts.master-layout`), the `yajra.*` datatable partials and Spatie permissions
(`approval-matrix-index|create|edit|delete|simulator`, `approval-inbox`). Back the DB up before `php artisan migrate`:
the menu migration writes the shared `menus`, `sub_menus`, `permissions` and `role_has_permissions` tables.

## Using it
```php
use Bizzsol\ApprovalMatrix\Traits\HasApproval;
use Bizzsol\ApprovalMatrix\Services\{ApprovalEngine, ApprovalSimulator, WorkflowService};
use Bizzsol\ApprovalMatrix\Support\DocumentLinks;
```
- Model: `use HasApproval;` implement `approvalDocumentType()`, optionally `approvalAmount()` / `approvalAttributes()`; or call the engine directly with a context override.
- `ApprovalEngine`: `submit($doc, $requesterId, $type, $ctx, $resume)`, `approve($req, $user, $comment, $finish)`, `reject`, `recall`, `cancel`, `inbox($userId)`,
  `openRequestFor`, `isAssigned`, `currentApprovers`, `assignmentFor`, `assigneeIdsFor`, `withDelegates`.
- `submit(..., resume: true)`: after a rejection on the same workflow version the levels below the rejecting one are carried over.
- `ApprovalSimulator::simulate([...])` is a dry run that writes nothing.
- Events: `ApprovalStepAssigned`, `ApprovalFinished`, `ApprovalOverdue`. Exceptions: `ApprovalException`, `NoApproverException` (a mandatory step has nobody - fall back, never block the user).
- `DocumentLinks::register($type, fn (Model $d) => ['url' => ..., 'label' => ...])` tells the inbox how to open a document.
- Per-document-type rules live in `config('approvalmatrix.document_types')` (`modes`, `allow_finish`), e.g. CS = mode `all` only.

## Delegation and reminders
- **Delegation** (My Approvals > Delegation): a delegate sees, is notified about and can approve/reject what is assigned to the delegator for a date range
  (optionally one document type). History keeps the original assignee and records the real decider in `acted_by`; a requester can never approve their own request this way.
- `php artisan approval:remind [--repeat-hours=24] [--dry-run]` fires `ApprovalOverdue` for pending assignments past their step's `sla_hours`.

## Tests
Tests run inside a host app (they need its `users`, `hrms_*`, `hr_unit`, `hr_department`, `master_departments` ... tables), always against a
dedicated `*_testing` database and inside rolled-back transactions. In erp-main: `vendor/bin/phpunit --testsuite ApprovalMatrix`.
