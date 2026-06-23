"""
Spotify adapter.

Same job as the Apple one: take one Spotify chart's cleaned records and turn
them into tidy rows for the spotify_main table. No database work here.

The only real differences from Apple:
  - the id field is 'spotify_id', the author field is 'publisher'
  - Spotify HANDS US the arrow already, as 'rank_move' (UP/DOWN/UNCHANGED),
    so we just pass it through — no computing needed for Spotify.
  - the chart slug (e.g. 'business', 'top-podcasts', 'trending') is the
    'chart' value, handed in by the loader.
"""

from .normalize import make_match_key


def adapt_spotify(records, country_code, chart, run_date):
    """
    records      : list of dicts from the JSON file
    country_code : e.g. 'us'
    chart        : e.g. 'business' / 'top-podcasts' / 'trending'
    run_date     : e.g. '2026-06-22'

    Returns (rows, skipped). Bad records are skipped, not crashed on.
    """
    rows = []
    skipped = 0

    for rec in records:
        spotify_id = rec.get("spotify_id")
        name = rec.get("name")
        rank = rec.get("rank")

        # Validation gate: need an id, a name, and a rank or the row is useless.
        if not spotify_id or not name or rank is None:
            skipped += 1
            continue

        rows.append({
            "country_code": country_code,
            "chart":        chart,
            "chart_rank":   int(rank),
            "spotify_id":   str(spotify_id),
            "name":         name.strip(),
            "publisher":    (rec.get("publisher") or "").strip() or None,
            "artwork":      rec.get("artwork") or None,
            "rank_move":    rec.get("rank_move") or None,   # Spotify gives this free
            "match_key":    make_match_key(name),
            "run_date":     run_date,
        })

    return rows, skipped