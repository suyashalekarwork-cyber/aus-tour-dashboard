"""
One-command pipeline: scraped data -> cleaned-keyword-ready.
=============================================================

Takes whatever you've already scraped (any source under webscraping/*/data/latest/)
and runs it through the processing chain, stopping right before the manual
keyword-cleaning step:

    1. combine_sources.py        -> data/raw/combined_itineraries_{month}.csv + _latest.csv
    2. build_keyword_dataset.py  -> data/keywords/keyword_dataset_{month}.csv + keyword_review.csv
    3. keyword_review_tool.py export -> data/keywords/keyword_review.xlsx  (you clean here)
    4. city_review_tool.py export    -> data/config/city_review.xlsx        (city audit)

New sources are picked up automatically by combine_sources.py (auto-discovery),
so you don't need to register them in code.

Usage:
    python run_pipeline.py                 # current month (default)
    python run_pipeline.py --month 2026-06 # explicit snapshot label
    python run_pipeline.py --skip-combine  # re-tag without re-combining sources

After cleaning keywords in the Excel file, apply your decisions with:
    python analysis/keyword_review_tool.py update
"""

import argparse
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PY = sys.executable  # use the same interpreter that launched this script

COMBINE = ROOT / "webscraping" / "pipeline" / "combine_sources.py"
BUILD = ROOT / "analysis" / "build_keyword_dataset.py"
REVIEW = ROOT / "analysis" / "keyword_review_tool.py"
CITY_REVIEW = ROOT / "analysis" / "city_review_tool.py"

REVIEW_XLSX = ROOT / "data" / "keywords" / "keyword_review.xlsx"
CITY_REVIEW_XLSX = ROOT / "data" / "config" / "city_review.xlsx"


def run(label, cmd, cwd=None):
    print(f"\n{'=' * 70}\n>>> {label}\n{'=' * 70}")
    print("    " + " ".join(str(c) for c in cmd))
    result = subprocess.run(cmd, cwd=str(cwd) if cwd else None)
    if result.returncode != 0:
        print(f"\n[FAILED] {label} exited with code {result.returncode}. Stopping pipeline.")
        sys.exit(result.returncode)


def main():
    ap = argparse.ArgumentParser(description="Run combine -> build keyword dataset -> export review.")
    ap.add_argument("--month", help="Snapshot label YYYY-MM (default: current month)")
    ap.add_argument("--skip-combine", action="store_true",
                    help="Skip the combine step and re-tag the existing combined dataset")
    args = ap.parse_args()

    month = args.month or datetime.now(timezone.utc).strftime("%Y-%m")

    # Step 1: combine all scraped sources into the unified dataset.
    if args.skip_combine:
        print("Skipping combine step (--skip-combine).")
    else:
        run("Step 1/4  Combine sources", [PY, str(COMBINE), "--month", month],
            cwd=COMBINE.parent)

    # Step 2: build the keyword-tagged dataset (NER + tagging + config rules).
    run("Step 2/4  Build keyword dataset", [PY, str(BUILD)])

    # Step 3: export the human-review workbook (keywords).
    run("Step 3/4  Export keyword review workbook", [PY, str(REVIEW), "export"])

    # Step 4: export the city audit workbook (city types / null audit / canonicalization).
    run("Step 4/4  Export city review workbook", [PY, str(CITY_REVIEW), "export"])

    print(f"""
{'=' * 70}
[DONE] Pipeline complete.
{'=' * 70}

Next step - clean the keywords:
  1. Open: {REVIEW_XLSX}
  2. Fill in the 'Action' column (blacklist / synonym / allowlist).
  3. Apply your decisions and rebuild:
       python analysis/keyword_review_tool.py update

Curate city types (optional):
  1. Open: {CITY_REVIEW_XLSX}
  2. Fix the 'Type' column; review Null Audit + Canonicalization sheets.
  3. Apply:  python analysis/city_review_tool.py apply

Your final analysis files:
  - data/raw/combined_itineraries_latest.csv      (unified tour-day dataset)
  - data/keywords/keyword_dataset_{month}.csv     (with place/activity/theme tags)
""")


if __name__ == "__main__":
    main()
