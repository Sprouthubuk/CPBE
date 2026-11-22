<?php

namespace App\Support;

/** Reads a small uploaded CSV into rows keyed by its lower-case header. */
final class Csv
{
    /** @return list<array<string, string>> */
    public static function rows(string $text, int $max = 5000): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text; // Excel's byte-order mark
        $lines = preg_split('/\r\n|\n|\r/', trim($text)) ?: [];
        $head = null;
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, ',', '"', ''));
            if ($head === null) {
                $head = array_map(fn ($h) => strtolower(str_replace([' ', '-'], '_', $h)), $cells);

                continue;
            }
            if (count($out) >= $max) {
                throw new \InvalidArgumentException("The file has more than {$max} rows.");
            }
            $out[] = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
        }

        return $out;
    }
}
