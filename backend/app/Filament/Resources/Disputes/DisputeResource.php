<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Support\Money;
use App\Models\Dispute;
use App\Services\Disputes;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Chargebacks and disputes (SOW 2.15). Logged from an order (Orders → Log a dispute); each has an evidence pack. */
class DisputeResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('finance');
    }

    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Payments';

    protected static ?string $navigationLabel = 'Disputes';

    public static function getNavigationBadge(): ?string
    {
        $n = Dispute::query()->whereIn('status', ['open', 'evidence_sent'])->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('order.user'))->columns([
            TextColumn::make('created_at')->label('Logged')->dateTime('j M Y', 'Europe/London'),
            TextColumn::make('order.number')->label('Order')->searchable(),
            TextColumn::make('order.user.email')->label('Player')->searchable(),
            TextColumn::make('kind')->formatStateUsing(fn ($state) => Dispute::KINDS[$state] ?? $state),
            TextColumn::make('reference')->placeholder('—')->searchable(),
            TextColumn::make('amount_pence')->label('Amount')->formatStateUsing(fn ($state) => Money::show($state)),
            TextColumn::make('reason')->limit(40),
            TextColumn::make('respond_by')->date('j M Y')->placeholder('—')->color(fn (Dispute $d) => $d->respond_by?->isPast() && $d->status === 'open' ? 'danger' : null),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Dispute::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) { 'won' => 'success', 'lost', 'accepted' => 'danger', default => 'warning' }),
        ])->filters([
            SelectFilter::make('status')->options(Dispute::STATUSES),
        ])->recordActions([
            Action::make('evidence')->label('Evidence pack')->icon(Heroicon::OutlinedDocumentText)
                ->url(fn (Dispute $d) => route('admin.evidence', $d->order->number))->openUrlInNewTab(),
            Action::make('status')->label('Update')->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    Select::make('status')->options(Dispute::STATUSES)->required()->default(fn (Dispute $d) => $d->status)
                        ->helperText('A chargeback marked Lost or Accepted marks the order refunded, which reverses its referral and loyalty rewards.'),
                    Textarea::make('notes')->maxLength(1000),
                ])
                ->action(function (Dispute $d, array $data) {
                    app(Disputes::class)->settle(auth()->user(), $d, $data['status'], $data['notes'] ?? null);
                    Notification::make()->success()->title('Dispute updated')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDisputes::route('/')];
    }
}
