"""
build_product_data_prototype.py  —  PROTOTYPE of the product-data foundation.

Reads ONLY the sample (prototype/sample_keyword_dataset.csv) and produces three tables
in prototype/output/ (CSV + xlsx) for review:

  1. day_blocks    — lean atomic inventory, one row per tour-day, with `segment` + `pickable`.
  2. day_templates — deduped "day ideas" (city + place signature) with market counts.
  3. place_catalog — per-attraction market reference (board vs competitor coverage).

Throwaway prototype: isolated under prototype/, touches nothing in data/ or the pipeline.
Run:  python prototype/build_product_data_prototype.py
"""

import os
import sys
import hashlib
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SAMPLE_CSV = os.path.join(HERE, "sample_keyword_dataset.csv")
SEGMENTS_TXT = os.path.join(HERE, "source_segments.txt")
OUT_DIR = os.path.join(HERE, "output")

# Reuse the lock-safe Excel writer we already built (lives in analysis/).
sys.path.insert(0, os.path.join(ROOT, "analysis"))
from xlsx_io import write_xlsx_safe  # noqa: E402


# --------------------------------------------------------------------------- #
# helpers
# --------------------------------------------------------------------------- #
def _load_segments(path):
    """Load 'source -> segment' map (case-insensitive keys). Default: Competitor."""
    mapping = {}
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "->" not in line:
                    continue
                src, seg = line.split("->", 1)
                mapping[src.strip().lower()] = seg.strip()
    return mapping


def _tags(value):
    """Split a '; '-separated tag cell into a clean list (handles NaN/empty)."""
    if value is None or (isinstance(value, float)) or str(value).strip() in ("", "nan"):
        return []
    return [t.strip() for t in str(value).split(";") if t.strip()]


def _short_id(*parts):
    raw = "|".join(str(p) for p in parts)
    return hashlib.md5(raw.encode("utf-8")).hexdigest()[:10]


def _save(df, name):
    """Write a table as CSV + xlsx into output/."""
    os.makedirs(OUT_DIR, exist_ok=True)
    csv_path = os.path.join(OUT_DIR, f"{name}.csv")
    xlsx_path = os.path.join(OUT_DIR, f"{name}.xlsx")
    df.to_csv(csv_path, index=False)
    write_xlsx_safe(lambda p: df.to_excel(p, index=False), xlsx_path)
    print(f"  {name:14}: {len(df):4} rows -> {name}.csv / .xlsx")


# --------------------------------------------------------------------------- #
# 1. day_blocks
# --------------------------------------------------------------------------- #
def build_day_blocks(df, segments):
    rows = []
    for r in df.itertuples(index=False):
        city = "" if pd.isna(r.city) else str(r.city).strip()
        places = _tags(r.place_tags)
        themes = _tags(r.theme_tags)
        pickable = bool(city) and (bool(places) or bool(themes))
        rows.append({
            "day_block_id": _short_id(r.source, r.tour_url, r.day_number),
            "source": r.source,
            "segment": segments.get(str(r.source).lower(), "Competitor"),
            "tour_name": r.tour_name,
            "tour_url": r.tour_url,
            "day_number": r.day_number,
            "total_days": r.total_days,
            "state": "" if pd.isna(r.state) else r.state,
            "city": city,
            "city_type": "" if pd.isna(r.city_type) else r.city_type,
            "experiences": "; ".join(places),
            "activities": "; ".join(_tags(r.activity_tags)),
            "themes": "; ".join(themes),
            "description": "" if pd.isna(r.activity) else str(r.activity),
            "pickable": pickable,
        })
    return pd.DataFrame(rows)


# --------------------------------------------------------------------------- #
# 2. day_templates  (deduped "day ideas" = city + place signature)
# --------------------------------------------------------------------------- #
def build_day_templates(blocks):
    pick = blocks[blocks["pickable"]].copy()
    # signature = sorted, lowercased place set for that day
    pick["signature"] = pick["experiences"].map(
        lambda s: tuple(sorted({p.strip().lower() for p in s.split(";") if p.strip()}))
    )

    out = []
    for (city, sig), g in pick.groupby(["city", "signature"], sort=False):
        first = g.iloc[0]
        n_board = g.loc[g["segment"] == "Tourism Board", "tour_url"].nunique()
        n_comp = g.loc[g["segment"] == "Competitor", "tour_url"].nunique()
        # union of themes across the member days
        themes = sorted({t for s in g["themes"] for t in s.split("; ") if t})
        out.append({
            "template_id": _short_id(city, sig),
            "city": city,
            "state": first["state"],
            "city_type": first["city_type"],
            "experiences": first["experiences"],
            "themes": "; ".join(themes),
            "n_tours": g["tour_url"].nunique(),
            "n_board": n_board,
            "n_competitor": n_comp,
            "example_tour_name": first["tour_name"],
            "example_tour_url": first["tour_url"],
            "example_description": first["description"][:300],
            "member_day_block_ids": "; ".join(g["day_block_id"]),
        })
    templates = pd.DataFrame(out).sort_values(
        ["n_tours", "city"], ascending=[False, True]
    ).reset_index(drop=True)
    return templates


# --------------------------------------------------------------------------- #
# 3. place_catalog  (per attraction, board vs competitor coverage)
# --------------------------------------------------------------------------- #
def build_place_catalog(blocks):
    recs = []
    for r in blocks.itertuples(index=False):
        for place in _tags(r.experiences):
            recs.append((place, r.state, r.city_type, r.segment, r.tour_url, r.themes))
    long = pd.DataFrame(recs, columns=["place", "state", "city_type", "segment", "tour_url", "themes"])
    if long.empty:
        return pd.DataFrame()

    def _mode(s):
        m = s[s != ""].mode()
        return m.iat[0] if not m.empty else ""

    g = long.groupby("place")
    catalog = pd.DataFrame({
        "state": g["state"].agg(_mode),
        "city_type": g["city_type"].agg(_mode),
        "n_tours": g["tour_url"].nunique(),
    })
    board = long[long.segment == "Tourism Board"].groupby("place")["tour_url"].nunique()
    comp = long[long.segment == "Competitor"].groupby("place")["tour_url"].nunique()
    catalog["n_board"] = board.reindex(catalog.index).fillna(0).astype(int)
    catalog["n_competitor"] = comp.reindex(catalog.index).fillna(0).astype(int)
    catalog["themes"] = g["themes"].agg(
        lambda col: "; ".join(sorted({t for s in col for t in str(s).split("; ") if t}))
    )
    # popularity 0-100 within state
    catalog = catalog.reset_index()
    catalog["popularity"] = (
        catalog.groupby("state")["n_tours"]
        .transform(lambda x: (x / x.max() * 100).round().astype(int))
    )
    cols = ["place", "state", "city_type", "themes", "n_tours", "n_board", "n_competitor", "popularity"]
    return catalog[cols].sort_values(["n_tours", "place"], ascending=[False, True]).reset_index(drop=True)


# --------------------------------------------------------------------------- #
def main():
    print(f"Reading sample: {SAMPLE_CSV}")
    df = pd.read_csv(SAMPLE_CSV, low_memory=False)
    segments = _load_segments(SEGMENTS_TXT)
    print(f"  {len(df)} rows | segments loaded: {len(segments)} sources\n")

    blocks = build_day_blocks(df, segments)
    templates = build_day_templates(blocks)
    catalog = build_place_catalog(blocks)

    print("Writing tables:")
    _save(blocks, "day_blocks")
    _save(templates, "day_templates")
    _save(catalog, "place_catalog")

    # quick summary for review
    print("\n--- Summary ---")
    print(f"day_blocks   : {len(blocks)} rows, {blocks['pickable'].sum()} pickable, "
          f"segments={sorted(blocks['segment'].unique())}")
    print(f"day_templates: {len(templates)} (deduped from {blocks['pickable'].sum()} pickable days)")
    print(f"place_catalog: {len(catalog)} distinct places")
    if not templates.empty:
        print("\nTop 5 day-templates by market frequency:")
        print(templates[["city", "n_tours", "n_board", "n_competitor", "experiences"]]
              .head(5).to_string(index=False))


if __name__ == "__main__":
    main()
