"""
prepare_tokens.py
Reads keyword_dataset CSV and outputs tokens.js for Token Editor.dc.html.
Run: python prepare_tokens.py
"""
import json
import re
from collections import defaultdict, Counter
from pathlib import Path
import pandas as pd

CSV_PATH = r'C:\Users\SuyashA\Desktop\Itinenaries_analysis\data\keywords\keyword_dataset_2026-06.csv'
OUT_PATH  = r'C:\Users\SuyashA\Downloads\Build and explore\tokens.js'

PREFIX_VERBS = {
    'Arrive', 'Return', 'Travel', 'Drive', 'Continue', 'Fly',
    'Head', 'Depart', 'Leave', 'Check', 'Transfer', 'Take',
    'Visit', 'Go',
}

SUFFIX_NOUNS = {
    'transfer', 'accommodation', 'resort', 'hotel', 'motel',
    'included', 'available', 'optional', 'only',
}

# Australian state/territory names and abbreviations — too broad to be useful
# product chips; filtered out before they reach the Token Editor.
GEO_NOISE = {
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


def parse_semi(val):
    if not val or isinstance(val, float):
        return []
    return [x.strip() for x in str(val).split(';') if x.strip()]


def detect_extra(name):
    words = name.split()
    if len(words) < 2:
        return None
    if words[0] in PREFIX_VERBS:
        stripped = ' '.join(words[1:]).strip()
        return stripped if stripped else None
    if words[-1].lower() in SUFFIX_NOUNS:
        stripped = ' '.join(words[:-1]).strip()
        return stripped if stripped else None
    return None


def detect_issue(name, canonical_map):
    # Priority 1: nonplace — possessive
    if name.endswith("'s") or name.endswith('’s'):
        suggest = re.sub(r"[’']s$", '', name).strip()
        return 'nonplace', suggest, None

    # Priority 2: split — ampersand compound
    if ' & ' in name:
        parts = [p.strip() for p in name.split(' & ', 1)]
        return 'split', '', parts

    # Priority 3: extra — action-word prefix or suffix
    stripped = detect_extra(name)
    if stripped is not None:
        return 'extra', stripped, None

    # Priority 4: dupe — near-duplicate of a higher-count token
    if name in canonical_map:
        return 'dupe', canonical_map[name], None

    # Priority 5: caps — all alphabetic characters are uppercase
    alpha = [c for c in name if c.isalpha()]
    if alpha and all(c.isupper() for c in alpha):
        return 'caps', _title(name), None

    # Priority 6: lower — first character is lowercase
    if name and name[0].islower():
        return 'lower', _title(name), None

    return None, '', None


def _title(name):
    """Title-case a string, respecting short connector words."""
    LOWER_WORDS = {'of', 'the', 'and', 'in', 'at', 'on', 'a', 'an', 'to', 'de'}
    words = name.split()
    result = []
    for i, w in enumerate(words):
        if i == 0 or w.lower() not in LOWER_WORDS:
            result.append(w.capitalize())
        else:
            result.append(w.lower())
    return ' '.join(result)


def build():
    df = pd.read_csv(CSV_PATH, low_memory=False)
    df['place_tags']  = df['place_tags'].fillna('')
    df['location']    = df['location'].fillna('')
    df['tour_name']   = df['tour_name'].fillna('')
    df['state']       = df['state'].fillna('')
    df['source_type'] = df['source_type'].fillna('Competitor')
    df['_is_board']   = df['source_type'].str.lower() == 'reference'
    df['_tokens']     = df['place_tags'].apply(parse_semi)

    # Collect per-token stats across all states
    stats = defaultdict(lambda: {
        'rows': 0,
        'board_tours': set(),
        'comp_tours':  set(),
        'states':      Counter(),
        'ctx':         [],
    })

    for _, row in df.iterrows():
        for tok in row['_tokens']:
            if not tok:
                continue
            s = stats[tok]
            s['rows'] += 1
            if row['_is_board']:
                s['board_tours'].add(row['tour_name'])
            else:
                s['comp_tours'].add(row['tour_name'])
            if row['state']:
                s['states'][row['state']] += 1
            # Collect up to 5 unique context strings (trimmed to 3 in output)
            if len(s['ctx']) < 10:
                ctx = str(row['location']).strip()
                if not ctx or ctx.lower() == 'nan':
                    ctx = str(row['tour_name']).strip()
                if ctx and ctx not in s['ctx']:
                    s['ctx'].append(ctx)

    # Filter: must appear in at least 2 distinct tours, and not a state/country name
    universe = {
        name: s for name, s in stats.items()
        if (len(s['board_tours']) + len(s['comp_tours'])) >= 2
        and name.lower() not in GEO_NOISE
    }

    # Build canonical_map for dupe detection
    # Group by token.lower(); canonical = highest row count (ties broken alphabetically)
    lower_groups = defaultdict(list)
    for name in universe:
        lower_groups[name.lower()].append(name)

    canonical_map = {}
    for names in lower_groups.values():
        if len(names) < 2:
            continue
        names_sorted = sorted(names, key=lambda n: (-universe[n]['rows'], n))
        canonical = names_sorted[0]
        for other in names_sorted[1:]:
            canonical_map[other] = canonical

    # Build output records
    records = []
    for name, s in universe.items():
        has_board = len(s['board_tours']) > 0
        has_comp  = len(s['comp_tours'])  > 0
        src   = 'BC' if (has_board and has_comp) else ('B' if has_board else 'C')
        state = s['states'].most_common(1)[0][0] if s['states'] else ''

        issue, suggest, split_parts = detect_issue(name, canonical_map)

        records.append({
            'name':    name,
            'state':   state,
            'count':   s['rows'],
            'src':     src,
            'issue':   issue,
            'suggest': suggest,
            'split':   split_parts,
            'ctx':     s['ctx'][:6],
        })

    # Sort: flagged first (by row count desc), then clean
    records.sort(key=lambda r: (0 if r['issue'] else 1, -r['count']))
    return records


def main():
    print('Reading dataset...')
    records = build()

    print(f'Writing tokens.js ({len(records)} tokens)...')
    js = 'const TOKENS = ' + json.dumps(records, ensure_ascii=False, indent=2) + ';\n'
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        f.write(js)

    by_issue = Counter(r['issue'] for r in records)
    total    = len(records)
    flagged  = sum(1 for r in records if r['issue'])
    print(f'\nDone! {total} tokens, {flagged} flagged, {total - flagged} clean')
    for issue, cnt in sorted(by_issue.items(), key=lambda x: -x[1]):
        print(f'  {issue or "clean":12s}  {cnt}')


if __name__ == '__main__':
    main()
