# Australian Tour Itineraries — Data Report
**Generated:** 2026-06-11  
**Dataset:** `data/keywords/keyword_dataset_2026-06.csv`

---

## 1. Dataset Overview

| Metric | Value |
|--------|-------|
| Total tour-days (rows) | 7,881 |
| Unique tours | 890 |
| Data sources | 6 |
| Scrape period | 2026-06 |

---

## 2. Source Breakdown

| Source | Tours | Tour-days | Avg keywords/day |
|--------|------:|----------:|-----------------:|
| global_journeys | 387 | 3,725 | 4.5 |
| inside_australia | 371 | 3,397 | 1.8 |
| australia_com | 77 | 513 | 6.3 |
| queensland_com | 25 | 135 | 9.6 |
| discover_tasmania | 21 | 84 | 9.1 |
| sydney_com | 9 | 27 | 10.4 |
| **TOTAL** | **890** | **7,881** | — |

**Notes:**
- `inside_australia` has the lowest average keywords/day (1.8) — its activity descriptions are shorter and more structured, giving spaCy fewer place mentions to extract.
- `sydney_com` and `queensland_com` have the richest descriptions (editorial-style), hence the highest keyword density.
- 217 non-Australian rows (South Pacific, NZ, Fiji) were filtered out before analysis.

---

## 3. Tag Coverage

| Tag type | Rows with tags | % of total |
|----------|---------------:|-----------:|
| Any keyword | 7,319 | 93% |
| Place tags | 6,573 | 83% |
| Theme tags | 5,225 | 66% |
| Activity tags | 639 | 8% |
| Has activity text | 5,234 | 66% |

**Coverage gaps:**
- 34% of rows have no activity text — these are tour-days where only a location was scraped (common in `global_journeys` for embark/disembark days).
- Activity tags are sparse (8%) because the named-activity regex requires a multi-word proper noun followed by an activity type (Tour, Cruise, Walk, etc.). Shorter activity names are not captured.

---

## 4. Top 25 Place Tags

| Rank | Place | Frequency |
|-----:|-------|----------:|
| 1 | Sydney | 452 |
| 2 | Darwin | 389 |
| 3 | Adelaide | 380 |
| 4 | Cairns | 376 |
| 5 | Uluru | 348 |
| 6 | Perth | 298 |
| 7 | Melbourne | 285 |
| 8 | Hobart | 263 |
| 9 | Brisbane | 247 |
| 10 | Broome | 239 |
| 11 | Alice Springs | 225 |
| 12 | Outback | 183 |
| 13 | Katherine | 154 |
| 14 | Kununurra | 125 |
| 15 | Margaret River | 118 |
| 16 | Kimberley | 111 |
| 17 | Great Barrier Reef | 110 |
| 18 | Cradle Mountain | 105 |
| 19 | Flinders Ranges | 97 |
| 20 | Kata Tjuta | 94 |
| 21 | El Questro | 93 |
| 22 | Gibb River Road | 89 |
| 23 | Blue Mountains | 87 |
| 24 | Port Douglas | 83 |
| 25 | Coober Pedy | 80 |

2,874 unique place tags total. The long tail is significant — many tags appear only once (single-attraction or village-level places).

---

## 5. Theme Distribution

| Theme | Frequency | Share |
|-------|----------:|------:|
| Nature & Scenery | 3,004 | 25% |
| Food & Wine | 2,397 | 20% |
| Beach & Coast | 1,947 | 16% |
| Culture & History | 1,758 | 14% |
| Hiking & Walks | 1,278 | 11% |
| Wildlife | 1,170 | 10% |
| City & Shopping | 944 | 8% |
| Scenic Drives | 362 | 3% |

**Most common theme pairs (co-occurring on same tour-day):**
1. Food & Wine + Nature & Scenery — 1,417
2. Culture & History + Nature & Scenery — 1,212
3. Culture & History + Food & Wine — 1,188
4. Hiking & Walks + Nature & Scenery — 877
5. Food & Wine + Hiking & Walks — 860

Food & Wine appears in almost every combination, reflecting its consistent presence across Australian tourism content.

---

## 6. Geographic Distribution

| State | Unique tours |
|-------|------------:|
| WA | 172 |
| NT | 155 |
| QLD | 138 |
| TAS | 121 |
| NSW | 112 |
| SA | 108 |
| VIC | 79 |
| ACT | 2 |

WA and NT dominate because long-form multi-day tours (outback, Kimberley, Red Centre) are most heavily featured by the scraping sources. ACT is underrepresented — only 2 Canberra-centric tours in the dataset.

---

## 7. Top Named Activities

| Activity | Frequency |
|----------|----------:|
| Katherine Outback Experience | 33 |
| Mitchell Falls Scenic Helicopter Flight | 16 |
| The Ghan Expedition | 14 |
| Bungle Bungle Helicopter Flight Experience | 12 |
| Kings Canyon Rim Walk | 12 |
| Nyanyjili Footprint Tour | 10 |
| Blue Mountains Deluxe Day Tour | 10 |
| Panoramic Sydney Sights Tour | 9 |
| Shark Bay Wildlife Cruise | 8 |
| Arraluli Heritage Exploration Experience | 8 |

342 unique activity tags total. These represent the most commonly cross-sold commercial experiences across tour operators.

---

## 8. Coverage Gaps & Next Steps

### Low-coverage sources
- `inside_australia` averages only 1.8 keywords/day. The activity field for this source contains short structured entries ("Sydney to Perth on board The Indian Pacific") rather than descriptive prose, so spaCy extracts fewer entities. Consider adding a second NER pass on the `description` column for this source.

### Tours with zero keywords
- **Bay of Fires Long Weekend** and **Walk Tasmania's Bay of Fires** — both have 0 keywords. The place "Bay of Fires" is a proper noun but is not in the current place lookup list in `combine_sources.py`. Add it to `_PLACES_RAW` and the keyword allowlist.
- **Three Capes (Signature Walk)** — "Three Capes" resolves to Port Arthur via the synonym map, but the description is minimal. Likely needs allowlist entry.

### ACT under-representation
Only 2 ACT tours. No data from tourism.act.gov.au or visitcanberra.com.au — worth scraping if ACT content is needed.

### Cleaning still needed
Open `data/keywords/keyword_review.csv` (3,202 unique tags) to review the long tail. Focus on:
- Tags with frequency 1–2 that appear to be sentence fragments
- Any remaining possessive or prefix artifacts
- Proper nouns that are ship/train names rather than places (e.g., "Indian Pacific", "The Ghan")

---

*Data pipeline: `webscraping/pipeline/combine_sources.py` → `analysis/build_keyword_dataset.py`*  
*For manual tuning: `data/config/keyword_blacklist.txt`, `data/config/keyword_synonyms.txt`, `data/config/keyword_allowlist.txt`*
