"""
Apple adapter.

Its only job: take the cleaned JSON records for ONE Apple chart and turn them
into tidy rows ready for the apple_main table. It does NOT touch the database
— that's the loader's job. Keeping it separate means we can test it on its own.

Input  : a list of records (what your fetch_apple.py already wrote), plus the
          country + genre + date that the loader knows from the file it's reading.
Output : a list of clean row-dicts, one per show, matching apple_main's columns.
"""

from .normalize import make_match_key


def adapt_apple(records, country_code, genre_id, run_date):
    """
    Convert one chart's worth of Apple records into apple_main rows.

    records      : list of dicts from the JSON file
    country_code : e.g. 'us'   (the loader gets this from the file path)
    genre_id     : e.g. '1488' for True Crime, or 'top' for the overall chart
    run_date     : e.g. '2026-06-22'  (today's load date)

    Returns a list of clean row-dicts. Bad records are skipped, not crashed on —
    one broken show should never sink the whole chart.
    """
    rows = []
    skipped = 0

    for rec in records:
        apple_id = rec.get("apple_id")
        name = rec.get("name")
        rank = rec.get("rank")

        # Validation gate: a row is useless without an id, a name, and a rank.
        # If any are missing, skip it and keep a tally (the loader can log this).
        if not apple_id or not name or rank is None:
            skipped += 1
            continue

        rows.append({
            "country_code": country_code,
            "genre_id":     genre_id,
            "chart_rank":   int(rank),
            "apple_id":     str(apple_id),
            "name":         name.strip(),
            "artist":       (rec.get("artist") or "").strip() or None,
            "artwork":      rec.get("artwork") or None,
            "url":          rec.get("url") or None,
            "match_key":    make_match_key(name),
            "run_date":     run_date,
        })

    return rows, skipped