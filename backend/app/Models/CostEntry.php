<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A cost the platform can't see by itself (SOW 2.14), marked actual, estimated or forecast. */
class CostEntry extends Model
{
    public const CATEGORIES = ['prizes' => 'Prizes', 'marketing' => 'Marketing / ad spend', 'payment_fees' => 'Payment fees', 'operating' => 'Running costs', 'other' => 'Other'];

    public const BASES = ['actual' => 'Actual (verified)', 'estimated' => 'Estimated', 'forecast' => 'Forecast'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
