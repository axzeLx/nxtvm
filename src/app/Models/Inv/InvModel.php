<?php

namespace App\Models\Inv;

use App\Models\VCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Base for inventory rows: read-only from the app's point of view, written in bulk by the Ingestor. */
abstract class InvModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'maintenance' => 'boolean', 'template' => 'boolean', 'accessible' => 'boolean', 'acknowledged' => 'boolean',
            'ha_enabled' => 'boolean', 'drs_enabled' => 'boolean',
            'boot_time' => 'datetime', 'expires_at' => 'datetime', 'valid_from' => 'datetime', 'valid_to' => 'datetime',
            'triggered_at' => 'datetime',
        ];
    }

    /** Route parameters for detail pages: stable across collections (row ids are not). */
    public function ref(): array
    {
        return ['vc' => $this->vcenter_id, 'moid' => $this->moid];
    }

    public function vcenter(): BelongsTo
    {
        return $this->belongsTo(VCenter::class, 'vcenter_id');
    }
}
