# Itinerary Products — Prototype

A self-contained web app that demonstrates the **"experiences → products"** idea on a
real sample of the scraped itinerary data. Everything runs locally, no AI/API needed.

## What it shows

A transparent **rules-based engine** (`extractor.py`) reads each day's text and:
- keeps **products** — named places (Bell Gorge, Gibb River Road, Uluru) via place-type
  rules (*…Gorge, …Falls, …Road, …National Park*) + a known-places dictionary;
- assigns **theme tags** — the 8 business themes (Nature & Scenery, Hiking & Walks,
  Beach & Coast, Wildlife, Food & Wine, Culture & History, Scenic Drives, City & Shopping);
- drops filler (*travel to, continue, back on the…*).

Those tags power five views:
1. **Overview** — headline numbers + a live "type text → see products/themes" demo.
2. **Product Insights** — top products per state/theme, popularity = number of distinct tours.
3. **Itinerary Builder** — pick a state + length, drop recommended products into each day, export CSV.
4. **Tagged Data** — every scraped day, now carrying `product_tags` + `theme_tags` (the asset).
5. **Board vs Operator Gap** — products the board features vs what operator tours cover.

## Run it

```bash
cd product_app
pip install -r requirements.txt          # only streamlit + pandas
python build_sample.py                    # (re)build data/sample_itineraries.csv from the main scrape
streamlit run app.py
```

Then open the URL Streamlit prints (default http://localhost:8501).

## Files

| File | Purpose |
|------|---------|
| `extractor.py` | The rules engine (edit the lists to tune products/themes). |
| `app.py` | The Streamlit UI (5 views). |
| `build_sample.py` | Builds the local dataset from `../data/combined_itineraries_latest.csv`. |
| `data/sample_itineraries.csv` | 835 tours / 7,635 day rows, Australia only. |

## Notes

- The data is a **sample of the real scrape** (international/South Pacific tours removed).
- It's a prototype: the rule lists are deliberately small and editable. Coverage today is
  ~89% of days getting a product tag; grow the dictionary in `extractor.py` to push it higher.
- This mirrors the agreed plan: the same `extractor.py` becomes the pipeline's `tag_products.py`
  step, and "scrape more sources" just feeds more rows through the same tagging.
