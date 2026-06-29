"""
build_config_files.py — Build keyword lookup config files from NotebookLM output.

Reads:
  Gemini_Noteboolm/notebooklm_report.txt      — 925 PLACE, 90 BLACKLIST, 18 synonyms
  Gemini_Noteboolm/notebooklm_chat.txt        — 1,146 PLACE, 505 BLACKLIST
  Gemini_Noteboolm/notebooklm_extraction_50rows.txt  — NLM 50-row extraction
  Gemini_Noteboolm/ai_playground.txt          — AIP 50-row extraction (JSON)
  Gemini_Noteboolm/ai_playground_3.5flash.txt — Flash 50-row extraction (plain text)

Writes:
  data/config/keyword_allowlist.txt   — one canonical place per line (~1,200+ items)
  data/config/keyword_blacklist.txt   — one noise phrase per line (~500+ items)
  data/config/keyword_synonyms.txt    — "Alias -> Canonical" format, one per line

Run:
    python analysis/build_config_files.py
"""

import os, re, json

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

TESTING_DIR   = os.path.join(BASE, "Gemini_Noteboolm", "testing_files")
REPORT_FILE   = os.path.join(TESTING_DIR, "notebooklm_report.txt")
CHAT_FILE     = os.path.join(TESTING_DIR, "notebooklm_chat.txt")
NLM_EXTR      = os.path.join(TESTING_DIR, "notebooklm_extraction_50rows.txt")
AIP_EXTR      = os.path.join(TESTING_DIR, "ai_playground.txt")
FLASH_EXTR    = os.path.join(TESTING_DIR, "ai_playground_3.5flash.txt")

OUT_DIR       = os.path.join(BASE, "data", "config")
ALLOWLIST_OUT = os.path.join(OUT_DIR, "keyword_allowlist.txt")
BLACKLIST_OUT = os.path.join(OUT_DIR, "keyword_blacklist.txt")
SYNONYMS_OUT  = os.path.join(OUT_DIR, "keyword_synonyms.txt")

# ---------------------------------------------------------------------------
# Manual conflict overrides (from our analysis)
# ---------------------------------------------------------------------------

# Items that appeared in some BLACKLIST but are real Australian places
FORCE_PLACE = {
    "queenstown",      # Tasmania has a Queenstown (not just NZ)
    "wintjiri wiru",   # Indigenous cultural experience at Uluru
    "yulara",          # Town next to Uluru
    "peninsula hot springs",  # Real tourist attraction in Mornington Peninsula
    "kunanyi",         # Indigenous name for Mt Wellington, Hobart
}

# Items that appeared in some PLACE list but are NOT places
FORCE_BLACKLIST = {
    "saffire freycinet",           # luxury lodge name
    "empire revival",              # furniture store (ROW 5)
    "1770 distillery",             # business
    "mcg",                         # acronym duplicate of Melbourne Cricket Ground
    "cbd",                         # too generic
    "harbour",                     # too generic
    "manly ferry",                 # ferry service, not a place
    "lobster shack",               # business (ROW 41)
    "undersong perfumery",         # business (ROW 41)
    "waubs harbour distillery",    # business (ROW 41)
    "devil's den",                 # unclear / not a named AU place
    "freycinet marine farm",       # farm business
    "kate's berry farm",           # farm business
    "clifford's honey farm",       # farm business
    "emu ridge eucalyptus distillery",  # business
    "coal river farm",             # farm business
    "rayner's orchard",            # farm business
    "cascade brewery",             # business
    "australia",                   # too generic (country name)
    "indian ocean",                # ocean body, not a specific AU place
    "bellarine region",            # too generic (vs "Bellarine Peninsula")
    "atila (mt. conner)",          # malformed merged entry
    "noongar boodja",              # Indigenous country name (too broad for lookup)
    "cook",                        # ambiguous: Cook SA + "James Cook Museum" partial + Captain Cook
    "sandstone",                   # geological term: matches "sandstone cliffs/ridges" not just the WA town
}

# Extra synonyms (not from NLM) — consolidate duplicate / generic forms found in review
EXTRA_SYNONYMS = [
    ("Opera House", "Sydney Opera House"),   # always Sydney in context
    ("Byron", "Byron Bay"),                  # duplicate of Byron Bay (142 + 138 hits)
]

# ---------------------------------------------------------------------------
# Parse NotebookLM report
# ---------------------------------------------------------------------------

def parse_report(path):
    text = open(path, encoding="utf-8").read()

    def extract_list(label):
        m = re.search(rf"{label}:\s*(.+?)(?=\n\n|\Z)", text, re.S | re.I)
        if not m:
            return []
        return [p.strip() for p in m.group(1).strip().split(",") if p.strip()]

    places    = extract_list("PLACE")
    blacklist = extract_list("BLACKLIST")
    uncertain = extract_list("UNCERTAIN")
    synonyms  = []
    for m in re.finditer(r"\*\s*(.+?)\s*->\s*(.+)", text):
        synonyms.append((m.group(1).strip(), m.group(2).strip()))

    return {"places": places, "blacklist": blacklist, "synonyms": synonyms, "uncertain": uncertain}


# ---------------------------------------------------------------------------
# Parse NotebookLM chat
# ---------------------------------------------------------------------------

def parse_chat(path):
    text = open(path, encoding="utf-8").read()
    all_places, all_blacklist = [], []

    def extract_after(label, block):
        m = re.search(rf"{label}:\s*(.+?)(?=\n(?:PLACE|BLACKLIST|UNCERTAIN):|$)", block, re.S | re.I)
        if not m:
            return []
        return [p.strip() for p in m.group(1).split(",") if p.strip()]

    for block in re.split(r"\n{2,}", text):
        if re.search(r"^PLACE:", block, re.M | re.I):
            all_places    += extract_after("PLACE",     block)
            all_blacklist += extract_after("BLACKLIST", block)

    return {"places": all_places, "blacklist": all_blacklist}


# ---------------------------------------------------------------------------
# Parse extraction files (50-row tests)
# ---------------------------------------------------------------------------

def parse_extraction_linewise(path):
    """NLM extraction: plain text, one ROW per line."""
    results = {}
    with open(path, encoding="utf-8") as f:
        for line in f:
            m = re.match(r"ROW\s*(\d+)\s*:\s*(.+)", line.strip(), re.I)
            if m:
                results[int(m.group(1))] = [p.strip() for p in m.group(2).split(",") if p.strip()]
    return results


def parse_extraction_json(path):
    """AIP extraction: JSON array of "ROW N: A, B" strings."""
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    results = {}
    for item in data:
        m = re.match(r"ROW\s*(\d+)\s*:\s*(.+)", item.strip(), re.I)
        if m:
            results[int(m.group(1))] = [p.strip() for p in m.group(2).split(",") if p.strip()]
    return results


def parse_extraction_wrapped(path):
    """Flash extraction: plain text with possible line wrapping."""
    with open(path, encoding="utf-8") as f:
        raw = " ".join(f.read().split())
    results = {}
    for m in re.finditer(r"ROW\s*(\d+)\s*:\s*(.*?)(?=ROW\s*\d+\s*:|$)", raw, re.I):
        results[int(m.group(1))] = [p.strip() for p in m.group(2).split(",") if p.strip()]
    return results


# ---------------------------------------------------------------------------
# Main build logic
# ---------------------------------------------------------------------------

def main():
    os.makedirs(OUT_DIR, exist_ok=True)

    print("Parsing NotebookLM report ...")
    report = parse_report(REPORT_FILE)
    print(f"  {len(report['places'])} PLACE | {len(report['blacklist'])} BLACKLIST | {len(report['synonyms'])} synonyms")

    print("Parsing NotebookLM chat ...")
    chat = parse_chat(CHAT_FILE)
    print(f"  {len(chat['places'])} PLACE | {len(chat['blacklist'])} BLACKLIST")

    # --- ALLOWLIST ---
    # Start with report PLACE (highest quality), then add chat items not already seen
    allowlist_ordered = []
    seen_lower = set()
    for p in report["places"]:
        k = p.strip().lower()
        if k and k not in seen_lower:
            seen_lower.add(k)
            allowlist_ordered.append(p.strip())

    chat_adds = 0
    for p in chat["places"]:
        k = p.strip().lower()
        if k and k not in seen_lower:
            seen_lower.add(k)
            allowlist_ordered.append(p.strip())
            chat_adds += 1
    print(f"\nBase allowlist: {len(report['places'])} from report + {chat_adds} new from chat = {len(allowlist_ordered)} total")

    # --- Add validated places from 3-way extraction (2+ models agreed) ---
    extr_additions = 0
    if os.path.exists(NLM_EXTR) and os.path.exists(AIP_EXTR) and os.path.exists(FLASH_EXTR):
        print("\nParsing 50-row extraction outputs ...")
        nlm_rows   = parse_extraction_linewise(NLM_EXTR)
        aip_rows   = parse_extraction_json(AIP_EXTR)
        flash_rows = parse_extraction_wrapped(FLASH_EXTR)

        # Count votes per place across all 50 rows
        place_votes = {}  # lower → {canonical, votes}
        for row_dict, label in [(nlm_rows, "nlm"), (aip_rows, "aip"), (flash_rows, "flash")]:
            for row_n, places in row_dict.items():
                seen_this_row = set()
                for p in places:
                    k = p.strip().lower()
                    if not k or k in seen_this_row:
                        continue
                    seen_this_row.add(k)
                    if k not in place_votes:
                        place_votes[k] = {"canonical": p.strip(), "votes": 0}
                    place_votes[k]["votes"] += 1

        # Add places with 2+ votes that are not already in allowlist
        for k, info in sorted(place_votes.items(), key=lambda x: -x[1]["votes"]):
            if info["votes"] >= 2 and k not in seen_lower:
                allowlist_ordered.append(info["canonical"])
                seen_lower.add(k)
                extr_additions += 1

        print(f"  Added {extr_additions} extraction-validated places (2+ model agreement) to allowlist")
    else:
        print("  (Skipping extraction files — one or more not found)")

    # Apply FORCE_PLACE (ensure these are in allowlist)
    for p in FORCE_PLACE:
        if p not in seen_lower:
            allowlist_ordered.append(p.title())
            seen_lower.add(p)

    # Apply FORCE_BLACKLIST (remove from allowlist if present)
    allowlist_final = [
        p for p in allowlist_ordered
        if p.strip().lower() not in FORCE_BLACKLIST
    ]
    # Also remove items that are in BOTH NLM report blacklist AND chat blacklist (consensus blacklist)
    report_bl = {b.strip().lower() for b in report["blacklist"]}
    chat_bl   = {b.strip().lower() for b in chat["blacklist"]}
    consensus_bl = report_bl & chat_bl  # only remove if BOTH sources agree it's noise
    allowlist_final = [
        p for p in allowlist_final
        if p.strip().lower() not in consensus_bl or p.strip().lower() in FORCE_PLACE
    ]

    print(f"\nAllowlist after FORCE_BLACKLIST + consensus blacklist filter: {len(allowlist_final)} items")

    # --- BLACKLIST ---
    # Union of report + chat blacklists, plus FORCE_BLACKLIST, minus FORCE_PLACE
    blacklist_seen = set()
    blacklist_ordered = []

    for b in report["blacklist"] + chat["blacklist"]:
        k = b.strip().lower()
        if k and k not in blacklist_seen and k not in FORCE_PLACE:
            blacklist_seen.add(k)
            blacklist_ordered.append(b.strip())

    for b in FORCE_BLACKLIST:
        if b not in blacklist_seen:
            blacklist_seen.add(b)
            blacklist_ordered.append(b.title() if b == b.lower() else b)

    # Remove items that are in FORCE_PLACE
    blacklist_final = [b for b in blacklist_ordered if b.strip().lower() not in FORCE_PLACE]

    # Embedded-place rescue: if a blacklist phrase contains a known allowlist place
    # as a substring, keep the allowlist place but still blacklist the full phrase
    # (the allowlist entry will match the shorter inner place instead)
    # This is already handled by having both lists separately — no extra step needed.

    print(f"Blacklist: {len(blacklist_final)} items")

    # --- SYNONYMS ---
    synonyms = list(report["synonyms"])  # list of (alias, canonical) tuples
    # Append extra hand-curated synonyms (skip if alias already present)
    existing_aliases = {a.strip().lower() for a, _ in synonyms}
    for alias, canonical in EXTRA_SYNONYMS:
        if alias.strip().lower() not in existing_aliases:
            synonyms.append((alias, canonical))
            existing_aliases.add(alias.strip().lower())
    print(f"Synonyms: {len(synonyms)} pairs (incl. {len(EXTRA_SYNONYMS)} extra)")

    # --- WRITE FILES ---
    allowlist_final_sorted = sorted(set(allowlist_final), key=str.lower)
    blacklist_final_sorted = sorted(set(blacklist_final), key=str.lower)

    with open(ALLOWLIST_OUT, "w", encoding="utf-8") as f:
        f.write("# Australian place name allowlist — generated by build_config_files.py\n")
        f.write("# One place per line. Used by build_keyword_dataset.py (NER_BACKEND=lookup)\n\n")
        for p in allowlist_final_sorted:
            f.write(p + "\n")
    print(f"\nWrote: {ALLOWLIST_OUT}  ({len(allowlist_final_sorted)} places)")

    with open(BLACKLIST_OUT, "w", encoding="utf-8") as f:
        f.write("# Keyword blacklist — generated by build_config_files.py\n")
        f.write("# One phrase per line. These are excluded from place_tags output.\n\n")
        for b in blacklist_final_sorted:
            f.write(b + "\n")
    print(f"Wrote: {BLACKLIST_OUT}  ({len(blacklist_final_sorted)} items)")

    with open(SYNONYMS_OUT, "w", encoding="utf-8") as f:
        f.write("# Place name synonyms — generated by build_config_files.py\n")
        f.write("# Format: Alias -> Canonical\n\n")
        for alias, canonical in sorted(synonyms, key=lambda x: x[0].lower()):
            f.write(f"{alias} -> {canonical}\n")
    print(f"Wrote: {SYNONYMS_OUT}  ({len(synonyms)} pairs)")

    # --- PREVIEW ---
    print("\n--- Sample allowlist (first 20) ---")
    for p in allowlist_final_sorted[:20]:
        print(f"  {p}")

    print("\n--- Sample blacklist (first 10) ---")
    for b in blacklist_final_sorted[:10]:
        print(f"  {b}")

    print("\n--- All synonyms ---")
    for alias, canonical in synonyms:
        print(f"  {alias} -> {canonical}")

    print("\nDone. Config files ready for use with NER_BACKEND=lookup")


if __name__ == "__main__":
    main()
