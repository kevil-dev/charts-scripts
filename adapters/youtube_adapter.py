"""
YouTube adapter.

Same job again. YouTube is the simplest shape: one chart per country, no genres.

Differences from the others:
  - the id field in the JSON is 'youtube_playlist_id'; it lands in the
    'youtube_id' column.
  - the author field is 'channel', plus a 'channel_url'.
  - YouTube has no genre/chart split, so there's no 'chart' value here.
    (When the loader writes the history row, it uses a fixed chart label
    like 'top' for YouTube, since there's only one chart per country.)
  - YouTube doesn't give an arrow, so rank movement is COMPUTED later by the
    loader (today's main vs. yesterday's history) — nothing to do here.
  - the 'genre' in the JSON is intentionally dropped: youtube_main has no
    column for it. (Easy to add later if you ever want it.)
"""

from .normalize import make_match_key


def adapt_youtube(records, country_code, run_date):
    """
    records      : list of dicts from the JSON file
    country_code : e.g. 'us'
    run_date     : e.g. '2026-06-22'

    Returns (rows, skipped). Bad records are skipped, not crashed on.
    """
    rows = []
    skipped = 0

    for rec in records:
        youtube_id = rec.get("youtube_playlist_id")
        name = rec.get("name")
        rank = rec.get("rank")

        # Validation gate: need an id, a name, and a rank.
        if not youtube_id or not name or rank is None:
            skipped += 1
            continue

        rows.append({
            "country_code": country_code,
            "chart_rank":   int(rank),
            "youtube_id":   str(youtube_id),
            "name":         name.strip(),
            "channel":      (rec.get("channel") or "").strip() or None,
            "artwork":      rec.get("artwork") or None,
            "channel_url":  rec.get("channel_url") or None,
            "match_key":    make_match_key(name),
            "run_date":     run_date,
        })

    return rows, skipped