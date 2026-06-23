<?php

namespace App\Scripts\Adapters;

class AppleAdapter 
{
    /**
     * Convert one chart's worth of Apple records into apple_main rows.
     * Returns an array: [$rows, $skippedCount]
     */
    public static function adapt(array $records, string $countryCode, string $genreId, string $runDate): array 
    {
        $rows = [];
        $skipped = 0;

        foreach ($records as $rec) {
            $appleId = $rec['apple_id'] ?? null;
            $name = $rec['name'] ?? null;
            $rank = $rec['rank'] ?? null;

            // Validation gate: a row is useless without an id, a name, and a rank.
            if (!$appleId || !$name || $rank === null) {
                $skipped++;
                continue;
            }

            $rows[] = [
                "country_code" => $countryCode,
                "genre_id"     => $genreId,
                "chart_rank"   => (int) $rank,
                "apple_id"     => (string) $appleId,
                "name"         => trim($name),
                "artist"       => trim($rec['artist'] ?? '') ?: null, // Converts empty strings to null
                "artwork"      => $rec['artwork'] ?? null,
                "url"          => $rec['url'] ?? null,
                "match_key"    => Normalize::makeMatchKey($name),
                "run_date"     => $runDate,
            ];
        }

        return [$rows, $skipped];
    }
}