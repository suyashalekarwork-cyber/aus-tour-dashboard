"""
Product Itinerary Prototype — Streamlit app.

Demonstrates the "experiences -> products" idea on a real sample of the
scraped data:
  * a rules-based engine tags every day with PRODUCTS (named places) + THEMES,
  * those tags power product insights, an itinerary builder, and a
    board-vs-operator gap view.

Run:  streamlit run app.py
"""

import os
import re
import html
from collections import defaultdict

import pandas as pd
import streamlit as st

import extractor as ex

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, "data", "sample_itineraries.csv")
SOURCE_LOCATIONS_PATH = os.path.join(os.path.dirname(HERE), "data", "config", "source_locations.csv")

st.set_page_config(page_title="Itinerary Products", page_icon="🧭", layout="wide")

# --------------------------------------------------------------------------
# Styling
# --------------------------------------------------------------------------
st.markdown(
    """
    <style>
      .chip {display:inline-block; padding:3px 10px; margin:2px 3px; border-radius:14px;
             font-size:0.80rem; font-weight:600; line-height:1.4;}
      .prod {background:#e3effd; color:#1257a6; border:1px solid #bcd8f7;}
      .theme {background:#e6f6ec; color:#1f7a42; border:1px solid #bfe6cd;}
      .board {background:#fdeede; color:#9a5b13; border:1px solid #f5d6ad;}
      .muted {color:#9aa0a6; font-size:0.8rem;}
      .daycard {border:1px solid #e6e8eb; border-radius:10px; padding:12px 16px; margin-bottom:10px;
                background:#fbfcfd;}
      .daynum {font-weight:700; color:#444; font-size:0.85rem; text-transform:uppercase; letter-spacing:.04em;}
      h1 {font-size:1.9rem;}
    </style>
    """,
    unsafe_allow_html=True,
)


def chips(items, css):
    items = [i for i in items if i]
    if not items:
        return '<span class="muted">— none —</span>'
    return " ".join(f'<span class="chip {css}">{html.escape(i)}</span>' for i in items)


# --------------------------------------------------------------------------
# Data
# --------------------------------------------------------------------------

@st.cache_data(show_spinner=False)
def _load_source_meta():
    df = pd.read_csv(SOURCE_LOCATIONS_PATH, dtype=str)
    labels    = dict(zip(df["source_key"], df["display_name"]))
    locations = dict(zip(df["source_key"], df["location"]))
    return labels, locations


@st.cache_data(show_spinner="Tagging itineraries with the rules engine…")
def load_tagged():
    source_labels, source_locations = _load_source_meta()
    df = pd.read_csv(DATA)
    df["location"] = df["location"].fillna("")
    df["activity"] = df["activity"].fillna("")
    df = ex.tag_dataframe(df)
    df["kind"]            = df["source"].map(lambda s: "Board" if s == "australia_com" else "Operator")
    df["source_label"]    = df["source"].map(source_labels).fillna(df["source"])
    df["source_location"] = df["source"].map(source_locations).fillna("Unknown")
    return df


@st.cache_data(show_spinner=False)
def build_catalog(tagged):
    recs = []
    theme_acc = defaultdict(set)
    for row in tagged.itertuples(index=False):
        prods = [p for p in row.product_tags.split("; ") if p]
        themes = [t for t in row.theme_tags.split("; ") if t]
        for p in prods:
            recs.append((p, row.state, row.tour_url, row.kind))
            theme_acc[p].update(themes)
    long = pd.DataFrame(recs, columns=["product", "state", "tour_url", "kind"])

    g = long.groupby("product")
    catalog = pd.DataFrame({"n_tours": g["tour_url"].nunique()})
    board = long[long.kind == "Board"].groupby("product")["tour_url"].nunique()
    oper = long[long.kind == "Operator"].groupby("product")["tour_url"].nunique()
    catalog["n_board_tours"] = board.reindex(catalog.index).fillna(0).astype(int)
    catalog["n_operator_tours"] = oper.reindex(catalog.index).fillna(0).astype(int)
    catalog["state"] = g["state"].agg(lambda s: s.mode().iat[0] if not s.mode().empty else s.iloc[0])
    catalog["themes"] = catalog.index.map(lambda p: ", ".join(sorted(theme_acc[p])))
    catalog["popularity"] = (
        catalog.groupby("state")["n_tours"].transform(lambda x: (x / x.max() * 100).round().astype(int))
    )
    catalog = catalog.reset_index().sort_values("n_tours", ascending=False)
    return catalog, long


tagged = load_tagged()
catalog, long = build_catalog(tagged)
STATES = sorted(tagged["state"].dropna().unique())
THEMES = list(ex.THEME_KEYWORDS.keys())

# --------------------------------------------------------------------------
# Navigation
# --------------------------------------------------------------------------
st.sidebar.title("🧭 Itinerary Products")
st.sidebar.caption("Prototype · rules-based tagging on real scraped data")
page = st.sidebar.radio(
    "View",
    ["Overview", "Product Insights", "Itinerary Builder", "Tagged Data", "Board vs Operator Gap"],
)
st.sidebar.markdown("---")
st.sidebar.metric("Tours", f"{tagged['tour_url'].nunique():,}")
st.sidebar.metric("Day rows", f"{len(tagged):,}")
st.sidebar.metric("Distinct products", f"{catalog['product'].nunique():,}")


# ==========================================================================
# OVERVIEW
# ==========================================================================
def view_overview():
    st.title("From experiences to products")
    st.write(
        "Generic itinerary text becomes **marketable products** (named places) plus "
        "**theme tags** — using transparent, editable rules. Below: try the engine live."
    )

    c = st.columns(4)
    cov = (tagged["product_tags"].str.len() > 0).mean()
    c[0].metric("Tours", f"{tagged['tour_url'].nunique():,}")
    c[1].metric("Distinct products", f"{catalog['product'].nunique():,}")
    c[2].metric("Product coverage", f"{cov*100:.0f}%", help="Share of days that got at least one product tag")
    c[3].metric("States", len(STATES))

    st.markdown("#### Try the extractor")
    st.caption("Edit the text — products and themes update from the rules (no AI).")
    demo = st.text_area(
        "Day description",
        "Back on the Gibb River Road, travel to Bell Gorge, where spectacular cliffs "
        "surround a series of tiered waterholes. You may hike to the lower pool and "
        "enjoy a swim below the cascading waterfall.",
        height=110,
    )
    prods = ex.extract_products(demo)
    themes = ex.extract_themes(demo)
    a, b = st.columns(2)
    a.markdown("**Products kept**")
    a.markdown(chips(prods, "prod"), unsafe_allow_html=True)
    b.markdown("**Themes**")
    b.markdown(chips(themes, "theme"), unsafe_allow_html=True)

    with st.expander("How the rules work"):
        st.markdown(
            "- **Keep as a product** if a phrase ends in a place-type word "
            "(*Gorge, Falls, Road, Bridge, Springs, National Park…*) or is in the known-places "
            "dictionary. Captures attraction-level names automatically.\n"
            "- **Keep as a theme** by matching activity keywords to the 8 business themes "
            "(*hike → Hiking & Walks, wine → Food & Wine…*).\n"
            "- **Drop** filler (*travel to, continue, back on the…*).\n\n"
            "Every rule list lives in `extractor.py` and is meant to be edited by the business."
        )


# ==========================================================================
# PRODUCT INSIGHTS
# ==========================================================================
def view_insights():
    st.title("Product insights")
    st.caption("Which products dominate each state — popularity = number of distinct tours featuring it.")

    f = st.columns([1, 1, 2])
    state = f[0].selectbox("State", ["All states"] + STATES)
    theme = f[1].selectbox("Theme", ["All themes"] + THEMES)
    topn = f[2].slider("How many top products", 5, 30, 15)

    view = catalog.copy()
    if state != "All states":
        view = view[view["state"] == state]
    if theme != "All themes":
        view = view[view["themes"].str.contains(theme, regex=False)]
    view = view.sort_values(["popularity", "n_tours"], ascending=False)

    if view.empty:
        st.info("No products match these filters.")
        return

    top = view.head(topn)
    st.markdown(f"**Top {len(top)} products** · {state} · {theme}")
    try:
        st.bar_chart(top.set_index("product")["n_tours"], horizontal=True, color="#1257a6")
    except TypeError:
        st.bar_chart(top.set_index("product")["n_tours"])

    st.dataframe(
        view[["product", "state", "themes", "n_tours", "popularity"]].rename(
            columns={"n_tours": "tours", "popularity": "popularity (0-100)"}
        ),
        width="stretch",
        hide_index=True,
    )

    st.markdown("#### Where a product appears")
    pick = st.selectbox("Product", view["product"].tolist())
    hits = tagged[tagged["product_tags"].str.contains(r"\b" + re.escape(pick) + r"\b", regex=True)]
    st.caption(f"{pick} appears in {hits['tour_url'].nunique()} tours, {len(hits)} days.")
    show = hits[["tour_name", "day_number", "location", "source_label"]].head(40)
    st.dataframe(show, width="stretch", hide_index=True)


# ==========================================================================
# ITINERARY BUILDER
# ==========================================================================
def view_builder():
    st.title("Itinerary builder")
    st.caption("Pick a state and length, then drop recommended products into each day — Neha's workflow.")

    f = st.columns([1, 1])
    state = f[0].selectbox("State", STATES, index=STATES.index("WA") if "WA" in STATES else 0)
    ndays = f[1].slider("Days", 2, 10, 4)

    pool = catalog[catalog["state"] == state].sort_values("n_tours", ascending=False)
    options = pool["product"].tolist()
    theme_of = dict(zip(pool["product"], pool["themes"]))

    if not options:
        st.info("No products for this state.")
        return

    st.markdown(f"**Recommended for {state}** (most popular first)")
    st.markdown(chips(options[:12], "prod"), unsafe_allow_html=True)
    st.markdown("---")

    plan = {}
    for d in range(1, ndays + 1):
        st.markdown(f'<div class="daynum">Day {d}</div>', unsafe_allow_html=True)
        picks = st.multiselect(
            f"Products for day {d}",
            options,
            key=f"{state}_day_{d}",
            label_visibility="collapsed",
            placeholder="Add products to this day…",
        )
        plan[d] = picks
        if picks:
            day_themes = sorted({t for p in picks for t in theme_of.get(p, "").split(", ") if t})
            st.markdown(chips(day_themes, "theme"), unsafe_allow_html=True)

    rows = [
        {"day": d, "product": p, "state": state, "themes": theme_of.get(p, "")}
        for d, ps in plan.items() for p in ps
    ]
    st.markdown("---")
    if rows:
        out = pd.DataFrame(rows)
        st.markdown(f"#### Your {ndays}-day {state} itinerary · {len(out)} products")
        st.dataframe(out, width="stretch", hide_index=True)
        st.download_button(
            "⬇ Download itinerary (CSV)",
            out.to_csv(index=False).encode("utf-8"),
            file_name=f"itinerary_{state}_{ndays}day.csv",
            mime="text/csv",
        )
    else:
        st.info("Add products above to build the itinerary.")


# ==========================================================================
# TAGGED DATA
# ==========================================================================
def view_tagged():
    st.title("Tagged data")
    st.caption("Every scraped day, now carrying product_tags + theme_tags. This is the asset.")

    f = st.columns([1, 1, 2])
    src = f[0].selectbox("Source", ["All"] + sorted(tagged["source_label"].unique()))
    state = f[1].selectbox("State", ["All"] + STATES, key="td_state")
    q = f[2].text_input("Search tour name")

    view = tagged
    if src != "All":
        view = view[view["source_label"] == src]
    if state != "All":
        view = view[view["state"] == state]
    if q:
        view = view[view["tour_name"].str.contains(q, case=False, na=False)]

    st.caption(f"{view['tour_url'].nunique()} tours · {len(view)} days")
    show = view[["tour_name", "day_number", "state", "location", "product_tags", "theme_tags"]].copy()
    show["location"] = show["location"].str.slice(0, 60)
    st.dataframe(show.head(300), width="stretch", hide_index=True)


# ==========================================================================
# BOARD VS OPERATOR GAP
# ==========================================================================
def view_gap():
    st.title("Board vs operator gap")
    st.caption(
        "Products the tourism board features vs what your operator tours cover — "
        "the roadmap for board-aligned itineraries."
    )
    st.info(
        "In this sample the only **board** source is Australia.com. Phase 1 of the plan "
        "adds the state tourism boards, which makes this comparison far richer.",
        icon="ℹ️",
    )

    state = st.selectbox("State", STATES)
    sub = catalog[catalog["state"] == state]
    board = sub[sub["n_board_tours"] > 0]
    both = sub[(sub["n_board_tours"] > 0) & (sub["n_operator_tours"] > 0)]
    board_only = sub[(sub["n_board_tours"] > 0) & (sub["n_operator_tours"] == 0)]
    oper_only = sub[(sub["n_operator_tours"] > 0) & (sub["n_board_tours"] == 0)]

    m = st.columns(3)
    m[0].metric("Board features", len(board))
    m[1].metric("In both", len(both))
    m[2].metric("Board-only (gap to fill)", len(board_only))

    a, b = st.columns(2)
    a.markdown("**Board recommends, operators miss** — products to add")
    a.markdown(chips(board_only.sort_values("n_tours", ascending=False)["product"].tolist()[:40], "board"),
               unsafe_allow_html=True)
    b.markdown("**Operators cover, board doesn't** — your differentiators")
    b.markdown(chips(oper_only.sort_values("n_tours", ascending=False)["product"].tolist()[:40], "prod"),
               unsafe_allow_html=True)


PAGES = {
    "Overview": view_overview,
    "Product Insights": view_insights,
    "Itinerary Builder": view_builder,
    "Tagged Data": view_tagged,
    "Board vs Operator Gap": view_gap,
}
PAGES[page]()
