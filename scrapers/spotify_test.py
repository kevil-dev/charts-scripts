import requests
import time
import json
import os
import sys
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed

# ---------- settings ----------
SCRAPERS_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.normpath(os.path.join(SCRAPERS_DIR, ".."))
CONFIG_FILE = os.path.join(PROJECT_ROOT, "config", "spotify_config.json")
OUT_DIR = os.path.join(PROJECT_ROOT, "data", "spotify")
MAX_WORKERS = 5      # charts fetched at the same time (polite)
RETRIES = 3          # tries per chart before giving up
PAUSE = 0.3          # gap between charts so we don't hammer Spotify

try:
    with open(CONFIG_FILE, encoding="utf-8") as f:
        CONFIG = json.load(f)
    LIMIT = CONFIG["limit"]
except FileNotFoundError:
    sys.exit(f"ERROR: config file not found: {CONFIG_FILE}")
except json.JSONDecodeError as e:
    sys.exit(f"ERROR: invalid JSON in {CONFIG_FILE}: {e}")
except KeyError as e:
    sys.exit(f"ERROR: missing key in {CONFIG_FILE}: {e}")

LOCK_FILE = os.path.join(PROJECT_ROOT, ".spotify.lock")
LOG_FILE  = os.path.join(PROJECT_ROOT, "logs", "spotify.log")
os.makedirs(os.path.join(PROJECT_ROOT, "logs"), exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler()],
)
log = logging.getLogger(__name__)


def build_url(country, slug):
    return f"https://podcastcharts.byspotify.com/api/charts/{slug}?region={country}&limit={LIMIT}"


def clean_entry(entry, rank):
    uri = entry.get("showUri", "")
    spotify_id = uri.split(":")[-1] if uri else ""
    return {
        "rank": rank,
        "spotify_id": spotify_id,
        "name": entry["showName"],
        "publisher": entry["showPublisher"],
        "artwork": entry["showImageUrl"],
        "rank_move": entry["chartRankMove"],
        "url": f"https://open.spotify.com/show/{spotify_id}" if spotify_id else "",
    }


def fetch_and_save(country, chart):
    time.sleep(PAUSE)
    url = build_url(country, chart["slug"])
    for attempt in range(1, RETRIES + 1):
        try:
            r = requests.get(url, timeout=15)

            # This chart isn't offered for this country -> skip quietly, don't retry.
            if r.status_code == 404:
                return {"status": "skip", "country": country, "slug": chart["slug"]}
            r.raise_for_status()

            entries = r.json()
            if not entries:                                 # 200 but empty -> also skip
                return {"status": "skip", "country": country, "slug": chart["slug"]}

            shows = [clean_entry(e, i) for i, e in enumerate(entries, start=1)]

            chart_data = {
                "platform": "spotify",
                "country": country,
                "genre": chart["name"],
                "slug": chart["slug"],
                "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "count": len(shows),
                "shows": shows,
            }

            # One clean file per chart: data/spotify/{country}/{slug}.json
            folder = os.path.join(OUT_DIR, country)
            os.makedirs(folder, exist_ok=True)
            path = os.path.join(folder, f"{chart['slug']}.json")
            tmp = path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as out:
                json.dump(chart_data, out, ensure_ascii=False, indent=2)
            os.replace(tmp, path)

            return {"status": "ok", "country": country, "slug": chart["slug"], "count": len(shows)}

        except Exception as err:
            if attempt == RETRIES:
                return {"status": "fail", "country": country, "slug": chart["slug"], "error": str(err)}
            time.sleep(2 * attempt)   # wait longer each retry


def main():
    try:
        import fcntl
        _lf = open(LOCK_FILE, "w")
        fcntl.flock(_lf, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except ImportError:
        pass  # Windows dev environment — skip locking
    except OSError:
        sys.exit("ERROR: another instance is already running")

    jobs = [(c, g) for c in CONFIG["countries"] for g in CONFIG["genres"]]
    log.info(f"Trying {len(jobs)} charts into {OUT_DIR}/ ...")

    ok = skip = fail = 0
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
        futures = [pool.submit(fetch_and_save, c, g) for c, g in jobs]
        for fut in as_completed(futures):
            res = fut.result()
            if res["status"] == "ok":
                ok += 1
                log.info(f"ok   {res['country']}/{res['slug']}: {res['count']} shows")
            elif res["status"] == "skip":
                skip += 1
            else:
                fail += 1
                log.error(f"FAIL {res['country']}/{res['slug']}: {res['error']}")

    log.info(f"Done. {ok} ok, {skip} skipped (not offered), {fail} failed.")
    if fail:
        sys.exit(1)


if __name__ == "__main__":
    main()