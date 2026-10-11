<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Bizzsol\ApprovalMatrix\Models\ApprovalDocumentSetting;
use Bizzsol\ApprovalMatrix\Models\ApprovalModuleSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Is the approval matrix switched on? Two levels, both edited on ACL > Approval Matrix:
 *   module        - no row = config('approvalmatrix.default_module_enabled') (true)
 *   document type - ("workflow") a row is an explicit decision; no row = "not set" and the consuming app's own default applies
 * Off at either level = WorkflowResolver finds nothing, so the documents keep using their legacy approval flow.
 * Consuming apps that have their own .env mode ask `active($type, $envDefault)`: the dashboard decision wins, the env mode is the fallback.
 * Registered as a singleton (one DB read per request); a missing table (migration not run yet) counts as "no rows".
 */
class ModuleSwitch
{
    /** @var array<string,bool>|null module => enabled */
    private ?array $rows = null;

    /** @var array<string,bool>|null document_type => enabled (explicit settings only) */
    private ?array $docs = null;

    public function isEnabled(string $module): bool
    {
        return $this->rows()[$module] ?? (bool) config('approvalmatrix.default_module_enabled', true);
    }

    /** Used by the resolver: module on AND the document type not explicitly switched off. */
    public function isEnabledFor(string $documentType): bool
    {
        return $this->isEnabled($this->moduleOf($documentType)) && ($this->documentSetting($documentType) ?? true);
    }

    /** The explicit dashboard setting of a document type: true / false, or null when never set. */
    public function documentSetting(string $documentType): ?bool
    {
        return $this->docs()[$documentType] ?? null;
    }

    /**
     * For consuming apps with their own mode: should the matrix run for this document type? The module must be on; then the
     * dashboard setting decides, and when it was never set $appDefault (the app's .env mode === 'matrix') does.
     */
    public function active(string $documentType, bool $appDefault): bool
    {
        return $this->isEnabled($this->moduleOf($documentType)) && ($this->documentSetting($documentType) ?? $appDefault);
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

    /** $enabled null = back to "not set" (the app default applies) */
    public function setDocument(string $documentType, ?bool $enabled, ?int $userId = null): void
    {
        if ($enabled === null) {
            ApprovalDocumentSetting::where('document_type', $documentType)->delete();
        } else {
            ApprovalDocumentSetting::updateOrCreate(['document_type' => $documentType], ['enabled' => $enabled, 'updated_by' => $userId]);
        }
        $this->docs = null;
    }

    private function docs(): array
    {
        if ($this->docs === null) {
            $this->docs = Schema::hasTable('approval_document_settings')
                ? ApprovalDocumentSetting::pluck('enabled', 'document_type')->map(fn ($v) => (bool) $v)->all()
                : [];
        }

        return $this->docs;
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
