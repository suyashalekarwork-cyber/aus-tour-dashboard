"""
token_editor.py — Place tag quality editor.
Run: streamlit run token_editor.py
"""
import streamlit as st
import pandas as pd
import json
import subprocess
from pathlib import Path
from collections import defaultdict

ROOT             = Path(__file__).parent.parent   # the "Build and explore" folder
CSV_PATH         = r'C:\Users\SuyashA\Desktop\Itinenaries_analysis\data\keywords\keyword_dataset_2026-06.csv'
CORRECTIONS_PATH = ROOT / 'data' / 'token_corrections.json'
PREPARE_SCRIPT   = ROOT / 'pipeline' / 'prepare_data.py'


def parse_semi(val):
    if not val or isinstance(val, float):
        return []
    return [x.strip() for x in str(val).split(';') if x.strip()]


def flag_reason(token):
    if token.endswith("'s") or token.endswith('’s'):
        return 'possessive'
    if ' & ' in token:
        return 'ampersand'
    if len(token.strip()) <= 2:
        return 'too short'
    return ''


@st.cache_data(show_spinner='Loading dataset…')
def load_token_stats():
    df = pd.read_csv(CSV_PATH, low_memory=False)
    df['place_tags'] = df['place_tags'].fillna('')
    df['location']   = df['location'].fillna('')
    df['state']      = df['state'].fillna('')
    df['tour_name']  = df['tour_name'].fillna('')
    df['_is_board']  = df['source_type'].str.lower() == 'reference'
    df['_tokens']    = df['place_tags'].apply(parse_semi)

    stats = defaultdict(lambda: {
        'count': 0, 'tours': set(), 'states': set(),
        'board': 0, 'comp': 0, 'examples': [],
    })

    for _, row in df.iterrows():
        for tok in row['_tokens']:
            if not tok:
                continue
            s = stats[tok]
            s['count'] += 1
            s['tours'].add(row['tour_name'])
            s['states'].add(row['state'])
            if row['_is_board']:
                s['board'] += 1
            else:
                s['comp'] += 1
            if len(s['examples']) < 5:
                s['examples'].append({
                    'tour':     str(row['tour_name'])[:70],
                    'location': str(row['location'])[:90],
                    'state':    str(row['state']),
                    'source':   'Board' if row['_is_board'] else 'Competitor',
                })

    rows = []
    for token, s in stats.items():
        rows.append({
            'token':    token,
            'count':    s['count'],
            'tours':    len(s['tours']),
            'states':   ', '.join(sorted(s['states'])),
            'board':    s['board'],
            'comp':     s['comp'],
            'flag':     flag_reason(token),
            'examples': s['examples'],
        })

    rows.sort(key=lambda r: -r['count'])
    return rows


def load_corrections():
    if CORRECTIONS_PATH.exists():
        with open(CORRECTIONS_PATH, 'r', encoding='utf-8') as f:
            c = json.load(f)
        return {
            'rename': dict(c.get('rename', {})),
            'split':  dict(c.get('split', {})),
            'delete': list(c.get('delete', [])),
        }
    return {'rename': {}, 'split': {}, 'delete': []}


def save_corrections(corrections):
    with open(CORRECTIONS_PATH, 'w', encoding='utf-8') as f:
        json.dump({
            'rename': corrections['rename'],
            'split':  corrections['split'],
            'delete': corrections['delete'],
        }, f, ensure_ascii=False, indent=2)


def correction_status(token, corrections):
    if token in corrections['delete']:
        return 'deleted'
    if token in corrections['rename']:
        return f'-> {corrections["rename"][token]}'
    if token in corrections['split']:
        return '-> ' + ' + '.join(corrections['split'][token])
    return ''


# ── app ────────────────────────────────────────────────────────────────────────

st.set_page_config(page_title='Token Editor', layout='wide')
st.title('Place Tag Quality Editor')
st.caption('Review and correct place_tags tokens from keyword_dataset. Corrections are saved to token_corrections.json and applied every time prepare_data.py runs.')

if 'corrections' not in st.session_state:
    st.session_state.corrections = load_corrections()
if 'dirty' not in st.session_state:
    st.session_state.dirty = False

corrections = st.session_state.corrections
all_stats   = load_token_stats()
stats_index = {r['token']: r for r in all_stats}

corrected_set = (
    set(corrections['delete'])
    | set(corrections['rename'])
    | set(corrections['split'])
)

# ── sidebar ────────────────────────────────────────────────────────────────────

with st.sidebar:
    st.header('Filters')
    all_states    = sorted({s for r in all_stats for s in r['states'].split(', ') if s})
    state_filter  = st.selectbox('State', ['All'] + all_states)
    source_filter = st.radio('Source', ['All', 'Board only', 'Competitor only'], horizontal=True)
    status_filter = st.selectbox('Status', ['All', 'Flagged (uncorrected)', 'Corrected', 'Clean'])
    search        = st.text_input('Search token', placeholder='type to filter…')

    st.divider()
    st.subheader('Corrections saved')
    st.write(f'Deleted: **{len(corrections["delete"])}**')
    st.write(f'Renamed: **{len(corrections["rename"])}**')
    st.write(f'Split: **{len(corrections["split"])}**')

    if st.session_state.dirty:
        st.warning('Unsaved changes')

    st.divider()
    col_sv, col_rl = st.columns(2)
    with col_sv:
        if st.button('Save', type='primary', use_container_width=True):
            save_corrections(corrections)
            st.session_state.dirty = False
            st.success('Saved')
    with col_rl:
        if st.button('Reload', use_container_width=True):
            st.session_state.corrections = load_corrections()
            st.session_state.dirty = False
            st.rerun()

    if st.button('Save & Regenerate data.js', use_container_width=True):
        save_corrections(corrections)
        st.session_state.dirty = False
        with st.spinner('Running prepare_data.py…'):
            result = subprocess.run(
                ['python', str(PREPARE_SCRIPT)],
                capture_output=True, text=True, cwd=str(PREPARE_SCRIPT.parent),
            )
        if result.returncode == 0:
            st.success('data.js regenerated')
            st.code(result.stdout[-1500:])
        else:
            st.error('Script failed')
            st.code(result.stderr[-1500:])

    st.divider()
    if st.button('Clear cache & reload CSV', use_container_width=True):
        st.cache_data.clear()
        st.rerun()

# ── apply filters ──────────────────────────────────────────────────────────────

filtered = all_stats

if state_filter != 'All':
    filtered = [r for r in filtered if state_filter in r['states'].split(', ')]

if source_filter == 'Board only':
    filtered = [r for r in filtered if r['board'] > 0 and r['comp'] == 0]
elif source_filter == 'Competitor only':
    filtered = [r for r in filtered if r['comp'] > 0 and r['board'] == 0]

if search:
    q = search.lower()
    filtered = [r for r in filtered if q in r['token'].lower()]

if status_filter == 'Flagged (uncorrected)':
    filtered = [r for r in filtered if r['flag'] and r['token'] not in corrected_set]
elif status_filter == 'Corrected':
    filtered = [r for r in filtered if r['token'] in corrected_set]
elif status_filter == 'Clean':
    filtered = [r for r in filtered if not r['flag'] and r['token'] not in corrected_set]

# ── metrics ────────────────────────────────────────────────────────────────────

unfixed = sum(1 for r in all_stats if r['flag'] and r['token'] not in corrected_set)
m1, m2, m3, m4 = st.columns(4)
m1.metric('Total tokens',       len(all_stats))
m2.metric('Flagged (remaining)', unfixed)
m3.metric('Corrected',          len(corrected_set))
m4.metric('Showing',            len(filtered))

# ── bulk actions ───────────────────────────────────────────────────────────────

with st.expander('Bulk auto-fix', expanded=False):
    st.caption('Apply corrections to entire flag categories at once.')

    possessives = [r['token'] for r in all_stats if r['flag'] == 'possessive' and r['token'] not in corrected_set]
    ampersands  = [r['token'] for r in all_stats if r['flag'] == 'ampersand'  and r['token'] not in corrected_set]
    too_short   = [r['token'] for r in all_stats if r['flag'] == 'too short'  and r['token'] not in corrected_set]

    b1, b2, b3 = st.columns(3)
    with b1:
        st.write(f'**{len(possessives)}** possessives uncorrected')
        if possessives:
            st.caption(', '.join(possessives[:8]) + ('…' if len(possessives) > 8 else ''))
        if st.button('Delete all possessives', disabled=not possessives):
            for tok in possessives:
                if tok not in corrections['delete']:
                    corrections['delete'].append(tok)
            st.session_state.dirty = True
            st.rerun()

    with b2:
        st.write(f'**{len(ampersands)}** ampersand compounds uncorrected')
        if ampersands:
            st.caption(', '.join(ampersands[:4]) + ('…' if len(ampersands) > 4 else ''))
        if st.button('Split all on &', disabled=not ampersands):
            for tok in ampersands:
                parts = [p.strip() for p in tok.split(' & ')]
                corrections['split'][tok] = parts
            st.session_state.dirty = True
            st.rerun()

    with b3:
        st.write(f'**{len(too_short)}** tokens ≤ 2 chars uncorrected')
        if too_short:
            st.caption(repr(too_short[:8]))
        if st.button('Delete all too-short', disabled=not too_short):
            for tok in too_short:
                if tok not in corrections['delete']:
                    corrections['delete'].append(tok)
            st.session_state.dirty = True
            st.rerun()

st.divider()

# ── token table ────────────────────────────────────────────────────────────────

display = filtered[:500]
if len(filtered) > 500:
    st.caption(f'Showing 500 of {len(filtered)} — use filters to narrow down.')

if display:
    table = pd.DataFrame([{
        'Token':  r['token'],
        'Count':  r['count'],
        'Tours':  r['tours'],
        'States': r['states'],
        'Board':  r['board'],
        'Comp':   r['comp'],
        'Flag':   r['flag'],
        'Status': correction_status(r['token'], corrections),
    } for r in display])

    st.dataframe(
        table,
        use_container_width=True,
        height=280,
        hide_index=True,
        column_config={
            'Token':  st.column_config.TextColumn(width='medium'),
            'Count':  st.column_config.NumberColumn(width='small'),
            'Tours':  st.column_config.NumberColumn(width='small'),
            'States': st.column_config.TextColumn(width='medium'),
            'Board':  st.column_config.NumberColumn(width='small'),
            'Comp':   st.column_config.NumberColumn(width='small'),
            'Flag':   st.column_config.TextColumn(width='small'),
            'Status': st.column_config.TextColumn(width='medium'),
        },
    )
else:
    st.info('No tokens match current filters.')
    st.stop()

# ── edit panel ─────────────────────────────────────────────────────────────────

st.divider()
st.subheader('Edit Token')

token_list = [r['token'] for r in display]
selected   = st.selectbox('Select token to edit', token_list)
info       = stats_index.get(selected, {})
status     = correction_status(selected, corrections)

left, right = st.columns(2)

with left:
    flag = info.get('flag', '')
    if flag:
        st.warning(f'Auto-flagged: **{flag}**')
    if status:
        st.info(f'Current correction: **{status}**')
    st.markdown(
        f"**{info.get('count', 0)}** rows · **{info.get('tours', 0)}** tours · "
        f"Board: {info.get('board', 0)} · Comp: {info.get('comp', 0)}"
    )
    examples = info.get('examples', [])
    if examples:
        st.markdown('**Example rows from dataset:**')
        st.dataframe(pd.DataFrame(examples), hide_index=True, use_container_width=True)

with right:
    st.markdown('**Actions**')

    if st.button(f'Delete "{selected}"', use_container_width=True):
        corrections['rename'].pop(selected, None)
        corrections['split'].pop(selected, None)
        if selected not in corrections['delete']:
            corrections['delete'].append(selected)
        st.session_state.dirty = True
        st.rerun()

    if ' & ' in selected:
        parts = [p.strip() for p in selected.split(' & ')]
        label = ' + '.join(f'"{p}"' for p in parts)
        if st.button(f'Split → {label}', use_container_width=True):
            corrections['rename'].pop(selected, None)
            corrections['delete'] = [t for t in corrections['delete'] if t != selected]
            corrections['split'][selected] = parts
            st.session_state.dirty = True
            st.rerun()

    st.markdown('**Rename to:**')
    existing_rename = corrections['rename'].get(selected, '')
    rename_to = st.text_input(
        'New name', value=existing_rename,
        label_visibility='collapsed', key='rename_input',
    )
    if st.button('Apply rename', use_container_width=True,
                 disabled=not rename_to or rename_to == selected):
        corrections['delete'] = [t for t in corrections['delete'] if t != selected]
        corrections['split'].pop(selected, None)
        corrections['rename'][selected] = rename_to
        st.session_state.dirty = True
        st.rerun()

    if status:
        st.divider()
        if st.button('Clear correction (mark clean)', use_container_width=True):
            corrections['rename'].pop(selected, None)
            corrections['split'].pop(selected, None)
            corrections['delete'] = [t for t in corrections['delete'] if t != selected]
            st.session_state.dirty = True
            st.rerun()
