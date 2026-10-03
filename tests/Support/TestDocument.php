<?php

namespace Bizzsol\ApprovalMatrix\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Bizzsol\ApprovalMatrix\Traits\HasApproval;

/** Stand-in approvable (borrows the `users` table purely for an id; never written to). */
class TestDocument extends Model
{
    use HasApproval;

    protected $table = 'users';

    public $fakeAmount = null;

    public $fakeAttributes = [];

    public function approvalDocumentType(): string
    {
        return 'procurement.requisition';
    }

    public function approvalAmount(): ?float
    {
        return $this->fakeAmount;
    }

    public function approvalAttributes(): array
    {
        return $this->fakeAttributes;
    }
}
