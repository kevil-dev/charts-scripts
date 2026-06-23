<?php

namespace App\Scripts\Adapters;

class SpotifyAdapter 
{
    /**
     * Convert one chart's worth of Spotify records into spotify_main rows.
     * Returns an array: [$rows, $skippedCount]
     */
    public static function adapt(array $records, string $countryCode, string $chart, string $runDate): array 
    {
        $rows = [];
        $skipped = 0;

        foreach ($records as $rec) {
            $spotifyId = $rec['spotify_id'] ?? null;
            $name = $rec['name'] ?? null;
            $rank = $rec['rank'] ?? null;

            // Validation gate
            if (!$spotifyId || !$name || $rank === null) {
                $skipped++;
                continue;
            }

            $rows[] = [
                "country_code" => $countryCode,
                "chart"        => $chart,
                "chart_rank"   => (int) $rank,
                "spotify_id"   => (string) $spotifyId,
                "name"         => trim($name),
                "publisher"    => trim($rec['publisher'] ?? '') ?: null,
                "artwork"      => $rec['artwork'] ?? null,
                "rank_move"    => $rec['rank_move'] ?? null, // Spotify gives this free
                "match_key"    => Normalize::makeMatchKey($name),
                "run_date"     => $runDate,
            ];
        }

        return [$rows, $skipped];
    }
}