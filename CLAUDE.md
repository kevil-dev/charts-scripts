# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A PHP pipeline that scrapes podcast chart rankings from Apple, Spotify, and YouTube, saves them as JSON files, then bulk-loads them into a MySQL database. Runs daily (manually or via cron).

## Commands

```bash
# Run the full pipeline (scrape all platforms, then load to DB)
php run_pipeline.php

# Run a single scraper
php scrapers/apple_test.php
php scrapers/spotify_test.php
php scrapers/youtube_test.php

# Run only the DB loader (reads existing data/ files)
php loader.php
```

There are no tests, no build step, and no Composer dependencies — plain PHP with built-in extensions (`curl`, `PDO`/`pdo_mysql`).

## Database Configuration

`loader.php` reads from environment variables with these defaults:

| Env var | Default |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_USER` | `root` |
| `DB_PASSWORD` | *(empty)* |
| `DB_NAME` | `charts` |
| `DATA_DIR` | `./data` |
| `LOG_DIR` | `./logs` |
| `COUNTRY_CODE_CASE` | `upper` |

## Architecture

### Pipeline flow

```
run_pipeline.php
  ├── scrapers/apple_test.php   → data/apple/{country}/{genre}.json
  ├── scrapers/spotify_test.php → data/spotify/{country}/{chart}.json
  ├── scrapers/youtube_test.php → data/youtube/{country}/top.json
  └── loader.php                → MySQL (apple_main / spotify_main / youtube_main / history)
```

`run_pipeline.php` runs each stage as a subprocess (`exec("php script.php 2>&1")`), captures output, and tracks success/failure via exit codes. Stages always continue even if one fails.

### Scrapers

Each scraper (`scrapers/*.php`) follows the same pattern:
1. Load `config/{platform}_config.json` (countries + genres/charts + fetch limit)
2. Acquire a file lock (`.{platform}.lock`) to prevent overlapping runs
3. Build a jobs queue of `(country, genre)` pairs
4. Execute concurrently via `curl_multi` (5 workers, 0.3 s polite pause, 3 retries on failure)
5. Write output atomically: write to `{path}.tmp`, then `rename()` to final path

Spotify 404 responses are silently treated as "chart not available in this country" — not an error.

### Loader (`loader.php`)

Reads all `data/*/*/*.json` files. For each file, the path encodes the metadata: `data/{platform}/{country}/{chart}.json`.

Per-chart loading is transactional:
1. `DELETE` existing rows for that `(country, chart)` from the main table
2. Bulk-`INSERT` new rows into the main table
3. Bulk-`INSERT … ON DUPLICATE KEY UPDATE` into the `history` table
4. `COMMIT` (or `ROLLBACK` on failure, leaving yesterday's data intact)

Rank move (`UP` / `DOWN` / `UNCHANGED` / `NEW`) is computed before the transaction by querying the most recent prior day's `history` rows for that chart.

### Adapters (`adapters/`)

Each adapter class (`AppleAdapter`, `SpotifyAdapter`, `YoutubeAdapter`) converts raw API JSON into typed DB rows and returns `[$rows, $skippedCount]`. `Normalize::makeMatchKey()` produces a lowercased, punctuation-stripped string used across all platforms to identify the same show.

### DB Tables

| Table | Key columns |
|---|---|
| `apple_main` | `country_code`, `genre_id`, `chart_rank`, `apple_id`, `rank_move` |
| `spotify_main` | `country_code`, `chart`, `chart_rank`, `spotify_id`, `rank_move` |
| `youtube_main` | `country_code`, `chart_rank`, `youtube_id`, `rank_move` |
| `history` | `platform`, `country_code`, `chart`, `external_id`, `chart_rank`, `run_date` |
| `genres` | `platform`, `native_id`, `display_name` |

### Logs

Each run produces:
- `logs/apple.log`, `logs/spotify.log`, `logs/youtube.log` — scraper output (appended)
- `logs/pipeline_{date}.log` — orchestrator summary
- `logs/loader_{date}.log` — per-file load results
- `logs/manifest_{date}.json` — machine-readable summary with per-file status, row counts, and totals

## Adding a New Platform

1. Create `config/{platform}_config.json`
2. Create `scrapers/{platform}_test.php` (follow the `curl_multi` pattern)
3. Create `adapters/{platform}_adapter.php` implementing a static `adapt()` method
4. Add an entry to `$platformSpec` in `loader.php`
5. Add the scraper stage to `$stages` in `run_pipeline.php`

# CLAUDE.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
