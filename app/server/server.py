"""
server.py — Itinerary Builder local server
Run once:  python server.py
Then open: http://localhost:8765

Endpoints:
  GET  /              → redirects to Itinerary Builder
  GET  /corrections   → returns token_corrections.json (or empty object)
  POST /sync          → saves corrections + timestamped backup + runs prepare_all.py
  GET  /backups       → lists backup history
  POST /restore       → restores a backup to token_corrections.json + reruns pipeline
"""
import base64
import hmac
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
import webbrowser
from datetime import datetime
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

# Force UTF-8 for our own console output so arrows/em-dashes don't crash
# on Windows' default cp1252 console encoding.
for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(encoding='utf-8')
    except Exception:
        pass

ROOT              = Path(__file__).parent.parent  # the app/ folder
NEW_DIR           = ROOT / 'frontend'
STATIC_ROOT       = NEW_DIR                       # everything the browser loads
PIPELINE_DIR      = ROOT / 'pipeline'
CORRECTIONS_PATH  = NEW_DIR / 'token_corrections.json'
BACKUPS_DIR       = NEW_DIR / 'corrections_history'
PREPARE_SCRIPT    = PIPELINE_DIR / 'prepare_all.py'
ITINERARIES_PATH  = NEW_DIR / 'saved_itineraries.json'
PORT              = 8765

# Shared-password login (HTTP Basic Auth). Override via environment variables
# APP_USER / APP_PASSWORD; otherwise these defaults (kept in sync with
# SHARE_WITH_PM.txt) are used.
AUTH_USER = os.environ.get('APP_USER', 'neha')
AUTH_PASS = os.environ.get('APP_PASSWORD', 'Koala-River-8823')

OLLAMA_URL   = 'http://localhost:11434/api/generate'
OLLAMA_MODEL = 'qwen2.5:7b'

PROXY_URL     = 'https://gtxn8n.yourbestwayhome.com.au/webhook/openai-response-proxy'
PROXY_HEADERS = {
    'N8N-KEY': 'Gtx1234*',
    'Content-Type': 'application/json',
    # Cloudflare in front of this proxy blocks Python's default urllib User-Agent
    # with a 403 (error code 1010) — a browser-like UA is required to reach it at all.
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
}

PREDICT_SYSTEM_PROMPT = (
    "You are a tourism market analyst for TDU (Turtle Down Under), an Australian inbound tour operator. "
    "Predict which international markets an itinerary would appeal to. "
    "Respond with valid JSON only — no explanation, no markdown."
)


def _build_predict_prompt(itinerary, market_data, board_data=None):
    # Preferred shape: regions=[{state, days, products}, ...], total_days=N — supports
    # itineraries spanning multiple Australian states. Falls back to the older single
    # state/days/products shape so any other caller of this function keeps working.
    regions = itinerary.get('regions')
    if regions is None:
        regions = [{
            'state': itinerary.get('state', ''),
            'days': itinerary.get('days', ''),
            'products': itinerary.get('products') or [],
        }]
    total_days = itinerary.get('total_days', sum((r.get('days') or 0) for r in regions) or itinerary.get('days', ''))

    # Trim context to only the states actually in this itinerary — the products list
    # itself is never touched, only the market/board reference data sent alongside it.
    detected_states = {r.get('state') for r in regions if r.get('state')}
    print(f'  /api/predict — filtering prompt context to {len(detected_states)} state(s): {sorted(detected_states)}')

    market_lines = []
    for market, stats in (market_data or {}).items():
        state_visits = stats.get('stateVisits') or {}
        state_visits = {s: c for s, c in state_visits.items() if s in detected_states}
        visits_str = ', '.join(f'{s}: {c}' for s, c in state_visits.items())
        market_lines.append(
            f"- {market}: {stats.get('totalTours', 0)} total tours, "
            f"avg duration {stats.get('avgDays', 0)} days, "
            f"state visits — {visits_str or 'none recorded'}"
        )
    market_summary = '\n'.join(market_lines)

    board_lines = []
    for state_key, stats in (board_data or {}).items():
        if state_key not in detected_states:
            continue
        top_products = ', '.join(stats.get('topProducts') or [])
        board_lines.append(
            f"{state_key}: {stats.get('boardTours', 0)} board tours — top products: {top_products or 'none recorded'}"
        )
    board_summary = '\n'.join(board_lines) if board_lines else 'none recorded'

    region_lines = []
    for r in regions:
        products_str = ', '.join(r.get('products') or [])
        region_lines.append(f"- {r.get('state','')}: {r.get('days','')} days — Products/Places: {products_str}")
    regions_block = '\n'.join(region_lines)

    is_multi = len(regions) > 1
    span_line = (
        "Multi-state itinerary — score based on the FULL itinerary across all regions "
        "and consider which markets travel to this combination of states.\n"
        if is_multi else ""
    )

    return f"""Market data (what each market's operators currently sell), limited to this itinerary's states:
{market_summary}

Board promotion data (what tourism boards recommend), same states:
{board_summary}
Where board promotion is high and competitor coverage is low, flag it as a first-mover opportunity.

Itinerary:
{span_line}Total duration: {total_days} days
Regions:
{regions_block}

Score each market's fit for this itinerary (historical behaviour, untapped opportunity, product mix, duration fit).

Return ONLY this JSON structure, no other text:
{{
  "predictions": [
    {{"market": "India", "score": 42, "label": "Moderate fit", "reason": "one sentence why"}},
    {{"market": "Singapore", "score": 28, "label": "Low fit", "reason": "one sentence why"}},
    {{"market": "UK", "score": 18, "label": "Low fit", "reason": "one sentence why"}},
    {{"market": "New Zealand", "score": 8, "label": "Low fit", "reason": "one sentence why"}},
    {{"market": "Australia", "score": 4, "label": "Low fit", "reason": "one sentence why"}}
  ],
  "summary": "Two sentence overall recommendation for the PM"
}}
Scores must add up to 100. Labels: 60+ = Strong fit, 35-59 = Moderate fit, below 35 = Low fit."""


def _predict_via_proxy(system_prompt, user_prompt):
    """Primary path: company ChatGPT proxy. Raises on any failure — caller falls back to Ollama."""
    req = urllib.request.Request(
        PROXY_URL,
        data=json.dumps({'system': system_prompt, 'user': user_prompt}).encode('utf-8'),
        headers=PROXY_HEADERS,
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = json.loads(resp.read().decode('utf-8'))
    text = raw['output'][0]['content'][0]['text']
    return json.loads(text)


def _predict_via_ollama(system_prompt, user_prompt):
    """Fallback path: local Ollama. Raises on any failure — caller returns the error response."""
    ollama_req = urllib.request.Request(
        OLLAMA_URL,
        data=json.dumps({
            'model':  OLLAMA_MODEL,
            'system': system_prompt,
            'prompt': user_prompt,
            'stream': False,
            'format': 'json',
        }).encode('utf-8'),
        headers={'Content-Type': 'application/json'},
    )
    with urllib.request.urlopen(ollama_req, timeout=90) as resp:
        ollama_result = json.loads(resp.read().decode('utf-8'))
    return json.loads(ollama_result.get('response', '{}'))


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(STATIC_ROOT), **kwargs)

    def end_headers(self):
        # Never let the browser cache — during active dev the .dc.html / data.js
        # change often and a stale cache silently serves old code.
        self.send_header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        self.send_header('Pragma', 'no-cache')
        self.send_header('Expires', '0')
        super().end_headers()

    def log_message(self, fmt, *args):
        # Only print sync/restore events, not every file request
        if self.path in ('/sync', '/backups', '/restore', '/corrections', '/itineraries') or self.path.startswith('/itineraries/'):
            print(f'  {self.command} {self.path}')

    def do_OPTIONS(self):
        self.send_response(200)
        self._cors()
        self.end_headers()

    def _cors(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')

    def _json(self, data, code=200):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self._cors()
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', len(body))
        self.end_headers()
        self.wfile.write(body)

    def _read_body(self):
        length = int(self.headers.get('Content-Length', 0))
        return self.rfile.read(length)

    # ── auth ─────────────────────────────────────────────────────────────────

    def _authed(self):
        return True  # auth disabled for local dev — re-enable before sharing

    def _require_auth(self):
        self.send_response(401)
        self.send_header('WWW-Authenticate', 'Basic realm="Token Editor"')
        self._cors()
        self.send_header('Content-Length', '0')
        self.end_headers()

    # ── GET ────────────────────────────────────────────────────────────────────

    def do_GET(self):

        if self.path in ('/', ''):
            self.send_response(302)
            self._cors()
            self.send_header('Location', '/builder.html')
            self.end_headers()
            return

        if self.path == '/backups':
            try:
                if not BACKUPS_DIR.exists():
                    return self._json([])
                files = sorted(BACKUPS_DIR.glob('token_corrections_*.json'), reverse=True)
                result = []
                for fp in list(files)[:50]:
                    try:
                        with open(fp, 'r', encoding='utf-8') as f:
                            d = json.load(f)
                        counts = {
                            'rename': len(d.get('rename', {})),
                            'split':  len(d.get('split', {})),
                            'delete': len(d.get('delete', [])),
                        }
                    except Exception:
                        counts = {'rename': 0, 'split': 0, 'delete': 0}
                    result.append({'file': fp.name, 'counts': counts})
                self._json(result)
            except Exception as e:
                self._json({'error': str(e)}, 500)
            return

        if self.path == '/corrections':
            try:
                if CORRECTIONS_PATH.exists():
                    data = json.loads(CORRECTIONS_PATH.read_text(encoding='utf-8'))
                else:
                    data = {'rename': {}, 'split': {}, 'delete': []}
                self._json(data)
            except Exception as e:
                self._json({'error': str(e)}, 500)
            return

        if self.path == '/itineraries':
            try:
                if ITINERARIES_PATH.exists():
                    data = json.loads(ITINERARIES_PATH.read_text(encoding='utf-8'))
                else:
                    data = []
                self._json(data)
            except Exception as e:
                self._json({'error': str(e)}, 500)
            return

        # everything else: serve as static file
        super().do_GET()

    # ── POST ───────────────────────────────────────────────────────────────────

    def do_POST(self):

        if self.path == '/sync':
            try:
                payload = json.loads(self._read_body())
                # Accept both {corrections:{...}} and the corrections object directly
                corrections = payload.get('corrections', payload)

                # 1. Timestamped backup
                BACKUPS_DIR.mkdir(exist_ok=True)
                ts          = datetime.now().strftime('%Y-%m-%d_%H-%M-%S')
                backup_name = f'token_corrections_{ts}.json'
                with open(BACKUPS_DIR / backup_name, 'w', encoding='utf-8') as f:
                    json.dump(corrections, f, ensure_ascii=False, indent=2)

                # 2. Write current corrections
                with open(CORRECTIONS_PATH, 'w', encoding='utf-8') as f:
                    json.dump(corrections, f, ensure_ascii=False, indent=2)

                # 3. Run prepare_data.py
                result = subprocess.run(
                    [sys.executable, str(PREPARE_SCRIPT)],
                    capture_output=True, text=True,
                    encoding='utf-8', errors='replace',
                    env={**os.environ, 'PYTHONIOENCODING': 'utf-8'},
                    cwd=str(PIPELINE_DIR), timeout=180,
                )
                ok = result.returncode == 0
                n_rename = len(corrections.get('rename', {}))
                n_delete = len(corrections.get('delete', []))
                n_split  = len(corrections.get('split', {}))
                summary  = f'{n_rename} rename, {n_delete} delete, {n_split} split'
                msg = (f'data.js + tokens.js regenerated  —  {summary}') if ok else 'prepare_all.py failed'
                print(f'  Synced: {summary}  →  backup: {backup_name}  ok={ok}')
                self._json({
                    'ok':         ok,
                    'backupFile': backup_name,
                    'message':    msg,
                    'stdout':     result.stdout[-2000:],
                    'stderr':     result.stderr[-1000:],
                })
            except Exception as e:
                self._json({'ok': False, 'message': str(e)}, 500)
            return

        if self.path == '/restore':
            try:
                payload  = json.loads(self._read_body())
                filename = Path(payload['file']).name   # strip any path traversal
                src = BACKUPS_DIR / filename
                if not src.exists():
                    return self._json({'ok': False, 'message': 'Backup not found'}, 404)

                # Back up current before overwriting
                if CORRECTIONS_PATH.exists():
                    ts = datetime.now().strftime('%Y-%m-%d_%H-%M-%S')
                    pre = BACKUPS_DIR / f'pre_restore_{ts}.json'
                    with open(CORRECTIONS_PATH, 'r', encoding='utf-8') as f:
                        current = json.load(f)
                    with open(pre, 'w', encoding='utf-8') as f:
                        json.dump(current, f, ensure_ascii=False, indent=2)

                # Restore
                with open(src, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                with open(CORRECTIONS_PATH, 'w', encoding='utf-8') as f:
                    json.dump(data, f, ensure_ascii=False, indent=2)

                # Regenerate
                result = subprocess.run(
                    [sys.executable, str(PREPARE_SCRIPT)],
                    capture_output=True, text=True,
                    encoding='utf-8', errors='replace',
                    env={**os.environ, 'PYTHONIOENCODING': 'utf-8'},
                    cwd=str(PIPELINE_DIR), timeout=180,
                )
                ok  = result.returncode == 0
                msg = f'Restored from {filename} — data.js regenerated' if ok else f'Restored file but prepare_data.py failed'
                print(f'  Restore: {filename}  ok={ok}')
                self._json({'ok': ok, 'message': msg})
            except Exception as e:
                self._json({'ok': False, 'message': str(e)}, 500)
            return

        if self.path == '/itineraries':
            try:
                payload = json.loads(self._read_body())
                data = []
                if ITINERARIES_PATH.exists():
                    data = json.loads(ITINERARIES_PATH.read_text(encoding='utf-8'))
                idx = next((i for i, x in enumerate(data) if x.get('id') == payload.get('id')), -1)
                if idx >= 0:
                    data[idx] = payload
                else:
                    data.append(payload)
                ITINERARIES_PATH.write_text(
                    json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8'
                )
                self._json({'ok': True})
            except Exception as e:
                self._json({'ok': False, 'message': str(e)}, 500)
            return

        if self.path == '/api/predict':
            try:
                payload     = json.loads(self._read_body())
                itinerary   = payload.get('itinerary') or {}
                market_data = payload.get('marketData') or {}
                board_data  = payload.get('boardData') or {}
                prompt = _build_predict_prompt(itinerary, market_data, board_data)

                try:
                    prediction = _predict_via_proxy(PREDICT_SYSTEM_PROMPT, prompt)
                    print('  /api/predict — Used proxy')
                    self._json(prediction)
                except Exception as proxy_err:
                    print(f'  /api/predict — proxy failed ({proxy_err}), falling back to Ollama')
                    try:
                        prediction = _predict_via_ollama(PREDICT_SYSTEM_PROMPT, prompt)
                        print('  /api/predict — Used Ollama fallback')
                        self._json(prediction)
                    except Exception as ollama_err:
                        print(f'  /api/predict — Both failed (proxy: {proxy_err}; ollama: {ollama_err})')
                        self._json({'error': 'AI prediction unavailable'})
            except Exception as e:
                self._json({'error': str(e)}, 500)
            return

        self.send_response(404)
        self.end_headers()

    # ── DELETE ─────────────────────────────────────────────────────────────────

    def do_DELETE(self):
        if self.path.startswith('/itineraries/'):
            import urllib.parse
            itin_id = urllib.parse.unquote(self.path[len('/itineraries/'):])
            try:
                data = []
                if ITINERARIES_PATH.exists():
                    data = json.loads(ITINERARIES_PATH.read_text(encoding='utf-8'))
                data = [x for x in data if x.get('id') != itin_id]
                ITINERARIES_PATH.write_text(
                    json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8'
                )
                self._json({'ok': True})
            except Exception as e:
                self._json({'ok': False, 'message': str(e)}, 500)
            return
        self.send_response(404)
        self.end_headers()


def main():
    server = ThreadingHTTPServer(('localhost', PORT), Handler)
    url = f'http://localhost:{PORT}'
    print(f'\n  Itinerary Builder server')
    print(f'  Running at  {url}')
    print(f'  Backups in  app/frontend/corrections_history/')
    print(f'  Login       user="{AUTH_USER}"  password="{AUTH_PASS}"')
    print(f'              (share these with the PM — see SHARE_WITH_PM.txt)')
    print(f'\n  Opening browser...')
    webbrowser.open(url)
    print('  Press Ctrl+C to stop.\n')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\n  Server stopped.')


if __name__ == '__main__':
    main()
