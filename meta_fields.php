<?php

namespace App\Scripts;

/**
 * meta_fields.php
 * -----------------------------------------------------------------------------
 * SINGLE SOURCE OF TRUTH for podcast metadata fields.
 *
 * Every metadata field is declared once, here, with:
 *   - tier:   which subscription level may SEE this field ('free'|'pro'|'elite')
 *   - type:   how the generator should fabricate a dummy value
 *   - column: the DB column name in `podcast_meta`
 *
 * This file is consumed by TWO places:
 *   1. generate_meta.php  -> ignores `tier`, generates ALL fields for every show.
 *                            (Tiering is a read-time concern, never a data concern.)
 *   2. The model read path -> uses `tier` to project only the columns a given
 *                            subscription level is allowed to receive.
 *
 * To add a field, or move it between tiers, edit ONLY this file.
 * -----------------------------------------------------------------------------
 */

final class MetaFields
{
    public const TIER_FREE  = 'free';
    public const TIER_PRO   = 'pro';
    public const TIER_ELITE = 'elite';

    /** Tier hierarchy: each tier includes everything from the tiers before it. */
    public const TIER_ORDER = [
        self::TIER_FREE  => 0,
        self::TIER_PRO   => 1,
        self::TIER_ELITE => 2,
    ];

    /**
     * Field catalogue. Order here is also the natural column order.
     *
     * `type` values are interpreted by generate_meta.php:
     *   short_text | long_text | genre | publisher | language | freq |
     *   advisory | url | feed_url | int_range:<min>:<max> | float_range:<min>:<max> |
     *   date_past | date_recent | json_history | json_footprint
     */
    public static function catalogue(): array
    {
        return [
            // ---- FREE: identity. "Is this the show I mean?" --------------------
            ['column' => 'description',                 'tier' => self::TIER_FREE,  'type' => 'short_text'],
            ['column' => 'primary_genre',               'tier' => self::TIER_FREE,  'type' => 'genre'],
            ['column' => 'author',                      'tier' => self::TIER_FREE,  'type' => 'publisher'],
            ['column' => 'episode_count',               'tier' => self::TIER_FREE,  'type' => 'int_range:8:1200'],

            // ---- PRO: depth. "Tell me about this show." ------------------------
            ['column' => 'long_description',            'tier' => self::TIER_PRO,   'type' => 'long_text'],
            ['column' => 'language',                    'tier' => self::TIER_PRO,   'type' => 'language'],
            ['column' => 'first_published_date',        'tier' => self::TIER_PRO,   'type' => 'date_past'],
            ['column' => 'last_published_date',         'tier' => self::TIER_PRO,   'type' => 'date_recent'],
            ['column' => 'release_frequency',           'tier' => self::TIER_PRO,   'type' => 'freq'],
            ['column' => 'avg_episode_duration_minutes','tier' => self::TIER_PRO,   'type' => 'int_range:12:135'],
            ['column' => 'content_advisory_rating',     'tier' => self::TIER_PRO,   'type' => 'advisory'],
            ['column' => 'website_url',                 'tier' => self::TIER_PRO,   'type' => 'url'],
            ['column' => 'feed_url',                    'tier' => self::TIER_PRO,   'type' => 'feed_url'],

            // ---- ELITE: intelligence. "Give me the decision-grade data." -------
            ['column' => 'rating_average',              'tier' => self::TIER_ELITE, 'type' => 'float_range:3.4:5.0'],
            ['column' => 'rating_count',                'tier' => self::TIER_ELITE, 'type' => 'int_range:11:84000'],
            ['column' => 'rank_history',                'tier' => self::TIER_ELITE, 'type' => 'json_history'],
            ['column' => 'global_footprint',            'tier' => self::TIER_ELITE, 'type' => 'json_footprint'],
        ];
    }

    /**
     * Columns visible to a given tier (that tier + all lower tiers).
     * Always includes match_key so callers can join/identify the row.
     *
     * @return string[] column names
     */
    public static function visibleColumns(string $tier): array
    {
        $ceiling = self::TIER_ORDER[$tier] ?? 0;

        $cols = ['match_key'];
        foreach (self::catalogue() as $field) {
            if (self::TIER_ORDER[$field['tier']] <= $ceiling) {
                $cols[] = $field['column'];
            }
        }
        return $cols;
    }
}
