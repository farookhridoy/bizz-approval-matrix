<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalDelegation extends Model
{
    use SoftDeletes;

    protected $table = 'approval_delegations';

    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    /** Delegations whose window includes the given day (default today). */
    public function scopeActiveOn(Builder $query, ?\DateTimeInterface $on = null): Builder
    {
        $day = ($on ? \Carbon\Carbon::instance($on) : now())->toDateString();

        return $query->where('starts_on', '<=', $day)->where('ends_on', '>=', $day);
    }
}
