<?php

namespace App\Filament\Resources\Competitions\Pages;

use App\Filament\Resources\Competitions\CompetitionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompetition extends EditRecord
{
    protected static string $resource = CompetitionResource::class;

    /** No limit per person: follows the number of tickets until the competition is published (then both are fixed). */
    use SavesBundles;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['max_per_player'] = $this->record->max_per_person < $this->record->max_tickets ? $this->record->max_per_person : null;

        return $data + $this->bundleFormData($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $limit = (int) ($data['max_per_player'] ?? 0);
        unset($data['max_per_player']);
        if ($this->record->verity_ref === null && isset($data['max_tickets'])) {
            // SOW 2.2: an optional limit per player; empty means anyone may buy up to every ticket.
            $data['max_per_person'] = $limit > 0 ? min($limit, (int) $data['max_tickets']) : (int) $data['max_tickets'];
        }

        return $this->takeBundleData($data);
    }

    protected function afterSave(): void
    {
        $this->saveBundles($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            CompetitionResource::publishAction(),
            CompetitionResource::openNowAction(),
            CompetitionResource::previewGameAction(),
            \Filament\Actions\Action::make('importPrizes')->label('Import prizes (CSV)')->icon(\Filament\Support\Icons\Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => $this->record->verity_ref === null && $this->record->isInstant())
                ->modalDescription('Replaces the instant-win prizes with the file: columns name, value (pounds), type (site_credit, cash or physical) and quantity. Which tickets win is still decided by Verity at publish.')
                ->schema([\Filament\Forms\Components\FileUpload::make('file')->label('CSV file')->disk('local')->directory('imports')->visibility('private')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(512)->required()])
                ->action(function (array $data) {
                    $disk = \Illuminate\Support\Facades\Storage::disk('local');
                    $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    try {
                        $prizes = \App\Support\PrizeTable::parse((string) $disk->get($path));
                        \App\Support\PrizeTable::replace($this->record, $prizes);
                        \App\Models\AdminAction::record(auth()->user(), 'competition.prizes_imported', (string) $this->record->id, null, ['prizes' => count($prizes)]);
                        \Filament\Notifications\Notification::make()->success()->title(count($prizes).(count($prizes) === 1 ? ' prize' : ' prizes').' imported')->send();
                        $this->redirect(CompetitionResource::getUrl('edit', ['record' => $this->record]));
                    } catch (\InvalidArgumentException $e) {
                        \Filament\Notifications\Notification::make()->danger()->title('Not imported')->body($e->getMessage())->send();
                    } finally {
                        $disk->delete($path);
                    }
                }),
            DeleteAction::make()->visible(fn () => $this->record->verity_ref === null),
        ];
    }
}
