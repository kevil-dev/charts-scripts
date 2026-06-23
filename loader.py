"""
loader.py  —  Podcast Charts pipeline, write path.

Takes the JSON files the fetch scripts dropped under data/{platform}/{country}/{slug}.json,
runs each through its adapter, and writes the result to MySQL.

What it guarantees (the parts worth defending):
  * Per-chart transactional swap. For each chart we DELETE that chart's rows from the
    platform `main` table and INSERT the fresh ones inside ONE transaction. So dropped-off
    shows actually disappear (a plain upsert can't do that), and a visitor never sees an
    empty chart mid-write — they keep yesterday's rows until COMMIT.
  * Idempotent. Re-running the same day is a no-op-equivalent: the swap rebuilds `main`
    from scratch and `history` uses ON DUPLICATE KEY so the same (run_date) row just updates.
  * Rank arrows for Apple/YouTube are computed against the PREVIOUS RUN of that exact chart,
    not "yesterday". That keeps it correct for Apple (daily), YouTube (weekly), and any day
    the pipeline didn't run.
  * A missing / empty / broken file is skipped and logged — never crashes the run.
    yesterday's data for that chart stays untouched (we never opened its transaction).
  * Every file's loaded/skipped counts go into a per-run manifest (logs/manifest_<date>.json).

Assumptions you may need to tweak (kept at the top so they're easy to find):
  1. Your adapters return (rows, skipped) where each row is a dict whose KEYS MATCH THE
     COLUMN NAMES of the platform's main table. If your keys differ, edit ALLOWED_COLUMNS
     / the *_id key in PLATFORM_SPEC below — nothing else changes.
  2. Folder country code -> DB country_code. We uppercase by default (ISO 3166-1 alpha-2,
     matches the breadcrumb "US"). Flip COUNTRY_CODE_CASE if your countries table is lower.
  3. apple_main and youtube_main need a `rank_move VARCHAR(12)` column to store the computed
     arrow (spotify_main already has one). Run ONCE before first load:
         ALTER TABLE apple_main   ADD COLUMN rank_move VARCHAR(12) AFTER chart_rank;
         ALTER TABLE youtube_main ADD COLUMN rank_move VARCHAR(12) AFTER chart_rank;
"""

from __future__ import annotations

import json
import logging
import os
import sys
from datetime import date, datetime
from pathlib import Path

# ----- adapters (already built + tested) -----------------------------------
try:
    from adapters.apple_adapter import adapt_apple
    from adapters.spotify_adapter import adapt_spotify
    from adapters.youtube_adapter import adapt_youtube
except ImportError as exc:  # pragma: no cover - environment guard
    sys.exit(
        f"Could not import an adapter ({exc}). Ensure the `adapters` package exists and "
        f"contains apple_adapter.py / spotify_adapter.py / youtube_adapter.py, and run from the project root."
    )

# ----- db driver -----------------------------------------------------------
try:
    import pymysql
    from pymysql.cursors import DictCursor
except ImportError:  # pragma: no cover
    sys.exit("PyMySQL is not installed.  ->  pip install pymysql")


# ===========================================================================
# CONFIG  (env vars override the defaults; defaults suit a stock WAMP install)
# ===========================================================================
DB = dict(
    host=os.getenv("DB_HOST", "127.0.0.1"),
    port=int(os.getenv("DB_PORT", "3306")),
    user=os.getenv("DB_USER", "root"),
    password=os.getenv("DB_PASSWORD", ""),
    database=os.getenv("DB_NAME", "charts"),
    charset="utf8mb4",            # non-negotiable, or non-English text corrupts
    cursorclass=DictCursor,
    autocommit=False,             # we manage transactions per chart, by hand
)

DATA_DIR = Path(os.getenv("DATA_DIR", "data"))
LOG_DIR = Path(os.getenv("LOG_DIR", "logs"))
COUNTRY_CODE_CASE = os.getenv("COUNTRY_CODE_CASE", "upper")  # "upper" | "lower" | "asis"
RUN_DATE = date.today()

# Per-platform wiring. Adding a 4th platform later = one more entry here + an adapter.
PLATFORM_SPEC = {
    "apple": {
        "main_table": "apple_main",
        "id_col": "apple_id",        # external id column on the main table + in history
        "chart_col": "genre_id",     # the column that scopes a chart inside main
        "has_arrow": True,           # Apple gives no move field -> we compute it
        "adapter": lambda records, country, chart: adapt_apple(records, country, chart, RUN_DATE),
        "allowed_columns": {
            "country_code", "genre_id", "chart_rank", "apple_id", "name", "artist",
            "artwork", "url", "match_key", "run_date", "rank_move",
        },
    },
    "spotify": {
        "main_table": "spotify_main",
        "id_col": "spotify_id",
        "chart_col": "chart",
        "has_arrow": False,          # Spotify ships rank_move for free; leave it alone
        "adapter": lambda records, country, chart: adapt_spotify(records, country, chart, RUN_DATE),
        "allowed_columns": {
            "country_code", "chart", "chart_rank", "spotify_id", "name", "publisher",
            "artwork", "rank_move", "match_key", "run_date",
        },
    },
    "youtube": {
        "main_table": "youtube_main",
        "id_col": "youtube_id",
        "chart_col": None,           # one chart per country -> no scoping column in main
        "has_arrow": True,
        "history_chart": "top",      # history needs a chart value; YouTube has just one
        "adapter": lambda records, country, chart: adapt_youtube(records, country, RUN_DATE),
        "allowed_columns": {
            "country_code", "chart_rank", "youtube_id", "name", "channel", "artwork",
            "channel_url", "match_key", "run_date", "rank_move",
        },
    },
}


# ===========================================================================
# logging
# ===========================================================================
def setup_logging() -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s  %(levelname)-7s  %(message)s",
        handlers=[
            logging.StreamHandler(sys.stdout),
            logging.FileHandler(LOG_DIR / f"loader_{RUN_DATE}.log", encoding="utf-8"),
        ],
    )


log = logging.getLogger("loader")


# ===========================================================================
# path + file helpers
# ===========================================================================
def parse_path(path: Path) -> tuple[str, str, str]:
    """data/{platform}/{country}/{slug}.json  ->  (platform, country_code, slug)."""
    platform = path.parent.parent.name.lower()
    raw_country = path.parent.name
    slug = path.stem  # filename without .json -> Apple genre id (e.g. 1488) or 'top'

    if COUNTRY_CODE_CASE == "upper":
        country = raw_country.upper()
    elif COUNTRY_CODE_CASE == "lower":
        country = raw_country.lower()
    else:
        country = raw_country
    return platform, country, slug


def read_records(path: Path) -> list:
    """Return the list of raw record dicts from a chart file.

    Handles either a bare JSON list or a dict that wraps the list under a common key.
    A broken/empty file raises, and the caller turns that into a skip.
    """
    with path.open(encoding="utf-8") as fh:
        data = json.load(fh)

    if isinstance(data, list):
        records = data
    elif isinstance(data, dict):
        records = None
        for key in ("records", "results", "items", "data", "entries", "shows"):
            if isinstance(data.get(key), list):
                records = data[key]
                break
        if records is None and isinstance(data.get("feed"), dict):
            feed = data["feed"]
            for key in ("results", "entry"):
                if isinstance(feed.get(key), list):
                    records = feed[key]
                    break
        if records is None:
            raise ValueError("no record list found in JSON object")
    else:
        raise ValueError("JSON root is neither a list nor an object")

    if not records:
        raise ValueError("file contains zero records")
    return records


# ===========================================================================
# arrow logic — compares to the PREVIOUS RUN of this chart, not 'yesterday'
# ===========================================================================
def previous_ranks(cur, platform: str, country: str, chart: str) -> dict[str, int]:
    """external_id -> rank, from the most recent run STRICTLY BEFORE today's run_date."""
    cur.execute(
        """
        SELECT external_id, chart_rank
        FROM history
        WHERE platform = %s AND country_code = %s AND chart = %s
          AND run_date = (
              SELECT MAX(run_date) FROM history
              WHERE platform = %s AND country_code = %s AND chart = %s
                AND run_date < %s
          )
        """,
        (platform, country, chart, platform, country, chart, RUN_DATE),
    )
    return {r["external_id"]: r["chart_rank"] for r in cur.fetchall()}


def arrow_for(today_rank: int, prev_rank: int | None) -> str:
    if prev_rank is None:
        return "NEW"
    if today_rank < prev_rank:
        return "UP"
    if today_rank > prev_rank:
        return "DOWN"
    return "UNCHANGED"


# ===========================================================================
# genre auto-register (best effort — a new genre self-heals, never blocks a load)
# ===========================================================================
def ensure_genre(conn, platform: str, slug: str, records: list) -> None:
    """INSERT IGNORE a (platform, native_id) into `genres` so dropdowns discover it.

    Display name comes from the raw record when present, else a humanised slug.
    Wrapped so a failure here can never stop the actual chart load.
    """
    if platform == "youtube" or slug == "top":
        return
    try:
        display = None
        if platform == "apple":
            for rec in records:
                if str(rec.get("genre_id")) == str(slug) and rec.get("genre"):
                    display = rec["genre"]
                    break
        if not display:
            display = slug.replace("-", " ").replace("_", " ").title()

        with conn.cursor() as cur:
            cur.execute(
                "INSERT IGNORE INTO genres (platform, native_id, display_name) "
                "VALUES (%s, %s, %s)",
                (platform, str(slug), display),
            )
        conn.commit()
    except Exception as exc:  # noqa: BLE001 - genuinely best-effort
        conn.rollback()
        log.warning("genre auto-register skipped for %s/%s: %s", platform, slug, exc)


# ===========================================================================
# the swap: one chart, one transaction
# ===========================================================================
def insert_sql(table: str, columns: list[str]) -> str:
    cols = ", ".join(f"`{c}`" for c in columns)
    placeholders = ", ".join(["%s"] * len(columns))
    return f"INSERT INTO `{table}` ({cols}) VALUES ({placeholders})"


def load_chart(conn, platform: str, country: str, slug: str, records: list) -> dict:
    """Swap one chart inside a transaction. Returns a stats dict for the manifest."""
    spec = PLATFORM_SPEC[platform]
    chart_for_main = slug                                   # apple genre_id / spotify slug
    chart_for_history = spec.get("history_chart", slug)     # youtube collapses to 'top'

    rows, skipped = spec["adapter"](records, country, chart_for_main)
    if not rows:
        raise ValueError(f"adapter returned no usable rows (skipped {skipped})")

    id_col = spec["id_col"]
    allowed = spec["allowed_columns"]

    with conn.cursor() as cur:
        # arrows first, BEFORE we touch history, so "previous run" excludes today
        prev = previous_ranks(cur, platform, country, chart_for_history) if spec["has_arrow"] else {}

        clean_rows = []
        for row in rows:
            row = dict(row)
            row.setdefault("run_date", RUN_DATE)            # adapter may already set it
            if id_col not in row or row.get("chart_rank") is None:
                skipped += 1                                 # double-guard; adapter should catch
                continue
            if spec["has_arrow"]:
                row["rank_move"] = arrow_for(int(row["chart_rank"]), prev.get(row[id_col]))
            bad = set(row) - allowed
            if bad:
                raise ValueError(f"row has columns not in {spec['main_table']}: {sorted(bad)}")
            clean_rows.append(row)

        if not clean_rows:
            raise ValueError("every row was skipped during validation")

        # --- the transactional swap ---
        if spec["chart_col"]:
            cur.execute(
                f"DELETE FROM `{spec['main_table']}` WHERE country_code = %s AND `{spec['chart_col']}` = %s",
                (country, chart_for_main),
            )
        else:  # youtube: one chart per country
            cur.execute(
                f"DELETE FROM `{spec['main_table']}` WHERE country_code = %s",
                (country,),
            )

        columns = list(clean_rows[0].keys())
        sql = insert_sql(spec["main_table"], columns)
        cur.executemany(sql, [[r[c] for c in columns] for r in clean_rows])

        # --- append to history (idempotent on re-run) ---
        cur.executemany(
            """
            INSERT INTO history (platform, country_code, chart, external_id, chart_rank, run_date)
            VALUES (%s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE chart_rank = VALUES(chart_rank)
            """,
            [
                (platform, country, chart_for_history, r[id_col], int(r["chart_rank"]), RUN_DATE)
                for r in clean_rows
            ],
        )

    conn.commit()
    return {"loaded": len(clean_rows), "skipped": skipped}


# ===========================================================================
# driver
# ===========================================================================
def main() -> int:
    setup_logging()
    started = datetime.now()
    log.info("loader start  run_date=%s  data_dir=%s", RUN_DATE, DATA_DIR.resolve())

    if not DATA_DIR.exists():
        log.error("data dir %s does not exist — nothing to load", DATA_DIR.resolve())
        return 1

    try:
        conn = pymysql.connect(**DB)
    except Exception as exc:  # noqa: BLE001
        log.error("could not connect to MySQL: %s", exc)
        return 1

    manifest = {"run_date": str(RUN_DATE), "started_at": started.isoformat(), "files": []}
    totals = {"files": 0, "ok": 0, "skipped_files": 0, "rows_loaded": 0, "rows_skipped": 0}

    try:
        for path in sorted(DATA_DIR.glob("*/*/*.json")):
            totals["files"] += 1
            platform, country, slug = parse_path(path)
            entry = {"file": str(path), "platform": platform, "country": country, "chart": slug}

            if platform not in PLATFORM_SPEC:
                entry["status"] = "skipped"
                entry["error"] = f"unknown platform '{platform}'"
                totals["skipped_files"] += 1
                manifest["files"].append(entry)
                log.warning("skip %s: unknown platform", path)
                continue

            try:
                records = read_records(path)
                ensure_genre(conn, platform, slug, records)
                stats = load_chart(conn, platform, country, slug, records)
                entry.update(status="ok", **stats)
                totals["ok"] += 1
                totals["rows_loaded"] += stats["loaded"]
                totals["rows_skipped"] += stats["skipped"]
                log.info("ok   %-7s %s/%-12s loaded=%d skipped=%d",
                         platform, country, slug, stats["loaded"], stats["skipped"])
            except Exception as exc:  # noqa: BLE001 - one bad chart must not kill the run
                conn.rollback()       # undo the half-done swap -> yesterday's rows survive
                entry["status"] = "skipped"
                entry["error"] = str(exc)
                totals["skipped_files"] += 1
                manifest["files"].append(entry)
                log.warning("skip %s: %s  (yesterday's data kept)", path, exc)
                continue

            manifest["files"].append(entry)
    finally:
        conn.close()

    manifest["finished_at"] = datetime.now().isoformat()
    manifest["totals"] = totals
    manifest_path = LOG_DIR / f"manifest_{RUN_DATE}.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")

    log.info("loader done  files=%d ok=%d skipped=%d rows_loaded=%d rows_skipped=%d",
             totals["files"], totals["ok"], totals["skipped_files"],
             totals["rows_loaded"], totals["rows_skipped"])
    log.info("manifest -> %s", manifest_path)

    # exit non-zero only if we loaded nothing at all (so cron can alarm on a dead run)
    return 0 if totals["ok"] else 2


if __name__ == "__main__":
    raise SystemExit(main())