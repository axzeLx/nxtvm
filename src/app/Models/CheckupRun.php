<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Latest checkup run of a vCenter (one row per vCenter). */
class CheckupRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ran_at' => 'datetime', 'inventory_at' => 'datetime', 'results' => 'array'];
    }

    public function vcenter(): BelongsTo
    {
        return $this->belongsTo(VCenter::class, 'vcenter_id');
    }
}
