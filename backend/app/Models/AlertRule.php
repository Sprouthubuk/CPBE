<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A configurable alert (SOW 2.14), checked every hour; it emails the admins at most once a day per rule. */
class AlertRule extends Model
{
    public const KINDS = [
        'break_even_risk' => 'Competition forecast to finish below break-even (threshold: hours to close, e.g. 48)',
        'underperforming' => 'Competition behind pace (threshold: % sold when half its time has gone, e.g. 30)',
        'marketing_cost_high' => 'Marketing cost over this % of revenue in the last 7 days',
        'promo_budget' => 'Promotional cost over this % of revenue in the last 7 days',
        'unusual_activity' => 'More than this many orders from one IP address in an hour',
        'sales_drop' => 'Sales today down more than this % on the same day last week',
        // Fraud (amendment 3)
        'failed_payments' => 'More than this many declined card payments in the last hour, site-wide',
        'shared_device' => 'More than this many accounts joined from one device in the last 7 days',
        'shared_card' => 'One card used by more than this many accounts in the last 30 days',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_fired_at' => 'datetime'];
    }
}
