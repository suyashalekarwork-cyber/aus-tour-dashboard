# Project Roadmap — Itineraries Analysis & Builder

> **Status:** Core is still being built. Each phase below is marked with its completion state.
> **Timeline:** June 1 – August 31, 2026 (13 weeks)

---

## Background

Before this project existed, Australia tour research was done manually — browsing competitor websites and tourism boards with no systematic structure, no way to compare what operators were offering, and no tool to help the product manager build informed itineraries. This project was built to fix that from the ground up.

---

## Week-by-Week Timeline

3-month project: **June 1 → August 31, 2026**
Today is **June 22** — end of Week 3.

### June — Foundation (Weeks 1–4)

| Week | Dates | Focus | Status |
|------|-------|-------|--------|
| 1 | Jun 1–7 | Project setup, first scrapers (tourism boards + 3–4 operators), combine pipeline skeleton | ✅ Done |
| 2 | Jun 8–14 | Remaining competitor scrapers (Thomas Cook, SOTC, MakeMyTrip, Chan Brothers, Veena World, etc.), validate schema | ✅ Done |
| 3 | Jun 15–21 | Keyword tagging engine (4-layer), manual cleaning tools (keyword_review_tool, city_review_tool), config files | ✅ Done |
| 4 | Jun 22–28 | **Current week** — Builder data-correctness first (faithful day-by-day itineraries), then UX polish | 🔄 In progress |

---

### July — Builder + Dashboard (Weeks 5–9)

| Week | Dates | Focus | Status |
|------|-------|-------|--------|
| 5 | Jun 29–Jul 5 | Searchable & filterable data-review view (replace Excel navigation), filter by City + Source | ⏳ Upcoming |
| 6 | Jul 6–12 | Builder: search inside suggestions panel, graphs/visualisations for theme and source coverage | ⏳ Upcoming |
| 7 | Jul 13–19 | Analytics Dashboard: full graphs, trend views, state/theme breakdowns — fully usable without Excel | ⏳ Upcoming |
| 8 | Jul 20–26 | Data quality pass — re-run all scrapers, re-clean keywords, ensure dataset is complete and accurate | ⏳ Upcoming |
| 9 | Jul 27–Aug 2 | End-to-end integration test: full pipeline run → Builder → export. Fix any gaps found | ⏳ Upcoming |

---

### August — Polish, Testing & Delivery (Weeks 10–13)

| Week | Dates | Focus | Status |
|------|-------|-------|--------|
| 10 | Aug 3–9 | PM walkthrough & feedback — iterate on Builder UX, token corrections, export quality | ⏳ Upcoming |
| 11 | Aug 10–16 | Final feature additions from feedback, performance & reliability pass | ⏳ Upcoming |
| 12 | Aug 17–23 | Buffer week — catch-up, documentation, edge-case fixes, final data clean | ⏳ Upcoming |
| 13 | Aug 24–31 | **Final delivery** — full working system: scrapers → tagged dataset → Builder → Dashboard → export | ⏳ Upcoming |

---

### Delivery Checklist (by Aug 31)
- [ ] All 21 scrapers running and producing clean data
- [ ] Full tagged dataset (keyword + city/state cleaned)
- [ ] Searchable/filterable data-review interface (no Excel required)
- [ ] Itinerary Builder fully working (suggestions, metrics, export)
- [ ] Analytics Dashboard with graphs and visualisations
- [ ] Server + Cloudflare deploy working (PM can access from anywhere)
- [ ] End-to-end pipeline runnable in one command

---

## Phase 1 — Data Collection: Web Scrapers ✅ Done

**Goal:** Systematically collect itinerary data from 21 real sources.

### Tourism Boards (reference sources)
| Source | Status |
|--------|--------|
| australia.com | ✅ |
| westernaustralia.com | ✅ |
| visitvictoria.com | ✅ |
| queensland.com | ✅ |
| sydney.com | ✅ |
| discovertasmania.com.au | ✅ |

### Competitor Tour Operators
| Source | Status | Notes |
|--------|--------|-------|
| Global Journeys | ✅ | |
| Inside Australia | ✅ | |
| Absolute Australia | ✅ | |
| Scott Dunn | ✅ | |
| Jacada Travel | ✅ | |
| Thomas Cook (IN) | ✅ | Private JSON API, Playwright session reuse |
| SOTC | ✅ | Same TCIL API as Thomas Cook group |
| Kesari Tours | ✅ | |
| Veena World | ✅ | Angular transfer-state JSON, no Playwright needed |
| MakeMyTrip | ✅ | Akamai-guarded; requires real Chrome + in-page fetch |
| Chan Brothers (SG) | ✅ | Clean public Drupal JSON API |

**What each scraper produces:**
- One row per day per tour: `source | tour_name | tour_url | total_days | day_number | location | activity | price | scrape_date`
- Saved as `webscraping/<source>/data/latest/itinerary_days.csv`

**Key decisions made:**
- Experiences/activities are the signal, not hotel names — hotels are excluded
- Sequential day structure preserved (Day 1, Day 2, ...) so templates can be built
- Each scraper is independent and re-runnable

---

## Phase 2 — Data Pipeline: Combine, Enrich & Validate ✅ Done

**Goal:** Merge all 21 sources into one unified, clean dataset.

### 2a. Combine Sources
- `webscraping/pipeline/combine_sources.py` — auto-discovers all 21 source CSVs and merges them
- Normalises schema across all sources
- Enriches with `state` and `city` columns (geo-lookup)
- Filters to Australia-only tours
- Output: `data/raw/combined_itineraries_latest.csv` (~master dataset, always current)

### 2b. Schema Validation
- `webscraping/pipeline/validate_schema.py` — runs data-quality checks
- Hard errors exit non-zero (fail fast before downstream steps)

### 2c. Master Orchestrator
- `webscraping/pipeline/run_all.py` — runs all scrapers then combine + validate in one command
- Also supports smoke-test mode (fast check without full scrape)

---

## Phase 3 — Data Tagging: Keyword & Theme Extraction ✅ Done

**Goal:** Tag every itinerary day with what it contains (products/places, themes) so the PM can filter and search meaningfully.

### Four-layer tagging engine (`analysis/build_keyword_dataset.py`)
1. **Regex** — rule-based place and activity extraction
2. **spaCy NER** — catches GPE, LOC, FAC, EVENT entities missed by regex
3. **Named-activity patterns** — structured phrases like "Daintree River Cruise"
4. **Allowlist** — custom phrases that are always included if found

**Output:** `data/keywords/keyword_dataset_YYYY-MM.csv`
- Adds `products | themes | product_list | place_type` columns to every row

### 8 Business Themes defined
1. Nature & Scenery
2. Hiking & Walks
3. Beach & Coast
4. Wildlife
5. Food & Wine
6. Culture & History
7. Scenic Drives
8. City & Shopping

---

## Phase 4 — Manual Cleaning Tools ✅ Done

**Goal:** Give a human a way to review and correct the tagged data without touching code.

### Keyword Cleaning (`analysis/keyword_review_tool.py`)
- `export` — generates `data/keywords/keyword_review.xlsx` (one row per keyword, with Action column)
- Human fills in: `blacklist` / `synonym` / `allowlist` for each keyword
- `apply` — writes corrected rules to `data/config/` (blacklist.txt, synonyms.txt, allowlist.txt)
- `update` — apply + rebuild + re-export in one command
- Audit trail: `data/config/keyword_changelog.txt`

### City / State Consolidation (`analysis/city_review_tool.py`)
- `export` — generates `data/config/city_review.xlsx` (Cities / States / City Types / Null Audit sheets)
- `apply` — writes `city_synonyms.txt`, `state_synonyms.txt`, `city_types.txt`
- Audit trail: `data/config/city_changelog.txt`

### Config files (rules live here)
```
data/config/
  keyword_blacklist.txt    — phrases always removed
  keyword_allowlist.txt    — phrases always kept
  keyword_synonyms.txt     — canonical name mappings (e.g. Ayers Rock → Uluru)
  city_synonyms.txt        — city renames
  state_synonyms.txt       — state renames
  city_types.txt           — classify each canonical city
```

---

## Phase 5 — Frontend: PM's Itinerary Builder ✅ Done (ongoing refinement)

**Goal:** Give the product manager a tool to build itineraries informed by market data — not a blank canvas.

### Itinerary Builder (`app/frontend/Itinerary Builder.dc.html`)
The PM's main tool. Key features:
- **Day card build surface** — drag and drop, add/remove products per day
- **Three suggestion panels** side-by-side:
  - Popular (most frequent across all sources)
  - Competitors (what rival operators include)
  - Board (what tourism boards recommend)
- **Live metrics** — days planned, products added, themes covered, board alignment %
- **Research tabs** — Product Insights, Board vs Operators Gap, Tagged Data
- **Export** — CSV / Excel / Word

### Analytics Dashboard (`app/frontend/Analytics Dashboard.dc.html`)
- Secondary view for filtering and exploring the full itinerary dataset

### How the frontend is fed data
```
keyword_dataset CSV
    ↓
prepare_data.py       → app/frontend/data.js      (loaded by Builder)
prepare_tokens.py     → app/frontend/tokens.js    (loaded by Token Editor)
                         + GeoNames validation (~35% auto-validated)
                         + fuzzy-cluster near-duplicate spellings
```

Token corrections made by the PM in the UI are saved as `data/token_corrections.json` and applied on the next `prepare_data.py` run.

---

## Phase 6 — Server & Deployment ✅ Done

**Goal:** Let the PM access the tool from anywhere without needing Python installed.

### Local Server (`app/server/server.py`)
- HTTP server on `localhost:8765`
- HTTP Basic Auth
- Handles token corrections JSON saves
- Backs up previous corrections before overwriting
- Triggers pipeline re-run on sync

### One-command deploy (`app/server/go_live.py`)
- Starts server + Cloudflare tunnel in one command
- Writes `SHARE_WITH_PM.txt` with the live URL + credentials
- No port-forwarding or cloud infra required

---

## Phase 7 — Analysis & Reporting ✅ Done

**Goal:** Give managers a way to review the data outside the Builder tool.

### Streamlit Dashboard (`analysis/dashboard.py`)
- Filters by state, source, theme, price
- Live metrics and trend views
- Runs locally with `streamlit run`

### Export scripts
| Script | Output |
|--------|--------|
| `analysis/export_excel.py` | Manager-ready AU-only workbook (Tours + View Itinerary sheets) |
| `analysis/export_competitors.py` | Competitor-only workbook |
| `analysis/export_debug_excel.py` | Raw all-sources inspection workbook |

---

## Full Pipeline Flow (End to End)

```
① Scrape 21 sources
      ↓
② webscraping/*/data/latest/itinerary_days.csv  (per source)
      ↓
③ combine_sources.py
      → data/raw/combined_itineraries_latest.csv
      ↓
④ build_keyword_dataset.py + data/config/*.txt
      → data/keywords/keyword_dataset_YYYY-MM.csv
      ↓
⑤ keyword_review_tool.py export
      → keyword_review.xlsx  [PM cleans manually]
      ↓
⑥ keyword_review_tool.py update
      → config files updated + dataset rebuilt
      ↓
⑦ prepare_data.py + prepare_tokens.py
      → data.js + tokens.js
      ↓
⑧ server.py + Itinerary Builder HTML
      [PM builds itinerary, saves corrections]
      ↓
⑨ Export CSV / Excel / Word
```

Top-level `run_pipeline.py` covers steps ③ → ⑥ (combine → tag → export review workbooks).

---

## Current Work & Backlog

> **Week-4 priority (decided 22 Jun): fix builder data-correctness BEFORE adding more
> itineraries.** Collection is done (1,327 tours); the blocker is the builder
> misrepresenting the data it already has. The bugs below share one root cause — a lossy
> data contract between `prepare_data.py` and the builder: the real scraped `location`
> route is discarded and rebuilt from the city. More itineraries → deferred to the Week-8
> data-quality pass.

### P0 — Data correctness (do first)
- [ ] Itinerary loads as a *combined/collapsed* blob, not the real day-by-day route
      (shows "Perth, Perth, Perth" instead of Fremantle → Rottnest → Margaret River)
- [ ] "Only one itinerary showing — where's the other?" — combined view hides distinct tours
- [ ] Count mismatch: same tour shows 23 vs 46 days (duplicate rows), and
      "41 itineraries but only 26 days" (days silently dropped/merged)
- [ ] Dashboard itineraries missing from builder template list (e.g. "Paradise in Perth")
- [ ] Document the data contract — which columns/shape reach the builder
- [ ] Regenerate data.js (recovered_data.js suggests it is stale)

### P1 — Quick UX wins (after data is correct)
- [ ] "Clear All Days" button in Builder not working
- [ ] Filter by City + Source (currently state-only)
- [ ] Search feature inside the suggestions panel
- [ ] Remove "Clean tokens" view from the builder (not needed)

### P2 — Visualisation
- [ ] Graphs and visualisations for theme/source coverage (currently table-only)

### Deferred
- [ ] Searchable data-review interface (replace Excel navigation)
- [ ] Add more itineraries / new scrapers → Week-8 data-quality pass

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Scraping | Playwright, BeautifulSoup4, requests |
| Data processing | Pandas, NumPy, openpyxl |
| NLP / Tagging | spaCy (NER) |
| Frontend | React (bundled offline), Babel (JSX), HTML5 |
| Server | Python `http.server` |
| Deployment | Cloudflare tunnel (cloudflared.exe) |
| Analysis | Streamlit |
| Geo validation | GeoNames database (AU_geonames.txt) |
| Config / cleaning | Plain text rules files + Excel workbooks |
