<?php

namespace App\Models\Inv;

/** Inventory table inv_alarms (latest state, replaced on every collection). */
class Alarm extends InvModel
{
    protected $table = 'inv_alarms';
}
