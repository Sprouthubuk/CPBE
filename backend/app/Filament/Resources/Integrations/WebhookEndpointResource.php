<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\Pages\CreateWebhookEndpoint;
use App\Filament\Resources\Integrations\Pages\EditWebhookEndpoint;
use App\Filament\Resources\Integrations\Pages\ListWebhookEndpoints;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Amendment 2: where the Platform sends events. Admin role only. */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTopRightOnSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?string $modelLabel = 'Webhook';

    protected static ?string $slug = 'webhooks';

    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('settings');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80),
            TextInput::make('url')->label('Address')->required()->url()->startsWith(['https://'])->maxLength(500)
                ->helperText('Must be https. Each event is a JSON POST signed with X-Signature: sha256=<HMAC of the body with the secret>.'),
            CheckboxList::make('events')->options(WebhookEndpoint::EVENTS)->required()->columns(2),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'), TextColumn::make('url')->label('Address')->limit(50),
            TextColumn::make('events')->badge(),
            TextColumn::make('waiting')->label('Waiting')->state(fn (WebhookEndpoint $e) => $e->deliveries()->whereNull('delivered_at')->whereNotNull('next_attempt_at')->count()),
            TextColumn::make('failed')->label('Gave up')->state(fn (WebhookEndpoint $e) => $e->deliveries()->whereNull('delivered_at')->whereNull('next_attempt_at')->count()),
            IconColumn::make('active')->boolean(),
        ])->recordActions([
            EditAction::make(),
            Action::make('secret')->label('Secret')->modalSubmitAction(false)
                ->modalDescription(fn (WebhookEndpoint $e) => 'Signing secret: '.$e->secret),
            Action::make('test')->label('Send Test')->action(function (WebhookEndpoint $e) {
                $e->deliveries()->create(['event' => 'test', 'payload' => ['message' => 'A test from the admin.'], 'next_attempt_at' => now()]);
                [$sent] = app(Webhooks::class)->send();
                Notification::make()->{$sent ? 'success' : 'warning'}()->title($sent ? 'Test delivered' : 'The test was not accepted: it will retry')->send();
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWebhookEndpoints::route('/'), 'create' => CreateWebhookEndpoint::route('/create'), 'edit' => EditWebhookEndpoint::route('/{record}/edit')];
    }
}
