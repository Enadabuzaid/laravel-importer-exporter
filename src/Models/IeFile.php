<?php

namespace Enadstack\ImporterExporter\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IeFile extends Model
{
    protected $table = 'ie_files';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'type','direction','status','disk','path','original_name','size',
        'total_rows','success_rows','failed_rows','options','user_id',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(IeRow::class, 'file_id');
    }
}