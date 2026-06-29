"""
prepare_all.py
Runs both prepare_data and prepare_tokens, writing catalog.js (ES module)
to 'app/frontend/'.
Run: python prepare_all.py  (from the pipeline/ directory)
"""
import json
from pathlib import Path

import prepare_data
import prepare_tokens

NEW_DIR     = Path(__file__).resolve().parent.parent / 'frontend'
CATALOG_OUT = NEW_DIR / 'catalog.js'


def main():
    print('[prepare_all] Reading CSV...')

    prepare_data.CORRECTIONS_PATH = NEW_DIR / 'token_corrections.json'
    data = prepare_data.build()

    records = prepare_tokens.build()

    # Merge issue flag into each product from the token analysis
    tok_by_name = {r['name']: r for r in records}
    for state_data in data.values():
        for prod_name, prod_info in state_data['products'].items():
            tok = tok_by_name.get(prod_name)
            prod_info['issue'] = tok['issue'] if tok else None

    print(f'[prepare_all] Writing catalog.js → {CATALOG_OUT}')
    CATALOG_OUT.write_text(
        'export const THEMES = ' + json.dumps(prepare_data.THEMES, ensure_ascii=False) + ';\n\n'
        'export const CATALOG = ' + json.dumps(data, ensure_ascii=False, indent=2) + ';\n\n'
        'export const TOKENS = ' + json.dumps(records, ensure_ascii=False, indent=2) + ';\n',
        encoding='utf-8',
    )

    print('[prepare_all] Done.')
    parts = '  |  '.join(f'{sk} {len(sd["products"])} products' for sk, sd in data.items())
    print(f'  catalog.js : {parts}')
    total   = len(records)
    flagged = sum(1 for r in records if r['issue'])
    print(f'  TOKENS     : {total:,} tokens ({flagged} flagged, {total - flagged} clean)')


if __name__ == '__main__':
    main()
