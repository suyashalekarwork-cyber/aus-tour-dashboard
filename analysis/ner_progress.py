"""
NER progress checker — run anytime, even while build_keyword_dataset.py is running.

Usage:
    python analysis/ner_progress.py
    python analysis/ner_progress.py --backend cerebras   # default
    python analysis/ner_progress.py --backend ollama
    python analysis/ner_progress.py --backend gemini
"""
import os, sys, json, hashlib, argparse
import pandas as pd

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSV  = os.path.join(ROOT, "data", "keywords", "keyword_dataset_2026-06.csv")

CACHE_FILES = {
    "cerebras": "cerebras_ner_cache.json",
    "ollama":   "ollama_ner_cache.json",
    "gemini":   "gemini_ner_cache.json",
}

def cache_key(backend, loc, act, ollama_model="qwen2.5:7b"):
    if backend == "cerebras":
        return hashlib.md5(f"cerebras_v1|{loc}|||{act[:1500]}".encode()).hexdigest()
    elif backend == "ollama":
        # key includes model name — matches _run_ollama_ner exactly
        return hashlib.md5(f"ollama|{ollama_model}|{loc}|||{act[:1500]}".encode()).hexdigest()
    elif backend == "gemini":
        return hashlib.md5(f"v2|||{loc}|||{act[:2000]}".encode()).hexdigest()
    return ""

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--backend", default="cerebras",
                    choices=list(CACHE_FILES), help="Which NER backend to check")
    args = ap.parse_args()

    backend = args.backend
    cache_file = os.path.join(ROOT, "data", "keywords", CACHE_FILES[backend])

    # Load CSV
    if not os.path.exists(CSV):
        sys.exit(f"CSV not found: {CSV}")
    df = pd.read_csv(CSV, low_memory=False)
    df["location"] = df["location"].fillna("")
    df["activity"]  = df["activity"].fillna("")
    total = len(df)

    # Load cache
    cache = {}
    if os.path.exists(cache_file):
        try:
            cache = json.load(open(cache_file, encoding="utf-8"))
        except Exception as e:
            print(f"  Warning: could not read cache ({e})")
    else:
        print(f"  Cache file not found: {cache_file}")
        print(f"  Run has not started yet (0 / {total:,} rows done).")
        return

    # For ollama, detect model from cache keys (first entry tells us nothing —
    # so we try the env var or default, which is what the build script uses)
    ollama_model = os.environ.get("OLLAMA_MODEL", "qwen2.5:7b")

    # Check each row
    done_idx = []
    todo_idx = []
    for i, row in df.iterrows():
        k = cache_key(backend, str(row["location"]), str(row["activity"]), ollama_model)
        if k in cache:
            done_idx.append(i)
        else:
            todo_idx.append(i)

    done  = len(done_idx)
    todo  = len(todo_idx)
    pct   = done / total * 100 if total else 0

    # Avg seconds/row from a typical run (Cerebras ~2.5s, Ollama ~8s, Gemini ~4s)
    avg_s = {"cerebras": 2.5, "ollama": 8.0, "gemini": 4.5}.get(backend, 3.0)
    eta_h = todo * avg_s / 3600

    print(f"\n{'='*60}")
    print(f"  NER progress — backend: {backend}")
    print(f"{'='*60}")
    print(f"  Total rows  : {total:,}")
    print(f"  Done        : {done:,}  ({pct:.1f}%)")
    print(f"  Remaining   : {todo:,}  ({100-pct:.1f}%)")
    if todo > 0:
        print(f"  ETA         : ~{eta_h:.1f}h at {avg_s}s/row")
    else:
        print(f"  Status      : COMPLETE — all rows cached")
    print(f"  Cache file  : {cache_file}")
    print(f"{'='*60}")

    # Breakdown by source
    if done > 0:
        df_done = df.loc[done_idx]
        df_todo = df.loc[todo_idx]
        print(f"\n  By source:")
        sources = df["source"].fillna("(unknown)").unique()
        for src in sorted(sources):
            n_done = (df_done["source"].fillna("(unknown)") == src).sum()
            n_total = (df["source"].fillna("(unknown)") == src).sum()
            bar_len = 20
            filled = int(bar_len * n_done / n_total) if n_total else 0
            bar = "#" * filled + "-" * (bar_len - filled)
            print(f"    {src:<25} [{bar}] {n_done:>5}/{n_total:<5} ({n_done/n_total*100:.0f}%)")

    print()

if __name__ == "__main__":
    main()
