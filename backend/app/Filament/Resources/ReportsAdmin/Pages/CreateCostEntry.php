<?php

namespace App\Filament\Resources\ReportsAdmin\Pages;

use App\Filament\Resources\ReportsAdmin\CostEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCostEntry extends CreateRecord
{
    protected static string $resource = CostEntryResource::class;
}
