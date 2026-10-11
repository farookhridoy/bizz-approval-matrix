<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Bizzsol\ApprovalMatrix\Models\ApprovalModuleSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Is the approval matrix switched on for a module? Off = WorkflowResolver finds nothing, so the module's documents keep using their
 * legacy approval flow. No row = config('approvalmatrix.default_module_enabled'). Registered as a singleton (one DB read per request);
 * a missing table (migration not run yet) counts as "no rows".
 */
class ModuleSwitch
{
    /** @var array<string,bool>|null module => enabled */
    private ?array $rows = null;

    public function isEnabled(string $module): bool
    {
        return $this->rows()[$module] ?? (bool) config('approvalmatrix.default_module_enabled', true);
    }

    public function isEnabledFor(string $documentType): bool
    {
        return $this->isEnabled($this->moduleOf($documentType));
    }

    /** the module a document type belongs to: config `module`, else the part before the first dot */
    public function moduleOf(string $documentType): string
    {
        return config("approvalmatrix.document_types.{$documentType}.module") ?? explode('.', $documentType)[0];
    }

    /** @return array<string,bool> every configured module => enabled */
    public function all(): array
    {
        return collect(config('approvalmatrix.modules', []))->mapWithKeys(fn ($m) => [$m => $this->isEnabled($m)])->all();
    }

    public function set(string $module, bool $enabled, ?int $userId = null): void
    {
        ApprovalModuleSetting::updateOrCreate(['module' => $module], ['enabled' => $enabled, 'updated_by' => $userId]);
        $this->rows = null;
    }

    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = Schema::hasTable('approval_module_settings')
                ? ApprovalModuleSetting::pluck('enabled', 'module')->map(fn ($v) => (bool) $v)->all()
                : [];
        }

        return $this->rows;
    }
}
