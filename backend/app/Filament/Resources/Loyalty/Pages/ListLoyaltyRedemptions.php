<?php

namespace App\Filament\Resources\Loyalty\Pages;

use App\Filament\Resources\Loyalty\LoyaltyRedemptionResource;
use Filament\Resources\Pages\ListRecords;

class ListLoyaltyRedemptions extends ListRecords
{
    protected static string $resource = LoyaltyRedemptionResource::class;
}
