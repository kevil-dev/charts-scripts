import requests
import time
import json
import os
import sys
import logging

# ---------- settings ----------
SCRAPERS_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.normpath(os.path.join(SCRAPERS_DIR, ".."))
CONFIG_FILE = os.path.join(PROJECT_ROOT, "config", "youtube_config.json")
OUT_DIR = os.path.join(PROJECT_ROOT, "data", "youtube")
URL = "https://charts.youtube.com/youtubei/v1/browse?alt=json"
RETRIES = 3
PAUSE = 1.0   # YouTube is one big request per country, so go gentle

try:
    with open(CONFIG_FILE, encoding="utf-8") as f:
        CONFIG = json.load(f)
except FileNotFoundError:
    sys.exit(f"ERROR: config file not found: {CONFIG_FILE}")
except json.JSONDecodeError as e:
    sys.exit(f"ERROR: invalid JSON in {CONFIG_FILE}: {e}")

LOCK_FILE = os.path.join(PROJECT_ROOT, ".youtube.lock")
LOG_FILE  = os.path.join(PROJECT_ROOT, "logs", "youtube.log")
os.makedirs(os.path.join(PROJECT_ROOT, "logs"), exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler()],
)
log = logging.getLogger(__name__)


def build_body(country):
    # The country lives in the query string, not the URL.
    query = (
        "flags=MusicCharts__enable_apac_and_shorts_charts_expansion"
        "&perspective=PODCAST_SHOW"
        f"&chart_params_country_code={country}"
        "&chart_params_chart_type=PODCAST_SHOWS_BY_WATCH_TIME"
        "&chart_params_period_type=WEEKLY"
    )
    return {
        "context": CONFIG["context"],
        "browseId": "FEmusic_analytics_charts_home",
        "query": query,
    }


def clean_genre(raw):
    # PODCAST_GENRE_TRUE_CRIME -> True Crime
    if not raw or raw == "PODCAST_GENRE_UNSPECIFIED":
        return None
    words = raw.replace("PODCAST_GENRE_", "").split("_")
    return " ".join(w.capitalize() for w in words)


def find_entries(data):
    # Dig down to podcastShowEntries through YouTube's deep nesting.
    sections = data["contents"]["sectionListRenderer"]["contents"]
    for section in sections:
        block = section.get("musicAnalyticsSectionRenderer")
        if block:
            return block["content"]["podcastShows"][0]["podcastShowEntries"]
    return []


def clean_entry(entry):
    thumbs = entry.get("thumbnail", {}).get("thumbnails", [])
    channel_url = (
        entry.get("channelNavigationEndpoint", {})
        .get("urlEndpoint", {})
        .get("url", "")
    )
    return {
        "rank": entry["chartEntryMetadata"]["currentPosition"],
        "youtube_playlist_id": entry["externalPlaylistId"],
        "name": entry["name"],
        "channel": entry.get("channelName", ""),
        "artwork": thumbs[-1]["url"] if thumbs else "",   # last = largest
        "genre": clean_genre(entry.get("primaryGenre")),  # kept, not filtered on
        "channel_url": channel_url,
    }


def fetch_and_save(country):
    body = build_body(country)
    for attempt in range(1, RETRIES + 1):
        try:
            r = requests.post(URL, json=body, timeout=20)
            r.raise_for_status()
            entries = find_entries(r.json())
            shows = [clean_entry(e) for e in entries]

            chart_data = {
                "platform": "youtube",
                "country": country,
                "slug": "top",
                "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "count": len(shows),
                "shows": shows,
            }

            folder = os.path.join(OUT_DIR, country)
            os.makedirs(folder, exist_ok=True)
            path = os.path.join(folder, "top.json")
            tmp = path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as out:
                json.dump(chart_data, out, ensure_ascii=False, indent=2)
            os.replace(tmp, path)

            return {"ok": True, "country": country, "count": len(shows)}

        except Exception as err:
            if attempt == RETRIES:
                return {"ok": False, "country": country, "error": str(err)}
            time.sleep(2 * attempt)


def main():
    try:
        import fcntl
        _lf = open(LOCK_FILE, "w")
        fcntl.flock(_lf, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except ImportError:
        pass  # Windows dev environment — skip locking
    except OSError:
        sys.exit("ERROR: another instance is already running")

    countries = CONFIG["countries"]
    log.info(f"Fetching {len(countries)} YouTube charts into {OUT_DIR}/ ...")

    ok = fail = 0
    for country in countries:
        res = fetch_and_save(country)
        if res["ok"]:
            ok += 1
            log.info(f"ok   {res['country']}/top: {res['count']} shows")
        else:
            fail += 1
            log.error(f"FAIL {res['country']}/top: {res['error']}")
        time.sleep(PAUSE)

    log.info(f"Done. {ok} ok, {fail} failed.")
    if fail == len(countries):
        log.error("All countries failed — YouTube API may be broken or credentials expired")
    if fail:
        sys.exit(1)


if __name__ == "__main__":
    main()