<?php

namespace Bizzsol\ApprovalMatrix\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalDocumentSetting extends Model
{
    protected $table = 'approval_document_settings';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];
}
