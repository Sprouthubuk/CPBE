<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\Pages\ListApiTokens;
use App\Models\AdminAction;
use App\Models\ApiToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Amendment 2: read-only API tokens for the Client's own tools. Admin role only. */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'API Tokens';

    protected static ?string $modelLabel = 'API Token';

    protected static ?string $slug = 'api-tokens';

    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('settings');
    }

    public static function canCreate(): bool
    {
        return false; // made by the "New Token" action, which shows the token once
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('name'),
            TextColumn::make('hint')->label('Ends')->formatStateUsing(fn ($s) => '…'.$s),
            TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
            TextColumn::make('created_at')->label('Made')->dateTime('j M Y', 'Europe/London'),
            TextColumn::make('revoked_at')->label('Revoked')->dateTime('j M Y, H:i', 'Europe/London')->placeholder('—'),
        ])->recordActions([
            Action::make('revoke')->label('Revoke')->color('danger')->requiresConfirmation()
                ->visible(fn (ApiToken $t) => $t->revoked_at === null)
                ->action(function (ApiToken $t) {
                    $t->forceFill(['revoked_at' => now()])->save();
                    AdminAction::record(auth()->user(), 'api_token.revoked', (string) $t->id, null, ['name' => $t->name]);
                    Notification::make()->success()->title('Token revoked')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListApiTokens::route('/')];
    }
}
