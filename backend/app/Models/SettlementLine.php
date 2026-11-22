<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a payment provider's settlement report, matched against our paid orders (SOW 2.14). */
class SettlementLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settled_on' => 'date'];
    }
}
