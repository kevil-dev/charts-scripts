"""
Shared helpers used by every adapter.

The match_key rule lives here ONCE so all three platforms compute it the same
way. If this rule ever differed between platforms, the same show would get
different keys and the cross-platform icons would stop matching. One rule,
one place.
"""

import re


def make_match_key(name):
    """
    Turn a show name into a plain 'match_key' for spotting the same show across
    platforms.  e.g.  "The Diary Of A CEO!"  ->  "the diary of a ceo"

    Rules: lowercase, drop punctuation, squeeze multiple spaces into one, trim.
    """
    if not name:
        return ""
    text = name.lower()
    text = re.sub(r"[^a-z0-9\s]", " ", text)   # keep letters/numbers/spaces only
    text = re.sub(r"\s+", " ", text)            # collapse runs of spaces
    return text.strip()