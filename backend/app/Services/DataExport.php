<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Amendment 27: a full copy of the Platform's database, every month and on request, sent to storage the Client
 * controls (`DATA_EXPORT_DISK`, normally `client_export`: an S3-compatible bucket in the Client's own account).
 *
 * One zip, one JSON Lines file per table plus `manifest.json` (tables, row counts, schema version, time). Secrets
 * the Client doesn't need and that would let someone sign in as a person are left out (passwords, two-factor
 * secrets, session tokens, API token hashes, webhook secrets); everything else, Verity's records included, is kept.
 * With `DATA_EXPORT_PASSWORD` set the zip is AES-256 encrypted.
 */
final class DataExport
{
    /** Framework tables with nothing of the Client's. */
    private const SKIP_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens', 'migrations'];

    /** Columns never exported. */
    private const SKIP_COLUMNS = [
        'users' => ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'],
        'api_tokens' => ['token_hash'],
        'webhook_endpoints' => ['secret'],
    ];

    /** Make the export and send it. Returns [path on the disk, rows written]. */
    public function run(?string $disk = null): array
    {
        $disk ??= (string) config('services.data_export.disk', 'local');
        $name = 'platform-export-'.now('Europe/London')->format('Y-m-d-His').'.zip';
        $tmp = tempnam(sys_get_temp_dir(), 'cpexp');
        $zip = new \ZipArchive;
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not start the export file.');
        }
        $password = (string) config('services.data_export.password', '');
        if ($password !== '') {
            $zip->setPassword($password);
        }
        $files = [];
        $manifest = ['madeAt' => now()->toIso8601ZuluString(), 'app' => config('app.name'), 'format' => 'One JSON object per line, money in whole pence, times in UTC.', 'tables' => []];
        $total = 0;
        foreach ($this->tables() as $table) {
            $skip = self::SKIP_COLUMNS[$table] ?? [];
            $cols = array_values(array_diff(Schema::getColumnListing($table), $skip));
            $file = tempnam(sys_get_temp_dir(), 'cpt');
            $files[] = $file;
            $out = fopen($file, 'w');
            $n = 0;
            $q = DB::table($table)->select($cols);
            $key = in_array('id', $cols, true) ? 'id' : $cols[0];
            $q->orderBy($key)->chunk(1000, function ($rows) use ($out, &$n) {
                foreach ($rows as $r) {
                    fwrite($out, json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                    $n++;
                }
            });
            fclose($out);
            $zip->addFile($file, $table.'.jsonl');
            if ($password !== '') {
                $zip->setEncryptionName($table.'.jsonl', \ZipArchive::EM_AES_256);
            }
            $manifest['tables'][$table] = ['rows' => $n, 'columns' => $cols, 'left out' => $skip];
            $total += $n;
        }
        $manifest['schemaVersion'] = DB::table('migrations')->max('migration');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($password !== '') {
            $zip->setEncryptionName('manifest.json', \ZipArchive::EM_AES_256);
        }
        $zip->close();
        $path = 'exports/'.$name;
        $stream = fopen($tmp, 'r');
        try {
            if (! Storage::disk($disk)->writeStream($path, $stream)) {
                throw new \RuntimeException("The export could not be saved to the '{$disk}' storage.");
            }
        } finally {
            fclose($stream);
            @unlink($tmp);
            foreach ($files as $f) {
                @unlink($f);
            }
        }

        return [$path, $total];
    }

    /** @return list<string> */
    public function tables(): array
    {
        $names = array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables());

        return array_values(array_filter($names, fn ($t) => ! in_array($t, self::SKIP_TABLES, true) && ! str_starts_with($t, 'sqlite_')));
    }
}
