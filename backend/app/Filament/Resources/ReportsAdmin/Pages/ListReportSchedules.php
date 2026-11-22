<?php

namespace App\Filament\Resources\ReportsAdmin\Pages;

use App\Filament\Resources\ReportsAdmin\ReportScheduleResource;
use Filament\Resources\Pages\ListRecords;

class ListReportSchedules extends ListRecords
{
    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
