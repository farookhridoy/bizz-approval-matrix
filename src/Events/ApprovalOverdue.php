<?php

namespace Bizzsol\ApprovalMatrix\Events;

use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;

/** A pending assignment has been waiting longer than its step's SLA (fired by `approval:remind`). */
class ApprovalOverdue
{
    public function __construct(public ApprovalRequest $request, public ApprovalAction $action, public int $hoursWaiting, public int $slaHours)
    {
    }
}
