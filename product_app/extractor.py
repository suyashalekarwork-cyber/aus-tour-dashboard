"""
Rules-based product + theme extractor.

This is the "tokenize and keep only what matters to the business" engine,
implemented with transparent, editable rules (no AI black box):

  * A phrase is kept as a PRODUCT if it ends in a place-type word
    (Gorge, Falls, Road, Bridge, Springs, National Park, ...) or is in the
    known-places dictionary. This captures attraction-level names like
    "Bell Gorge" or "Sea Cliff Bridge" automatically.
  * A day is given THEME tags by matching activity keywords to one of the
    8 business themes (hike -> Hiking & Walks, wine -> Food & Wine, ...).
  * Filler (motion verbs, articles, generic words) is dropped.

Every list below is meant to be edited by the business. Grow PLACE_TYPES /
KNOWN_PLACES to catch more products; tune THEME_KEYWORDS to match how trips
are marketed.
"""

import re

# --------------------------------------------------------------------------
# 1. PRODUCT RULES
# --------------------------------------------------------------------------

# Place-type words. A run of Capitalised words ending in one of these is a
# named place. (?:...) groups, plural-tolerant. Order doesn't matter.
PLACE_TYPES = [
    "Gorges?", "Falls?", "Beaches?", "Bays?", "Lakes?", "Rivers?", "Roads?",
    "Bridges?", "Springs?", "Islands?", "Capes?", "Points?", "Reefs?",
    "Ranges?", "Valleys?", "Gardens?", "Caves?", "Lookouts?", "Creeks?",
    "Chasms?", "Plateaus?", "Stations?", "National Park", "Conservation Park",
    "Marine Park", "Parks?", "Peninsulas?", "Coves?", "Pools?", "Rocks?",
    "Wharf", "Lighthouse", "Markets?", "Gulf", "Harbours?", "Hills?",
    "Mountains?", "Forests?", "Vineyards?", "Wineries", "Winery", "Deserts?",
    "Plains?", "Tracks?", "Trails?", "Lagoons?", "Gardens?", "Sanctuary",
]

# Leading-type words: "Lake X", "Mount X" — the type comes first.
LEADING_TYPES = ["Lake", "Mount", "Mt", "Cape", "Port", "Gulf"]

# Known places that have NO place-type suffix, so the suffix rule misses them.
# Seed list — the real system would grow this from the pipeline's gazetteer.
KNOWN_PLACES = {
    "Uluru", "Kata Tjuta", "Kakadu", "Litchfield", "Darwin", "Alice Springs",
    "Katherine", "Broome", "Kununurra", "Derby", "Wyndham", "Halls Creek",
    "Perth", "Fremantle", "Margaret River", "Sydney", "Melbourne", "Adelaide",
    "Brisbane", "Cairns", "Hobart", "Canberra", "Nullarbor", "Kimberley",
    "Outback", "Daintree", "Kuranda", "Port Douglas", "Noosa", "Byron Bay",
    "Blue Mountains", "El Questro", "Bungle Bungle", "Purnululu", "Broken Hill",
    "Coober Pedy", "Rottnest Island", "Tasmania", "Whitsundays", "Fraser Island",
    "Great Barrier Reef", "Twelve Apostles", "Great Ocean Road", "Grampians",
    "Barossa Valley", "Yarra Valley", "Hunter Valley", "Flinders Ranges",
    "Wineglass Bay", "Cradle Mountain", "Port Arthur", "Mimbi Caves",
    "Bungle Bungle Range", "Sea Cliff Bridge", "Bald Hill", "Grand Pacific Drive",
}

# Sentence-initial verbs / words to strip when they lead a matched phrase,
# e.g. "Visit Windjana Gorge" -> "Windjana Gorge".
STOP_LEADS = {
    "Visit", "Explore", "Discover", "Enjoy", "See", "Take", "Continue",
    "Travel", "Arrive", "Leave", "Depart", "Cross", "Wade", "Begin", "Set",
    "Witness", "Conclude", "Following", "Afterwards", "Later", "Next", "Today",
    "Back", "Board", "Disembark", "Go", "Head", "Journey", "Settle", "Admire",
    "View", "Experience", "Savour", "Look", "Keep", "Marvel", "Uncover",
    "Order", "Wander", "Get", "Unwind", "Taste", "Enrol", "Along", "Towards",
    "Towards", "Towards", "Towards", "Drive", "Walk", "Hike", "Stroll", "Spend",
    "Morning", "Afternoon", "Evening", "Stop", "Visiting", "Then", "This",
}

# Generic phrases that pass the suffix rule but aren't real products.
BLACKLIST = {
    "National Park", "Conservation Park", "Marine Park", "Wilderness Park",
    "Art Centre", "Welcome Dinner", "Farewell Dinner", "Signature Experience",
    "Five Rivers", "The Park", "A Park", "Spring Creek", "Day", "Resort",
}

_TRAIL_RE = re.compile(
    r"\b((?:[A-Z][a-zA-Z'’]+\s+){1,4}(?:" + "|".join(PLACE_TYPES) + r"))\b"
)
_LEAD_RE = re.compile(
    r"\b((?:" + "|".join(LEADING_TYPES) + r")\s+[A-Z][a-zA-Z'’]+"
    r"(?:\s+[A-Z][a-zA-Z'’]+){0,2})\b"
)
_DICT_RE = re.compile(
    r"\b(" + "|".join(re.escape(p) for p in sorted(KNOWN_PLACES, key=len, reverse=True)) + r")\b"
)

_MOJIBAKE = {
    "�": "'", "â€™": "'", "â€“": "-",
    "â€”": "-", "â€œ": '"', "â€": '"',
}


def clean_text(s):
    if not isinstance(s, str):
        return ""
    if s.strip().lower() in ("no description", "nan", ""):
        return ""
    for bad, good in _MOJIBAKE.items():
        s = s.replace(bad, good)
    return s


def _dedupe_substrings(items):
    """Drop a product if it's a whole-phrase substring of a longer kept one
    (keep 'Purnululu National Park', drop bare 'Purnululu')."""
    out = []
    for a in items:
        al = a.lower()
        if any(a is not b and al != b.lower()
               and re.search(r"\b" + re.escape(al) + r"\b", b.lower())
               for b in items):
            continue
        out.append(a)
    return out


def _strip_stop_lead(phrase):
    words = phrase.split()
    while len(words) > 1 and words[0] in STOP_LEADS:
        words = words[1:]
    return " ".join(words)


def extract_products(*texts):
    """Return an ordered, de-duplicated list of product (place) names."""
    # " . " hard-separates fields so a place name at the end of `location`
    # can't merge with one at the start of `activity`.
    blob = " . ".join(clean_text(t) for t in texts)
    found = []
    seen = set()

    def _add(p):
        p = re.sub(r"\s+", " ", p).strip(" .,'’")
        if not p or p in BLACKLIST:
            return
        if len(p.split()) < 2 and p not in KNOWN_PLACES:
            return  # bare single words must be known places
        key = p.lower()
        if key not in seen:
            seen.add(key)
            found.append(p)

    for m in _DICT_RE.findall(blob):
        _add(m)
    for m in _LEAD_RE.findall(blob):
        _add(m)
    for m in _TRAIL_RE.findall(blob):
        _add(_strip_stop_lead(m))
    return _dedupe_substrings(found)


# --------------------------------------------------------------------------
# 2. THEME RULES  (the 8 business themes)
# --------------------------------------------------------------------------

THEME_KEYWORDS = {
    "Nature & Scenery": [
        "gorge", "falls", "waterfall", "national park", "rainforest", "sunset",
        "lookout", "vista", "panoram", "wilderness", "cave", "chasm", "range",
        "desert", "scenery", "landscape", "domes", "gardens", "oasis", "lagoon",
    ],
    "Hiking & Walks": [
        "hike", "hiking", "walk", "trek", "stroll", "wander", "trail",
        "bushwalk", "ramble", "on foot",
    ],
    "Beach & Coast": [
        "beach", "coast", "snorkel", "surf", "seaside", "ocean", "island",
        "reef", "coastal", "sea cliff", "by the ocean", "white sand",
    ],
    "Wildlife": [
        "wildlife", "quokka", "kangaroo", "koala", "crocodile", "croc",
        "dolphin", "whale", "bird", "penguin", "animal", "wildlife cruise",
        "fauna", "marine life",
    ],
    "Food & Wine": [
        "wine", "winery", "vineyard", "dine", "dining", "cuisine", "taste",
        "tasting", "brewery", "beer", "gin", "restaurant", "lunch", "dinner",
        "feast", "food", "produce", "cellar", "fine-dining", "fish and chips",
    ],
    "Culture & History": [
        "aboriginal", "indigenous", "dreaming", "rock art", "museum", "gallery",
        "heritage", "history", "convict", "cultural", "art centre", "historic",
        "ochre", "ancient", "fossil", "traditional", "mining heritage",
    ],
    "Scenic Drives": [
        "scenic drive", "road trip", "self-drive", "four-wheel", "4wd",
        "drive along", "drive to", "gibb river road", "great ocean road",
        "ocean road", "grand pacific drive", "touring route", "coastal drive",
    ],
    "City & Shopping": [
        "city", "shopping", "shop", "mall", "nightlife", "skyline", "harbour bridge",
        "opera house", "market", "urban", "downtown", "boutique",
    ],
}


def extract_themes(*texts):
    """Return the list of business themes present in the text."""
    blob = " . ".join(clean_text(t) for t in texts).lower()
    themes = []
    for theme, kws in THEME_KEYWORDS.items():
        if any(kw in blob for kw in kws):
            themes.append(theme)
    return themes


# --------------------------------------------------------------------------
# 3. DATAFRAME HELPER
# --------------------------------------------------------------------------

def tag_dataframe(df):
    """Add product_tags and theme_tags columns to a day-level dataframe."""
    prods, themes = [], []
    for _, r in df.iterrows():
        loc, act = r.get("location", ""), r.get("activity", "")
        prods.append("; ".join(extract_products(loc, act)))
        themes.append("; ".join(extract_themes(loc, act)))
    out = df.copy()
    out["product_tags"] = prods
    out["theme_tags"] = themes
    return out


if __name__ == "__main__":
    demo = (
        "Bell Gorge",
        "Back on the Gibb River Road, travel to Bell Gorge, where spectacular "
        "cliffs surround a series of tiered waterholes. Should you be feeling "
        "energetic, you may hike to the lower pool and enjoy a relaxing dip "
        "below the cascading waterfall.",
    )
    print("PRODUCTS:", extract_products(*demo))
    print("THEMES:  ", extract_themes(*demo))
