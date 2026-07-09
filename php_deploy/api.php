<?php
/**
 * api.php — Itinerary Builder backend (PHP + MySQL port of app/server/server.py)
 *
 * Routed here by .htaccess:
 *   GET    itineraries        → list all saved itineraries (from MySQL)
 *   POST   itineraries        → save/update one itinerary (upsert by id)
 *   DELETE itineraries/{id}   → delete one itinerary
 *   POST   api/predict        → build prompt + forward to the AI proxy
 *
 * Setup (table creation + import of the old saved_itineraries.json) is done
 * by install.php — run it once after uploading, then delete it.
 *
 * data.js / catalog.js / market.js / tokens.js stay as static files for now
 * (phase 1); moving them into MySQL is a later phase.
 */

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ── routing ───────────────────────────────────────────────────────────────────

// Path of the request relative to the folder api.php lives in, so the app
// works both in public_html and in any subfolder.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($base !== '' && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
}
$path   = '/' . ltrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($path === '/itineraries' && $method === 'GET') {
        list_itineraries($config);
    } elseif ($path === '/itineraries' && $method === 'POST') {
        save_itinerary($config);
    } elseif (preg_match('#^/itineraries/(.+)$#', $path, $m) && $method === 'DELETE') {
        delete_itinerary($config, urldecode($m[1]));
    } elseif ($path === '/api/predict' && $method === 'POST') {
        predict($config);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found: ' . $method . ' ' . $path]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

// ── database ──────────────────────────────────────────────────────────────────

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );
    // Table creation + one-time import live in install.php — run it once
    // after uploading, then delete it.
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    return $pdo;
}

function read_json_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid JSON body']);
        exit;
    }
    return $body;
}

// ── itineraries ───────────────────────────────────────────────────────────────

function list_itineraries(array $config): void
{
    // seq ASC matches the old file's append order.
    $rows = db($config)
        ->query('SELECT data FROM itineraries ORDER BY seq ASC')
        ->fetchAll(PDO::FETCH_COLUMN);
    $items = array_map(fn ($r) => json_decode($r, true), $rows);
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function save_itinerary(array $config): void
{
    $payload = read_json_body();
    $id = (string) ($payload['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Missing itinerary id']);
        return;
    }
    $stmt = db($config)->prepare(
        'INSERT INTO itineraries (id, data) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data)'
    );
    $stmt->execute([$id, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    echo json_encode(['ok' => true]);
}

function delete_itinerary(array $config, string $id): void
{
    $stmt = db($config)->prepare('DELETE FROM itineraries WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['ok' => true]);
}

// ── AI prediction ─────────────────────────────────────────────────────────────

function predict(array $config): void
{
    $payload    = read_json_body();
    $itinerary  = $payload['itinerary']  ?? [];
    $marketData = $payload['marketData'] ?? [];
    $boardData  = $payload['boardData']  ?? [];

    $systemPrompt =
        'You are a tourism market analyst for TDU (Turtle Down Under), an Australian inbound tour operator. ' .
        'Predict which international markets an itinerary would appeal to. ' .
        'Respond with valid JSON only — no explanation, no markdown.';

    $userPrompt = build_predict_prompt($itinerary, $marketData, $boardData);

    $ch = curl_init($config['proxy_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['system' => $systemPrompt, 'user' => $userPrompt]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'N8N-KEY: ' . $config['proxy_key'],
            'Content-Type: application/json',
            // Cloudflare in front of this proxy blocks non-browser User-Agents
            // with a 403 (error 1010) — a browser-like UA is required.
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        echo json_encode(['error' => 'AI prediction unavailable', 'detail' => $curlErr ?: "HTTP $status"]);
        return;
    }

    $raw  = json_decode($response, true);
    $text = $raw['output'][0]['content'][0]['text'] ?? null;
    $prediction = $text !== null ? json_decode($text, true) : null;

    if (!is_array($prediction)) {
        echo json_encode(['error' => 'AI prediction unavailable', 'detail' => 'Unexpected proxy response']);
        return;
    }
    echo json_encode($prediction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Direct port of _build_predict_prompt() from server.py. */
function build_predict_prompt(array $itinerary, array $marketData, array $boardData): string
{
    $regions = $itinerary['regions'] ?? null;
    if ($regions === null) {
        $regions = [[
            'state'    => $itinerary['state'] ?? '',
            'days'     => $itinerary['days'] ?? '',
            'products' => $itinerary['products'] ?? [],
        ]];
    }

    $sumDays = 0;
    foreach ($regions as $r) {
        $sumDays += (int) ($r['days'] ?? 0);
    }
    $totalDays = $itinerary['total_days'] ?? ($sumDays ?: ($itinerary['days'] ?? ''));

    $detectedStates = [];
    foreach ($regions as $r) {
        if (!empty($r['state'])) {
            $detectedStates[$r['state']] = true;
        }
    }

    $marketLines = [];
    foreach ($marketData as $market => $stats) {
        $visits = [];
        foreach (($stats['stateVisits'] ?? []) as $s => $c) {
            if (isset($detectedStates[$s])) {
                $visits[] = "$s: $c";
            }
        }
        $visitsStr = $visits ? implode(', ', $visits) : 'none recorded';
        $marketLines[] = sprintf(
            '- %s: %s total tours, avg duration %s days, state visits — %s',
            $market,
            $stats['totalTours'] ?? 0,
            $stats['avgDays'] ?? 0,
            $visitsStr
        );
    }
    $marketSummary = implode("\n", $marketLines);

    $boardLines = [];
    foreach ($boardData as $stateKey => $stats) {
        if (!isset($detectedStates[$stateKey])) {
            continue;
        }
        $topProducts = implode(', ', $stats['topProducts'] ?? []);
        $boardLines[] = sprintf(
            '%s: %s board tours — top products: %s',
            $stateKey,
            $stats['boardTours'] ?? 0,
            $topProducts !== '' ? $topProducts : 'none recorded'
        );
    }
    $boardSummary = $boardLines ? implode("\n", $boardLines) : 'none recorded';

    $regionLines = [];
    foreach ($regions as $r) {
        $productsStr = implode(', ', $r['products'] ?? []);
        $regionLines[] = sprintf(
            '- %s: %s days — Products/Places: %s',
            $r['state'] ?? '',
            $r['days'] ?? '',
            $productsStr
        );
    }
    $regionsBlock = implode("\n", $regionLines);

    $spanLine = count($regions) > 1
        ? "Multi-state itinerary — score based on the FULL itinerary across all regions "
          . "and consider which markets travel to this combination of states.\n"
        : '';

    return <<<PROMPT
Market data (what each market's operators currently sell), limited to this itinerary's states:
{$marketSummary}

Board promotion data (what tourism boards recommend), same states:
{$boardSummary}
Where board promotion is high and competitor coverage is low, flag it as a first-mover opportunity.

Itinerary:
{$spanLine}Total duration: {$totalDays} days
Regions:
{$regionsBlock}

Score each market's fit for this itinerary (historical behaviour, untapped opportunity, product mix, duration fit).

Return ONLY this JSON structure, no other text:
{
  "predictions": [
    {"market": "India", "score": 42, "label": "Moderate fit", "reason": "one sentence why"},
    {"market": "Singapore", "score": 28, "label": "Low fit", "reason": "one sentence why"},
    {"market": "UK", "score": 18, "label": "Low fit", "reason": "one sentence why"},
    {"market": "New Zealand", "score": 8, "label": "Low fit", "reason": "one sentence why"},
    {"market": "Australia", "score": 4, "label": "Low fit", "reason": "one sentence why"}
  ],
  "summary": "Two sentence overall recommendation for the PM"
}
Scores must add up to 100. Labels: 60+ = Strong fit, 35-59 = Moderate fit, below 35 = Low fit.
PROMPT;
}
