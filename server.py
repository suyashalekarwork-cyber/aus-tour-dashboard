"""
server.py — Token Editor local server
Run once:  python server.py
Then open: http://localhost:8765

Endpoints:
  GET  /              → redirects to Token Editor
  POST /sync          → saves corrections + timestamped backup + runs prepare_data.py
  GET  /backups       → lists backup history
  POST /restore       → restores a backup to token_corrections.json + reruns pipeline
"""
import base64
import hmac
import json
import os
import subprocess
import sys
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

BASE_DIR         = Path(__file__).parent
CORRECTIONS_PATH = BASE_DIR / 'token_corrections.json'
BACKUPS_DIR      = BASE_DIR / 'corrections_history'
PREPARE_SCRIPT   = BASE_DIR / 'prepare_data.py'
PORT             = 8765

# Shared-password login (HTTP Basic Auth).
# Set APP_USER and APP_PASSWORD as environment variables before running.
# Example (PowerShell):  $env:APP_USER="name"; $env:APP_PASSWORD="secret"; python server.py
AUTH_USER = os.environ['APP_USER']
AUTH_PASS = os.environ['APP_PASSWORD']


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(BASE_DIR), **kwargs)

    def log_message(self, fmt, *args):
        # Only print sync/restore events, not every file request
        if self.path in ('/sync', '/backups', '/restore'):
            print(f'  {self.command} {self.path}')

    def do_OPTIONS(self):
        self.send_response(200)
        self._cors()
        self.end_headers()

    def _cors(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
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
        hdr = self.headers.get('Authorization', '')
        if not hdr.startswith('Basic '):
            return False
        try:
            user, _, pw = base64.b64decode(hdr[6:]).decode('utf-8').partition(':')
        except Exception:
            return False
        # constant-time compare to avoid leaking length/timing
        return (hmac.compare_digest(user, AUTH_USER)
                and hmac.compare_digest(pw, AUTH_PASS))

    def _require_auth(self):
        self.send_response(401)
        self.send_header('WWW-Authenticate', 'Basic realm="Token Editor"')
        self._cors()
        self.send_header('Content-Length', '0')
        self.end_headers()

    # ── GET ────────────────────────────────────────────────────────────────────

    def do_GET(self):
        if not self._authed():
            return self._require_auth()

        if self.path in ('/', ''):
            self.send_response(302)
            self._cors()
            self.send_header('Location', '/Token%20Editor.dc.html')
            self.end_headers()
            return

        if self.path == '/version':
            try:
                mtime = int((BASE_DIR / 'data.js').stat().st_mtime * 1000)
            except FileNotFoundError:
                mtime = 0
            self._json({'v': mtime})
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

        # everything else: serve as static file
        super().do_GET()

    # ── POST ───────────────────────────────────────────────────────────────────

    def do_POST(self):
        if not self._authed():
            return self._require_auth()

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
                    cwd=str(BASE_DIR), timeout=180,
                )
                ok = result.returncode == 0
                n_rename = len(corrections.get('rename', {}))
                n_delete = len(corrections.get('delete', []))
                n_split  = len(corrections.get('split', {}))
                summary  = f'{n_rename} rename, {n_delete} delete, {n_split} split'
                msg = (f'data.js regenerated  —  {summary}') if ok else 'prepare_data.py failed'
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
                    cwd=str(BASE_DIR), timeout=180,
                )
                ok  = result.returncode == 0
                msg = f'Restored from {filename} — data.js regenerated' if ok else f'Restored file but prepare_data.py failed'
                print(f'  Restore: {filename}  ok={ok}')
                self._json({'ok': ok, 'message': msg})
            except Exception as e:
                self._json({'ok': False, 'message': str(e)}, 500)
            return

        self.send_response(404)
        self.end_headers()


def main():
    server = ThreadingHTTPServer(('localhost', PORT), Handler)
    url = f'http://localhost:{PORT}'
    print(f'\n  Token Editor server')
    print(f'  Running at  {url}')
    print(f'  Backups in  corrections_history/')
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
