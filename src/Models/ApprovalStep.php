<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalStep extends Model
{
    public const TYPE_REPORTING_HEAD = 'reporting_head';

    public const TYPE_CUSTOM_USER = 'custom_user';

    public const TYPE_SPECIFIC_USER = 'specific_user';

    public const TYPE_ROLE = 'role';

    public const TYPE_PERMISSION = 'permission';

    protected $table = 'approval_steps';

    protected $guarded = [];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'skip_condition' => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function customUsers(): HasMany
    {
        return $this->hasMany(ApprovalStepUser::class, 'step_id');
    }
}
