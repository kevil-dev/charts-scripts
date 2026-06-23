"""
run_pipeline.py  —  one entry point for the whole daily run.

Runs each stage in order:  apple fetch  ->  spotify fetch  ->  youtube fetch  ->  loader

Each stage runs as its OWN subprocess (a separate file = a separate process). That isolation
is the point: if a stage errors, hangs, or even crashes hard, we kill/abandon just that one
process, log what happened, and move on to the next stage. One platform's outage never stops
the others, and the loader still runs at the end — it simply loads whatever files exist and
keeps yesterday's data for whatever's missing.

Why the loader runs even if fetches failed: the loader treats a missing/broken file as a skip,
so a failed Spotify fetch just means "serve yesterday's Spotify" while Apple + YouTube update.

Edit STAGES below to match your actual fetch-script filenames and give each a sane timeout.
"""

from __future__ import annotations

import logging
import subprocess
import sys
from datetime import date, datetime
from pathlib import Path
from dotenv import load_dotenv
load_dotenv()

BASE_DIR = Path(__file__).resolve().parent
LOG_DIR = BASE_DIR / "logs"
RUN_DATE = date.today()

# (label, script filename, timeout in seconds).  Order matters: fetches first, loader last.
# Timeouts are generous because Apple alone is ~175 countries x genres; tune to your machine.
STAGES = [
    ("apple fetch",   "scrapers.apple_test",   7200),
    ("spotify fetch", "scrapers.spotify_test", 3600),
    ("youtube fetch", "scrapers.youtube_test", 1800),
    ("loader",        "loader.py",             1800),
]


def setup_logging() -> logging.Logger:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s  %(levelname)-7s  %(message)s",
        handlers=[
            logging.StreamHandler(sys.stdout),
            logging.FileHandler(LOG_DIR / f"pipeline_{RUN_DATE}.log", encoding="utf-8"),
        ],
    )
    return logging.getLogger("pipeline")


log = setup_logging()


def run_stage(label: str, script: str, timeout: int) -> tuple[str, str]:
    """Run one stage in isolation. Returns (label, status). Never raises."""
    is_module = not script.endswith(".py")
    if not is_module:
        path = BASE_DIR / script
        if not path.exists():
            log.error("[%s] script not found: %s — skipping stage", label, path)
            return label, "missing"

    log.info("[%s] starting  (%s, timeout %ds)", label, script, timeout)
    start = datetime.now()
    try:
        if is_module:
            cmd = [sys.executable, "-m", script]
        else:
            cmd = [sys.executable, str(path)]
        result = subprocess.run(
            cmd,
            cwd=str(BASE_DIR),
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except subprocess.TimeoutExpired:
        # subprocess.run has already killed the child process for us.
        log.error("[%s] TIMED OUT after %ds — process killed, continuing", label, timeout)
        return label, "timeout"
    except Exception as exc:  # noqa: BLE001 - never let a launch error stop the pipeline
        log.error("[%s] could not run (%s) — continuing", label, exc)
        return label, "error"

    elapsed = (datetime.now() - start).total_seconds()

    # Pipe the child's output into our log so everything lives in one place.
    if result.stdout:
        log.info("[%s] stdout (tail):\n%s", label, _tail(result.stdout))
    if result.returncode != 0:
        log.error("[%s] FAILED rc=%d after %.0fs — continuing", label, result.returncode, elapsed)
        if result.stderr:
            log.error("[%s] stderr (tail):\n%s", label, _tail(result.stderr))
        return label, "failed"

    log.info("[%s] done  rc=0  %.0fs", label, elapsed)
    return label, "ok"


def _tail(text: str, lines: int = 25) -> str:
    return "\n".join(text.strip().splitlines()[-lines:])


def main() -> int:
    log.info("=" * 70)
    log.info("pipeline start  run_date=%s  dir=%s", RUN_DATE, BASE_DIR)

    results = [run_stage(label, script, timeout) for label, script, timeout in STAGES]

    log.info("-" * 70)
    log.info("pipeline summary:")
    for label, status in results:
        log.info("    %-14s %s", label, status.upper())

    failures = [label for label, status in results if status != "ok"]
    log.info("pipeline done  %d/%d stages ok", len(results) - len(failures), len(results))
    log.info("=" * 70)

    # Exit code = number of stages that didn't succeed, so cron/monitoring can alarm
    # while the pipeline itself still ran every stage to completion.
    return len(failures)


if __name__ == "__main__":
    raise SystemExit(main())