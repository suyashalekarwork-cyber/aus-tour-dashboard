# Itinerary Product Dashboard — Project Context

> A single reference document covering the business objective, the data behind it,
> and the dashboard we are building. Use this as context for future work,
> briefs, or AI prompts.

---

## 1. The objective

We are in the business of **creating and selling tour itineraries** for Australia.

We don't want to invent itineraries blindly. Instead, we look at what already exists
in the market — both what the official tourism boards promote and what competitor tour
operators actually sell — and use that intelligence to assemble our own itineraries that
are competitive, well-rounded, and aligned with what travellers are shown.

The workflow is:

1. **Extract** itinerary data from the internet (tourism boards + competitor operators).
2. **Clean and tag** that data so every day of every tour is broken into structured
   pieces: the named places ("products"), the location, and theme tags.
3. **Present it to the product manager (PM)** through a dashboard.
4. The **PM builds our own itinerary** — picking days, locations, and experiences,
   informed by the market data — then exports it so it can be **promoted and sold**.

The PM's job is the centre of gravity. Everything else (scraping, cleaning, tagging,
market analysis) exists to make that one job — building a sellable itinerary — fast,
informed, and defensible.

---

## 2. Why we collect two kinds of sources

We deliberately collect from two market segments so the PM can see both sides:

| Segment | What it represents | Example sources |
|---|---|---|
| **Tourism Board** | The aspirational view — what the destination *wants* promoted | australia.com, state boards (WA, QLD, VIC, etc.) |
| **Competitor / Operator** | The commercial view — what is *actually being sold* | Global Journeys, Inside Australia, and other tour operators |

Comparing the two reveals the **gap**: experiences the board features that no operator
currently sells (potential differentiators for us), and experiences operators cover that
the board doesn't emphasise.

---

## 3. The data: from raw text to structured products

Each scraped tour is broken down to the **day level**. Every day row carries:

- `tour_name`, `tour_url`, `source`, `source_type` (Board or Competitor)
- `total_days`, `day_number`
- `location` / `city`, `state`
- the day's description / activity text

A transparent, **rules-based tagging engine** then reads each day's text and adds:

- **Products** — named places extracted from the text. A phrase is kept as a product if
  it ends in a place-type word (Gorge, Falls, Road, National Park, Springs, Bridge…) or
  appears in a known-places dictionary. Example: *Bell Gorge*, *Gibb River Road*, *Uluru*.
- **Theme tags** — each day is mapped to one or more of the **8 business themes**.
- Filler is dropped (*"travel to", "continue", "back on the…"*).

### The 8 business themes

1. Nature & Scenery
2. Hiking & Walks
3. Beach & Coast
4. Wildlife
5. Food & Wine
6. Culture & History
7. Scenic Drives
8. City & Shopping

The tagging is intentionally rule-based and editable (no black-box AI), so the business
can tune the place lists and theme keywords directly. "Scrape more sources" simply means
feeding more day rows through the same tagging step.

**"Popularity"** of a product = the number of distinct tours that feature it. This is the
core signal for what the market considers important.

---

## 4. The dashboard — design and rationale

The dashboard is **builder-first**. The Itinerary Builder is the primary, hero view;
the research/market-analysis views are secondary tabs that support it.

### 4.1 Sidebar navigation

A persistent left rail with:

- **Itinerary builder** (the hero — default view)
- **Product insights** (market data)
- **Board vs operators** (the gap analysis)
- **Tagged data** (the underlying day-level asset)
- Dataset stat readouts: tours, distinct products, day rows.

### 4.2 Itinerary builder (primary view)

**Setup row** at the top: pick a **State**, type a **Tour name**, set the **Duration**
(number of days).

**Summary metrics** beneath it, updating live as the PM builds:
- Days planned (of total)
- Products added
- Themes covered (of 8)
- **Board alignment %** — share of the PM's chosen products that the tourism board also
  features. This keeps the itinerary anchored to the aspirational/destination view.

**Day cards (the build surface):** one card per day. Each card holds the day's location,
an optional description, and the chips for that day's products and themes. Planned days
show their content; unplanned days are visually dimmed and prompt the PM to add products.
The active/selected day is highlighted.

**Suggestion panel (alongside the day cards):** the single most important design decision.
For the currently active day it shows **all three intelligence sources in one panel**,
as requested:
- **Popular** — products most used across tours in that state (market consensus).
- **Competitors** — what competitor operators include on comparable days.
- **Board** — what the tourism board recommends for that region.

Each suggested product is a clickable chip that drops straight into the active day.

**Theme coverage bar:** a live chart of how many days cover each of the 8 themes, with
any missing themes flagged. This lets the PM build a balanced tour at a glance, without
re-reading every day.

### 4.3 Research views (secondary, below / behind tabs)

- **Market data / Product insights:** top products per state and theme, ranked by
  popularity (number of distinct tours). Helps the PM understand what dominates a region.
- **Board vs operators (gap):** for a chosen state, three buckets —
  - products the **board features but operators miss** (gaps to fill / differentiators),
  - products in **both**,
  - products **operators cover but the board doesn't** (our existing strengths).

### 4.4 Export (always available)

At the end the PM can export in **two formats**:
- **CSV / Excel** — the structured itinerary data, for handoff into other systems.
- **Word / formatted document** — a polished, ready-to-share deliverable for the sales team.

A persistent export bar shows a live summary (tour name · days · product count · state)
plus both export buttons.

### 4.5 Colour coding (consistent throughout)

- **Blue** chips = products (named places)
- **Green** chips = theme tags
- **Amber** chips = board-recommended items
- The active/selected day is accented green.

---

## 5. Key principles to preserve

- **Builder is the hero.** Research exists to feed the build, not the other way round.
- **All three intel sources together.** Popular / competitor / board sit side by side for
  the active day — the PM never has to switch context to compare them.
- **Market-grounded, not invented.** Every suggestion traces back to real scraped tours;
  popularity = distinct tour count.
- **Transparent tagging.** Rules are editable by the business; no opaque AI step.
- **Board alignment is a first-class metric.** It keeps our itineraries anchored to the
  destination's aspirational view while still letting us differentiate via the gap analysis.
- **Two exports.** Data (CSV/Excel) for systems, formatted doc (Word/PDF) for sales.

---

## 6. Glossary

- **Product** — a named, marketable place extracted from a day's text (e.g. Bell Gorge).
- **Theme** — one of the 8 business categories a day maps to.
- **Popularity** — number of distinct tours featuring a product.
- **Board** — official tourism-board sources (the aspirational "what's promoted").
- **Operator / Competitor** — companies selling tours (the commercial "what's sold").
- **Gap** — products the board features that operators don't (or vice versa).
- **Board alignment** — share of an itinerary's products that the board also features.
