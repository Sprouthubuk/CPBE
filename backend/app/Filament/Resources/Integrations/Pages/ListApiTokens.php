<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\ApiTokenResource;
use App\Models\AdminAction;
use App\Models\ApiToken;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    public ?string $newToken = null;

    public function getSubheading(): ?string
    {
        return 'Read-only access to competitions, orders, players, postal entries and daily totals at /api/v1 (docs/api.md). '
            .'Send it as "Authorization: Bearer <token>". It is shown once: keep it in the tool that uses it.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New Token')
                ->schema([TextInput::make('name')->label('What it is for')->required()->maxLength(80)])
                ->action(function (array $data) {
                    [$t, $plain] = ApiToken::issue($data['name'], auth()->user());
                    AdminAction::record(auth()->user(), 'api_token.created', (string) $t->id, null, ['name' => $t->name]);
                    $this->newToken = $plain;
                    Notification::make()->success()->title('Copy this token now: it is not shown again')->body($plain)->persistent()->send();
                }),
        ];
    }
}
