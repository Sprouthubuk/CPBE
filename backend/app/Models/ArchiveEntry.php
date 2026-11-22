<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One ticket on an old-site entry list, with its masked name (vault:import-archive). */
class ArchiveEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
