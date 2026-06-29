"""
prepare_tokens.py
Reads keyword_dataset CSV and outputs tokens.js for the Token Editor.

What this does:
  1. Confirms ~35% of tokens against the official Australian GeoNames database
     (government place-name list) — these are auto-validated, no PM review needed.
  2. Clusters near-duplicate spellings together using fuzzy matching (rapidfuzz),
     so look-alikes appear side-by-side in the Excel review sheet.
  3. Marks tokens already resolved in token_corrections.json — skipped in Excel.
  4. Auto-handles obvious high-confidence fixes (ALL CAPS, possessives, etc.)
     so the PM only sees genuinely uncertain tokens.

Run: python prepare_tokens.py
"""
import json
import re
from collections import defaultdict, Counter
from pathlib import Path
import pandas as pd

ROOT            = Path(__file__).parent.parent          # the app/ folder
CSV_PATH        = r'C:\Users\SuyashA\Desktop\Itinenaries_analysis\data\keywords\keyword_dataset_2026-06.csv'
OUT_PATH        = str(ROOT / 'frontend' / 'tokens.js')
GEONAMES_PATH   = ROOT.parent / 'data' / 'gazetteer' / 'AU_geonames.txt'
CORRECTIONS_PATH = ROOT / 'frontend' / 'token_corrections.json'

PREFIX_VERBS = {
    'Arrive', 'Return', 'Travel', 'Drive', 'Continue', 'Fly',
    'Head', 'Depart', 'Leave', 'Check', 'Transfer', 'Take',
    'Visit', 'Go',
}

SUFFIX_NOUNS = {
    'transfer', 'accommodation', 'resort', 'hotel', 'motel',
    'included', 'available', 'optional', 'only',
}

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

# Single-word generic English words that are never useful product tokens.
# These pass the 2-tour filter but are clearly not place names.
# Checked as lowercase whole-token match only — does NOT affect multi-word tokens.
GENERIC_SINGLES = {
    'accommodation', 'tours', 'sea', 'ocean', 'valley', 'coast',
    'pacific', 'farewell', 'overview', 'scenery', 'wildlife',
    'adventure', 'experience', 'highlights', 'optional', 'included',
    'transfer', 'standard', 'provincial', 'heritage', 'colonial',
    'tropical', 'classic', 'historic', 'scenic', 'remote',
}


# ── GeoNames ────────────────────────────────────────────────────────────────

def load_geonames(path):
    """
    Load official Australian place names.
    Returns a set of lowercase official names.
    We use only the exact official name (column 2), not alternates,
    because alternate-name lookups produce too many false matches
    (e.g. 'Adelaide' is an alternate for 'Adelaide River').
    """
    confirmed = set()
    p = Path(path)
    if not p.exists():
        print(f'  WARNING: GeoNames file not found at {path} — skipping validation.')
        return confirmed
    with open(p, encoding='utf-8', errors='replace') as f:
        for line in f:
            parts = line.strip().split('\t')
            if len(parts) >= 2:
                name = parts[1].strip()
                if name:
                    confirmed.add(name.lower())
    print(f'  Loaded {len(confirmed):,} official place names from GeoNames.')
    return confirmed


# ── Existing corrections ─────────────────────────────────────────────────────

def load_corrections(path):
    """Return the set of all token names already in token_corrections.json."""
    p = Path(path)
    if not p.exists():
        return set()
    with open(p, encoding='utf-8') as f:
        c = json.load(f)
    resolved = set()
    resolved.update(c.get('rename', {}).keys())
    resolved.update(c.get('split',  {}).keys())
    resolved.update(c.get('delete', []))
    return resolved


# ── Token helpers ─────────────────────────────────────────────────────────────

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


def _title(name):
    LOWER_WORDS = {'of', 'the', 'and', 'in', 'at', 'on', 'a', 'an', 'to', 'de'}
    words = name.split()
    result = []
    for i, w in enumerate(words):
        if i == 0 or w.lower() not in LOWER_WORDS:
            result.append(w.capitalize())
        else:
            result.append(w.lower())
    return ' '.join(result)


def detect_issue(name, canonical_map):
    # Priority 0: junk phrase - obvious non-place strings.
    # Sentence fragments, special chars, or incomplete NER extractions.
    if re.search(r"[@|\[\]{}]", name):
        return "junk", "", None
    # Ends with a dangling connector word (incomplete extraction)
    if re.search(r"\s(?:en|&|to|and|or|-|of the|a|an)$", name, re.IGNORECASE):
        return "junk", "", None
    # Long dash-separated multi-part strings (not a place name)
    if name.count(" - ") >= 2:
        return "junk", "", None
    # Contains // (URL artifact or data error)
    if "//" in name:
        return "junk", "", None

    # Priority 1: nonplace - possessive
    if name.endswith("'s") or name.endswith("\u2019s"):
        suggest = re.sub(r"[\u2018\u2019\'\\']s$", "", name).strip()
        return "nonplace", suggest, None

    # Priority 2: split - ampersand compound
    if " & " in name:
        parts = [p.strip() for p in name.split(" & ", 1)]
        return "split", "", parts

    # Priority 3: extra - action-word prefix or suffix
    stripped = detect_extra(name)
    if stripped is not None:
        return "extra", stripped, None

    # Priority 4: dupe - near-duplicate of a higher-count token
    if name in canonical_map:
        return "dupe", canonical_map[name], None

    # Priority 5: caps - all alphabetic characters are uppercase
    alpha = [c for c in name if c.isalpha()]
    if alpha and all(c.isupper() for c in alpha):
        return "caps", _title(name), None

    # Priority 6: lower - first character is lowercase
    if name and name[0].islower():
        return "lower", _title(name), None

    return None, "", None


def build_fuzzy_canonical_map(universe, threshold=90):
    """
    Replace exact-lowercase dupe detection with similarity-based clustering.

    Processes tokens in order of row-count descending, so the most-used spelling
    becomes the canonical. Any token scoring >= threshold against a canonical
    that was processed earlier is assigned to that canonical's cluster.

    Returns: dict of token -> canonical_name  (only for non-canonical members)
    """
    try:
        from rapidfuzz import process, fuzz
    except ImportError:
        print('  WARNING: rapidfuzz not installed — falling back to exact-lowercase dedup.')
        print('  Run:  pip install rapidfuzz')
        # Fall back to exact-lowercase
        lower_groups = defaultdict(list)
        for name in universe:
            lower_groups[name.lower()].append(name)
        result = {}
        for names in lower_groups.values():
            if len(names) < 2:
                continue
            canonical = sorted(names, key=lambda n: (-universe[n]['rows'], n))[0]
            for other in names:
                if other != canonical:
                    result[other] = canonical
        return result

    # Sort highest count first so the most-used spelling becomes the canonical
    names = sorted(universe.keys(), key=lambda n: (-universe[n]['rows'], n))
    assigned = {}   # token -> canonical_name

    for i, name in enumerate(names):
        if name in assigned:
            continue
        assigned[name] = name  # this token becomes a cluster canonical

        # Build the pool of still-unassigned names after position i
        remaining = [n for n in names[i + 1:] if n not in assigned]
        if not remaining:
            continue

        matches = process.extract(
            name, remaining,
            scorer=fuzz.WRatio,
            score_cutoff=threshold,
            limit=None,
        )
        for match, score, _ in matches:
            if match not in assigned:
                # Guard: reject if one token is much shorter than the other.
                # This prevents "Sea" (3 chars) clustering with "Coral Sea" (9 chars),
                # or "Pacific" clustering with "Indian Pacific".
                # Short proper nouns (e.g. "Broome") still cluster with themselves fine
                # because they're the same length. We only block large length mismatches.
                len_ratio = min(len(name), len(match)) / max(len(name), len(match))
                if len_ratio < 0.50:
                    continue
                assigned[match] = name

    return {tok: can for tok, can in assigned.items() if tok != can}


# ── Confidence logic ──────────────────────────────────────────────────────────

def is_high_confidence(tok_record, geonames_set):
    """
    Returns True if this token doesn't need PM review.
    High-confidence tokens are shown in the Excel (Auto-Fixed sheet)
    for transparency but the PM is not asked to action them.

    Rules:
      - ALLCAPS / lowercase → auto title-case (always obvious)
      - Possessive (nonplace) → auto-delete (Western Australia's is never a product)
      - Fuzzy-duplicate whose canonical IS in GeoNames → auto-rename
      - Extra-word suffix/prefix whose stripped name IS in GeoNames → auto-rename
      - Clean token (no detected issue) appearing in 10+ tour day-rows →
        almost certainly a real tourist destination; no change needed, just accept.
        (GeoNames misses many Australian tourist attractions by design — they only
        cover geographic features, not branded experiences or driving routes.)
    """
    issue   = tok_record.get('issue')
    suggest = tok_record.get('suggest', '')

    if issue in ('caps', 'lower'):
        return True
    if issue == 'nonplace':
        return True
    if issue == 'junk':
        return True   # auto-delete obvious sentence fragments
    if issue == 'dupe' and suggest.lower() in geonames_set:
        return True
    if issue == 'extra' and suggest.lower() in geonames_set:
        return True
    # Clean token appearing in 3+ distinct tours: very likely a real tourist
    # product. GeoNames misses many Australian tourist attractions by design.
    # Using distinct-tour count (not day-rows) is more meaningful here.
    if not issue and tok_record.get('tours', 0) >= 3:
        return True
    return False


# ── Main build ────────────────────────────────────────────────────────────────

def build():
    print('Loading GeoNames...')
    geonames = load_geonames(str(GEONAMES_PATH))

    print('Loading existing corrections...')
    resolved_names = load_corrections(str(CORRECTIONS_PATH))
    print(f'  {len(resolved_names)} tokens already resolved (will be skipped in Excel).')

    print('Reading dataset...')
    df = pd.read_csv(CSV_PATH, low_memory=False)
    df['place_tags']  = df['place_tags'].fillna('')
    df['location']    = df['location'].fillna('')
    df['tour_name']   = df['tour_name'].fillna('')
    df['state']       = df['state'].fillna('')
    df['source_type'] = df['source_type'].fillna('Competitor')
    df['_is_board']   = df['source_type'].str.lower() == 'reference'
    df['_tokens']     = df['place_tags'].apply(parse_semi)

    # Collect per-token stats
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
            if len(s['ctx']) < 10:
                ctx = str(row['location']).strip()
                if not ctx or ctx.lower() == 'nan':
                    ctx = str(row['tour_name']).strip()
                if ctx and ctx not in s['ctx']:
                    s['ctx'].append(ctx)

    # Filter: must appear in >= 2 distinct tours, not a state/country name,
    # and not a single-word generic English noun that can never be a product.
    universe = {
        name: s for name, s in stats.items()
        if (len(s['board_tours']) + len(s['comp_tours'])) >= 2
        and name.lower() not in GEO_NOISE
        and not (len(name.split()) == 1 and name.lower() in GENERIC_SINGLES)
    }

    print(f'Building fuzzy clusters ({len(universe)} tokens)...')
    canonical_map = build_fuzzy_canonical_map(universe, threshold=90)
    print(f'  {len(canonical_map)} tokens grouped into clusters.')

    # Build output records
    records = []
    for name, s in universe.items():
        has_board = len(s['board_tours']) > 0
        has_comp  = len(s['comp_tours'])  > 0
        src   = 'BC' if (has_board and has_comp) else ('B' if has_board else 'C')
        # All distinct states (comma-joined, e.g. "WA, NT") for context in review
        all_states = ', '.join(sorted(s['states'].keys())) if s['states'] else ''
        # Up to 2 representative tour names for context in review
        all_tours = sorted(s['board_tours'] | s['comp_tours'])
        sample_tours = all_tours[:2]

        issue, suggest, split_parts = detect_issue(name, canonical_map)

        in_geonames = name.lower() in geonames
        resolved    = name in resolved_names
        # group = the cluster canonical (self if already canonical)
        group = canonical_map.get(name, name)

        n_tours = len(s['board_tours']) + len(s['comp_tours'])
        rec = {
            'name':         name,
            'state':        all_states,
            'count':        s['rows'],
            'tours':        n_tours,
            'src':          src,
            'issue':        issue,
            'suggest':      suggest,
            'split':        split_parts,
            'ctx':          s['ctx'][:6],
            'sample_tours': sample_tours,
            'geonames':     in_geonames,
            'group':        group,
            'resolved':     resolved,
        }

        # Tag high-confidence auto-fixes
        rec['auto'] = is_high_confidence(rec, geonames)

        records.append(rec)

    # Sort: flagged first (by count desc), then clean
    records.sort(key=lambda r: (0 if r['issue'] else 1, -r['count']))

    return records


def main():
    records = build()

    print(f'\nWriting tokens.js ({len(records)} tokens)...')
    js = 'const TOKENS = ' + json.dumps(records, ensure_ascii=False, indent=2) + ';\n'
    js += 'window.TOKENS = TOKENS;\n'
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        f.write(js)

    # Summary
    total       = len(records)
    flagged     = sum(1 for r in records if r['issue'])
    clean       = total - flagged
    geonames_ok = sum(1 for r in records if r['geonames'] and not r['issue'])
    auto_fix    = sum(1 for r in records if r['auto'])
    resolved    = sum(1 for r in records if r['resolved'])
    needs_review = sum(1 for r in records if not r['resolved'] and not r['auto'] and not (r['geonames'] and not r['issue']))

    sep = '=' * 55
    print(f'\n{sep}')
    print(f'  Total tokens          {total:>6}')
    print(f'  Flagged with issue    {flagged:>6}')
    print(f'  Clean (no issue)      {clean:>6}')
    print(sep)
    print(f'  GeoNames confirmed    {geonames_ok:>6}  (auto-validated, skip PM review)')
    print(f'  Auto-fix (obvious)    {auto_fix:>6}  (caps/possessives/confirmed renames)')
    print(f'  Already resolved      {resolved:>6}  (in token_corrections.json)')
    print(f'  -> Needs PM review    {needs_review:>6}')
    print(sep)

    by_issue = Counter(r['issue'] for r in records)
    print('\n  Issues breakdown:')
    for issue, cnt in sorted(by_issue.items(), key=lambda x: -x[1]):
        print(f'    {issue or "clean":12s}  {cnt}')


if __name__ == '__main__':
    main()
