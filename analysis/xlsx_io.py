"""Helper for writing Excel workbooks that might be open in Excel.

On Windows, an .xlsx that is currently open in Excel is locked: writing to it raises
PermissionError, which would otherwise crash the pipeline and leave a stale workbook.
`write_xlsx_safe` falls back to a fresh, numbered copy so an up-to-date workbook is
ALWAYS produced, and prints a clear warning telling the user to close Excel.
"""

from pathlib import Path


def write_xlsx_safe(build_fn, path):
    """Write an .xlsx by calling build_fn(path_str).

    If `path` is locked because it's open in Excel, write to a numbered sibling instead
    (e.g. 'report (new 1).xlsx') so the latest data is never lost, and warn loudly.
    Returns the path actually written (as a string).
    """
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    try:
        build_fn(str(target))
        return str(target)
    except PermissionError:
        for i in range(1, 1000):
            alt = target.with_name(f"{target.stem} (new {i}){target.suffix}")
            try:
                build_fn(str(alt))
                print(f"  [warn] '{target.name}' is open in Excel, so it could not be updated.")
                print(f"         Wrote a fresh copy -> '{alt.name}' (has the latest data).")
                print(f"         Close Excel and re-run to update '{target.name}' directly.")
                return str(alt)
            except PermissionError:
                continue
        raise
