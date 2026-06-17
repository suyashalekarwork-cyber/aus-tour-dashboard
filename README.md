# Itineraries Analysis

Scrape Australia travel itineraries from many tour operators and tourism boards,
combine them into one day-level dataset, tag each day with places/activities/themes,
and explore the result in dashboards.

```
scrape sources  ─▶  combine into master CSV  ─▶  keyword-tag  ─▶  clean  ─▶  analyse/dashboards
 webscraping/*        data/raw/                   data/keywords/    (Excel)    analysis/, product_app/
```

> **Two reference docs:** this README is the overview. [PIPELINE.md](PIPELINE.md)
> is the detailed "which script reads/writes what" map — read it when you need to
> know exactly where your data is at any stage.

## Quick start

```bash
# 1. One command: combine all scraped sources → tag keywords → export review workbooks
python run_pipeline.py                  # current month
#    (Windows: double-click run_pipeline.bat)

# 2. Manually clean keywords in data/keywords/keyword_review.xlsx (fill the "Action" column),
#    then apply + rebuild:
python analysis/keyword_review_tool.py update

# 3. Explore:
streamlit run analysis/dashboard.py     # the data dashboard
cd product_app && streamlit run app.py  # the "experiences → products" prototype
```

`run_pipeline.py` runs steps 1–3 below and stops at the manual cleaning step:

1. `webscraping/pipeline/combine_sources.py` → `data/raw/combined_itineraries_{latest,YYYY-MM}.csv`
2. `analysis/build_keyword_dataset.py` → `data/keywords/keyword_dataset_YYYY-MM.csv`
3. `analysis/keyword_review_tool.py export` + `analysis/city_review_tool.py export` → review workbooks

Flags: `--month 2026-06` (explicit snapshot label), `--skip-combine` (re-tag without re-combining).

## Repo layout

```
Itinenaries_analysis/
├── run_pipeline.py / run_pipeline.bat   # one-command orchestrator (combine → tag → export review)
├── requirements.txt                     # top-level app deps: streamlit, pandas, openpyxl
├── PIPELINE.md                          # detailed data-flow / script reference
│
├── webscraping/                         # all scrapers (one folder per source) + the combiner
│   ├── sources.json                     # central URL config (one entry per site)
│   ├── pipeline/
│   │   ├── combine_sources.py           # normalise every source → unified schema, enrich, filter to AU
│   │   ├── validate_schema.py           # schema / data-quality checks
│   │   └── run_all.py                   # scrape everything, then combine + validate
│   ├── requirements.txt                 # scraper deps: playwright, beautifulsoup4, lxml, requests
│   └── <source>/                        # e.g. global_journeys, scott_dunn, australia_com, …
│       └── data/latest/itinerary_days.(csv|xlsx)   # raw scraper output (what the combiner reads)
│
├── data/                                # processed datasets (the analysis inputs)
│   ├── raw/      combined_itineraries_{latest,YYYY-MM}.(csv|xlsx)   ← MASTER day-level dataset
│   ├── keywords/ keyword_dataset_YYYY-MM.csv + keyword_review.(csv|xlsx)   ← tagged dataset + review
│   └── config/   {keyword,city,state}_* rules + changelogs            ← cleaning decisions live here
│
├── analysis/                            # processing + reporting scripts (see table below)
│   ├── build_keyword_dataset.py         # NER + 4-layer place/activity/theme tagging
│   ├── keyword_review_tool.py           # export/apply/update the keyword-cleaning workbook
│   ├── city_review_tool.py              # export/apply the city-cleaning workbook
│   ├── dashboard.py                     # Streamlit data explorer
│   ├── export_*.py / csv_to_xlsx.py     # Excel exports (manager / competitor / debug views)
│   └── *.ipynb                          # EDA notebooks
│
├── product_app/                         # self-contained Streamlit prototype: "experiences → products"
└── sampledata/                          # source/reference spreadsheets
```

Every scraper folder writes to `data/latest/` (always overwritten with the newest
run) and dated monthly snapshots. The combiner **auto-discovers** these folders, so
adding a new source needs no code change (see below).

## Sources

Listed in `webscraping/sources.json`. Each is tagged in the master dataset as either
`Competitor` (a direct tour operator we compete with) or `Reference` (a tourism board /
inspiration site).

| Type | Sources |
|------|---------|
| **Competitor** (operators) | global_journeys, inside_australia, absolute_australia, scott_dunn, jacada, thomas_cook, sotc, kesari, veena_world, make_my_trip |
| **Reference** (boards) | australia_com, westernaustralia_com, visitvictoria_com, queensland_com, sydney_com, discover_tasmania |

`source_type` is set per-source in the scraper output (or in `combine_sources.py`).
Scraper conventions for each competitor site are documented in the agent memory notes.

## Unified schema

`combine_sources.py` normalises every source to **one row per tour per day**:

| column | meaning |
|---|---|
| source | site key, e.g. `global_journeys`, `australia_com` |
| source_type | `Competitor` \| `Reference` |
| tour_name | tour / itinerary title |
| tour_url | source URL |
| total_days | trip length (int) |
| day_number | day index within the trip |
| location | the day's place(s) |
| activity | what happens that day |
| price | per-person price (tourism boards have none) |
| scrape_date | date the source data was captured |

The combiner also enriches state/city and filters out non-Australia rows. The
keyword dataset (`data/keywords/`) is a superset of this, adding place/activity/theme tags.

## Script reference (analysis + pipeline)

| Script | Role |
|--------|------|
| `run_pipeline.py` | **Single entry point** — combine → build → export review, then stops |
| `webscraping/pipeline/combine_sources.py` | Normalise all sources → master CSV, enrich state/city, filter to AU |
| `webscraping/pipeline/validate_schema.py` | Schema / data-quality checks |
| `analysis/build_keyword_dataset.py` | NER + 4-layer keyword tagging → `data/keywords/keyword_dataset_*.csv` |
| `analysis/keyword_review_tool.py` | `export` review xlsx · `apply` decisions → config · `update` = apply + rebuild + re-export |
| `analysis/city_review_tool.py` | `export`/`apply` the city-name + city-type cleaning workbook |
| `analysis/dashboard.py` | Interactive Streamlit explorer over the master dataset |
| `analysis/export_excel.py` | Manager-ready AU-only Excel export |
| `analysis/export_competitors.py` | Competitor-only Excel export |
| `analysis/export_debug_excel.py` | Raw all-sources inspection workbook |
| `analysis/csv_to_xlsx.py` | Bulk CSV→XLSX (run **without** `--delete` to keep CSVs the source of truth) |

## The two final datasets

| Use case | File |
|----------|------|
| General tour/itinerary analysis | `data/raw/combined_itineraries_latest.csv` |
| Keyword / theme / product analysis | `data/keywords/keyword_dataset_YYYY-MM.csv` (master + tags) |

## Adding a new source

1. Scrape it so it produces `webscraping/<new_source>/data/latest/itinerary_days.csv`
   (or `.xlsx`) with columns including `source`, `source_type`, `tour_name`,
   `tour_url`, `total_days`, `day_number`, `location`, and either `activity` or
   `description`/`experiences`. Set `source_type` to `Competitor` for operators.
2. Run `python run_pipeline.py`. The combiner **auto-discovers** the folder — no code
   edit required.

> Bespoke loaders in `combine_sources.py` (the original tourism-board/reference
> sources) take precedence; everything else is auto-discovered.

## Environments

Two separate dependency sets:

```bash
# App / analysis (root) — dashboard, pipeline orchestration, Excel exports
pip install -r requirements.txt           # streamlit, pandas, openpyxl

# Scrapers — Playwright + parsing
pip install -r webscraping/requirements.txt
python -m playwright install chromium
```

> Note: `webscraping/` and generated `*.xlsx` are git-ignored — the repo tracks the
> code and processed `data/` outputs, not the raw scrape working dirs.
