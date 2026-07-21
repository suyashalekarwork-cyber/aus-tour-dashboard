# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Preferences

- Keep words simple and direct — no filler phrases like "Great question!"
- If something is unclear, ask before assuming
- Python 3.11+: type hints always, f-strings (not `.format()`)
- Prefer code + bullet points over long prose
- Don't explain things I didn't ask about

## Subagent Rules
- Never spawn Explore automatically
- Only explore files I explicitly name
- Ask before scanning broadly across the codebase
- Always prefer reading specific files over broad exploration

## Context
- Primary project: Itinenaries_analysis (Python/Streamlit/data analysis)
- Use existing virtual environment at webscraping/.venv

## What this project is

Scrape Australia travel itineraries from many tour operators and tourism boards,
combine them into one day-level dataset, tag each day with places/activities/themes,
and explore the result in Streamlit dashboards. The business goal is to assemble our
own competitive itineraries informed by what boards promote vs. what operators actually
sell. See `README.md` (overview), `PIPELINE.md` (exact file-by-file data flow), and
`CONTEXT.md` (business framing + the downstream `app/` Itinerary Builder PM tool).

## Commands

```bash
# Full pipeline: combine all scraped sources → keyword-tag → export review workbooks
python run_pipeline.py                 # current month; Windows: run_pipeline.bat
python run_pipeline.py --month 2026-06 # explicit snapshot label
python run_pipeline.py --skip-combine  # re-tag without re-combining

# After manually cleaning data/keywords/keyword_review.xlsx (fill "Action" column):
python analysis/keyword_review_tool.py update   # apply decisions + rebuild + re-export

# After manually filling data/config/city_review.xlsx (parallel track to keyword review):
python analysis/city_review_tool.py apply       # apply city/state renames + type classifications
python analysis/city_review_tool.py export      # re-export fresh review workbook without applying

# Convert CSVs to .xlsx (keep CSVs as source of truth — never use --delete)
python analysis/csv_to_xlsx.py            # convert all real data CSVs to sibling .xlsx files
python analysis/csv_to_xlsx.py --dry-run  # preview what would be converted

# Export competitor/reference workbooks for manager review
python analysis/export_competitors_excel.py   # competitor tours (source_type==Competitor) → Excel
python analysis/export_competitors.py         # competitor itineraries → manager-friendly workbook

# Dashboards
streamlit run analysis/dashboard.py    # data explorer over the master dataset
cd product_app && streamlit run app.py # "experiences → products" prototype (see product_app/)

# Itinerary Builder PM tool (app/) — local server, then open http://localhost:8765
python app/server/server.py

# Share the Builder with a remote PM (bundled Cloudflare tunnel, no account needed)
python app/server/go_live.py   # starts server.py + cloudflared.exe, writes a share link + creds file
# Link changes every run — re-share it each time. Don't hand-roll a separate tunnel; this script exists.

# Geo extraction: ground itinerary places to real Australian locations
python analysis/geo_extract_experiment.py --backend ollama  # free, local (qwen2.5:7b), slow (~8–12 hrs for 9k rows)
python analysis/geo_extract_experiment.py --backend cloud   # faster (~3–5 hrs), uses company n8n proxy quota

# Scrape everything then combine + validate
python webscraping/pipeline/run_all.py
python webscraping/pipeline/validate_schema.py

# Partial combine (dev/debug — re-combine one or two sources only):
python webscraping/pipeline/combine_sources.py --only global_journeys inside_australia
```

There is no test suite or linter. `product_app/smoke_test.py` is a standalone sanity
check for that sub-app only (`python product_app/smoke_test.py`).

## Two dependency environments (kept separate on purpose)

```bash
pip install -r requirements.txt            # root: streamlit, pandas, openpyxl (app/analysis)
pip install -r webscraping/requirements.txt && python -m playwright install chromium  # scrapers
```

Optional NER backends for `build_keyword_dataset.py`:
```bash
pip install spacy && python -m spacy download en_core_web_lg  # local NER (default when installed)
# or set NER_BACKEND=cloud to use the company n8n webhook proxy (shared company quota; use sparingly)
# or set GEMINI_API_KEY + NER_BACKEND=gemini for Gemini Flash NER
```

## Choosing LLM backends

| Task | Command | Cost | Speed | Use when |
|------|---------|------|-------|----------|
| NER tagging | `NER_BACKEND=cloud` | Company quota | Medium (~2 hrs for 9k rows) | Need speed; safe for daytime runs |
| NER tagging | `NER_BACKEND=ollama` | Free | Slow (~4–6 hrs for 9k rows) | Overnight; no quota impact |
| Geo extraction | `--backend cloud` | Company quota | Fast (~3–5 hrs) | Daytime; need quick iteration |
| Geo extraction | `--backend ollama` | Free | Slow (~8–12 hrs) | Overnight; default choice |
| Geo extraction | `--backend gemini` | Requires API key | Fast (~2–3 hrs) | Only if GEMINI_API_KEY set |

**Key**: Ollama (free, local) is the safe default. Cloud backends use the company's shared n8n proxy — check with your team before large runs.

**Geo cache behavior**: `geo_api_cache.json` accumulates verdicts from all runs. Re-running with new data will reuse cached verdicts for unchanged rows (fast) and only adjudicate new candidates.

## Architecture — the data flow is the architecture

Data moves left to right; each stage is a script that reads the previous stage's output:

```
webscraping/<source>/data/latest/itinerary_days.(csv|xlsx)   ← raw scraper output, one folder per source
  │  combine_sources.py  (auto-discovers source folders; normalises to unified schema, enriches state/city, filters to AU)
  ▼
data/raw/combined_itineraries_{latest,YYYY-MM}.csv           ← MASTER dataset (one row per tour per day)
  │  build_keyword_dataset.py  (NER + 4-layer place/activity/theme tagging; applies data/config/*.txt rules)
  ▼
data/keywords/keyword_dataset_YYYY-MM.csv + keyword_review.xlsx   ← FINAL tagged dataset + manual-review workbook
  │  keyword_review_tool.py update  (apply human decisions → data/config/*.txt, then rebuild)
  ▼                                                           ← parallel: city_review_tool.py apply
data/config/{keyword,city,state}_* rules + changelogs        ← all cleaning decisions persist here
```

Key structural facts a new contributor needs:

- **`run_pipeline.py` is the single entry point.** It runs combine → build → export and
  deliberately **stops** at the manual keyword-cleaning step. Cleaning is a human-in-the-loop
  Excel step (`keyword_review.xlsx`), then `keyword_review_tool.py update` resumes the flow.
- **Adding a source needs no code change.** Drop a scraper that writes
  `webscraping/<new_source>/data/latest/itinerary_days.(csv|xlsx)` with the unified columns
  (`source`, `source_type`, `tour_name`, `tour_url`, `total_days`, `day_number`, `location`,
  and `activity`/`description`/`experiences`); `combine_sources.py` auto-discovers it. Bespoke
  loaders inside `combine_sources.py` (the original tourism-board sources) take precedence over
  auto-discovery.
- **Cleaning decisions live in `data/config/*.txt`, not in code.** Blacklists, synonyms,
  allowlists, and changelogs are plain text the review tools rewrite. Tagging is rules-based
  and editable — there is no black-box model in the tagging step.
- **`source_type` is `Competitor` (tour operators we compete with) or `Reference` (tourism
  boards).** This split is the core analytical axis — board "what's promoted" vs. operator
  "what's actually sold". Set it in the scraper output.
- **CSV is the source of truth; xlsx are derived.** `csv_to_xlsx.py` regenerates workbooks —
  run it **without** `--delete` so the CSVs remain authoritative.
- **Blacklist matching is context-aware.** Single-word entries match exactly (adding "Creek"
  won't block "Tunnel Creek"); multi-word entries match the exact phrase. Keep this in mind
  when adding entries to `data/config/keyword_blacklist.txt`.
- **City review is a separate human-in-the-loop track.** `run_pipeline.py` exports
  `data/config/city_review.xlsx` as its final step. Fill city/state renames and type
  classifications there, then run `city_review_tool.py apply` independently of keyword review.
- **`product_app/` is a separate prototype**, not part of the main pipeline. It's an
  "experiences → products" builder (`streamlit run product_app/app.py`) with its own
  dependency on the keyword dataset. Sanity-check with `python product_app/smoke_test.py`.
- **`app/` is the PM-facing Itinerary Builder**, downstream of the keyword dataset. It's a
  single-file React app (`app/frontend/builder.html`, no build step) served locally by
  `app/server/server.py` (`python app/server/server.py` → http://localhost:8765). Data prep
  scripts live in `app/pipeline/` (`prepare_all.py` regenerates `data.js`/`tokens.js` from
  the latest keyword dataset). See `CONTEXT.md` for the tool's tabs and data flow.

## The two final datasets

| Use case | File |
|----------|------|
| General tour/itinerary analysis | `data/raw/combined_itineraries_latest.csv` |
| Keyword / theme / product analysis | `data/keywords/keyword_dataset_YYYY-MM.csv` (master + tags) |

## Conventions for writing scrapers

Each competitor site has its own folder under `webscraping/` and its own scraping strategy
(private JSON API, transfer-state JSON, plain HTML, PDF vision, etc.). Per-source conventions
are recorded in the agent memory notes (`MEMORY.md` index) — **read the relevant memory note
before touching or adding a scraper.** General rules: one row per day; experiences/highlights
are the signal; exclude hotels; sequential day numbering.

## Repo notes

- Windows-first (PowerShell primary). Use forward slashes in Python; `run_pipeline.bat` is the
  double-click entry.
- `webscraping/` working dirs and generated `*.xlsx` are git-ignored — the repo tracks code and
  processed `data/` outputs, not raw scrape working state.
- `_archive/` is a legacy snapshot dir — do not edit.
- The old `Build and explore/` and `Build and explore New/` prototype dirs have been removed;
  the Itinerary Builder now lives permanently at `app/` (see above).
- The active pipeline is the top-level `run_pipeline.py` + `analysis/` + `webscraping/` flow.
- `ROADMAP.md` tracks the 13-week project timeline (June–August 2026) — read it for phase/priority context.
- **Templates in `app/` are multi-state.** `app/pipeline/prepare_data.py` builds one global
  `TEMPLATES` list per tour (not per-state buckets); each template carries `states: [...]`
  since a scraped tour can span multiple Australian states. See `PIPELINE.md`/`CONTEXT.md`.
- **Hiding a Builder nav tab without deleting it:** `app/frontend/builder.html` has a
  `HIDDEN_TABS` array (near the `NAV` definition) — add a tab's key there to hide it from
  the sidebar while keeping its code and data intact for later.

## AI prediction (`app/server/server.py` `/api/predict`) gotchas

- Proxy is primary, Ollama (`qwen2.5:7b`, localhost:11434) is fallback — keep both paths
  working when editing `_build_predict_prompt`.
- The n8n proxy sits behind Cloudflare, which blocks Python's default urllib User-Agent
  (HTTP 403 / code 1010) — `PROXY_HEADERS` must keep a browser-like `User-Agent`.
- Large multi-state prompts can time out — `_build_predict_prompt` filters `market_data`/
  `board_data` down to only the states present in the itinerary's `regions` array; don't
  reintroduce unfiltered state data.
- Windows console is cp1252 by default and crashes on box-drawing/em-dash chars in
  `print()` — call `sys.stdout.reconfigure(encoding='utf-8')` at the top of any script
  that prints them.
- Backgrounded `python server.py > log.txt` hides `print()` output until buffer flush —
  launch with `PYTHONUNBUFFERED=1 python -u server.py` when tailing logs live.
- To restart the server: find the PID on port 8765 (`netstat -ano | grep 8765 | grep
  LISTENING`) then `taskkill //F //PID <pid> //T`.

## Gemini extraction experiment (`webscraping/gemini_extraction/`)

- Standalone test harness comparing Gemini-based place/venue/experience extraction against
  the old keyword-based `place_tags` column — not part of the main pipeline, no code depends on it.
- `phase1_test/`, `phase2/{input,output,csv}/` hold hand-built batch `.txt` files (tour text fed
  to Gemini) and the resulting `.json`/`.csv` outputs. `phase2/qa_dashboard.py` is a Streamlit QA
  view over the results (`streamlit run webscraping/gemini_extraction/phase2/qa_dashboard.py`).
- Gemini output JSON has no `source` field — resolve it via `tour_url` lookup against the raw
  `itineraries.json` files in `webscraping/<source>/data/latest/`, not by guessing from filenames.
- **Raw JSON shape varies by source** — check for all of: `days`, `blocks`, `itinerary` keys on
  the tour dict, or (if the top-level list has no tour wrapper) a flat list of day dicts implying
  a single tour. Scripts that only check for `days` will silently undercount several sources.
- Old `place_tags` (keyword-based) is often noisier than it looks: duplicate/near-duplicate
  entries, encoding-corrupted tokens (`�`), generic non-place words (Outback, tour operator
  brand names), and origin-city noise pulled from marketing boilerplate (e.g. Indian departure
  cities on Australia tours). Don't assume a longer old tag list means better extraction.

## Windows/bash gotchas

- Multi-line or backslash-heavy Python via `bash -c "python -c '...'"` breaks on quoting
  (`SyntaxError: unterminated string literal`). Write the script to a `.py` file with the
  Write tool and run it normally instead.
- Streamlit: `st.dataframe`'s `column_config` (e.g. `LinkColumn`) and a pandas `.style.apply()`
  Styler can be passed together in the same call — don't sacrifice one for the other.
- Streamlit `LinkColumn` needs the full, untruncated URL as the cell value to render as a
  clickable link — truncating the string for display breaks the link.



