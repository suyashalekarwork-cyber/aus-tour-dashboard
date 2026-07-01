# Itinerary Builder — Project Context

> A single reference document covering the business objective, the data, the PM tool
> we have built, the server layer, and the open work items.
> Use this as context for future AI prompts, briefs, or onboarding.

---

## 1. The objective

We are in the business of **creating and selling tour itineraries** for Australia.

We don't invent itineraries blindly. Instead, we look at what already exists in the
market — what tourism boards promote and what competitor operators actually sell — and
use that intelligence to assemble itineraries that are competitive, well-rounded, and
aligned with what travellers are shown.

The workflow is:

1. **Scrape** itinerary data from tourism boards and competitor operators
   *(done in the main `Itinenaries_analysis` pipeline — see `PIPELINE.md`).*
2. **Clean and tag** each tour day into structured pieces: named places ("products"),
   location, and theme tags — via a rules-based engine, no black-box AI.
3. **Present it to the PM** through the Itinerary Builder web app (`app/`).
4. The **PM builds our own itinerary** — picks days, locations, products — informed
   by market data, then exports it for the sales team.

The PM's build job is the centre of gravity. Every other component exists to make that
one job fast, informed, and defensible.

---

## 2. Why we collect two kinds of sources

| Segment | What it represents | Example sources |
|---|---|---|
| **Board / Reference** | Aspirational view — what the destination *wants* promoted | australia.com, state boards (WA, QLD, VIC, etc.) |
| **Competitor / Operator** | Commercial view — what is *actually being sold* | Global Journeys, Inside Australia, other operators |

Comparing the two reveals the **gap**: experiences the board features that no operator
currently sells (potential differentiators), and experiences operators cover that the
board doesn't emphasise.

---

## 3. The data: from CSV to app

### 3.1 Upstream data (from the analysis pipeline)

The upstream pipeline lives at the repo root (`run_pipeline.py` + `analysis/` +
`webscraping/` — see `PIPELINE.md`). Its final output is:

```
data/keywords/keyword_dataset_YYYY-MM.csv
```

Every row is one **day of one tour**, tagged with:
- `tour_name`, `tour_url`, `source`, `source_type` (`Reference` = Board or `Competitor`)
- `total_days`, `day_number`, `location`, `city`, `state`
- extracted places (products) and theme tags

### 3.2 App data-prep scripts (`app/pipeline/`)

| Script | What it does |
|--------|-------------|
| `prepare_data.py` | Reads `keyword_dataset_*.csv` → generates `app/frontend/data.js` (per-tour templates + day cards) and `catalog.js`. Applies `token_corrections.json` on top. |
| `prepare_tokens.py` | Reads `keyword_dataset_*.csv` → generates `app/frontend/tokens.js` (raw extracted product tokens for review). |
| `prepare_all.py` | Runs both of the above in one go — this is what the server calls after every sync. |
| `import_excel.py` | Imports Excel-based token corrections back into `token_corrections.json`. |
| `make_excel.py` | Exports token data to an Excel workbook for offline review. |
| `token_editor.py` | Shared helpers for the token-correction workflow. |

**The full local data flow:**

```
data/keywords/keyword_dataset_YYYY-MM.csv
        │
        ├─▶ prepare_tokens.py  →  app/frontend/tokens.js
        │
        └─▶ prepare_data.py  →  app/frontend/data.js, catalog.js
                ▲
                └── app/frontend/token_corrections.json  (PM/data-owner edits applied on top)
```

After every `/sync` call, `app/server/server.py` automatically re-runs `prepare_all.py`
so `data.js`/`tokens.js` stay in sync with the latest corrections.

### 3.3 Business themes

The keyword pipeline tags each day with one or more business themes (e.g. Nature &
Scenery, Hiking & Walks, Beach & Coast, Wildlife, Food & Wine, Culture & History, Scenic
Drives, City & Shopping) — the exact list is defined in `data/config/` and surfaced in
the app as `THEMES`.

**Popularity** of a product = number of distinct tours that feature it. This is the core
signal for what the market considers important.

---

## 4. The Itinerary Builder app (`app/frontend/builder.html`)

A single-file React app (no build step — React/Babel are loaded from bundled local JS
files) served locally by `app/server/server.py` and opened at `http://localhost:8765`.
It has one nav bar with these tabs:

- **Builder** — the PM's main build surface. Pick a **State**, type a **Tour name**, set
  the **Duration**. Day cards hold each day's location, description, and place/theme
  chips. The active day shows a **suggestion panel** with three sources side by side:
  **Popular** (most-used products across tours in that state), **Competitor**, and
  **Board**. Clicking a chip drops it straight into the active day. Live summary metrics
  include days planned, products added, themes covered, and **Board alignment %** (share
  of the PM's products that the board also features).
- **Templates** — a card-grid browser over every real scraped tour (per-URL, not merged),
  redesigned 2026-07-02 to match the Claude Design mockup. Left filter rail: search,
  sort, source-type pills, state chips, duration range, **Route cities** (browsable
  toggle list + search — must-visit-all filter), **Themes** and **Sources** toggle lists
  (multi-select, click to browse rather than search-only). Cards show day/product/theme
  counts, popularity and **Board alignment %** bars, state badges (including a
  **Multi-state** badge — templates can span several Australian states), top products
  and themes. Each card has two actions: **Start from this** (opens a Confirm modal, then
  loads into the Builder) and **Preview** (a larger modal showing every day in a wrapping
  grid — the single place to glance at a full multi-day route — plus theme-coverage,
  days-per-state, and most-featured-product insight panels).
- **Product insights** — top products per state/theme, ranked by popularity. *(Hidden
  from the PM-facing nav as of 2026-07-02 — still in progress; see `HIDDEN_TABS` in
  `builder.html`.)*
- **Board vs operators (gap)** — three buckets: board-only, both, operator-only. *(Hidden
  from the PM-facing nav as of 2026-07-02 — still in progress.)*
- **Tagged data** — the raw day-level dataset, paginated, filterable by Board/Competitor,
  searchable. *(Hidden from the PM-facing nav as of 2026-07-02 — still in progress.)*
- **Saved itineraries** — itineraries the PM has saved (`saved_itineraries.json`),
  with delete support.
- **Analytics** — pure-CSS bar charts across sources, markets, geography, themes, and
  duration (added 2026-06-30).

Hidden tabs are excluded from the nav via a `HIDDEN_TABS` array, not deleted — remove a
key from that array to bring a tab back once it's ready for PM review.

### Filters (Builder + Templates)
Typeahead filters for **city** (pill selection) and **source name** (operator), plus a
**places** filter that matches across city + extracted places.

### Export (always visible from the Builder)
- **CSV / Excel** — structured data for handoff into other systems
- **Word / formatted doc** — polished deliverable for the sales team

### Colour coding
- **Blue** chips = products (named places)
- **Green** chips = theme tags
- **Amber/orange** = board-recommended items / Board source; **teal** = Competitor
- Active day = green accent

---

## 5. Token review

Raw extracted product names sometimes need cleanup (renamed, split, deleted) before they
show up in the Builder cleanly. This was previously a separate "Token Editor" tool; it's
now folded into the same `app/` codebase as a data-prep step, not a builder tab (see
open items below — removing the in-app "Clean tokens" affordance is on the task list).

- Corrections are saved to `app/frontend/token_corrections.json`.
- On save/sync, the server re-runs `prepare_all.py` so the Builder immediately reflects
  the clean data.
- A backup/restore system keeps timestamped snapshots in `app/frontend/corrections_history/`.
- `Token_Review.xlsx` supports an offline Excel-based review loop via `make_excel.py` /
  `import_excel.py`.

---

## 6. Server and deployment

### Local only — `app/server/server.py`

```bash
python app/server/server.py
```

- Runs on `http://localhost:8765`
- Serves `app/frontend/` as static files (the app root the browser loads)
- `GET /corrections` — returns `token_corrections.json` (or empty object)
- `POST /sync` — saves corrections + timestamped backup + runs `prepare_all.py`
- `GET /backups` — lists backup history
- `POST /restore` — restores a backup to `token_corrections.json` + reruns the pipeline
- `GET/POST /itineraries` — saved itinerary CRUD (`saved_itineraries.json`)

### Share with PM — `app/server/go_live.py`

```bash
python app/server/go_live.py
```

One command does everything:
1. Starts `server.py` locally
2. Starts a Cloudflare tunnel via the bundled `cloudflared.exe` → generates a public HTTPS link
3. Writes a share file with the link, username, and password

The PM can open the link on any device. The link changes each run — re-share it after
every restart. This is the **only supported way to share the Builder remotely** — it
already bundles `cloudflared.exe`, so there's no need to install or download a tunnel
tool separately.

### Bundled JS libraries (offline-capable)
`react.production.min.js`, `react-dom.production.min.js`, `babel.min.js` — all bundled
locally in `app/frontend/` so the tool works without an internet connection.

---

## 7. Key principles to preserve

- **Builder is the hero.** Templates, insights, gap, and data tabs exist to feed the build.
- **All three intel sources together.** Popular / Competitor / Board side by side for
  the active day — PM never switches context to compare.
- **Market-grounded, not invented.** Every suggestion traces back to real scraped tours;
  popularity = distinct tour count.
- **Transparent tagging.** Rules are editable; no opaque AI step.
- **Board alignment is a first-class metric.** Keeps itineraries anchored to the
  destination's aspirational view while still allowing gap differentiation.
- **Token corrections feed back in real time.** Every sync triggers `prepare_all.py`
  so the Builder immediately reflects clean data.
- **Two exports.** Data (CSV/Excel) for systems, formatted doc (Word/PDF) for sales.

---

## 8. Open work items (from `TASK.md` / `app/docs/TASK.md`)

- **Separate description from place chips on day cards** — a place mentioned in the
  description shouldn't also duplicate as a standalone tag; needs a clearer split
  between "What You'll Do" text and the tag/chip section.
- **Descriptions can reference places the day card doesn't show as chips** — extraction
  gap between prose and structured tags on some multi-city days.
- **"Clear all days" button** in the Builder.
- **Search box in the suggestions panel** — currently only pre-indexed products show;
  PM needs to search for places not already in the list.
- **Remove "Clean tokens"** from the Builder UI — token cleanup shouldn't be a PM-facing
  builder feature.
- **Add graphs to the data views** beyond what the Analytics tab already covers.
- **Editable descriptions/tags + a "save" workflow** that persists PM edits to a separate
  itinerary store (not the shared dataset) is under discussion — see the open questions
  in `TASK.md`.

---

## 9. Glossary

- **Product** — a named, marketable place extracted from a day's text (e.g. Bell Gorge).
- **Theme** — one of the business categories a day maps to.
- **Popularity** — number of distinct tours featuring a product.
- **Token** — raw extracted product string before correction (may be garbled or compound).
- **Token correction** — a rename, split, or delete applied during token review.
- **Board** — official tourism-board sources (`source_type == Reference`), aspirational "what's promoted".
- **Operator / Competitor** — companies selling tours (`source_type == Competitor`), commercial "what's sold".
- **Gap** — products the board features that operators don't (or vice versa).
- **Board alignment** — share of an itinerary's products that the board also features.
