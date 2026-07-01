# Data Pipeline — which file does what

This is the map of how scraped itinerary data flows from raw scraper output to the
final datasets you analyse. Use it to know, at any stage, **where your data is and
which script produced it**.

## The flow

```
webscraping/{any_source}/data/latest/itinerary_days.(csv|xlsx)   ← raw scraper output (per source)
        │   auto-discovered — adding a new source needs NO code change
        ▼  combine_sources.py
data/raw/combined_itineraries_latest.csv   +   combined_itineraries_<YYYY-MM>.csv   ← MASTER dataset
        ▼  build_keyword_dataset.py   (applies data/config/*.txt)
data/keywords/keyword_dataset_<YYYY-MM>.csv   +   keyword_review.csv               ← FINAL tagged dataset
        ▼  keyword_review_tool.py export
data/keywords/keyword_review.xlsx          ← STOP: clean keywords here (fill "Action" column)
        ▼  keyword_review_tool.py update   (apply decisions + rebuild + re-export)
data/config/{blacklist,synonyms,allowlist}.txt   +   refreshed keyword dataset
```

## One command

```bash
python run_pipeline.py            # combine → build → export review (current month)
# or on Windows: double-click run_pipeline.bat
```

This runs steps 1–3 and stops at the manual cleaning step. After you clean the
keywords in `keyword_review.xlsx`, run:

```bash
python analysis/keyword_review_tool.py update
```

## Your two final analysis files

| Use case | File |
|----------|------|
| General tour/itinerary analysis | `data/raw/combined_itineraries_latest.csv` |
| Keyword / theme / product analysis | `data/keywords/keyword_dataset_<YYYY-MM>.csv` (superset of the master, with tags) |

## Script reference

| Script | Reads | Writes | Role |
|--------|-------|--------|------|
| `run_pipeline.py` | — | — | **Single entry point**: runs combine → build → export review, then stops |
| `webscraping/pipeline/combine_sources.py` | every `webscraping/*/data/latest/itinerary_days.(csv\|xlsx)` | `data/raw/combined_itineraries_{latest,YYYY-MM}.csv` | Normalises all sources to one schema, enriches state/city, filters non-Australia |
| `analysis/build_keyword_dataset.py` | `data/raw/combined_itineraries_<YYYY-MM>.csv` + `data/config/*.txt` | `data/keywords/keyword_dataset_<YYYY-MM>.csv`, `keyword_review.csv` (+ `.xlsx`) | NER + 4-layer keyword tagging (place/activity/theme) |
| `analysis/keyword_review_tool.py export` | `data/keywords/keyword_review.csv` | `data/keywords/keyword_review.xlsx` | Builds the interactive review workbook |
| `analysis/keyword_review_tool.py apply` | `data/keywords/keyword_review.xlsx` | `data/config/{blacklist,synonyms,allowlist}.txt`, `keyword_changelog.txt` | Applies your cleaning decisions |
| `analysis/keyword_review_tool.py update` | the review xlsx | config files + rebuilt dataset | apply + rebuild + re-export in one go |
| `analysis/export_excel.py` | `data/raw/combined_itineraries_latest.csv` | `analysis/itineraries_for_manager.xlsx` | Manager-ready AU-only export |
| `analysis/export_competitors.py` | `webscraping/*/data/latest/itinerary_days.csv` | `analysis/competitor_itineraries.xlsx` | Competitor-only export |
| `analysis/export_debug_excel.py` | `data/raw/combined_itineraries_latest.csv` | `analysis/itineraries_all_sources.xlsx` | Raw inspection workbook |
| `analysis/dashboard.py` | `data/raw/combined_itineraries_latest.csv` | (live Streamlit UI) | Interactive explorer — `streamlit run analysis/dashboard.py` |
| `analysis/csv_to_xlsx.py` | all project CSVs | sibling `.xlsx` files | Bulk CSV→XLSX (use **without** `--delete` to keep CSVs the source of truth) |

## Adding a new source

1. Scrape it so it produces `webscraping/<new_source>/data/latest/itinerary_days.csv`
   (or `.xlsx`) with columns including `source`, `source_type`, `tour_name`,
   `tour_url`, `total_days`, `day_number`, `location`, and either `activity` or
   `description`/`experiences`.
2. Run `python run_pipeline.py`. The combiner auto-discovers the folder — **no code
   edit required**. Set `source_type` to `Competitor` in the scraper output so it's
   classified correctly.

> Bespoke loaders in `combine_sources.py` (the original 9 tourism-board/reference
> sources) still take precedence; everything else is auto-discovered.

## Downstream: the Itinerary Builder app

`keyword_dataset_<YYYY-MM>.csv` is also the input to `app/pipeline/prepare_data.py`,
which builds the Itinerary Builder's Templates tab (`app/frontend/data.js`). Templates
are **multi-state-aware**: a single scraped tour can touch several states (about a
third of all tours do), so each template carries a `states: [...]` list rather than
being bucketed into one state. See `CONTEXT.md` for the full `app/` data flow.
