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

ROOT = Path(__file__).parent.parent   # the "Build and explore" folder
CSV_PATH = r'C:\Users\SuyashA\Desktop\Itinenaries_analysis\data\keywords\keyword_dataset_2026-06.csv'
OUT_PATH  = str(ROOT / 'app' / 'data.js')
CORRECTIONS_PATH = ROOT / 'data' / 'token_corrections.json'

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


def majority_state(tour_name, df_all):
    """Return the state that has the most rows for this tour_name."""
    rows = df_all[df_all['tour_name'] == tour_name]
    if rows.empty:
        return None
    return rows['state'].value_counts().idxmax()


def build():
    df = pd.read_csv(CSV_PATH, low_memory=False)

    df['place_tags']  = df['place_tags'].fillna('')
    df['theme_tags']  = df['theme_tags'].fillna('')
    df['city']        = df['city'].fillna('')
    df['source_type'] = df['source_type'].fillna('Competitor')
    df['source']      = df['source'].fillna('')

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

    # Pre-compute majority state per tour to filter out cross-state tours
    tour_majority_state = {
        tn: majority_state(tn, df)
        for tn in df['tour_name'].unique()
    }

    result = {}

    for state_key in STATE_ORDER:
        state_df = df[df['state'] == state_key].copy()
        if state_df.empty:
            continue

        state_name, state_abbr = STATE_META[state_key]

        # ── product catalog ────────────────────────────────────────────────
        # Track tour sets separately for board vs competitor (for pop scoring)
        prod_comp_tours  = defaultdict(set)   # competitor tours per product
        prod_board_tours = defaultdict(set)   # board docs per product
        prod_themes      = defaultdict(set)
        prod_cities      = defaultdict(Counter)

        for _, row in state_df.iterrows():
            is_board = row['_is_board']
            for p in row['_prods']:
                if is_board:
                    prod_board_tours[p].add(row['tour_name'])
                else:
                    prod_comp_tours[p].add(row['tour_name'])
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

        # ── templates ──────────────────────────────────────────────────────
        # Only include tours whose majority state is this state
        state_tours = [
            tn for tn in state_df['tour_name'].unique()
            if tour_majority_state.get(tn) == state_key
        ]

        competitor_tmpls = []
        board_tmpls      = []

        for tour_name in state_tours:
            tour_df = state_df[state_df['tour_name'] == tour_name].sort_values('day_number')

            is_board_tour = tour_df['_is_board'].sum() > len(tour_df) / 2
            tmpl_type     = 'Board' if is_board_tour else 'Competitor'
            src_val       = tour_df['source'].iloc[0]
            src_display   = re.sub(r'^https?://(www\.)?', '', str(src_val)).split('/')[0]

            days = []
            for _, day_row in tour_df.iterrows():
                day_prods = [p for p in day_row['_prods'] if p in products]
                if day_prods:
                    days.append(day_prods)

            if not days:
                continue

            all_p    = [p for d in days for p in d]
            # Use source-type-specific popularity so board and competitor are comparable
            if is_board_tour:
                pop = max((products[p]['board_pop'] for p in all_p if p in products), default=0)
            else:
                pop = max((products[p]['comp_pop'] for p in all_p if p in products), default=0)

            entry = {
                'name':   tour_name,
                'source': src_display,
                'type':   tmpl_type,
                'pop':    pop,
                'days':   days,
            }
            if is_board_tour:
                board_tmpls.append(entry)
            else:
                competitor_tmpls.append(entry)

        # Sort each group: longer tours first, then by pop
        competitor_tmpls.sort(key=lambda t: (-len(t['days']), -t['pop']))
        board_tmpls.sort(key=lambda t:      (-len(t['days']), -t['pop']))

        # Competitors first, then board; cap at 30 total
        templates = (competitor_tmpls + board_tmpls)[:30]

        # Remove internal tracking fields from products before output
        for name in products:
            del products[name]['comp_pop']
            del products[name]['board_pop']

        # ── tagged sample (real rows for the Tagged Data view) ─────────────
        tagged_sample = []
        board_rows = state_df[state_df['_is_board']].copy()
        comp_rows  = state_df[~state_df['_is_board']].copy()
        for chunk in [board_rows, comp_rows]:
            count = 0
            for _, row in chunk.iterrows():
                prods  = [p for p in row['_prods'] if p in products]
                themes = row['_themes']
                if not prods:
                    continue
                src_raw    = str(row['source'])
                src_domain = re.sub(r'^https?://(www\.)?', '', src_raw).split('/')[0]
                tagged_sample.append({
                    'tour':     row['tour_name'],
                    'source':   src_domain,
                    'type':     'Board' if row['_is_board'] else 'Competitor',
                    'day':      int(row['day_number']) if pd.notna(row['day_number']) else 1,
                    'city':     row['city'],
                    'location': row['location'],
                    'products': ', '.join(prods),
                    'themes':   ', '.join(themes),
                })
                count += 1
                if count >= 25:
                    break

        # ── seed (default builder state) ───────────────────────────────────
        # Pick the longest competitor tour as the seed
        seed_tmpl = next(
            (t for t in competitor_tmpls if len(t['days']) >= 3),
            (competitor_tmpls[0] if competitor_tmpls else
             board_tmpls[0]      if board_tmpls      else None),
        )

        if seed_tmpl:
            seed_days = seed_tmpl['days'][:4]
            seed = {
                'tourName': seed_tmpl['name'],
                'duration': min(len(seed_tmpl['days']), 7),
                'days': [
                    {
                        'location': cities.get(day[0], ''),
                        'desc':     '',
                        'products': day,
                    }
                    for day in seed_days
                ],
            }
        else:
            seed = {'tourName': '', 'duration': 5, 'days': []}

        result[state_key] = {
            'name':         state_name,
            'abbr':         state_abbr,
            'taggedSample': tagged_sample,
            'seed':      seed,
            'products':  products,
            'cities':    cities,
            'templates': templates,
        }

    return result


def main():
    print('Reading dataset...')
    data = build()

    print('Writing data.js...')
    js = 'const DATA = ' + json.dumps(data, ensure_ascii=False, indent=2) + ';\n'
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        f.write(js)

    print('\nDone!')
    for sk, sd in data.items():
        comp_tmpls = sum(1 for t in sd['templates'] if t['type'] == 'Competitor')
        board_tmpls = len(sd['templates']) - comp_tmpls
        print(f'  {sk}: {len(sd["products"])} products, '
              f'{comp_tmpls}C+{board_tmpls}B templates, '
              f'seed={sd["seed"]["tourName"]!r}')

    total_tours = sum(len(sd['templates'])       for sd in data.values())
    total_prods = sum(len(sd['products'])         for sd in data.values())
    total_days  = sum(
        sum(len(t['days']) for t in sd['templates']) for sd in data.values()
    )
    print(f'\nSidebar stats: {total_tours} tours  {total_prods} products  {total_days} day rows')


if __name__ == '__main__':
    main()
