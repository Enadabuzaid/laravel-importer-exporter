<?php

namespace Enadstack\ImporterExporter\Models;

use Illuminate\Database\Eloquent\Model;

class IeRow extends Model
{
    protected $table = 'ie_rows';

    protected $fillable = [
        'file_id','row_index','payload','status','message','processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}