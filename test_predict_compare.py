"""
test_predict_compare.py — one-off comparison of Ollama vs the ChatGPT (n8n) proxy
for the /api/predict prompt. Standalone script, not part of the app — run directly:

    python test_predict_compare.py

Uses the exact system/user prompt already defined in app/server/server.py so the
two backends are judged on identical input.
"""
import json
import time
import urllib.error
import urllib.request

TEST_ITINERARY = {
    "state": "Tasmania",
    "days": 8,
    "products": ["Hobart", "Freycinet", "Cradle Mountain", "Wineglass Bay", "Port Arthur"],
}
TEST_MARKET_DATA = {
    "India":       {"totalTours": 59,  "stateVisits": {"TAS": 2,  "NSW": 31}, "avgDays": 8.4},
    "Singapore":   {"totalTours": 18,  "stateVisits": {"TAS": 1,  "NSW": 10}, "avgDays": 6.1},
    "UK":          {"totalTours": 21,  "stateVisits": {"TAS": 3,  "NSW": 8},  "avgDays": 12.2},
    "New Zealand": {"totalTours": 9,   "stateVisits": {"TAS": 4}},
    "Australia":   {"totalTours": 771, "stateVisits": {"TAS": 40, "NSW": 300}, "avgDays": 9.35},
}
TEST_MARKET_DATA["New Zealand"]["avgDays"] = 7.0

# same prompts as app/server/server.py
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


def test_ollama():
    url = 'http://localhost:11434/api/generate'
    req = urllib.request.Request(
        url,
        data=json.dumps({
            'model':  'qwen2.5:7b',
            'system': PREDICT_SYSTEM_PROMPT,
            'prompt': build_predict_prompt(TEST_ITINERARY, TEST_MARKET_DATA),
            'stream': False,
            'format': 'json',
        }).encode('utf-8'),
        headers={'Content-Type': 'application/json'},
    )
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=120) as resp:
        raw = json.loads(resp.read().decode('utf-8'))
    elapsed = time.time() - t0
    prediction = json.loads(raw.get('response', '{}'))
    return elapsed, prediction


def test_chatgpt_proxy():
    url = "https://gtxn8n.yourbestwayhome.com.au/webhook/openai-response-proxy"
    headers = {
        'N8N-KEY': 'Gtx1234*',
        'Content-Type': 'application/json',
        # Cloudflare in front of this proxy blocks the default urllib User-Agent
        # (error code 1010) — a browser-like UA is required to reach the workflow at all.
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    }
    body = {
        'system': PREDICT_SYSTEM_PROMPT,
        'user': build_predict_prompt(TEST_ITINERARY, TEST_MARKET_DATA),
    }
    req = urllib.request.Request(
        url,
        data=json.dumps(body).encode('utf-8'),
        headers=headers,
        method='POST',
    )
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = resp.read().decode('utf-8')
    elapsed = time.time() - t0
    prediction = json.loads(raw)
    return elapsed, prediction


def score_sum_ok(prediction):
    try:
        return sum(p.get('score', 0) for p in prediction.get('predictions', [])) == 100
    except Exception:
        return False


def schema_ok(prediction):
    preds = prediction.get('predictions')
    if not isinstance(preds, list) or len(preds) != 5:
        return False
    for p in preds:
        if not all(k in p for k in ('market', 'score', 'label', 'reason')):
            return False
    return isinstance(prediction.get('summary'), str)


if __name__ == '__main__':
    print('=== Test 1: Ollama qwen2.5:7b ===')
    try:
        t, result = test_ollama()
        print(f'Elapsed: {t:.1f}s')
        print(json.dumps(result, indent=2))
        print('Scores sum to 100:', score_sum_ok(result))
        print('Schema correct:', schema_ok(result))
    except Exception as e:
        print('Ollama test failed:', e)

    print('\n=== Test 2: ChatGPT proxy ===')
    try:
        t, result = test_chatgpt_proxy()
        print(f'Elapsed: {t:.1f}s')
        print(json.dumps(result, indent=2))
        print('Scores sum to 100:', score_sum_ok(result))
        print('Schema correct:', schema_ok(result))
    except urllib.error.HTTPError as e:
        print('ChatGPT proxy HTTP error:', e.code, e.read().decode('utf-8', errors='replace'))
    except Exception as e:
        print('ChatGPT proxy test failed:', e)
