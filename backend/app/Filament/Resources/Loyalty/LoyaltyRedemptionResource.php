<?php

namespace App\Filament\Resources\Loyalty;

use App\Filament\Resources\Loyalty\Pages\ListLoyaltyRedemptions;
use App\Models\LoyaltyRedemption;
use App\Services\Loyalty;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Rewards players have claimed with points. Custom rewards wait here until the Client fulfils or cancels them. */
class LoyaltyRedemptionResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('marketing') || Roles::allows('verification');
    }

    protected static ?string $model = LoyaltyRedemption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Rewards Claimed';

    protected static ?int $navigationSort = 9;

    public static function getNavigationBadge(): ?string
    {
        $n = LoyaltyRedemption::query()->where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with(['user', 'reward']))->columns([
            TextColumn::make('created_at')->label('Claimed')->dateTime('j M Y, H:i', 'Europe/London'),
            TextColumn::make('user.email')->label('Player')->searchable(),
            TextColumn::make('reward.name')->label('Reward'),
            TextColumn::make('points')->numeric(),
            TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'pending' => 'warning', 'cancelled' => 'gray', default => 'success' }),
            TextColumn::make('note')->limit(40)->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(['pending' => 'Waiting', 'done' => 'Done', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancelled']),
        ])->recordActions([
            Action::make('fulfil')->label('Mark fulfilled')->icon(Heroicon::OutlinedCheck)->visible(fn (LoyaltyRedemption $r) => $r->status === 'pending')
                ->schema([Textarea::make('note')->maxLength(300)->label('Note (e.g. tracking number)')])
                ->action(function (LoyaltyRedemption $r, array $data) {
                    app(Loyalty::class)->fulfil(auth()->user(), $r, $data['note'] ?? null);
                    Notification::make()->success()->title('Marked fulfilled')->send();
                }),
            Action::make('cancel')->label('Cancel and return points')->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                ->visible(fn (LoyaltyRedemption $r) => $r->status === 'pending')
                ->schema([Textarea::make('reason')->required()->maxLength(300)])
                ->action(function (LoyaltyRedemption $r, array $data) {
                    app(Loyalty::class)->cancel(auth()->user(), $r, $data['reason']);
                    Notification::make()->success()->title('Cancelled; points returned')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLoyaltyRedemptions::route('/')];
    }
}
