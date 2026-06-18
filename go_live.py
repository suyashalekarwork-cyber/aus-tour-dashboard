"""
go_live.py — one command to put the Token Editor online.

Run:  python go_live.py

It does everything:
  1. Starts the local server (server.py) with the shared login.
  2. Starts the Cloudflare tunnel (cloudflared.exe) → public https link.
  3. Auto-writes SHARE_WITH_PM.txt with the NEW link + username + password,
     so you can forward that file to the PM as-is.

Press Ctrl+C to stop both. The link changes each run — that's fine, the
share file is rewritten automatically every time.

Login is set via environment variables — required before running:
  PowerShell:  $env:APP_USER="name"; $env:APP_PASSWORD="secret"; python go_live.py
"""
import os
import re
import subprocess
import sys
import threading
import time
from pathlib import Path

BASE     = Path(__file__).parent
CF       = BASE / 'cloudflared.exe'
SHARE    = BASE / 'SHARE_WITH_PM.txt'
PORT     = 8765

# Shared login — set APP_USER and APP_PASSWORD as environment variables before running.
# Example (PowerShell):  $env:APP_USER="name"; $env:APP_PASSWORD="secret"; python go_live.py
USER     = os.environ['APP_USER']
PASSWORD = os.environ['APP_PASSWORD']

URL_RE = re.compile(r'https://[a-z0-9-]+\.trycloudflare\.com')


def write_share(url):
    """Write the share file with ONLY the link, username and password."""
    SHARE.write_text(
        f"Link:     {url}\n"
        f"Username: {USER}\n"
        f"Password: {PASSWORD}\n",
        encoding='utf-8',
    )


def main():
    if not CF.exists():
        print(f'ERROR: cloudflared.exe not found at {CF}')
        sys.exit(1)

    env = {**os.environ, 'APP_USER': USER, 'APP_PASSWORD': PASSWORD}

    print('Starting local server...')
    server = subprocess.Popen([sys.executable, 'server.py'], cwd=str(BASE), env=env)
    time.sleep(1.5)

    print('Starting Cloudflare tunnel (this can take a few seconds)...')
    tunnel = subprocess.Popen(
        [str(CF), 'tunnel', '--url', f'http://localhost:{PORT}'],
        cwd=str(BASE),
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        text=True, encoding='utf-8', errors='replace',
    )

    found = {'url': None}

    def reader():
        for line in tunnel.stdout:
            if not found['url']:
                m = URL_RE.search(line)
                if m:
                    found['url'] = m.group(0)
                    write_share(found['url'])
                    print('\n' + '=' * 64)
                    print('  LIVE — SHARE_WITH_PM.txt has been updated. Send that file.')
                    print('-' * 64)
                    print(f'  Link:      {found["url"]}')
                    print(f'  Username:  {USER}')
                    print(f'  Password:  {PASSWORD}')
                    print('=' * 64)
                    print('\n  Keep this window open. Press Ctrl+C to stop.\n')

    threading.Thread(target=reader, daemon=True).start()

    try:
        server.wait()
    except KeyboardInterrupt:
        print('\nStopping...')
    finally:
        for p in (tunnel, server):
            try:
                p.terminate()
            except Exception:
                pass
        print('Stopped server and tunnel.')


if __name__ == '__main__':
    main()
