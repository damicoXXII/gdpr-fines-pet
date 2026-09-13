#!/usr/bin/env python3
"""
GDPR Enforcement Tracker Scraper
=================================
Extracts GDPR fines data from enforcementtracker.com (CC BY-NC-SA 4.0).

The site embeds the full dataset as a JSON blob inside a <script> tag
on the homepage, so a single HTTP request is sufficient.

Attribution: enforcementtracker.com, provided by CMS
License: Creative Commons BY-NC-SA 4.0
"""

import hashlib
import json
import logging
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests
from bs4 import BeautifulSoup

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_DIR = SCRIPT_DIR.parent
DATA_DIR = PROJECT_DIR / "data"
LOG_DIR = PROJECT_DIR / "logs"
OUTPUT_FILE = DATA_DIR / "gdpr_fines.json"
LOG_FILE = LOG_DIR / "scraper.log"

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
SOURCE_URL = "https://www.enforcementtracker.com/"
LICENSE_TEXT = "Creative Commons BY-NC-SA 4.0"
ATTRIBUTION = "enforcementtracker.com, provided by CMS"

# Honest, identifiable User-Agent
USER_AGENT = (
    "EticariumGDPRTracker/1.0 "
    "(+https://www.eticarium.net; non-commercial research; "
    "respects robots.txt and rate limits)"
)

# Rate limiting: min seconds between requests (only 1 needed, but safety)
REQUEST_DELAY_S = 3

# Compact field mapping from the bootstrap JSON blob
FIELD_MAP = {
    "e": "etid",
    "c": "country_code",
    "C": "country",
    "F": "flag",
    "a": "authority",
    "d": "date",
    "y": "year",
    "f": "fine_eur",
    "p": "party",          # will be hashed/redacted
    "s": "sector",
    "r": "gdpr_articles",
    "t": "violation_type",
    "u": "source_url",
}

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
LOG_DIR.mkdir(parents=True, exist_ok=True)
DATA_DIR.mkdir(parents=True, exist_ok=True)

logger = logging.getLogger("gdpr_scraper")
logger.setLevel(logging.DEBUG)

# File handler
fh = logging.FileHandler(LOG_FILE, encoding="utf-8")
fh.setLevel(logging.DEBUG)

# Console handler
ch = logging.StreamHandler(sys.stdout)
ch.setLevel(logging.INFO)

fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s",
                        datefmt="%Y-%m-%d %H:%M:%S")
fh.setFormatter(fmt)
ch.setFormatter(fmt)

logger.addHandler(fh)
logger.addHandler(ch)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def _hash_party(name: str) -> str:
    """Return a truncated SHA-256 hex digest of the party name.

    The hash is deterministic so future runs produce stable IDs, but the
    original name cannot be recovered from the output file.
    """
    if not name:
        return ""
    return hashlib.sha256(name.encode("utf-8")).hexdigest()[:12]


def _tracker_url(etid: int) -> str:
    """Build the detail page URL on enforcementtracker.com."""
    return f"https://www.enforcementtracker.com/ETid-{etid}"


def _parse_record(raw: dict) -> dict | None:
    """Convert a compact record from the bootstrap blob into our schema."""
    etid = raw.get("e")
    if etid is None:
        return None

    party_raw = raw.get("p", "")
    record = {
        "etid": int(etid),
        "country_code": raw.get("c", ""),
        "country": raw.get("C", ""),
        "authority": raw.get("a", ""),
        "party_hash": _hash_party(party_raw),
        "fine_eur": raw.get("f"),
        "date": raw.get("d", ""),
        "year": raw.get("y"),
        "sector": raw.get("s", ""),
        "gdpr_articles": raw.get("r", ""),
        "violation_type": raw.get("t", ""),
        "source_url": raw.get("u", ""),
        "detail_url": _tracker_url(int(etid)),
    }
    return record


# ---------------------------------------------------------------------------
# Scraper
# ---------------------------------------------------------------------------
def check_robots_txt() -> bool:
    """Verify that robots.txt allows scraping the homepage."""
    logger.info("Checking robots.txt ...")
    try:
        resp = requests.get(
            SOURCE_URL + "robots.txt",
            headers={"User-Agent": USER_AGENT},
            timeout=30,
        )
        if resp.status_code == 200:
            body = resp.text.lower()
            logger.info("robots.txt fetched (%d bytes)", len(resp.text))
            # Simplistic check: look for Disallow: / that would block us
            for line in body.splitlines():
                line = line.strip()
                if line.startswith("user-agent:") and "*" in line:
                    continue
                if line == "disallow: /":
                    logger.warning("robots.txt disallows / for all agents!")
                    return False
            return True
        elif resp.status_code == 404:
            logger.info("No robots.txt found (404) -- proceeding.")
            return True
        else:
            logger.warning("robots.txt returned HTTP %d", resp.status_code)
            return True  # conservative: proceed but log
    except requests.RequestException as exc:
        logger.warning("Could not fetch robots.txt: %s", exc)
        return True  # proceed with caution


def fetch_homepage() -> str:
    """Fetch the enforcement tracker homepage HTML."""
    logger.info("Fetching homepage: %s", SOURCE_URL)
    resp = requests.get(
        SOURCE_URL,
        headers={"User-Agent": USER_AGENT},
        timeout=60,
    )
    resp.raise_for_status()
    logger.info("Homepage fetched: HTTP %d, %d bytes",
                resp.status_code, len(resp.text))
    return resp.text


def extract_json_blob(html: str) -> list[dict]:
    """Extract the embedded JSON dataset from the homepage HTML.

    The tracker stores all records in a <script> tag:
      <script type="application/json" id="et-cases"
              data-purpose="filter-bootstrap">[ ... ]</script>
    """
    soup = BeautifulSoup(html, "lxml")

    # Primary selector
    script = soup.find("script", {"id": "et-cases"})
    if script is None:
        # Fallback: search by data-purpose attribute
        script = soup.find("script",
                           {"data-purpose": "filter-bootstrap"})
    if script is None:
        # Broader fallback: look for any script containing the data pattern
        for tag in soup.find_all("script", {"type": "application/json"}):
            text = tag.get_text(strip=True)
            if '"e":' in text and '"C":' in text:
                script = tag
                break

    if script is None:
        raise ValueError(
            "Could not find the JSON bootstrap blob in the homepage HTML. "
            "The site structure may have changed. Please check manually."
        )

    raw_json = script.get_text(strip=True)
    data = json.loads(raw_json)

    if isinstance(data, dict):
        # Sometimes wrapped in an object with a key
        for key in ("cases", "data", "records", "items"):
            if key in data and isinstance(data[key], list):
                return data[key]
        raise ValueError(
            f"JSON blob is a dict with keys {list(data.keys())} "
            "but no recognisable list key found."
        )

    if not isinstance(data, list):
        raise ValueError(
            f"Expected JSON array, got {type(data).__name__}"
        )

    return data


def scrape() -> dict:
    """Run the full scraping pipeline. Returns the final JSON structure."""
    # 1. robots.txt
    if not check_robots_txt():
        logger.error("Scraping disallowed by robots.txt. Aborting.")
        sys.exit(1)

    time.sleep(REQUEST_DELAY_S)

    # 2. Fetch homepage
    html = fetch_homepage()

    # 3. Extract JSON blob
    raw_records = extract_json_blob(html)
    logger.info("Raw records found in blob: %d", len(raw_records))

    # 4. Parse & deduplicate
    seen_ids: set[int] = set()
    records: list[dict] = []
    skipped = 0

    for raw in raw_records:
        parsed = _parse_record(raw)
        if parsed is None:
            skipped += 1
            continue
        if parsed["etid"] in seen_ids:
            skipped += 1
            continue
        seen_ids.add(parsed["etid"])
        records.append(parsed)

    # Sort by date descending (newest first), falling back to etid.
    # Records without a valid date (e.g. "Unknown") go to the bottom.
    def _sort_key(r):
        d = r.get("date") or ""
        has_date = d not in ("", "Unknown")
        return (has_date, d, r["etid"])

    records.sort(key=_sort_key, reverse=True)

    logger.info("Parsed records: %d (skipped %d duplicates/invalid)",
                len(records), skipped)

    # 5. Build output
    now_utc = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    output = {
        "metadata": {
            "last_updated": now_utc,
            "total_records": len(records),
            "source_url": SOURCE_URL,
            "attribution": ATTRIBUTION,
            "license": LICENSE_TEXT,
            "license_url": "https://creativecommons.org/licenses/by-nc-sa/4.0/",
            "note": (
                "Party names are redacted (SHA-256 hash). "
                "Visit the detail_url for full information."
            ),
        },
        "fines": records,
    }

    return output


def save(output: dict) -> Path:
    """Write the JSON output to disk."""
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)
    logger.info("Saved %d records to %s",
                output["metadata"]["total_records"], OUTPUT_FILE)
    return OUTPUT_FILE


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main() -> None:
    logger.info("=" * 60)
    logger.info("GDPR Enforcement Tracker scraper starting")
    logger.info("=" * 60)

    try:
        output = scrape()
        save(output)
        logger.info("Scraper completed successfully.")
    except requests.HTTPError as exc:
        logger.error("HTTP error during scraping: %s", exc)
        if exc.response is not None and exc.response.status_code == 403:
            logger.error(
                "The site returned 403 Forbidden. This likely means "
                "Cloudflare or similar protection is active. "
                "See README.md for manual fallback instructions."
            )
        sys.exit(1)
    except requests.RequestException as exc:
        logger.error("Network error during scraping: %s", exc)
        sys.exit(1)
    except (ValueError, json.JSONDecodeError) as exc:
        logger.error("Data parsing error: %s", exc)
        logger.error(
            "The site structure may have changed. "
            "Please check enforcementtracker.com manually."
        )
        sys.exit(1)
    except Exception as exc:
        logger.exception("Unexpected error: %s", exc)
        sys.exit(1)


if __name__ == "__main__":
    main()
