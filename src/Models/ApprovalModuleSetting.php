<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalModuleSetting extends Model
{
    protected $table = 'approval_module_settings';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];
}
