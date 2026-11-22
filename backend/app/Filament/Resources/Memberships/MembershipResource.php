<?php

namespace App\Filament\Resources\Memberships;

use App\Filament\Resources\Memberships\Pages\ListMemberships;
use App\Models\Membership;
use App\Services\Memberships;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Members (SOW 2.12): who has which tier, renewal dates and failed payments; end one with a reason. */
class MembershipResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('marketing') || Roles::allows('players');
    }

    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Members';

    protected static ?int $navigationSort = 11;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with(['user', 'tier', 'pendingTier'])->where('status', '!=', 'pending'))->columns([
            TextColumn::make('user.email')->label('Player')->searchable(),
            TextColumn::make('tier.name')->label('Tier'),
            TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'active' => 'success', 'past_due' => 'warning', default => 'gray' }),
            TextColumn::make('pay_with')->label('Pays by'),
            TextColumn::make('current_period_end')->label('Renews / ends')->dateTime('j M Y', 'Europe/London'),
            IconColumn::make('cancel_at_period_end')->boolean()->label('Ending'),
            TextColumn::make('pendingTier.name')->label('Moving to')->placeholder('—'),
            TextColumn::make('failed_attempts')->label('Failed'),
            TextColumn::make('end_reason')->limit(30)->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(['active' => 'Active', 'past_due' => 'Payment failed', 'cancelled' => 'Cancelled', 'lapsed' => 'Lapsed']),
            SelectFilter::make('membership_tier_id')->relationship('tier', 'name')->label('Tier'),
        ])->recordActions([
            Action::make('end')->label('End now')->color('danger')->icon(Heroicon::OutlinedXMark)->requiresConfirmation()
                ->visible(fn (Membership $m) => in_array($m->status, Membership::CURRENT, true))
                ->schema([Textarea::make('reason')->required()->maxLength(300)])
                ->action(function (Membership $m, array $data) {
                    app(Memberships::class)->end(auth()->user(), $m, $data['reason']);
                    Notification::make()->success()->title('Membership ended')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberships::route('/')];
    }
}
