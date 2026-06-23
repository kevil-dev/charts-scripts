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
CONFIG_FILE = os.path.join(PROJECT_ROOT, "config", "apple_config.json")
OUT_DIR = os.path.join(PROJECT_ROOT, "data", "apple")
MAX_WORKERS = 5      # charts fetched at the same time (polite)
RETRIES = 3          # tries per chart before giving up
PAUSE = 0.3          # gap between charts so we don't hammer Apple

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

LOCK_FILE = os.path.join(PROJECT_ROOT, ".apple.lock")
LOG_FILE  = os.path.join(PROJECT_ROOT, "logs", "apple.log")
os.makedirs(os.path.join(PROJECT_ROOT, "logs"), exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler()],
)
log = logging.getLogger(__name__)


def build_url(country, genre_id):
    # One path for Top and genres: Top just has no genre part.
    base = f"https://itunes.apple.com/{country}/rss/toppodcasts/limit={LIMIT}"
    if genre_id:
        base += f"/genre={genre_id}"
    return base + "/json"


def clean_entry(entry, rank):
    # Keep only the fields that do a job.
    return {
        "rank": rank,
        "apple_id": entry["id"]["attributes"]["im:id"],
        "name": entry["im:name"]["label"],
        "artist": entry["im:artist"]["label"],
        "artwork": entry["im:image"][-1]["label"],   # last image = largest
        "genre": entry["category"]["attributes"]["term"],
        "genre_id": entry["category"]["attributes"]["im:id"],
        "url": entry["id"]["label"],
    }


def fetch_and_save(country, genre):
    time.sleep(PAUSE)
    url = build_url(country, genre["id"])
    for attempt in range(1, RETRIES + 1):
        try:
            r = requests.get(url, timeout=15)
            r.raise_for_status()
            entries = r.json()["feed"].get("entry", [])
            shows = [clean_entry(e, i) for i, e in enumerate(entries, start=1)]

            chart = {
                "platform": "apple",
                "country": country,
                "genre": genre["name"],
                "genre_id": genre["id"],
                "slug": genre["slug"],
                "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "count": len(shows),
                "shows": shows,
            }

            # One clean file per chart: data/apple/{country}/{slug}.json
            folder = os.path.join(OUT_DIR, country)
            os.makedirs(folder, exist_ok=True)
            path = os.path.join(folder, f"{genre['slug']}.json")
            tmp = path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as out:
                json.dump(chart, out, ensure_ascii=False, indent=2)
            os.replace(tmp, path)

            return {"ok": True, "country": country, "slug": genre["slug"], "count": len(shows)}

        except Exception as err:
            if attempt == RETRIES:
                return {"ok": False, "country": country, "slug": genre["slug"], "error": str(err)}
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
    log.info(f"Fetching {len(jobs)} charts into {OUT_DIR}/ ...")

    ok = fail = 0
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
        futures = [pool.submit(fetch_and_save, c, g) for c, g in jobs]
        for fut in as_completed(futures):
            res = fut.result()
            if res["ok"]:
                ok += 1
                log.info(f"ok   {res['country']}/{res['slug']}: {res['count']} shows")
            else:
                fail += 1
                log.error(f"FAIL {res['country']}/{res['slug']}: {res['error']}")

    log.info(f"Done. {ok} ok, {fail} failed.")
    if fail:
        sys.exit(1)


if __name__ == "__main__":
    main()