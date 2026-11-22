<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentAssignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
