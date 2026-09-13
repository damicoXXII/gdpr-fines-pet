#!/usr/bin/env python3
"""
Validation script for GDPR fines JSON.
========================================
Compares the current data/gdpr_fines.json with a previous snapshot
(data/gdpr_fines_prev.json) and reports differences.

Can also run standalone to validate the JSON structure.
"""

import json
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_DIR = SCRIPT_DIR.parent
DATA_DIR = PROJECT_DIR / "data"
CURRENT_FILE = DATA_DIR / "gdpr_fines.json"
PREV_FILE = DATA_DIR / "gdpr_fines_prev.json"


def load_json(path: Path) -> dict | None:
    """Load and return parsed JSON, or None if file missing/invalid."""
    if not path.exists():
        return None
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    except (json.JSONDecodeError, OSError) as exc:
        print(f"[WARN] Could not read {path}: {exc}", file=sys.stderr)
        return None


def validate_structure(data: dict) -> list[str]:
    """Validate the JSON structure and return a list of issues (empty = OK)."""
    issues: list[str] = []

    if not isinstance(data, dict):
        issues.append("Root element must be a JSON object")
        return issues

    # Metadata checks
    meta = data.get("metadata")
    if not isinstance(meta, dict):
        issues.append("Missing or invalid 'metadata' object")
    else:
        for key in ("last_updated", "total_records", "source_url",
                     "attribution", "license"):
            if key not in meta:
                issues.append(f"Missing metadata field: {key}")

    # Fines array checks
    fines = data.get("fines")
    if not isinstance(fines, list):
        issues.append("Missing or invalid 'fines' array")
        return issues

    if len(fines) == 0:
        issues.append("'fines' array is empty")
        return issues

    required_fields = {
        "etid", "country_code", "country", "authority",
        "fine_eur", "date", "sector", "gdpr_articles",
        "violation_type", "detail_url",
    }

    # Check first 5 records as sample
    for i, record in enumerate(fines[:5]):
        missing = required_fields - set(record.keys())
        if missing:
            issues.append(
                f"Record {i} (etid={record.get('etid', '?')}): "
                f"missing fields: {missing}"
            )

        # Party should be hashed, not cleartext
        if "party" in record:
            issues.append(
                f"Record {i}: 'party' field present in cleartext! "
                "Should be 'party_hash' instead."
            )

    return issues


def compare_datasets(current: dict, previous: dict) -> dict:
    """Compare current and previous datasets, return a summary."""
    curr_fines = {r["etid"]: r for r in current.get("fines", [])}
    prev_fines = {r["etid"]: r for r in previous.get("fines", [])}

    curr_ids = set(curr_fines.keys())
    prev_ids = set(prev_fines.keys())

    new_ids = curr_ids - prev_ids
    removed_ids = prev_ids - curr_ids
    common_ids = curr_ids & prev_ids

    # Check for changes in common records
    changed_ids = set()
    for etid in common_ids:
        if curr_fines[etid] != prev_fines[etid]:
            changed_ids.add(etid)

    return {
        "current_total": len(curr_fines),
        "previous_total": len(prev_fines),
        "new_records": len(new_ids),
        "removed_records": len(removed_ids),
        "changed_records": len(changed_ids),
        "new_etids": sorted(new_ids),
        "removed_etids": sorted(removed_ids),
    }


def main() -> None:
    print("=" * 50)
    print("GDPR Fines JSON Validator")
    print("=" * 50)

    # --- Validate current file ---
    current = load_json(CURRENT_FILE)
    if current is None:
        print(f"\n[ERROR] Current file not found: {CURRENT_FILE}")
        print("Run scrape.py first to generate the data.")
        sys.exit(1)

    print(f"\nValidating: {CURRENT_FILE}")
    issues = validate_structure(current)
    if issues:
        print("[FAIL] Validation issues found:")
        for issue in issues:
            print(f"  - {issue}")
        sys.exit(1)
    else:
        meta = current["metadata"]
        print("[OK] Structure is valid")
        print(f"  Total records : {meta['total_records']}")
        print(f"  Last updated  : {meta['last_updated']}")
        print(f"  Attribution   : {meta['attribution']}")

    # --- Compare with previous snapshot ---
    previous = load_json(PREV_FILE)
    if previous is None:
        print(f"\n[INFO] No previous snapshot found ({PREV_FILE}).")
        print("Skipping comparison. This is normal on first run.")
        sys.exit(0)

    print(f"\nComparing with previous: {PREV_FILE}")
    diff = compare_datasets(current, previous)

    print(f"  Previous total : {diff['previous_total']}")
    print(f"  Current total  : {diff['current_total']}")
    print(f"  New records    : {diff['new_records']}")
    print(f"  Removed records: {diff['removed_records']}")
    print(f"  Changed records: {diff['changed_records']}")

    if diff["new_records"] > 0:
        print(f"\n  New ETids: {diff['new_etids'][:20]}")
        if diff["new_records"] > 20:
            print(f"    ... and {diff['new_records'] - 20} more")

    if diff["removed_records"] > 0:
        print(f"\n  [WARN] Removed ETids: {diff['removed_etids'][:20]}")

    print("\n[OK] Comparison complete.")


if __name__ == "__main__":
    main()
