<?php

namespace Bizzsol\ApprovalMatrix\Events;

use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;

/** Fired when a request reaches a terminal status (approved / rejected / returned / recalled). */
class ApprovalFinished
{
    public function __construct(public ApprovalRequest $request)
    {
    }
}
