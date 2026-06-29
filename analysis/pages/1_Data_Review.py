"""
Streamlit page — Data Review.

Shows the FULL raw itinerary dataset (every day row, all columns) with a set of
searchable filters, so the scraped data can be reviewed/QA'd in the browser instead
of opening the Excel/CSV file.

This file is auto-discovered by Streamlit because it lives in `analysis/pages/`,
next to the entry app `analysis/dashboard.py`. Run the app as usual:

    cd Itinenaries_analysis
    streamlit run analysis/dashboard.py
"""

from pathlib import Path

import pandas as pd
import streamlit as st

# ── Constants ─────────────────────────────────────────────────────────────────

ROOT     = Path(__file__).resolve().parent.parent.parent
CSV_PATH = ROOT / "data" / "raw" / "combined_itineraries_latest.csv"

SOURCE_LABELS = {
    "global_journeys":  "Global Journeys",
    "inside_australia": "Inside Australia",
    "australia_com":    "Australia.com",
}

# All 12 columns in display order.
COLUMNS = [
    "source", "source_type", "tour_name", "tour_url", "total_days",
    "day_number", "location", "activity", "scrape_date", "state",
    "city", "city_type",
]


# ── Data loading ──────────────────────────────────────────────────────────────

@st.cache_data
def load_raw():
    """Return the full raw dataset — no rows hidden, no aggregation."""
    df = pd.read_csv(CSV_PATH, encoding="utf-8-sig", dtype=str)
    df["day_number"] = pd.to_numeric(df["day_number"], errors="coerce").fillna(0).astype(int)
    df["total_days"] = pd.to_numeric(df["total_days"], errors="coerce").fillna(0).astype(int)
    df["source"]     = df["source"].map(SOURCE_LABELS).fillna(df["source"])
    # Keep a stable column order; tolerate any unexpected extras at the end.
    ordered = [c for c in COLUMNS if c in df.columns]
    extras  = [c for c in df.columns if c not in ordered]
    return df[ordered + extras]


def _opts(series: pd.Series) -> list[str]:
    """Sorted unique non-blank values for a multiselect."""
    return sorted({v.strip() for v in series.dropna() if str(v).strip()})


# ── App ───────────────────────────────────────────────────────────────────────

def main():
    st.set_page_config(
        page_title="Data Review",
        page_icon="🔎",
        layout="wide",
        initial_sidebar_state="expanded",
    )

    df = load_raw()

    # ── Sidebar filters ─────────────────────────────────────────────────────────
    with st.sidebar:
        st.markdown("## 🔎 Data Review")
        st.caption("Browse the full raw dataset with filters.")

        if st.button("↺ Reset filters", use_container_width=True):
            for k in list(st.session_state.keys()):
                if k.startswith("dr_"):
                    del st.session_state[k]
            st.rerun()

        st.divider()

        search = st.text_input(
            "Search text",
            key="dr_search",
            placeholder="e.g. reef, Uluru, Margaret River…",
            help="Searches across tour name, location and activity (case-insensitive).",
        )

        sources      = st.multiselect("Source",      _opts(df["source"]),      key="dr_source")
        source_types = st.multiselect("Source type", _opts(df["source_type"]), key="dr_stype")
        states       = st.multiselect("State",       _opts(df["state"]),       key="dr_state")
        cities       = st.multiselect("City",        _opts(df["city"]),        key="dr_city")
        city_types   = st.multiselect("City type",   _opts(df["city_type"]),   key="dr_ctype")

        td_min, td_max = int(df["total_days"].min()), int(df["total_days"].max())
        if td_min < td_max:
            td_range = st.slider("Total days", td_min, td_max, (td_min, td_max), key="dr_td")
        else:
            td_range = (td_min, td_max)

        dn_min, dn_max = int(df["day_number"].min()), int(df["day_number"].max())
        if dn_min < dn_max:
            dn_range = st.slider("Day number", dn_min, dn_max, (dn_min, dn_max), key="dr_dn")
        else:
            dn_range = (dn_min, dn_max)

    # ── Filter (all combine with AND) ────────────────────────────────────────────
    filt = df
    if search and search.strip():
        s = search.strip()
        mask = (
            filt["tour_name"].str.contains(s, case=False, na=False)
            | filt["location"].str.contains(s, case=False, na=False)
            | filt["activity"].str.contains(s, case=False, na=False)
        )
        filt = filt[mask]
    if sources:
        filt = filt[filt["source"].isin(sources)]
    if source_types:
        filt = filt[filt["source_type"].isin(source_types)]
    if states:
        filt = filt[filt["state"].isin(states)]
    if cities:
        filt = filt[filt["city"].isin(cities)]
    if city_types:
        filt = filt[filt["city_type"].isin(city_types)]
    filt = filt[filt["total_days"].between(td_range[0], td_range[1])]
    filt = filt[filt["day_number"].between(dn_range[0], dn_range[1])]

    # ── Main area ────────────────────────────────────────────────────────────────
    st.markdown("# Data Review — Raw Itinerary Dataset")
    st.caption("Every day-level row, all columns. Use the sidebar filters to navigate.")

    m1, m2, m3 = st.columns(3)
    m1.metric("Rows shown", f"{len(filt):,}")
    m2.metric("Total rows", f"{len(df):,}")
    m3.metric("Tours shown", f"{filt['tour_url'].nunique():,}")

    st.divider()

    if len(filt) == 0:
        st.info("No rows match your filters — try clearing some, or use Reset filters.")
        return

    st.dataframe(
        filt,
        use_container_width=True,
        hide_index=True,
        height=650,
        column_config={
            "source":      st.column_config.TextColumn("Source",      width="small"),
            "source_type": st.column_config.TextColumn("Source type", width="small"),
            "tour_name":   st.column_config.TextColumn("Tour name",   width="medium"),
            "tour_url":    st.column_config.LinkColumn("URL",         width="small", display_text="🔗 open"),
            "total_days":  st.column_config.NumberColumn("Total days", width="small", format="%d"),
            "day_number":  st.column_config.NumberColumn("Day #",      width="small", format="%d"),
            "location":    st.column_config.TextColumn("Location",    width="medium"),
            "activity":    st.column_config.TextColumn("Activity",    width="large"),
            "scrape_date": st.column_config.TextColumn("Scraped",     width="small"),
            "state":       st.column_config.TextColumn("State",       width="small"),
            "city":        st.column_config.TextColumn("City",        width="small"),
            "city_type":   st.column_config.TextColumn("City type",   width="small"),
        },
    )

    csv_bytes = filt.to_csv(index=False).encode("utf-8-sig")
    st.download_button(
        label=f"⬇ Download {len(filt):,} rows (CSV)",
        data=csv_bytes,
        file_name="data_review_filtered.csv",
        mime="text/csv",
    )


if __name__ == "__main__":
    main()
