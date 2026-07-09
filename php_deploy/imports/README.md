# Data delivery format (for the data team)

This folder is where new data gets uploaded. The web app never reads these
files directly — `import.php` loads them into MySQL, and the app serves from
the database. To publish an update:

1. Overwrite the file(s) here (File Manager or FTP).
2. Open `https://<domain>/import.php?key=<import key>` (ask the web team for
   the key). It prints what it imported; errors leave the previous data live.

## The five files

| File | Type | Contents |
|---|---|---|
| `themes.json` | array of strings | the theme list |
| `data.json` | object | per-state tour data, keyed by state code |
| `templates.json` | array of objects | global template list |
| `market.json` | array of objects | market-intelligence tours |
| `tokens.json` | array of objects | keyword tokens |

Rules:

- **`themes.json` + `data.json` + `templates.json` are one dataset** — always
  deliver the three together. `market.json` and `tokens.json` are independent;
  any subset of the three groups may be delivered.
- Each import **fully replaces** that dataset — always send complete files,
  not just the new/changed records.

## How to export (Python)

Each file is one `json.dump` of the structure you already build:

```python
def export(obj, path):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(obj, f, indent=2, ensure_ascii=False)

export(THEMES,      'themes.json')
export(DATA,        'data.json')
export(TEMPLATES,   'templates.json')
export(MARKET_DATA, 'market.json')
export(TOKENS,      'tokens.json')
```

**`indent=2` is required** for `data.json` and `templates.json`: the importer
streams them one state / one template at a time instead of loading ~26 MB
into server memory, and that relies on `json.dump(indent=2)` line layout.
Minified or differently-indented files are rejected (safely — the old data
stays live).

## Schemas

The importer requires only the fields below; **every other key is stored and
delivered to the frontend verbatim**, so the actual content contract is with
the app (builder.html), not the importer. Adding new keys is always safe.

### `themes.json`

```json
["Nature & Scenery", "Hiking & Walks", "..."]
```

### `data.json` — `{ "<STATE>": { ... }, ... }`

State codes are letters only (`WA`, `NSW`, ...). Object order = display order
in the app. Each state object:

```json
{
  "name": "Western Australia",     // required
  "abbr": "WA",                    // should match the key
  "allRows": [ { ... }, ... ],     // day-by-day rows, see below
  "cities":    { ... },            // passed through verbatim
  "products":  { ... },            // passed through verbatim
  "seed":      { ... },            // passed through verbatim
  "templates": [ ... ]             // state-scoped templates, verbatim
}
```

Each `allRows` row: these fields become indexed database columns (queryable
in phpMyAdmin) — `tour` (string, ≤255 chars), `source` (≤64), `type` (≤32),
`day` (int), `city` (≤128). All other keys (`location`, `products`,
`sourceLocation`, ...) pass through verbatim.

### `templates.json` — `[ { ... }, ... ]`

Array order is preserved. Indexed fields per template: `name` (≤255),
`source` (≤64), `type` (≤32). Everything else (`themes`, `states`, `days`,
`pop`, `url`, ...) passes through verbatim.

### `market.json` — `[ { ... }, ... ]`

Indexed fields: `tour_name` (≤255), `source` (≤64), `source_type` (≤32),
`market` (≤64), `state` (≤8). Everything else (`states`, `city`, ...) passes
through verbatim.

### `tokens.json` — `[ { ... }, ... ]`

Indexed fields: `name` (≤255), `state` (≤64), `issue` (≤32). Everything else
(`count`, `tours`, `src`, `suggest`, `split`, `ctx`, `sample_tours`, ...)
passes through verbatim.

## Notes

- Encoding is UTF-8; `ensure_ascii=False` is fine (em-dashes etc. are stored
  as-is).
- The indexed-field length limits only affect the *index columns* — values
  longer than the limit are truncated in the index but stay complete in the
  data delivered to the app.
- The corrections logic that used to sit at the end of `data.js`
  (`DATA_MODULE`) is now app code (`data_module.js`, owned by the web team).
  Ship only data — if that logic needs changing, hand the change to the web
  team instead.
