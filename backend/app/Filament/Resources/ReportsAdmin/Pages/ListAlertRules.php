<?php

namespace App\Filament\Resources\ReportsAdmin\Pages;

use App\Filament\Resources\ReportsAdmin\AlertRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListAlertRules extends ListRecords
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
