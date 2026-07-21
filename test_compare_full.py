"""
test_compare_full.py — side-by-side comparison of the ChatGPT (n8n) proxy vs local
Ollama across 5 real itinerary scenarios, using the exact prompts from app/server/server.py.

Standalone, read-only script — does not import or modify server.py/builder.html.
Run directly:

    python test_compare_full.py

Prints each test's comparison table as it completes and writes the full output to
test_compare_results.txt (same content as stdout).
"""
import json
import sys
import time
import urllib.error
import urllib.request

# Force UTF-8 console output so the box-drawing characters below don't crash
# on Windows' default cp1252 console encoding.
try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

# ── same prompts as app/server/server.py ────────────────────────────────────

PREDICT_SYSTEM_PROMPT = (
    "You are a tourism market analyst for an Australian inbound tour operator called TDU (Turtle Down Under). \n"
    "You analyse itineraries and predict which international markets they would appeal to.\n"
    "You always respond with valid JSON only — no explanation, no markdown, no extra text."
)


def build_predict_prompt(itinerary, market_data):
    state    = itinerary.get('state', '')
    days     = itinerary.get('days', '')
    products = ', '.join(itinerary.get('products') or [])

    market_lines = []
    for market, stats in (market_data or {}).items():
        state_visits = stats.get('stateVisits') or {}
        visits_str = ', '.join(f'{s}: {c}' for s, c in state_visits.items())
        market_lines.append(
            f"- {market}: {stats.get('totalTours', 0)} total tours, "
            f"avg duration {stats.get('avgDays', 0)} days, "
            f"state visits — {visits_str or 'none recorded'}"
        )
    market_summary = '\n'.join(market_lines)

    return f"""Here is real market data showing what each market's tour operators currently sell:
{market_summary}

A product manager has built this itinerary:
State: {state}
Duration: {days} days
Products/Places: {products}

Predict which markets this itinerary would appeal to and why.
Consider: historical market behaviour, untapped opportunities, product mix, duration fit.

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


# Same shared market data used across the earlier comparison tests, so results
# are comparable to previous runs — not itinerary-specific, this is real
# background data on what each market's operators currently sell overall.
SHARED_MARKET_DATA = {
    "India":       {"totalTours": 59,  "stateVisits": {"TAS": 2,  "NSW": 31}, "avgDays": 8.4},
    "Singapore":   {"totalTours": 18,  "stateVisits": {"TAS": 1,  "NSW": 10}, "avgDays": 6.1},
    "UK":          {"totalTours": 21,  "stateVisits": {"TAS": 3,  "NSW": 8},  "avgDays": 12.2},
    "New Zealand": {"totalTours": 9,   "stateVisits": {"TAS": 4},             "avgDays": 7.0},
    "Australia":   {"totalTours": 771, "stateVisits": {"TAS": 40, "NSW": 300}, "avgDays": 9.35},
}

test_cases = [
    {"state": "TAS", "days": 8,
     "products": ["Hobart", "Freycinet", "Cradle Mountain", "Wineglass Bay", "Port Arthur"],
     "label": "Test 1 — TAS nature circuit"},
    {"state": "NSW", "days": 10,
     "products": ["Sydney", "Blue Mountains", "Hunter Valley", "Bondi", "Opera House"],
     "label": "Test 2 — NSW classic"},
    {"state": "WA", "days": 9,
     "products": ["Broome", "Ningaloo Reef", "Kimberley", "Cable Beach", "Horizontal Falls"],
     "label": "Test 3 — WA remote"},
    {"state": "NT", "days": 7,
     "products": ["Uluru", "Kakadu", "Darwin", "Kings Canyon", "Alice Springs"],
     "label": "Test 4 — NT outback"},
    {"state": "VIC", "days": 8,
     "products": ["Melbourne", "Great Ocean Road", "Phillip Island", "Yarra Valley", "Grampians"],
     "label": "Test 5 — VIC highlights"},
]

PROXY_URL = "https://gtxn8n.yourbestwayhome.com.au/webhook/openai-response-proxy"
PROXY_HEADERS = {
    'N8N-KEY': 'Gtx1234*',
    'Content-Type': 'application/json',
    # Cloudflare in front of this proxy blocks the default urllib User-Agent (403 / code 1010).
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
}
OLLAMA_URL = 'http://localhost:11434/api/generate'
OLLAMA_MODEL = 'qwen2.5:7b'


def call_proxy(system_prompt, user_prompt, timeout=90):
    req = urllib.request.Request(
        PROXY_URL,
        data=json.dumps({'system': system_prompt, 'user': user_prompt}).encode('utf-8'),
        headers=PROXY_HEADERS,
        method='POST',
    )
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = json.loads(resp.read().decode('utf-8'))
        elapsed = time.time() - t0
        text = raw['output'][0]['content'][0]['text']
        prediction = json.loads(text)
        return {'elapsed': elapsed, 'json_ok': True, 'prediction': prediction, 'error': None}
    except Exception as e:
        elapsed = time.time() - t0
        return {'elapsed': elapsed, 'json_ok': False, 'prediction': None, 'error': f'{type(e).__name__}: {e}'}


def call_ollama(system_prompt, user_prompt, timeout=150):
    req = urllib.request.Request(
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
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = json.loads(resp.read().decode('utf-8'))
        elapsed = time.time() - t0
        prediction = json.loads(raw.get('response', '{}'))
        return {'elapsed': elapsed, 'json_ok': True, 'prediction': prediction, 'error': None}
    except Exception as e:
        elapsed = time.time() - t0
        return {'elapsed': elapsed, 'json_ok': False, 'prediction': None, 'error': f'{type(e).__name__}: {e}'}


def scores_sum_100(prediction):
    try:
        return sum(p.get('score', 0) for p in prediction.get('predictions', [])) == 100
    except Exception:
        return False


def top_market(prediction):
    try:
        preds = prediction.get('predictions', [])
        if not preds:
            return 'n/a'
        return max(preds, key=lambda p: p.get('score', 0)).get('market', 'n/a')
    except Exception:
        return 'n/a'


def fmt_predictions(prediction):
    lines = []
    try:
        for p in prediction.get('predictions', []):
            market = p.get('market', '?')
            score = p.get('score', '?')
            label = p.get('label', '?')
            reason = p.get('reason', '?')
            lines.append(f'  {market:<12} {str(score)+"%":<5} — {label:<14} — "{reason}"')
    except Exception:
        lines.append('  (could not format predictions)')
    return '\n'.join(lines)


def run_one_test(case, out):
    def w(line=''):
        print(line)
        out.write(line + '\n')

    w('═' * 60)
    w(case['label'])
    w('═' * 60)
    w()

    user_prompt = build_predict_prompt(case, SHARED_MARKET_DATA)

    w('Running proxy...')
    proxy_result = call_proxy(PREDICT_SYSTEM_PROMPT, user_prompt)
    w('Running Ollama...')
    ollama_result = call_ollama(PREDICT_SYSTEM_PROMPT, user_prompt)
    w()

    p_time = f"{proxy_result['elapsed']:.1f}s"
    o_time = f"{ollama_result['elapsed']:.1f}s"
    p_json = 'Yes' if proxy_result['json_ok'] else 'No'
    o_json = 'Yes' if ollama_result['json_ok'] else 'No'
    p_sum  = 'Yes' if proxy_result['json_ok'] and scores_sum_100(proxy_result['prediction']) else ('No' if proxy_result['json_ok'] else 'n/a')
    o_sum  = 'Yes' if ollama_result['json_ok'] and scores_sum_100(ollama_result['prediction']) else ('No' if ollama_result['json_ok'] else 'n/a')
    p_top  = top_market(proxy_result['prediction']) if proxy_result['json_ok'] else 'n/a'
    o_top  = top_market(ollama_result['prediction']) if ollama_result['json_ok'] else 'n/a'

    w(f"{'':<19}{'PROXY':<15}{'OLLAMA':<15}")
    w(f"{'Response time:':<19}{p_time:<15}{o_time:<15}")
    w(f"{'JSON valid:':<19}{p_json:<15}{o_json:<15}")
    w(f"{'Scores sum 100:':<19}{p_sum:<15}{o_sum:<15}")
    w(f"{'Top market:':<19}{p_top:<15}{o_top:<15}")
    w()

    if proxy_result['json_ok']:
        w('PROXY predictions:')
        w(fmt_predictions(proxy_result['prediction']))
        w(f"Summary: \"{proxy_result['prediction'].get('summary', '')}\"")
    else:
        w(f"PROXY failed: {proxy_result['error']}")
    w()

    if ollama_result['json_ok']:
        w('OLLAMA predictions:')
        w(fmt_predictions(ollama_result['prediction']))
        w(f"Summary: \"{ollama_result['prediction'].get('summary', '')}\"")
    else:
        w(f"OLLAMA failed: {ollama_result['error']}")
    w('═' * 60)
    w()


if __name__ == '__main__':
    with open('test_compare_results.txt', 'w', encoding='utf-8') as out:
        header = f'Comparing ChatGPT proxy vs Ollama ({OLLAMA_MODEL}) across {len(test_cases)} test cases\n'
        print(header)
        out.write(header + '\n')
        for case in test_cases:
            run_one_test(case, out)
            out.flush()
        done = 'All tests complete. Results saved to test_compare_results.txt'
        print(done)
        out.write(done + '\n')
