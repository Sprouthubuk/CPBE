<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\DataExport;
use Illuminate\Console\Command;

class ExportData extends Command
{
    protected $signature = 'platform:export-data {--disk= : Storage disk (default DATA_EXPORT_DISK)}';

    protected $description = 'Send a full copy of the database to the Client\'s own storage (amendment 27)';

    public function handle(DataExport $export): int
    {
        try {
            [$path, $rows] = $export->run($this->option('disk') ?: null);
        } catch (\Throwable $e) {
            report($e);
            $this->error('The export failed: '.$e->getMessage());

            return self::FAILURE;
        }
        Setting::put('data_export_last', now()->toIso8601ZuluString().' '.$path);
        $this->info("Exported {$rows} rows to {$path}.");

        return self::SUCCESS;
    }
}
