"""
watch_excel.py — auto-import when the PM saves Token_Review.xlsx.

Run:  python watch_excel.py

Watches  data/Token_Review.xlsx  for changes. The moment the file is saved
(by Neha on OneDrive, or by you locally), it automatically runs
import_excel.py → prepare_data.py → data.js is rebuilt.

Reload the Itinerary Builder in the browser to see the updates.

Press Ctrl+C to stop watching.
"""
import subprocess
import sys
import time
from pathlib import Path

# Force UTF-8 console output on Windows
for _s in (sys.stdout, sys.stderr):
    try:
        _s.reconfigure(encoding='utf-8')
    except Exception:
        pass

BASE        = Path(__file__).parent
XLSX        = BASE / 'data' / 'Token_Review.xlsx'
IMPORT_SCRIPT = BASE / 'import_excel.py'
POLL_SECS   = 5   # check every 5 seconds


def get_mtime():
    try:
        return XLSX.stat().st_mtime
    except FileNotFoundError:
        return None


def run_import():
    print('\n' + '─' * 60)
    print('  Change detected — running import_excel.py ...')
    print('─' * 60)
    result = subprocess.run(
        [sys.executable, str(IMPORT_SCRIPT)],
        cwd=str(BASE),
        encoding='utf-8', errors='replace',
        env=__import__('os').environ | {'PYTHONIOENCODING': 'utf-8'},
    )
    if result.returncode == 0:
        print('─' * 60)
        print('  Done. Reload the Itinerary Builder to see updated data.')
        print('─' * 60 + '\n')
    else:
        print('─' * 60)
        print('  WARNING: import_excel.py reported an error (see above).')
        print('─' * 60 + '\n')


def main():
    if not IMPORT_SCRIPT.exists():
        print(f'ERROR: import_excel.py not found at {IMPORT_SCRIPT}')
        sys.exit(1)

    print()
    print('  Excel watcher started')
    print(f'  Watching:  data/Token_Review.xlsx  (every {POLL_SECS}s)')
    print('  When Neha saves the file, import runs automatically.')
    print('  Press Ctrl+C to stop.\n')

    last_mtime = get_mtime()
    if last_mtime is None:
        print(f'  NOTE: {XLSX.name} not found yet — will import when it appears.\n')

    try:
        while True:
            time.sleep(POLL_SECS)
            current = get_mtime()

            if current is None:
                continue  # file not there yet, keep waiting

            if last_mtime is None:
                # file just appeared for the first time
                print(f'  Token_Review.xlsx found — importing now.')
                last_mtime = current
                run_import()
            elif current != last_mtime:
                last_mtime = current
                run_import()

    except KeyboardInterrupt:
        print('\n  Watcher stopped.')


if __name__ == '__main__':
    main()
