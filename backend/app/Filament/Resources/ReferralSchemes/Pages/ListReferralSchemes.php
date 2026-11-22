<?php

namespace App\Filament\Resources\ReferralSchemes\Pages;

use App\Filament\Resources\ReferralSchemes\ReferralSchemeResource;
use Filament\Resources\Pages\ListRecords;

class ListReferralSchemes extends ListRecords
{
    protected static string $resource = ReferralSchemeResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
