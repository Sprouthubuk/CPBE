<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A summary report emailed on a schedule (SOW 2.14). */
class ReportSchedule extends Model
{
    public const FREQUENCIES = ['daily' => 'Every morning', 'weekly' => 'Every Monday', 'monthly' => 'The 1st of each month'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_sent_at' => 'datetime'];
    }
}
