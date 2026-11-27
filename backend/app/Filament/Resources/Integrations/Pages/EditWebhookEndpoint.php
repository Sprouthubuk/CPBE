<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\WebhookEndpointResource;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    protected static string $resource = WebhookEndpointResource::class;
}
