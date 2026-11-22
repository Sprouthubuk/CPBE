<?php

namespace App\Filament\Resources\ReportsAdmin\Pages;

use App\Filament\Resources\ReportsAdmin\CostEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListCostEntries extends ListRecords
{
    protected static string $resource = CostEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
