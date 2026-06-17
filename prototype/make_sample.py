"""
make_sample.py  —  draw a small, representative SAMPLE from the real cleaned dataset.

Why: the full keyword_dataset has ~9,596 rows — too big to eyeball while we design the
product-data model. This picks a deterministic handful of tours that exercise the model:
  * both segments (Tourism Board + Competitor),
  * two states (WA + NSW),
  * tours that visit Sydney or Perth, so the same city repeats across tours
    (lets us SEE the day-template dedup working).

Output: prototype/sample_keyword_dataset.csv  (~80-150 day rows)

This is a PROTOTYPE helper. It only READS the real dataset and writes into prototype/.
Run once:  python prototype/make_sample.py
"""

import os
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SOURCE_CSV = os.path.join(ROOT, "data", "keywords", "keyword_dataset_2026-06.csv")
OUT_CSV = os.path.join(HERE, "sample_keyword_dataset.csv")

# How many tours to take from each source. Tours are chosen deterministically:
# only tours that visit Sydney or Perth, sorted by tour_url, first N.
SAMPLE_PLAN = {
    "westernaustralia_com": 4,   # Tourism Board (WA / Perth)
    "australia_com":        4,   # Tourism Board (national)
    "global_journeys":      4,   # Competitor (large operator)
    "make_my_trip":         3,   # Competitor (India outbound)
    "scott_dunn":           4,   # Competitor (UK luxury)
}
# Cities used to guarantee overlap between tours (so dedup is visible).
ANCHOR_CITIES = {"Sydney", "Perth"}


def main():
    df = pd.read_csv(SOURCE_CSV, low_memory=False)
    print(f"Loaded full dataset: {len(df):,} rows, {df['tour_url'].nunique():,} tours")

    picked_tours = []
    for source, n_tours in SAMPLE_PLAN.items():
        sub = df[df["source"] == source]
        # tours from this source that visit an anchor city
        anchor_tours = sorted(
            sub[sub["city"].isin(ANCHOR_CITIES)]["tour_url"].dropna().unique()
        )
        chosen = anchor_tours[:n_tours]
        picked_tours.extend(chosen)
        print(f"  {source:22} chose {len(chosen)} tours (of {len(anchor_tours)} anchor tours)")

    sample = df[df["tour_url"].isin(picked_tours)].copy()
    # stable ordering for easy review/diffing
    sample = sample.sort_values(["source", "tour_url", "day_number"]).reset_index(drop=True)
    sample.to_csv(OUT_CSV, index=False)

    print(f"\nWrote sample: {OUT_CSV}")
    print(f"  rows={len(sample)}  tours={sample['tour_url'].nunique()}  "
          f"sources={sample['source'].nunique()}  states={sorted(sample['state'].dropna().unique())}")
    print("  rows per source:")
    print(sample.groupby("source").size().to_string())


if __name__ == "__main__":
    main()
