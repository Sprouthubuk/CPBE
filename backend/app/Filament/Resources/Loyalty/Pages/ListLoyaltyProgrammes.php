<?php

namespace App\Filament\Resources\Loyalty\Pages;

use App\Filament\Resources\Loyalty\LoyaltyProgrammeResource;
use Filament\Resources\Pages\ListRecords;

class ListLoyaltyProgrammes extends ListRecords
{
    protected static string $resource = LoyaltyProgrammeResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
