<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rows to a downloadable Excel or CSV file (SOW 2.14 exports). The first row is the header. */
final class Spreadsheet
{
    /** @param list<list<scalar|null>> $rows */
    public static function download(array $rows, string $name, string $format): StreamedResponse
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '-', $name).'.'.($format === 'xlsx' ? 'xlsx' : 'csv');
        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($rows) {
                $w = new XlsxWriter;
                $w->openToFile('php://output');
                foreach ($rows as $r) {
                    $w->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $r)));
                }
                $w->close();
            }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $r) {
                // A cell starting with = + - @ could run as a formula in a spreadsheet: prefix it.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $r));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
