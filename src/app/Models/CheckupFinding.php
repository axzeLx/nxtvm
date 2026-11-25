<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One problem found by a checkup check (replaced on every run). */
class CheckupFinding extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public const RANK = ['crit' => 0, 'warn' => 1, 'info' => 2];
}
