# Build and Explore — Project Context

> A single reference document covering the business objective, the data, the two
> tools we have built, the server/deployment layer, and the open work items.
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
   *(done in the separate `Itinenaries_analysis` pipeline).*
2. **Clean and tag** each tour day into structured pieces: named places ("products"),
   location, and theme tags — via a rules-based engine, no black-box AI.
3. **Review and correct product tokens** through the Token Editor so junk/duplicates
   are removed before the dashboard sees them.
4. **Present it to the PM** through the Itinerary Builder dashboard.
5. The **PM builds our own itinerary** — picks days, locations, products — informed
   by market data, then exports it for the sales team.

The PM's build job is the centre of gravity. Every other component exists to make that
one job fast, informed, and defensible.

---

## 2. Why we collect two kinds of sources

| Segment | What it represents | Example sources |
|---|---|---|
| **Tourism Board** | Aspirational view — what the destination *wants* promoted | australia.com, state boards (WA, QLD, VIC, etc.) |
| **Competitor / Operator** | Commercial view — what is *actually being sold* | Global Journeys, Inside Australia, other operators |

Comparing the two reveals the **gap**: experiences the board features that no operator
currently sells (potential differentiators), and experiences operators cover that the
board doesn't emphasise.

---

## 3. The data: from CSV to dashboard

### 3.1 Upstream data (from the analysis pipeline)

The upstream pipeline lives in `Itinenaries_analysis/`. Its final output is:

```
C:\Users\SuyashA\Desktop\Itinenaries_analysis\data\keywords\keyword_dataset_2026-06.csv
```

Every row is one **day of one tour**, tagged with:
- `tour_name`, `tour_url`, `source`, `source_type` (Board or Competitor)
- `total_days`, `day_number`, `location`, `city`, `state`
- `products` — named places extracted from the day's text
- `themes` — one or more of the 8 business themes

### 3.2 Data preparation scripts (in this folder)

| Script | What it does |
|--------|-------------|
| `prepare_data.py` | Reads `keyword_dataset_*.csv` → generates `data.js` for the Itinerary Builder. Also applies `token_corrections.json`. |
| `prepare_tokens.py` | Reads `keyword_dataset_*.csv` → generates `tokens.js` for the Token Editor. |
| `import_excel.py` | Imports Excel-based corrections back into the pipeline. |
| `make_excel.py` | Exports data to an Excel workbook. |
| `watch_excel.py` | Watches for Excel file changes and auto-syncs them. |

**The full local data flow:**

```
keyword_dataset_2026-06.csv
        │
        ├─▶ prepare_tokens.py  →  tokens.js   (Token Editor source)
        │
        └─▶ prepare_data.py  →  data.js       (Itinerary Builder source)
                ▲
                └── token_corrections.json  (PM edits applied on top)
```

After every sync in the Token Editor, `server.py` automatically re-runs
`prepare_data.py` so `data.js` stays in sync with the latest corrections.

### 3.3 The 8 business themes

1. Nature & Scenery
2. Hiking & Walks
3. Beach & Coast
4. Wildlife
5. Food & Wine
6. Culture & History
7. Scenic Drives
8. City & Shopping

**Popularity** of a product = number of distinct tours that feature it. This is the core
signal for what the market considers important.

---

## 4. Tool 1 — Itinerary Builder (`Itinerary Builder.dc.html`)

The primary, hero-view PM tool. Served by `server.py` and loaded with `data.js` +
`support.js`.

### Setup row
Pick a **State**, type a **Tour name**, set the **Duration** (number of days).

### Summary metrics (live)
- Days planned / total
- Products added
- Themes covered (of 8)
- **Board alignment %** — share of the PM's products that the tourism board also features.

### Day cards (build surface)
One card per day. Each holds the day's location, description, and chips for products
and themes. Unplanned days are dimmed. The active day is accented green.

### Suggestion panel (the key design decision)
For the active day, shows **all three intelligence sources side by side**:
- **Popular** — products most used across tours in that state (market consensus)
- **Competitors** — what operators include on comparable days
- **Board** — what the tourism board recommends

Each chip is clickable and drops straight into the active day.

### Theme coverage bar
Live chart of how many days cover each theme. Missing themes are flagged so the PM
can build a balanced tour without re-reading every day.

### Research views (secondary tabs)
- **Product insights** — top products per state and theme, ranked by popularity
- **Board vs Operators (gap)** — three buckets: board-only, both, operator-only
- **Tagged data** — the raw day-level asset

### Export (always visible)
- **CSV / Excel** — structured data for handoff into other systems
- **Word / formatted doc** — polished deliverable for the sales team

### Colour coding
- **Blue** chips = products (named places)
- **Green** chips = theme tags
- **Amber** chips = board-recommended items
- Active day = green accent

---

## 5. Tool 2 — Token Editor (`Token Editor.dc.html`)

A data-quality tool for the person managing the pipeline (not the PM). Loaded with
`tokens.js`.

The PM reviews extracted product tokens and can:
- **Rename** — correct a garbled or misspelled product name
- **Split** — break a compound token into two separate products
- **Delete** — remove junk tokens (filler phrases, non-places)

Edits are saved as `token_corrections.json`. On save, the server re-runs
`prepare_data.py` so the Itinerary Builder immediately reflects the clean data.

A **backup/restore** system keeps timestamped snapshots of every correction set in
`corrections_history/`.

---

## 6. Server and deployment

### Local only — `server.py`

```bash
python server.py
```

- Runs on `http://localhost:8765`
- Serves both HTML tools as static files
- HTTP Basic Auth — credentials set via `APP_USER` / `APP_PASSWORD` environment variables
- `POST /sync` — saves `token_corrections.json`, backs it up, re-runs `prepare_data.py`
- `POST /restore` — restores a backup and re-runs `prepare_data.py`
- `GET /backups` — lists all backup snapshots

### Share with PM — `go_live.py`

```bash
python go_live.py
```

One command does everything:
1. Starts `server.py` locally
2. Starts a Cloudflare tunnel via `cloudflared.exe` → generates a public HTTPS link
3. Writes `SHARE_WITH_PM.txt` with the link, username, and password

The PM can open the link on any device. The link changes each run; the share file is
rewritten automatically.

To change the login:
```powershell
$env:APP_USER="name"; $env:APP_PASSWORD="password"; python go_live.py
```

### Bundled JS libraries (offline-capable)
`react.production.min.js`, `react-dom.production.min.js`, `babel.min.js` — all bundled
locally so the tools work without an internet connection.

---

## 7. Key principles to preserve

- **Builder is the hero.** Research and Token Editor exist to feed the build.
- **All three intel sources together.** Popular / Competitor / Board side by side for
  the active day — PM never switches context to compare.
- **Market-grounded, not invented.** Every suggestion traces back to real scraped tours;
  popularity = distinct tour count.
- **Transparent tagging.** Rules are editable; no opaque AI step.
- **Board alignment is a first-class metric.** Keeps itineraries anchored to the
  destination's aspirational view while still allowing gap differentiation.
- **Token corrections feed back in real time.** Every sync triggers `prepare_data.py`
  so the Builder immediately reflects clean data.
- **Two exports.** Data (CSV/Excel) for systems, formatted doc (Word/PDF) for sales.

---

## 8. Open work items (from TASK.md)

- **Search in suggestions** — Suggestions panel only shows pre-indexed products; PM
  needs to search for places not already in the list (not all locations will be suggested).
- **Source URL link** — Add a link to the original itinerary URL in the Builder, so the
  PM can double-check context on any suggested product.
- **Template itinerary accuracy** — Itinerary templates shown in the Builder currently
  repeat generic places across days (e.g. Dandenong, Eureka Skydeck) rather than the
  actual extracted day-by-day data. Root cause TBD.
- **Tagged data view is empty** — The "Tagged Data" tab in the Builder currently shows
  no rows despite data being present in `data.js`.

---

## 9. Glossary

- **Product** — a named, marketable place extracted from a day's text (e.g. Bell Gorge).
- **Theme** — one of the 8 business categories a day maps to.
- **Popularity** — number of distinct tours featuring a product.
- **Token** — raw extracted product string before correction (may be garbled or compound).
- **Token correction** — a rename, split, or delete applied in the Token Editor.
- **Board** — official tourism-board sources (aspirational "what's promoted").
- **Operator / Competitor** — companies selling tours (commercial "what's sold").
- **Gap** — products the board features that operators don't (or vice versa).
- **Board alignment** — share of an itinerary's products that the board also features.
