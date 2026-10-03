<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalWorkflow extends Model
{
    use SoftDeletes;

    public const STATE_DRAFT = 'draft';

    public const STATE_ACTIVE = 'active';

    public const STATE_ARCHIVED = 'archived';

    protected $table = 'approval_workflows';

    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'status' => 'boolean',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'workflow_id')->orderBy('level');
    }

    /** Specificity score: department > unit > company > global. */
    public function specificity(): int
    {
        return ($this->company_id ? 1 : 0) + ($this->unit_id ? 2 : 0) + (($this->department_id || $this->master_department_id) ? 4 : 0);
    }
}
