<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalAction extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SKIPPED = 'skipped';

    public const SUPERSEDED = 'superseded';

    public const RECALLED = 'recalled';

    protected $table = 'approval_actions';

    protected $guarded = [];

    protected $casts = ['acted_at' => 'datetime'];
}
