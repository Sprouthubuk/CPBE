<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\WebhookEndpointResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookEndpoint extends CreateRecord
{
    protected static string $resource = WebhookEndpointResource::class;
}
