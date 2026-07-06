"""
prepare_data.py
Reads keyword_dataset CSV and outputs data.js for the Itinerary Builder prototype.
Run: python prepare_data.py
"""
import json
import re
from collections import defaultdict, Counter
from pathlib import Path
import pandas as pd

ROOT = Path(__file__).parent.parent   # the app/ folder
REPO_ROOT = ROOT.parent
CSV_PATH = str(REPO_ROOT / 'data' / 'keywords' / 'keyword_dataset_2026-06.csv')
OUT_PATH  = str(ROOT / 'frontend' / 'data.js')
MARKET_OUT_PATH = str(ROOT / 'frontend' / 'market.js')
CORRECTIONS_PATH = ROOT / 'frontend' / 'token_corrections.json'

THEMES = [
    'Nature & Scenery', 'Hiking & Walks', 'Beach & Coast', 'Wildlife',
    'Food & Wine', 'Culture & History', 'Scenic Drives', 'City & Shopping',
]
THEMES_SET = set(THEMES)

STATE_META = {
    'WA':  ('Western Australia',            'WA'),
    'NT':  ('Northern Territory',           'NT'),
    'QLD': ('Queensland',                   'QLD'),
    'NSW': ('New South Wales',              'NSW'),
    'SA':  ('South Australia',              'SA'),
    'TAS': ('Tasmania',                     'TAS'),
    'VIC': ('Victoria',                     'VIC'),
    'ACT': ('Australian Capital Territory', 'ACT'),
}
STATE_ORDER = ['WA', 'NT', 'QLD', 'NSW', 'SA', 'TAS', 'VIC', 'ACT']


def load_corrections():
    path = CORRECTIONS_PATH
    if not path.exists():
        return {'rename': {}, 'split': {}, 'delete': set()}
    with open(path, 'r', encoding='utf-8') as f:
        c = json.load(f)
    return {
        'rename': dict(c.get('rename', {})),
        'split':  dict(c.get('split', {})),
        'delete': set(c.get('delete', [])),
    }


def apply_corrections(tokens, corrections):
    result = []
    for tok in tokens:
        if tok in corrections['delete']:
            continue
        elif tok in corrections['rename']:
            result.append(corrections['rename'][tok])
        elif tok in corrections['split']:
            result.extend(corrections['split'][tok])
        else:
            result.append(tok)
    return result


def parse_semi(val):
    if not val or isinstance(val, float):
        return []
    return [x.strip() for x in str(val).split(';') if x.strip()]


def clean_title(location, city):
    """The day's base/heading — 'where you are'. Strips leading navigation
    words (Welcome to / Arrive / Depart …) and falls back to the canonical
    city when the heading is empty or just a state name."""
    loc = '' if (location is None or isinstance(location, float)) else str(location).strip()
    loc = re.sub(
        r'^(?:welcome to|arrive in|arriving in|arrive at|arrive|depart(?:ing)?|'
        r'fly to|drive to|travel to|transfer to|head to|return to|back to)\s+',
        '', loc, flags=re.IGNORECASE).strip()
    if not loc or is_noise(loc):
        c = '' if (city is None or isinstance(city, float)) else str(city).strip()
        if c and not is_noise(c):
            return c
    return loc


_TITLE_SPLIT   = re.compile(r'\s*(?:/|,|\bto\b|\s-\s)\s*', re.IGNORECASE)
_PLACE_GENERIC = ('city', 'cbd', 'airport', 'downtown', 'centre', 'center',
                  'region', 'area', 'surrounds')


def day_places(prods, title):
    """Genuine places to visit on a day = the extracted places, with the
    title/base name(s) removed so the day heading isn't repeated as a chip.

    `prods` is trusted clean (Gemini extraction). We drop only names that merely
    echo the title (title 'Melbourne' -> drop 'Melbourne', 'Melbourne City'),
    handle route titles ('Bunbury to Margaret River' -> drop both towns), and
    collapse same-day substring duplicates.
    """
    full = str(title).lower().strip()
    segs = {s.strip() for s in _TITLE_SPLIT.split(full) if s.strip()}
    if full:
        segs.add(full)   # also drop a place that echoes the whole heading verbatim
    out = []
    for p in prods:
        pl = p.lower().strip()
        if pl in segs:
            continue
        if any(pl == f'{s} {g}' for s in segs for g in _PLACE_GENERIC):
            continue
        if p not in out:
            out.append(p)
    lowers = [s.lower() for s in out]
    out = [s for s, sl in zip(out, lowers)
           if not any(ol != sl and ol in sl for ol in lowers)]
    return out


_GEO_NOISE = {
    'australia',
    'western australia', 'wa',
    'northern territory', 'nt',
    'queensland', 'qld', 'qld.',
    'new south wales', 'nsw',
    'south australia', 'sa',
    'tasmania', 'tas',
    'victoria', 'vic',
    'australian capital territory', 'act',
}


def is_noise(name):
    """Filter possessives and geographic names too broad to be product chips."""
    if name.endswith("'s") or name.endswith('’s'):
        return True
    return name.lower() in _GEO_NOISE


def url_slug(url):
    """Last meaningful path segment of a URL (no extension, no query)."""
    s = re.sub(r'[?#].*$', '', str(url)).rstrip('/')
    seg = s.split('/')[-1] if '/' in s else s
    seg = re.sub(r'\.html?$', '', seg, flags=re.IGNORECASE)
    return seg


def title_from_url(url):
    """Human title for an itinerary whose scraped tour_name is unreliable
    (e.g. australia.com pages where each day's heading landed in tour_name)."""
    slug = re.sub(r'[-_]+', ' ', url_slug(url)).strip()
    return slug.title() if slug else 'Untitled itinerary'


SOURCE_LOCATIONS_PATH = Path(__file__).parent.parent.parent / 'data' / 'config' / 'source_locations.csv'

# Sources temporarily excluded from the Builder/Consumer Analysis pipeline
# (scraper + raw data untouched — just kept out of data.js/market.js).
EXCLUDED_SOURCES = {'g_adventures'}


def _load_source_location_map():
    if not SOURCE_LOCATIONS_PATH.exists():
        return {}
    loc_df = pd.read_csv(SOURCE_LOCATIONS_PATH, dtype=str)
    return dict(zip(loc_df['source_key'], loc_df['location']))


def build():
    source_location_map = _load_source_location_map()
    df = pd.read_csv(CSV_PATH, low_memory=False)
    df = df[~df['source'].isin(EXCLUDED_SOURCES)]

    df['place_tags']  = df['place_tags'].fillna('')
    df['theme_tags']  = df['theme_tags'].fillna('')
    df['city']        = df['city'].fillna('')
    df['location']    = df['location'].fillna('')
    df['activity']    = df['activity'].fillna('') if 'activity' in df.columns else ''
    df['source_type'] = df['source_type'].fillna('Competitor')
    df['source']      = df['source'].fillna('')
    df['tour_url']    = df['tour_url'].fillna('') if 'tour_url' in df.columns else ''

    df['_prods']    = df['place_tags'].apply(
        lambda v: [p for p in parse_semi(v) if not is_noise(p)]
    )
    df['_themes']   = df['theme_tags'].apply(
        lambda v: [t for t in parse_semi(v) if t in THEMES_SET]
    )
    df['_is_board'] = df['source_type'].str.lower() == 'reference'

    # Apply token corrections (rename / split / delete) from token_corrections.json
    corrections = load_corrections()
    n_corrections = len(corrections['delete']) + len(corrections['rename']) + len(corrections['split'])
    if n_corrections:
        df['_prods'] = df['_prods'].apply(lambda tokens: apply_corrections(tokens, corrections))
        print(f'  Token corrections applied: {len(corrections["delete"])} deletions, '
              f'{len(corrections["rename"])} renames, {len(corrections["split"])} splits')
    else:
        print('  No token corrections file found — using raw tokens.')

    # ── per-state product catalogs ─────────────────────────────────────────
    # A product's popularity/board-op status is still meaningfully scoped to
    # a single state's market, so this stays a per-state pass.
    state_products = {}   # state_key -> {name: {...}}
    state_cities   = {}   # state_key -> {name: city}

    for state_key in STATE_ORDER:
        state_df = df[df['state'] == state_key]
        if state_df.empty:
            continue
        state_name, _ = STATE_META[state_key]

        prod_comp_tours  = defaultdict(set)   # competitor tours per product
        prod_board_tours = defaultdict(set)   # board docs per product
        prod_themes      = defaultdict(set)
        prod_cities      = defaultdict(Counter)

        for _, row in state_df.iterrows():
            is_board = row['_is_board']
            # Popularity = number of distinct itineraries (URLs) featuring a
            # product, not distinct names — names collide and, for some board
            # sources, vary per day, which would over/under-count.
            tour_id = row['tour_url']
            for p in row['_prods']:
                if is_board:
                    prod_board_tours[p].add(tour_id)
                else:
                    prod_comp_tours[p].add(tour_id)
                prod_themes[p].update(row['_themes'])
                if row['city']:
                    prod_cities[p][row['city']] += 1

        all_prods = set(prod_comp_tours) | set(prod_board_tours)

        products = {}
        cities   = {}

        for name in all_prods:
            if is_noise(name):
                continue
            comp_pop  = len(prod_comp_tours[name])
            board_pop = len(prod_board_tours[name])
            total_pop = comp_pop + board_pop
            if total_pop < 2:
                continue
            theme_list = [t for t in THEMES if t in prod_themes[name]]
            products[name] = {
                't':          theme_list,
                'pop':        total_pop,
                'comp_pop':   comp_pop,
                'board_pop':  board_pop,
                'board':      board_pop > 0,
                'op':         comp_pop > 0,
            }
            best_city = prod_cities[name].most_common(1)
            cities[name] = best_city[0][0] if best_city else state_name

        state_products[state_key] = products
        state_cities[state_key]   = cities

    # ── templates: one global pass across every tour_url ──────────────────
    # One template = one scraped itinerary = one tour_url. Grouping by URL
    # (not tour_name) keeps distinct tours that share a name separate and
    # prevents their days being concatenated into a frankenstein itinerary.
    # Built once globally (not per state_key) so cross-state tours keep every
    # day's real state instead of being collapsed into a single "home" state.
    competitor_tmpls = []
    board_tmpls      = []

    for tour_url in df['tour_url'].unique():
        if not tour_url:
            continue
        tour_df = df[df['tour_url'] == tour_url].sort_values('day_number')

        is_board_tour = tour_df['_is_board'].sum() > len(tour_df) / 2
        tmpl_type     = 'Board' if is_board_tour else 'Competitor'
        src_val       = tour_df['source'].iloc[0]
        src_display   = re.sub(r'^https?://(www\.)?', '', str(src_val)).split('/')[0]

        # Display name: use the scraped tour_name when the URL has a single
        # consistent name; fall back to a title derived from the URL slug
        # when the name varies per day (e.g. australia.com board pages).
        names = [str(n).strip() for n in tour_df['tour_name'].unique() if str(n).strip()]
        tour_name = names[0] if len(names) == 1 else title_from_url(tour_url)

        # Build each day as a structured object the builder can render
        # directly: the base/heading (title), the genuine places to visit
        # (Gemini-extracted, with the title name not repeated), the day's
        # themes (shown in their own row), the day's own state (a tour can
        # cross states), and the scraped "What You'll Do" description
        # (context / safety net for anything not chipped).
        days   = []
        themes = set()
        for _, day_row in tour_df.iterrows():
            title  = clean_title(day_row['location'], day_row['city'])
            places = day_places(day_row['_prods'], title)
            if not title and not places:
                continue
            dthemes = [t for t in THEMES if t in day_row['_themes']]
            desc    = str(day_row['activity']).strip()
            raw_city  = '' if (day_row['city'] is None or isinstance(day_row['city'], float)) else str(day_row['city']).strip()
            raw_state = '' if (day_row['state'] is None or isinstance(day_row['state'], float)) else str(day_row['state']).strip()
            days.append({'title': title, 'city': raw_city, 'state': raw_state,
                         'places': places, 'themes': dthemes, 'desc': desc})
            themes.update(day_row['_themes'])

        if not days:
            continue

        # Only keep genuine multi-stop routes. Movement is judged on the
        # day title (the base), so a tour that sits in one city the whole
        # time (or one city then jumps to another) is filtered out.
        primaries = [d['title'] or (d['places'][0] if d['places'] else '')
                     for d in days]
        distinct_stops = {p for p in primaries if p}
        if len(days) < 2 or len(distinct_stops) < 2:
            continue
        transitions = sum(
            1 for i in range(1, len(primaries)) if primaries[i] != primaries[i - 1]
        )
        if transitions < max(1, len(days) // 3):
            continue

        states_touched = []
        for d in days:
            if d['state'] and d['state'] not in states_touched:
                states_touched.append(d['state'])
        if not states_touched:
            continue

        # Popularity bar stays driven by place_tags product popularity,
        # resolved against each product's *own* day's state catalog (a
        # product can appear in more than one state's catalog under
        # different popularity numbers for a cross-state tour).
        pop = 0
        for d in days:
            prods_for_state = state_products.get(d['state'], {})
            for p in d['places']:
                info = prods_for_state.get(p)
                if not info:
                    continue
                pop = max(pop, info['board_pop'] if is_board_tour else info['comp_pop'])

        entry = {
            'name':           tour_name,
            'source':         src_display,
            'sourceLocation': source_location_map.get(str(tour_df['source'].iloc[0]), 'Unknown'),
            'type':           tmpl_type,
            'pop':            pop,
            'url':            tour_url,
            'themes':         [t for t in THEMES if t in themes],
            'states':         states_touched,
            'days':           days,
        }
        if is_board_tour:
            board_tmpls.append(entry)
        else:
            competitor_tmpls.append(entry)

    # Disambiguate display names: because every URL is kept as its own
    # template, two distinct itineraries can share a name. Append a short
    # hint from the URL slug to any name used by more than one template so
    # the siblings are distinguishable on their cards.
    name_counts = Counter(t['name'] for t in competitor_tmpls + board_tmpls)
    for t in competitor_tmpls + board_tmpls:
        if name_counts[t['name']] > 1:
            hint = url_slug(t['url'])
            if hint:
                t['name'] = t['name'] + ' · ' + hint

    # Sort each group: longer tours first, then by pop
    competitor_tmpls.sort(key=lambda t: (-len(t['days']), -t['pop']))
    board_tmpls.sort(key=lambda t:      (-len(t['days']), -t['pop']))

    # Competitors first, then board — this is the single source of truth for
    # every template; per-state buckets below are filtered views of it.
    all_templates = competitor_tmpls + board_tmpls

    result = {}

    for state_key in STATE_ORDER:
        state_df = df[df['state'] == state_key]
        if state_df.empty:
            continue

        state_name, state_abbr = STATE_META[state_key]
        products = state_products.get(state_key, {})
        cities   = state_cities.get(state_key, {})

        templates = [t for t in all_templates if state_key in t['states']]

        # Remove internal tracking fields from products before output
        for name in products:
            products[name].pop('comp_pop', None)
            products[name].pop('board_pop', None)

        # ── full dataset (every scraped day-row, for the data review screen) ─
        # The Tagged Data view shows the real, transparent day-level asset:
        # one row per scraped day with the engine's extracted products/themes.
        # All rows are exported (no cap) so it can replace reviewing in Excel.
        all_rows = []
        for _, row in state_df.sort_values(['tour_name', 'day_number']).iterrows():
            src_raw    = str(row['source'])
            src_domain = re.sub(r'^https?://(www\.)?', '', src_raw).split('/')[0]
            url_raw    = str(row['tour_url']).strip() if isinstance(row['tour_url'], str) else ''
            all_rows.append({
                'tour':           row['tour_name'],
                'source':         src_domain,
                'sourceLocation': source_location_map.get(str(row['source']), 'Unknown'),
                'type':           'Board' if row['_is_board'] else 'Competitor',
                'day':            int(row['day_number']) if pd.notna(row['day_number']) else 1,
                'city':           row['city'],
                'location':       row['location'],
                'products':       ', '.join(row['_prods']),
                'themes':         ', '.join(row['_themes']),
                'url':            url_raw,
            })

        # ── seed (default builder state) ───────────────────────────────────
        # The builder opens blank: empty days the user fills in, or loads a
        # template. initState pads `days` up to `duration` with empty cards.
        seed = {'tourName': '', 'duration': 5, 'days': []}

        result[state_key] = {
            'name':      state_name,
            'abbr':      state_abbr,
            'allRows':   all_rows,
            'seed':      seed,
            'products':  products,
            'cities':    cities,
            'templates': templates,
        }

    return result, all_templates


_DATA_MODULE_UTILS = r"""
const _SK = 'au_itinerary_corrections';
window.DATA_MODULE = {
  THEMES: THEMES,
  CATALOG: DATA,
  TEMPLATES: TEMPLATES,
  STORAGE_KEY: _SK,
  loadCorrections() {
    try { return JSON.parse(localStorage.getItem(_SK) || '{}'); } catch(e) { return {}; }
  },
  saveCorrections(c) {
    localStorage.setItem(_SK, JSON.stringify(c));
  },
  rewriteName(sk, name, corrections) {
    const key = sk + ':' + name, c = corrections[key];
    if (!c) return [name];
    if (c.action === 'rename') return [c.target || name];
    if (c.action === 'merge') return [c.target || name];
    if (c.action === 'remove') return [];
    if (c.action === 'split') return c.parts || [name];
    return [name];
  },
  resolveCatalog(corrections) {
    if (!corrections || !Object.keys(corrections).length) return DATA;
    const self = this, result = {};
    for (const [sk, sd] of Object.entries(DATA)) {
      const np = {}, nc = Object.assign({}, sd.cities || {});
      for (const [name, prod] of Object.entries(sd.products)) {
        for (const nn of self.rewriteName(sk, name, corrections)) {
          if (nn in np) {
            const ex = np[nn];
            ex.pop = (ex.pop || 0) + (prod.pop || 0);
            ex.t = Array.from(new Set([...(ex.t || []), ...(prod.t || [])]));
            ex.board = ex.board || prod.board;
            ex.op = ex.op || prod.op;
          } else {
            np[nn] = Object.assign({}, prod);
            if (!(nn in nc) && sd.cities && sd.cities[name]) nc[nn] = sd.cities[name];
          }
        }
      }
      const nt = (sd.templates || []).map(t => {
        const nd = (t.days || []).map(day => {
          const arr = [];
          (day.places || []).forEach(p => { self.rewriteName(sk, p, corrections).forEach(r => { if (!arr.includes(r)) arr.push(r); }); });
          return Object.assign({}, day, { places: arr });
        });
        return Object.assign({}, t, { days: nd });
      });
      result[sk] = Object.assign({}, sd, { products: np, cities: nc, templates: nt });
    }
    return result;
  },
  resolveTemplates(corrections) {
    if (!corrections || !Object.keys(corrections).length) return TEMPLATES;
    const self = this;
    return TEMPLATES.map(t => {
      const nd = (t.days || []).map(day => {
        const arr = [];
        (day.places || []).forEach(p => {
          self.rewriteName(day.state, p, corrections).forEach(r => { if (!arr.includes(r)) arr.push(r); });
        });
        return Object.assign({}, day, { places: arr });
      });
      return Object.assign({}, t, { days: nd });
    });
  },
  allTokens() {
    const T = window.TOKENS;
    if (!T) return [];
    return T.filter(t => !t.resolved).flatMap(t => {
      const states = t.state ? t.state.split(',').map(s => s.trim()).filter(Boolean) : [''];
      return states.map(s => Object.assign({}, t, { state: s }));
    });
  },
  tokenKey(state, name) { return (state || '') + ':' + name; },
  recommendedCorrection(token) {
    const i = token.issue;
    if (i === 'nonplace' || i === 'junk') return { action: 'remove' };
    if (i === 'split' && token.split) return { action: 'split', parts: token.split };
    if (i === 'dupe' && token.suggest) return { action: 'merge', target: token.suggest };
    if (i && token.suggest) return { action: 'rename', target: token.suggest };
    if (!i) return { action: 'approve' };
    return { action: 'flag' };
  },
  pendingIssueCount(corrections) {
    const T = window.TOKENS;
    if (!T) return 0;
    return T.filter(t => !t.resolved && t.issue && t.issue !== 'junk').filter(t => {
      const states = t.state ? t.state.split(',').map(s => s.trim()).filter(Boolean) : [''];
      return states.some(s => { const c = corrections[s + ':' + t.name]; return !c || c.action === 'flag'; });
    }).length;
  }
};
"""


def build_market_data(all_templates):
    """One record per tour (deduped by name+source), for the Market
    Intelligence tab. Derived from all_templates — no re-parsing of the CSV.
    Deliberately excludes themes and price (unreliable / gappy per spec)."""
    grouped = {}
    for t in all_templates:
        key = (t['name'], t['source'])
        cities = []
        for d in t['days']:
            if d['city'] and d['city'] not in cities:
                cities.append(d['city'])
        products = []
        for d in t['days']:
            for p in d['places']:
                if p not in products:
                    products.append(p)
        if key not in grouped:
            grouped[key] = {
                'tour_name':   t['name'],
                'source':      t['source'],
                'source_type': t['type'],
                'market':      t['sourceLocation'],
                'state':       t['states'][0] if t['states'] else '',
                'states':      list(t['states']),
                'city':        cities,
                'total_days':  len(t['days']),
                'products':    products,
            }
        else:
            g = grouped[key]
            g['total_days'] = max(g['total_days'], len(t['days']))
            for c in cities:
                if c not in g['city']:
                    g['city'].append(c)
            for p in products:
                if p not in g['products']:
                    g['products'].append(p)
            for s in t['states']:
                if s not in g['states']:
                    g['states'].append(s)
    return list(grouped.values())


def main():
    print('Reading dataset...')
    data, all_templates = build()

    print('Writing data.js...')
    themes_js = 'const THEMES = ' + json.dumps(THEMES, ensure_ascii=False) + ';\n'
    data_js   = 'const DATA = '   + json.dumps(data,   ensure_ascii=False, indent=2) + ';\n'
    tmpl_js   = 'const TEMPLATES = ' + json.dumps(all_templates, ensure_ascii=False, indent=2) + ';\n'
    js = themes_js + data_js + tmpl_js + _DATA_MODULE_UTILS
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        f.write(js)

    print('Writing market.js...')
    market_data = build_market_data(all_templates)
    market_js = 'window.MARKET_DATA = ' + json.dumps(market_data, ensure_ascii=False, indent=2) + ';\n'
    with open(MARKET_OUT_PATH, 'w', encoding='utf-8') as f:
        f.write(market_js)
    print(f'  {len(market_data)} tours in market.js')

    print('\nDone!')
    for sk, sd in data.items():
        comp_tmpls = sum(1 for t in sd['templates'] if t['type'] == 'Competitor')
        board_tmpls = len(sd['templates']) - comp_tmpls
        print(f'  {sk}: {len(sd["products"])} products, '
              f'{comp_tmpls}C+{board_tmpls}B templates, '
              f'{len(sd["allRows"])} data rows, '
              f'seed={sd["seed"]["tourName"]!r}')

    multi_state = sum(1 for t in all_templates if len(t['states']) > 1)
    total_prods = sum(len(sd['products']) for sd in data.values())
    total_days  = sum(len(t['days']) for t in all_templates)
    total_rows  = sum(len(sd['allRows']) for sd in data.values())
    print(f'\nSidebar stats: {len(all_templates)} tours ({multi_state} multi-state)  '
          f'{total_prods} products  {total_days} day rows')
    print(f'Full review dataset: {total_rows} scraped day-rows')


if __name__ == '__main__':
    main()
