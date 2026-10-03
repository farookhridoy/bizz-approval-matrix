<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RETURNED = 'returned';

    public const RECALLED = 'recalled';

    protected $table = 'approval_requests';

    protected $guarded = [];

    protected $casts = [
        'steps_snapshot' => 'array',
        'completed_at' => 'datetime',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'request_id');
    }

    public function stepAt(int $level): ?array
    {
        foreach ($this->steps_snapshot as $step) {
            if ((int) $step['level'] === $level) {
                return $step;
            }
        }

        return null;
    }

    public function nextLevelAfter(int $level): ?int
    {
        $levels = collect($this->steps_snapshot)->pluck('level')->map(fn ($l) => (int) $l)->sort()->values();

        return $levels->first(fn ($l) => $l > $level);
    }
}
