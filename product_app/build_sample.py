"""
Build the prototype's self-contained dataset from the real combined scrape.

Takes the main pipeline output, drops non-AU tours (South Pacific /
International), and writes a trimmed CSV the app reads. Run once:

    python build_sample.py
"""

import os
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "..", "data", "combined_itineraries_latest.csv")
OUT = os.path.join(HERE, "data", "sample_itineraries.csv")

KEEP = [
    "source", "tour_name", "tour_url", "total_days", "day_number",
    "location", "activity", "price", "state", "city",
]


def main():
    df = pd.read_csv(SRC)
    df = df[~df["state"].isin(["South Pacific", "International"])].copy()
    df = df[[c for c in KEEP if c in df.columns]]
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    df.to_csv(OUT, index=False)
    print(f"Wrote {len(df)} rows, {df['tour_url'].nunique()} tours -> {OUT}")


if __name__ == "__main__":
    main()
