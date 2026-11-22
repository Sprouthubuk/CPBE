<?php

namespace App\Filament\Resources\Competitions\Pages;

use App\Filament\Resources\Competitions\CompetitionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompetition extends CreateRecord
{
    protected static string $resource = CompetitionResource::class;

    use SavesBundles;

    /** SOW 2.2: an optional maximum per player; empty means anyone may buy up to every ticket. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $max = (int) ($data['max_tickets'] ?? 1);
        $limit = (int) ($data['max_per_player'] ?? 0);
        unset($data['max_per_player']);

        return $this->takeBundleData(['max_per_person' => $limit > 0 ? min($limit, $max) : $max] + $data);
    }

    protected function afterCreate(): void
    {
        $this->saveBundles($this->record);
    }
}
