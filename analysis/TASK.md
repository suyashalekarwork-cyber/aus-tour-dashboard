# Tasks

## City column cleaning

- [x] Some city values need to be renamed/consolidated (e.g. a region like Kimberley or
  Purnululu mapped to a hub city), which is a manual judgment the user makes.
  → Now config-driven. Easiest: `python analysis/city_review_tool.py export`, open
  `data/config/city_review.xlsx`, type the new name in the **Cities** sheet's "Change To"
  column, run `python analysis/city_review_tool.py apply`, then re-run the pipeline.
  (Or edit `data/config/city_synonyms.txt` by hand: `Old Name -> New Name`.)
- [x] States can be renamed the same way via the **States** sheet → `data/config/state_synonyms.txt`.
- [x] there are some states which are NaN — fixed. Blank state was the discover_tasmania
  "3 days on King Island" tour (gazetteer miss). Added king island / currie / cape wickham
  -> TAS / King Island to `_PLACES_RAW` in combine_sources.py, plus `King Island -> island`
  in city_types.txt. Re-ran combine + build: 0 blank states remain.