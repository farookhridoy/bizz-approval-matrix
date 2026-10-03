<?php

namespace Bizzsol\ApprovalMatrix\Events;

use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;

class ApprovalStepAssigned
{
    /** @param int[] $userIds */
    public function __construct(public ApprovalRequest $request, public int $level, public array $userIds)
    {
    }
}
