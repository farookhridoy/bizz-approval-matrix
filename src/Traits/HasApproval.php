<?php

namespace Bizzsol\ApprovalMatrix\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;

/**
 * Put on any approvable model. Optional hooks the model may define:
 *   approvalAmount(): float|null        - drives amount conditions / skip rules
 *   approvalAttributes(): array         - e.g. ['purchase_type' => 'foreign']
 * and must define approvalDocumentType(): string  (e.g. 'procurement.requisition').
 */
trait HasApproval
{
    abstract public function approvalDocumentType(): string;

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    public function currentApproval(): ?ApprovalRequest
    {
        return $this->approvalRequests()->latest('id')->first();
    }

    public function submitForApproval(int $requesterId, array $contextOverride = []): ApprovalRequest
    {
        return app(ApprovalEngine::class)->submit($this, $requesterId, $this->approvalDocumentType(), $contextOverride);
    }
}
