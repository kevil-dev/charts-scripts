<?php

namespace App\Scripts\Adapters;

class YoutubeAdapter 
{
    /**
     * Convert one chart's worth of YouTube records into youtube_main rows.
     * Returns an array: [$rows, $skippedCount]
     */
    public static function adapt(array $records, string $countryCode, string $runDate): array 
    {
        $rows = [];
        $skipped = 0;

        foreach ($records as $rec) {
            $youtubeId = $rec['youtube_playlist_id'] ?? null;
            $name = $rec['name'] ?? null;
            $rank = $rec['rank'] ?? null;

            // Validation gate
            if (!$youtubeId || !$name || $rank === null) {
                $skipped++;
                continue;
            }

            $rows[] = [
                "country_code" => $countryCode,
                "chart_rank"   => (int) $rank,
                "youtube_id"   => (string) $youtubeId,
                "name"         => trim($name),
                "channel"      => trim($rec['channel'] ?? '') ?: null,
                "artwork"      => $rec['artwork'] ?? null,
                "channel_url"  => $rec['channel_url'] ?? null,
                "match_key"    => Normalize::makeMatchKey($name),
                "run_date"     => $runDate,
            ];
        }

        return [$rows, $skipped];
    }
}