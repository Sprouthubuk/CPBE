<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\WebhookEndpointResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
