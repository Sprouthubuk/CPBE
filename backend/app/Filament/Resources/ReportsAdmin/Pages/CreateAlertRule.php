<?php

namespace App\Filament\Resources\ReportsAdmin\Pages;

use App\Filament\Resources\ReportsAdmin\AlertRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlertRule extends CreateRecord
{
    protected static string $resource = AlertRuleResource::class;
}
