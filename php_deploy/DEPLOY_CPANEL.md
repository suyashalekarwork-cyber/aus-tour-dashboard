# Deploying the Itinerary Builder to cPanel (PHP + MySQL)

The `php_deploy/` folder is a self-contained package: the frontend plus a PHP
backend that replaces `app/server/server.py`. Upload it as-is — no Python
needed on the server.

**All app data lives in MySQL:**

| Data | Table(s) | Loaded from |
|---|---|---|
| Saved itineraries (user work) | `itineraries` | `setup/saved_itineraries.json` (one-time migration) |
| Tour data per state | `states`, `tour_rows` | `imports/data.json` |
| Global templates | `templates` | `imports/templates.json` |
| Market intelligence | `market_tours` | `imports/market.json` |
| Keyword tokens | `tokens` | `imports/tokens.json` |
| Themes + dataset versions | `settings` | `imports/themes.json` / bumped on import |

The browser no longer downloads 26 MB static files: `dataset.php` serves each
dataset from MySQL (assembled once per import into `cache/`, compressed, and
revalidated with ETags — repeat visits get tiny 304 responses instead of
re-downloading).

`catalog.js` (28 MB) turned out to be completely unused by the app — it is not
loaded by any script tag — so it is not deployed at all.

---

## Step 1 — Create the database in cPanel

1. cPanel → **MySQL Databases**.
2. Under *Create New Database*, enter a name (e.g. `tdu`) → **Create Database**.
   cPanel prefixes it with your account name → note the full name, e.g.
   `youracct_tdu`.
3. Under *MySQL Users → Add New User*, create a user (e.g. `tduapp`) with a
   strong password → note the full username, e.g. `youracct_tduapp`.
4. Under *Add User To Database*, add that user to that database →
   check **ALL PRIVILEGES** → Make Changes.

## Step 2 — Configure the app

Edit `php_deploy/config.php`:

```php
'db_name'    => 'youracct_tdu',
'db_user'    => 'youracct_tduapp',
'db_pass'    => 'the-password-you-set',
'import_key' => 'any-long-random-string',   // protects import.php
```

`db_host` stays `localhost` on cPanel.

## Step 3 — Upload

The package is ~30 MB (mostly the data files in `imports/`), so zip first:

1. Zip the **contents** of `php_deploy/` (not the folder itself).
2. cPanel → **File Manager** → go to `public_html/` (or a subfolder like
   `public_html/itinerary/` — both work, the app uses relative URLs).
3. Upload the zip → right-click → **Extract** → delete the zip.
4. Make sure the `.htaccess` files were extracted (File Manager → Settings →
   **Show Hidden Files**) — there are four: one in the root and one each in
   `imports/`, `cache/`, and `setup/`.

## Step 4 — Run the one-time install script

Open `https://yourdomain.com.au/setup/install.php`. It creates all tables
(from `setup/schema.sql`) and imports the old `saved_itineraries.json` into
MySQL. Safe to re-run.

## Step 5 — Import the app data

Open `https://yourdomain.com.au/import.php?key=YOUR_IMPORT_KEY` (the key from
`config.php`). It loads the data files from `imports/` into MySQL — takes up
to a minute or two for the big file — and prints per-state progress. Re-run
it any time; each run fully replaces the app data. The `imports/` folder in
this package already contains the data in the JSON delivery format
(see *Routine maintenance*).

**When both scripts have run, delete the whole `setup/` folder from the
server** (it holds only one-time files: `install.php`, `schema.sql`, the
`saved_itineraries.json` seed). `import.php` stays — it's how future data
updates get in (it refuses to run without the key).

## Step 6 — Test

Open `https://yourdomain.com.au/`. Checklist:

- [ ] Builder page loads with states/products (first load builds the cache —
      a few seconds — after that it's fast, and repeat visits use 304s).
- [ ] Old saved itineraries appear; saving a new one survives a reload
      (check phpMyAdmin → `itineraries`).
- [ ] Delete an itinerary → reload → it stays gone.
- [ ] Templates and Market Intelligence views show data.
- [ ] AI prediction works (server-side call to the n8n proxy).

## Step 7 — Protect it with a login (recommended)

cPanel → **Directory Privacy** → select the folder you uploaded to → tick
*Password protect this directory* → create a user (e.g. `neha`) and password.
This protects everything: page, data, and API.

---

## Routine maintenance

**Data updates from the data team.** To publish a new dataset:

1. Upload the new files into the `imports/` folder, overwriting the old ones
   (File Manager or FTP).
2. Open `import.php?key=YOUR_IMPORT_KEY`.
3. Done — the cache regenerates automatically and every user gets the new
   data on their next page load.

**Preferred delivery format — plain JSON, one file per structure.** Each file
is a single `json.dump` from the data team's Python pipeline:

```python
def export(obj, path):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(obj, f, indent=2, ensure_ascii=False)   # indent=2 matters

export(THEMES,      'themes.json')      # ["Nature & Scenery", ...]
export(DATA,        'data.json')        # {"WA": {...}, "NSW": {...}, ...}
export(TEMPLATES,   'templates.json')   # [{...}, ...]
export(MARKET_DATA, 'market.json')      # [{...}, ...]
export(TOKENS,      'tokens.json')      # [{...}, ...]
```

Notes:
- `themes.json` + `data.json` + `templates.json` form one dataset and must be
  delivered together; `market.json` and `tokens.json` are independent.
- `indent=2` is required for the two big files — the importer streams them
  state-by-state so the server never loads 26 MB into memory.
- The data team ships *only data* — `data_module.js` (the corrections logic
  that used to ride along at the end of `data.js`) is app code owned by the
  web team; imports never touch it.
- **`imports/README.md` is the full spec** (schemas, required vs pass-through
  fields, delivery steps) — send a copy to the data team; the folder itself
  is not web-accessible.

Alternatively the data team can be given phpMyAdmin/remote-MySQL access to
write to the tables directly — but then bump the matching `*_version` row in
`settings` (e.g. `data_version` + 1) so caches and browsers pick up the change.

**Backups:** the only irreplaceable data is the `itineraries` table (user
work) — everything else can be re-imported from the data team's files.
phpMyAdmin → Export, or include the database in your cPanel backup schedule.

## Troubleshooting

- **500 error / blank page:** check cPanel → *Errors* (or `error_log` in the
  upload folder). Most common cause: wrong DB credentials in `config.php`.
- **"Dataset ... has not been imported yet":** run Step 5 (`import.php`).
- **Error mentioning a missing table:** run Step 4 (`install.php`).
- **"Not found" on save:** the root `.htaccess` didn't upload (hidden file) —
  the API routes live there.
- **import.php says Forbidden:** set a real `import_key` in `config.php` and
  pass it as `?key=`.
- **Page loads but no data:** check `cache/` is writable (permissions 755 and
  owned by your account — the default when PHP creates files on cPanel).
- **PHP version:** needs PHP 7.4+ — cPanel → *MultiPHP Manager* (8.x recommended).
- **AI prediction unavailable:** the server couldn't reach the n8n proxy.
  Some hosts block outbound HTTP — ask support to allow outbound HTTPS to
  `gtxn8n.yourbestwayhome.com.au`.

## File map

| File | Role |
|---|---|
| `builder.html` + `support.js` + react/babel libs | the app (frontend) |
| `data_module.js` | corrections logic (app code, owned by the web team — imports never touch it) |
| `api.php` | itineraries CRUD (MySQL) + AI prediction proxy |
| `dataset.php` | serves data/market/tokens datasets from MySQL (ETag + cache) |
| `import.php` | loads the data team's files from `imports/` into MySQL (stays on server, key-protected) |
| `config.php` | DB credentials, import key, proxy settings |
| `imports/` | the data team's JSON files (blocked from web access) — `imports/README.md` documents the delivery format for the data team |
| `cache/` | generated dataset JS, one per version (blocked from web access) |
| `setup/` | one-time install files: `install.php`, `schema.sql`, `saved_itineraries.json` seed — **delete the folder after setup** |

## What changed vs. the Python version

| | Local (Python) | cPanel (PHP) |
|---|---|---|
| Server | `server.py` on port 8765 | `api.php` + `dataset.php` behind Apache |
| Saved itineraries | JSON file | MySQL `itineraries` |
| App data | 26 MB+ static JS files, re-downloaded every load | MySQL tables, served with ETag/304 caching + gzip |
| Data updates | replace files, restart server | upload to `imports/`, run `import.php` |
| Corrections editor (`/sync`, `/restore`) | needed `app/pipeline/` (not in this package) | not deployed — the builder UI never calls them |
| Login | disabled | cPanel Directory Privacy |

Frontend changes: four `fetch()` URLs made relative, three `<script>` tags now
point at `dataset.php`, ids of saved itineraries get a random suffix. Nothing
else was touched.
