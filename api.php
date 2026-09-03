<?php
/**
 * api.php â€” Itinerary Builder backend (PHP + MySQL port of app/server/server.py)
 *
 * Routed here by .htaccess:
 *   GET    itineraries        â†’ list all saved itineraries (from MySQL)
 *   POST   itineraries        â†’ save/update one itinerary (upsert by id)
 *   DELETE itineraries/{id}   â†’ delete one itinerary
 *   POST   api/predict        â†’ build prompt + forward to the AI proxy
 *   GET    quotes             â†’ list real past quotes (?stage=, ?q= filters)
 *   GET    quotes/analytics   â†’ aggregate metrics/breakdowns for charts
 *   GET    quotes/outcomes    â†’ acceptance-rate, route, trip-length, region, hotel findings
 *   GET    quotes/seasonality â†’ travel-date-derived demand (month/season, city heatmap)
 *   GET    quotes/templates   â†’ route+trip-length itinerary templates (core/addon products, hotels); ?source=clean reads quotes_clean/ instead of the live uncleaned table (used only by the standalone quotes_itinerary_mock.html Patterns tab â€” the live dashboard's Templates tab is unaffected)
 *   GET    quotes/itinerary-patterns â†’ same template clustering as quotes/templates, but filter-driven (state/city/category/minDays/maxDays) over quotes_clean/; new, separate endpoint for the standalone quotes_dashboard.html Quotes tab only
 *   GET    quotes/routes â†’ PRIMARY table for the Quotes tab's filter -> routes -> itineraries drill-down: distinct exact-ordered routes matching the same sidebar filters, each with totalCount (originals+duplicates), originalCount, and duplicateCount (originalCount + duplicateCount = totalCount); state/category accept a comma-list for "match any of" (multi-select); also returns stateCounts/categoryCounts (per-option match counts, ignoring the state/category filters themselves, for sidebar chip badges); metadata-only. ?groupBy=cityset groups by WHICH cities are visited (ignoring order) instead of the exact ordered sequence â€” each group's row then also carries `orderings` (every distinct exact route folded into it, with its own count)
 *   GET    quotes/route-summary â†’ per-city day-count analytics for a SELECTED route/route-group: average days spent in each city across that route's own matching itineraries, alongside a dataset-wide baseline average for the same cities (independent of route) for comparison. ?route= (single exact route, matches quotes/export-clean's convention) or ?routes= (comma-list of exact routes, for a cityset GROUP's combined orderings) selects which itineraries to average; same sidebar filters as quotes/routes also apply
 *   GET    quotes/route-similarity â†’ groups NEAR-duplicate itineraries (not just exact duplicates) within a SELECTED route/route-group: pairwise Jaccard similarity of each quote's distinct product set (normalized name-keys, same merging as elsewhere in this app) â€” quotes at or above the similarity threshold (default 80%, tunable) are clustered together, one representative per cluster plus every other member's exact similarity %. Same ?route=/?routes=/sidebar-filter selection as quotes/route-summary; scoped to one route's quotes only (not dataset-wide) to keep the O(n^2) comparison cheap
 *   GET    quotes/cooccurrence â†’ product co-occurrence / "if A then B" rules
 *   GET    quotes/city-cooccurrence â†’ city co-occurrence (which cities travel together)
 *   GET    quotes/states      â†’ AU state breakdown + Board/Competitor cross-reference
 *   GET    quotes/products    â†’ category-by-trip-length, city concentration, comment mining
 *   GET    quotes/export-enriched â†’ every quote's raw data + total_days/states_touched/per-line state, for external use (itinerary-building input)
 *   GET    quotes/export-clean â†’ paginated/filtered browse over the CLEANED quotes only (quotes_clean/), each enriched with its metadata (route/trip_band/states_touched); filters: state, city, stage, q, days (exact trip length), minDays (at least N days), maxDays (at most N days), route (exact city-sequence match), category (must appear in category_mix), hasHotels=1 (only quotes with at least one hotel line item), duplicatesOnly=1 (only quotes that are part of a duplicate-itinerary group), originalsOnly=1 (drop every quote flagged as a duplicate, for a consistently-sized "one card per distinct itinerary" page); state/category accept a comma-list for "match any of" (multi-select); results are always ordered with duplicate groups (2+ identical itineraries) first, each group's members adjacent; light=1 skips full products/hotels for a fast metadata-only list
 *   GET    quotes/metadata/{quote_no} â†’ precomputed route/trip_band/category_mix metadata for one CLEANED quote (404 if it was excluded by clean_quotes.php)
 *   GET    quotes/clean/{quote_no} â†’ full products/hotels for one CLEANED quote, read from quotes_clean/ (city casing normalized) instead of the live table (404 if it was excluded by clean_quotes.php)
 *   GET    quotes/clean-analytics â†’ aggregate breakdowns (trip length, city/state frequency, category mix, product/hotel reuse, route uniqueness, non-transfer product popularity, per-route product-combo uniqueness) over all 3,847 cleaned quotes, optionally filtered by ?days=, ?city=, ?route=
 *   GET    quotes/clean-overview â†’ front-page KPI tiles + category pie-chart data + overall trip-length box plot â€” unfiltered, fixed-shape, fast (metadata-only), for the standalone mock's new Overview tab
 *   GET    quotes/clean-products â†’ category-by-trip-band mix, product city-concentration, comment-field mining â€” over the CLEANED dataset only (ported from the live dashboard's quotes_products(), quotestage-free)
 *   GET    quotes/clean-spread â†’ trip-length box-plot stats (min/p25/median/p75/max), overall + by state + by trip band â€” over the CLEANED dataset only (ported from the live dashboard's box plot, quotestage-free)
 *   GET    quotes/clean-seasonality â†’ travel-date-derived demand: monthly/seasonal volume, AU-vs-NZ split, cityÃ—month heatmap â€” over the CLEANED dataset only (ported from the live dashboard, loss-rate-by-month table deliberately dropped since it used quotestage)
 *   GET    quotes/clean-hotels â†’ hotel-specific breakdown: most common hotels, hotels-per-quote distribution, hotel city-concentration â€” over the CLEANED dataset only, quotestage-free
 *   GET    quotes/clean-extra â†’ category mix by state, single-vs-multi-city split by trip band, distinct-product variety by trip band â€” metadata-only, quotestage-free
 *   GET    quotes/clean-cooccurrence â†’ product pairing lift/support/confidence rules â€” over the CLEANED dataset only (ported from the live dashboard, quotestage-free)
 *   GET    quotes/clean-city-cooccurrence â†’ city pairing lift/support/confidence rules â€” over the CLEANED dataset only (ported from the live dashboard, quotestage-free)
 *   GET    quotes/{quote_no}  â†’ one quote's full products/hotels
 *
 * Setup (table creation + import of the old saved_itineraries.json) is done
 * by install.php â€” run it once after uploading, then delete it.
 *
 * data.js / catalog.js / market.js / tokens.js stay as static files for now
 * (phase 1); moving them into MySQL is a later phase.
 */

define('GUARD_API', true);
require_once __DIR__ . '/auth_guard.php'; // main-dashboard login + TOTP 2FA; JSON 401/403 on failure

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// â”€â”€ routing â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
    } elseif ($path === '/quotes' && $method === 'GET') {
        list_quotes($config);
    } elseif ($path === '/quotes/analytics' && $method === 'GET') {
        quotes_analytics($config);
    } elseif ($path === '/quotes/outcomes' && $method === 'GET') {
        quotes_outcomes($config);
    } elseif ($path === '/quotes/seasonality' && $method === 'GET') {
        quotes_seasonality($config);
    } elseif ($path === '/quotes/templates' && $method === 'GET') {
        quotes_templates($config);
    } elseif ($path === '/quotes/itinerary-patterns' && $method === 'GET') {
        quotes_itinerary_patterns();
    } elseif ($path === '/quotes/routes' && $method === 'GET') {
        quotes_routes();
    } elseif ($path === '/quotes/route-summary' && $method === 'GET') {
        quotes_route_summary();
    } elseif ($path === '/quotes/route-similarity' && $method === 'GET') {
        quotes_route_similarity();
    } elseif ($path === '/quotes/cooccurrence' && $method === 'GET') {
        quotes_cooccurrence($config);
    } elseif ($path === '/quotes/city-cooccurrence' && $method === 'GET') {
        quotes_city_cooccurrence($config);
    } elseif ($path === '/quotes/states' && $method === 'GET') {
        quotes_states($config);
    } elseif ($path === '/quotes/products' && $method === 'GET') {
        quotes_products($config);
    } elseif ($path === '/quotes/export-enriched' && $method === 'GET') {
        quotes_export_enriched($config);
    } elseif ($path === '/quotes/export-clean' && $method === 'GET') {
        quotes_export_clean();
    } elseif ($path === '/quotes/clean-analytics' && $method === 'GET') {
        quotes_clean_analytics();
    } elseif ($path === '/quotes/clean-overview' && $method === 'GET') {
        quotes_clean_overview();
    } elseif ($path === '/quotes/clean-products' && $method === 'GET') {
        quotes_clean_products();
    } elseif ($path === '/quotes/clean-spread' && $method === 'GET') {
        quotes_clean_spread();
    } elseif ($path === '/quotes/clean-seasonality' && $method === 'GET') {
        quotes_clean_seasonality();
    } elseif ($path === '/quotes/clean-hotels' && $method === 'GET') {
        quotes_clean_hotels();
    } elseif ($path === '/quotes/clean-extra' && $method === 'GET') {
        quotes_clean_extra();
    } elseif ($path === '/quotes/clean-cooccurrence' && $method === 'GET') {
        quotes_clean_cooccurrence();
    } elseif ($path === '/quotes/clean-city-cooccurrence' && $method === 'GET') {
        quotes_clean_city_cooccurrence();
    } elseif (preg_match('#^/quotes/metadata/(.+)$#', $path, $m) && $method === 'GET') {
        get_quote_metadata(urldecode($m[1]));
    } elseif (preg_match('#^/quotes/clean/(.+)$#', $path, $m) && $method === 'GET') {
        get_quote_clean(urldecode($m[1]));
    } elseif (preg_match('#^/quotes/(.+)$#', $path, $m) && $method === 'GET') {
        get_quote($config, urldecode($m[1]));
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found: ' . $method . ' ' . $path]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

// â”€â”€ database â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
    // Table creation + one-time import live in install.php â€” run it once
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

// â”€â”€ itineraries â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

// â”€â”€ quotes (read-only) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function list_quotes(array $config): void
{
    $stage = trim((string) ($_GET['stage'] ?? ''));
    $q     = trim((string) ($_GET['q'] ?? ''));

    $where  = [];
    $params = [];
    if ($stage !== '') {
        $where[]  = 'quotestage = ?';
        $params[] = $stage;
    }
    if ($q !== '') {
        $where[]  = 'quote_no LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $sql = 'SELECT quote_no, quotestage, product_count, hotel_count FROM quotes';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY quote_no ASC';

    $stmt = db($config)->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function get_quote(array $config, string $quoteNo): void
{
    $stmt = db($config)->prepare('SELECT data FROM quotes WHERE quote_no = ?');
    $stmt->execute([$quoteNo]);
    $row = $stmt->fetchColumn();
    if ($row === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Quote not found: ' . $quoteNo]);
        return;
    }
    echo $row; // already valid JSON, stored verbatim
}

/**
 * Serves one quote's precomputed metadata file (quotes_metadata/{quote_no}.json
 * â€” route, trip_band, category_mix, days per city/state, product/hotel
 * variety counts) built by generate_quotes_metadata.php from the cleaned
 * dataset (quotes_clean/). Only quotes that passed the cleaning pass
 * (clean_quotes.php) have a metadata file; anything else returns 404 â€”
 * this is the correct, expected response for an excluded/dirty quote, not
 * an error.
 *
 * $quoteNo is validated against a strict allowlist pattern before ever
 * touching the filesystem, so this can't be used to read an arbitrary path
 * (e.g. "../../config") regardless of what a caller sends.
 */
function get_quote_metadata(string $quoteNo): void
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $quoteNo)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid quote number format.']);
        return;
    }
    $path = __DIR__ . '/quotes_metadata/' . $quoteNo . '.json';
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'No metadata for quote (it may not have passed data cleaning): ' . $quoteNo]);
        return;
    }
    echo file_get_contents($path); // already valid JSON, stored verbatim
}

/**
 * Serves one quote's full products/hotels from quotes_clean/{quote_no}.json
 * â€” the CLEANED copy, with city casing already normalized by
 * clean_quotes.php â€” rather than get_quote()'s live-table row, which is the
 * original raw source data (same product/hotel content, but city casing
 * exactly as it was typed, e.g. "Carins" instead of "Cairns"). Used by the
 * standalone quotes_itinerary_mock.html's quote-detail modal so a lazily-
 * fetched full quote is consistent with the rest of the mock, which reads
 * quotes_clean/ everywhere else. Only quotes that passed cleaning have a
 * file here; anything else 404s, same convention as get_quote_metadata().
 *
 * $quoteNo is validated against a strict allowlist pattern before ever
 * touching the filesystem, same as get_quote_metadata() above.
 */
function get_quote_clean(string $quoteNo): void
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $quoteNo)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid quote number format.']);
        return;
    }
    $path = __DIR__ . '/quotes_clean/' . $quoteNo . '.json';
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Quote not found in the cleaned dataset (it may not have passed data cleaning): ' . $quoteNo]);
        return;
    }
    echo file_get_contents($path); // already valid JSON, stored verbatim
}

/**
 * Aggregate metrics + breakdowns for the Quotes analytics charts. Scans every
 * quote's JSON once per request (rows are small, ~3.7KB avg / ~24MB total â€”
 * cheap enough not to need a precomputed table or DB-side JSON functions).
 *
 * Optional cross-filters (clicking a bar/slice in the UI sets one of these):
 *   ?city=<name>       â€” case-insensitive match against product city
 *   ?stage=<stage>     â€” exact match against quotestage
 *   ?category=<name>   â€” case-insensitive match against product category
 * Filters scope every number below them (stat tiles + all three charts), same
 * quote-list rule as the rest of the app â€” the numbers always agree with what
 * was clicked. All three combine (AND).
 */
function quotes_analytics(array $config): void
{
    $f = quotes_read_filters();
    $filterCity = $f['city'];
    $filterCityKey = $f['cityKey'];
    $filterStage = $f['stage'];
    $filterCategory = $f['category'];
    $filterState = $f['state'];

    // Per-dimension filter variants: each breakdown chart must stay "whole"
    // with respect to its OWN dimension (e.g. clicking "Attraction" in the
    // category pie must not collapse the category pie down to one slice) â€”
    // it should still reflect every OTHER active filter, just not itself.
    // Only the top-line KPI tiles (totalQuotes/totalProducts/totalHotels)
    // use the full, unmodified $f.
    $fExceptStage = $f;
    $fExceptStage['stage'] = '';

    $fExceptCategory = $f;
    $fExceptCategory['category'] = '';
    $fExceptCategory['categoryKey'] = '';

    $fExceptCity = $f;
    $fExceptCity['city'] = '';
    $fExceptCity['cityKey'] = '';

    $fExceptState = $f;
    $fExceptState['state'] = '';

    // Stage can no longer be filtered at the SQL level: if we dropped every
    // row except stage='Rejected' before the PHP loop even starts, there
    // would be no way to reconstruct "how many were Accepted" for the
    // byStage chart. So stage qualification happens in PHP alongside the
    // other dimensions, and every row is fetched.
    $sql = 'SELECT quotestage, product_count, hotel_count, data FROM quotes';
    $stmt = db($config)->prepare($sql);
    $stmt->execute();

    $totalQuotes    = 0;
    $totalProducts  = 0;
    $totalHotels    = 0;
    $byStage        = [];
    $byCategory     = [];
    $byState        = [];
    // City names in the source data are inconsistently cased ("Gold Coast" vs
    // "Gold coast") â€” group case-insensitively, but display the casing seen
    // most often, rather than silently editing the source data.
    $cityCounts     = []; // lowercased key => count
    $cityDisplay    = []; // lowercased key => [display variant => count]

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];
        $stage = $row['quotestage'] !== '' ? $row['quotestage'] : 'Unknown';

        // A line-item filter (city/category/product/state) scopes to quotes
        // that have >=1 matching item at all; the per-item loop below then
        // counts only the matching items into every breakdown, so "quotes by
        // stage" for e.g. Sydney means "quotes with >=1 product there", not
        // "quotes whose ONLY products are there".
        $stageOk = $filterStage === '' || $filterStage === $stage;

        $qualifiesFull           = $stageOk && quotes_matches_filters($products, $f);
        $qualifiesExceptStage    = quotes_matches_filters($products, $fExceptStage);
        $qualifiesExceptCategory = $stageOk && quotes_matches_filters($products, $fExceptCategory);
        $qualifiesExceptCity     = $stageOk && quotes_matches_filters($products, $fExceptCity);
        $qualifiesExceptState    = $stageOk && quotes_matches_filters($products, $fExceptState);

        if ($qualifiesFull) {
            $totalQuotes++;
        }
        if ($qualifiesExceptStage) {
            $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
        }

        foreach ($products as $p) {
            if ($qualifiesFull && quotes_line_matches_filters($p, $f)) {
                $totalProducts++;
            }

            if ($qualifiesExceptCategory && quotes_line_matches_filters($p, $fExceptCategory)) {
                $cat = quotes_norm_category((string) ($p->category ?? '')) ?: 'Uncategorised';
                $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            }

            if ($qualifiesExceptCity && quotes_line_matches_filters($p, $fExceptCity)) {
                // Same junk-filtering + typo-fixing every other endpoint
                // already uses (quotes_norm_city) â€” this loop used to skip
                // it and group on the raw, uncleaned city string instead,
                // which is how stray values like "150" ended up in the
                // city chart as if they were real city names.
                $cityRaw = quotes_norm_city((string) ($p->city ?? ''));
                if ($cityRaw !== null) {
                    $cityKey = mb_strtolower($cityRaw);
                    $cityCounts[$cityKey] = ($cityCounts[$cityKey] ?? 0) + 1;
                    $cityDisplay[$cityKey][$cityRaw] = ($cityDisplay[$cityKey][$cityRaw] ?? 0) + 1;
                }
            }

            if ($qualifiesExceptState && quotes_line_matches_filters($p, $fExceptState)) {
                $state = quotes_state_of_city(mb_strtolower(trim((string) ($p->city ?? ''))));
                if ($state !== null) {
                    $byState[$state] = ($byState[$state] ?? 0) + 1;
                }
            }
        }

        if (!$qualifiesFull) {
            continue;
        }

        if ($filterCity === '' && $filterCategory === '' && $f['product'] === '' && $filterState === '') {
            $totalHotels += (int) $row['hotel_count'];
        } else {
            // Hotels have no "category"/"productname" field matching the product
            // catalog â€” a category or product filter can't meaningfully scope
            // hotels, so only city and state do (state was previously missing
            // here, so a state filter didn't shrink this count at all).
            $totalHotels += count(array_filter($obj->hotels ?? [], function ($h) use ($filterCityKey, $filterCity, $filterState) {
                $hCityClean = quotes_norm_city((string) ($h->city ?? ''));
                $hCityKey = $hCityClean !== null ? mb_strtolower($hCityClean) : null;
                if ($filterCity !== '' && $hCityKey !== $filterCityKey) {
                    return false;
                }
                if ($filterState !== '' && ($hCityKey === null || quotes_state_of_city($hCityKey) !== $filterState)) {
                    return false;
                }
                return true;
            }));
        }
    }

    arsort($byStage);
    arsort($byCategory);
    arsort($byState);
    arsort($cityCounts);

    $byCity = [];
    foreach ($cityCounts as $key => $count) {
        arsort($cityDisplay[$key]);
        $byCity[array_key_first($cityDisplay[$key])] = $count;
    }

    // All cities, not capped â€” the frontend paginates through this rather
    // than the backend silently dropping the long tail. Cities below the
    // threshold are also summed into a separate "Other" bucket so the UI can
    // offer "fold into Other" as an explicit choice, not a hidden cutoff.
    $cityThreshold = 10;
    $otherTotal = 0;
    $citiesAboveThreshold = [];
    foreach ($byCity as $name => $count) {
        if ($count >= $cityThreshold) {
            $citiesAboveThreshold[$name] = $count;
        } else {
            $otherTotal += $count;
        }
    }

    echo json_encode([
        'filter'             => ['city' => $filterCity ?: null, 'stage' => $filterStage ?: null, 'category' => $filterCategory ?: null, 'product' => $f['product'] ?: null, 'state' => $filterState ?: null],
        'totalQuotes'        => $totalQuotes,
        'totalProducts'      => $totalProducts,
        'totalHotels'        => $totalHotels,
        'avgProductsPerQuote' => $totalQuotes ? round($totalProducts / $totalQuotes, 1) : 0,
        'byStage'            => $byStage,
        'byCategory'         => $byCategory,
        'byState'            => $byState,
        'allCities'          => $byCity,
        'cityThreshold'      => $cityThreshold,
        'citiesAboveThreshold' => $citiesAboveThreshold,
        'citiesBelowThresholdCount' => count($byCity) - count($citiesAboveThreshold),
        'otherCitiesTotal'   => $otherTotal,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// â”€â”€ outcomes / patterns report (from OBJECTIVE_2_REPORT.md / eda_notebook.ipynb) â”€â”€

// Same three-way bucketing as the notebook's bucket_outcome(): stages not
// listed here fall into "In progress / other" and are excluded from every
// rate calculation below, exactly as the report does. (Plain function-local
// arrays, not top-level consts â€” this file's routing block runs before any
// top-level `const` below it would be defined.)
function quotes_outcome_bucket(string $stage): string
{
    static $negative = [
        'Rejected', 'Auto Rejected',
        'Rejected After Confrmation QA pending', 'Rejected After Confrmation QA completed',
    ];
    static $positive = ['Accepted', 'Completed (Accounts)', 'On Ground'];

    if (in_array($stage, $negative, true)) {
        return 'Rejected';
    }
    if (in_array($stage, $positive, true)) {
        return 'Accepted';
    }
    return 'In progress / other';
}

function quotes_nz_cities(): array
{
    // Same NZ_CITIES set as the notebook's region() function.
    static $cities = [
        'queenstown', 'rotorua', 'auckland', 'christchurch', 'franz josef',
        'wellington', 'dunedin', 'te anau', 'taupo', 'greymouth',
    ];
    return $cities;
}

/**
 * City -> Australian state/territory abbreviation, hand-built to cover the
 * real AU cities actually present in the quotes data (checked against the
 * live dataset, not guessed). No authoritative city->state mapping exists
 * elsewhere in this checkout â€” combine_sources.py's _PLACES_RAW (the
 * source-of-truth for the rest of the app) lives in the git-ignored
 * webscraping/ tree, not present here; the states.cities column in MySQL
 * turned out to be a noisy place-name lookup, not a clean city->state map
 * (e.g. it lists "Sydney" as appearing under more than one state's record).
 * NZ cities and anything not in this list return null ("Unmapped"), same
 * transparency approach as the AU/NZ region split â€” never guessed.
 */
function quotes_state_of_city(string $cityLower): ?string
{
    static $map = [
        'sydney' => 'NSW', 'canberra' => 'ACT',
        'melbourne' => 'VIC',
        'gold coast' => 'QLD', 'cairns' => 'QLD', 'brisbane' => 'QLD',
        'whitsunday' => 'QLD', 'hamilton island' => 'QLD', 'port douglas' => 'QLD',
        'noosa' => 'QLD', 'airlie beach' => 'QLD',
        'perth' => 'WA',
        'adelaide' => 'SA',
        'hobart' => 'TAS', 'launceston' => 'TAS',
        'darwin' => 'NT', 'uluru' => 'NT',
        'tangalooma island' => 'QLD',
    ];
    if (isset($map[$cityLower])) {
        return $map[$cityLower];
    }
    // NZ cities have no AU state, but were previously falling out of every
    // "state-wise" view entirely (states_touched, stateFrequency, the state
    // filter dropdown, category-by-state, the by-state box plot) with no
    // bucket at all â€” silently invisible, not just unfiltered. Bucketing
    // them all under one pseudo-state "NZ" at least surfaces them
    // everywhere a real state would appear.
    if (in_array($cityLower, quotes_nz_cities(), true)) {
        return 'NZ';
    }
    return null;
}

/**
 * Shared cross-filter reader â€” every quotes/* report endpoint (outcomes,
 * seasonality, states, products, analytics) accepts the same ?city=/
 * ?stage=/?category= params and applies them the same way, so clicking a
 * bar in one card scopes every card on the page, not just some of them.
 */
function quotes_read_filters(): array
{
    $city = trim((string) ($_GET['city'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $product = trim((string) ($_GET['product'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));
    return [
        'stage' => trim((string) ($_GET['stage'] ?? '')),
        'city' => $city,
        'cityKey' => mb_strtolower($city),
        'category' => $category,
        'categoryKey' => mb_strtolower($category),
        'product' => $product,
        'productKey' => mb_strtolower($product),
        'state' => $state,
    ];
}

/**
 * Does this quote qualify under the active city/category/product/state
 * filters (a quote qualifies if it has >=1 product matching ALL set filters
 * â€” matching the "quotes with >=1 product there" semantics already used in
 * quotes_analytics()). Stage is checked separately via SQL WHERE, before this
 * is ever called.
 */
function quotes_matches_filters(array $products, array $f): bool
{
    if ($f['city'] === '' && $f['category'] === '' && $f['product'] === '' && $f['state'] === '') {
        return true;
    }
    foreach ($products as $p) {
        if (quotes_line_matches_filters($p, $f)) {
            return true;
        }
    }
    return false;
}

/**
 * Does this one product line item match the active city/category/product/state
 * filters? City comparisons go through quotes_norm_city() first â€” the filter
 * value itself is a CLEANED city name (it's built from what a user clicked in
 * a chart, and every chart groups by the cleaned name), so a raw, uncleaned
 * value like "Me;Bourne" must also be cleaned to "Melbourne" before comparing,
 * or clicking "Melbourne" would silently miss every line item whose raw city
 * string happened to be a typo/junk variant of it.
 */
function quotes_line_matches_filters($p, array $f): bool
{
    $cleanCity = quotes_norm_city((string) ($p->city ?? ''));
    $cleanCityKey = $cleanCity !== null ? mb_strtolower($cleanCity) : null;
    $cityOk = $f['city'] === '' || $cleanCityKey === $f['cityKey'];
    $catOk  = $f['category'] === '' || mb_strtolower(quotes_norm_category((string) ($p->category ?? ''))) === $f['categoryKey'];
    $prodOk = $f['product'] === '' || mb_strtolower(trim((string) ($p->productname ?? ''))) === $f['productKey'];
    $stateOk = $f['state'] === '' || ($cleanCityKey !== null && quotes_state_of_city($cleanCityKey) === $f['state']);
    return $cityOk && $catOk && $prodOk && $stateOk;
}

/**
 * Parses the ?cityRules= JSON param used by quotes_routes() and
 * quotes_export_clean() to let a caller apply the "position on route" /
 * "days spent in that city" filters to MULTIPLE cities at once, each with
 * its own independent position/day constraint (e.g. "Sydney must be
 * first AND spend exactly 3 days there, AND Melbourne must be last").
 * Expected shape: '[{"city":"Sydney","position":"first","days":3,"daysMode":"exactly","visits":"once"}, ...]'
 * daysMode is 'exactly' (default, matches days literally) or 'atleast'
 * (days spent there must be >= the given number). visits is 'any'
 * (default, no constraint) or 'once' (the city must appear as EXACTLY one
 * leg of the route — excludes routes that double back through it, e.g.
 * "Brisbane > Gold Coast > Brisbane", which would otherwise satisfy a
 * plain "2 distinct cities" or "days there" rule despite really being a
 * there-and-back trip). Any rule missing a resolvable city name is
 * dropped. Returns [] if the param is absent, empty, or fails to decode.
 */
function quotes_parse_city_rules(): array
{
    $raw = (string) ($_GET['cityRules'] ?? '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $rules = [];
    foreach ($decoded as $r) {
        if (!is_array($r)) {
            continue;
        }
        $city = quotes_norm_city((string) ($r['city'] ?? ''));
        if ($city === null) {
            continue;
        }
        $rules[] = [
            'cityKey' => mb_strtolower($city),
            'position' => trim((string) ($r['position'] ?? '')),
            'days' => isset($r['days']) && $r['days'] !== '' && $r['days'] !== null ? (int) $r['days'] : null,
            'daysMode' => ($r['daysMode'] ?? '') === 'atleast' ? 'atleast' : 'exactly',
            'visits' => ($r['visits'] ?? '') === 'once' ? 'once' : 'any',
        ];
    }
    return $rules;
}

/**
 * Does this quote (via its metadata object $meta) satisfy EVERY city rule
 * from quotes_parse_city_rules()? A rule fails the whole quote if: the
 * city isn't on the route at all, its "days spent there" doesn't match
 * (when set), or its route position (first/middle/last, when set)
 * doesn't match. Empty $rules always passes (no constraint).
 */
function quotes_city_rules_match(array $rules, $meta): bool
{
    if (!$rules) {
        return true;
    }
    $rawCityMap = $meta->days_per_city ?? [];
    $cityDaysMap = is_array($rawCityMap) ? [] : get_object_vars($rawCityMap);
    $cityKeyToName = [];
    foreach (array_keys($cityDaysMap) as $name) {
        $cityKeyToName[mb_strtolower($name)] = $name;
    }
    $legKeys = array_map('mb_strtolower', explode(' > ', (string) ($meta->route ?? '')));
    $lastIndex = count($legKeys) - 1;

    foreach ($rules as $rule) {
        if (!isset($cityKeyToName[$rule['cityKey']])) {
            return false;
        }
        if ($rule['days'] !== null) {
            $daysThere = (int) ($cityDaysMap[$cityKeyToName[$rule['cityKey']]] ?? 0);
            $daysOk = $rule['daysMode'] === 'atleast' ? $daysThere >= $rule['days'] : $daysThere === $rule['days'];
            if (!$daysOk) {
                return false;
            }
        }
        if ($rule['visits'] === 'once') {
            $legCount = count(array_keys($legKeys, $rule['cityKey'], true));
            if ($legCount !== 1) {
                return false;
            }
        }
        if ($rule['position'] !== '' && $rule['position'] !== 'any') {
            $isFirst = ($legKeys[0] ?? null) === $rule['cityKey'];
            $isLast = ($legKeys[$lastIndex] ?? null) === $rule['cityKey'];
            if ($rule['position'] === 'first' && !$isFirst) {
                return false;
            }
            if ($rule['position'] === 'last' && !$isLast) {
                return false;
            }
            if ($rule['position'] === 'middle') {
                $matchIndexes = array_keys($legKeys, $rule['cityKey'], true);
                $hasMiddle = (bool) array_filter($matchIndexes, fn ($i) => $i !== 0 && $i !== $lastIndex);
                if (!$hasMiddle) {
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * Classifies a quote's transfer style from its metadata alone (no
 * quotes_clean/ read needed — product_names/category_mix are already
 * precomputed per quote). Per the product owner's confirmed definition:
 *   'sic'     â€” has >=1 SIC-category product, and NO "Private"-named
 *               transfer line (transfer lines named e.g. "Private Transfer
 *               from X to Y" â€” SIC itself never appears as a Transfers-
 *               category product name in this dataset, only as its own
 *               separate category tag, so this is purely a name match).
 *   'mix'     â€” has >=1 SIC-category product AND >=1 Private-named
 *               transfer line.
 *   'private' â€” has >=1 Private-named transfer line but NO SIC-category
 *               product at all.
 *   'neither' â€” has neither signal (e.g. only Charter/vehicle-hire
 *               transfers, or no transfers at all) â€” doesn't fit any of
 *               the three buckets above; surfaced as its own count rather
 *               than silently folded into one of them.
 */
function quotes_transfer_style($meta): string
{
    $rawCatMap = $meta->category_mix ?? [];
    $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
    $hasSic = ($catMap['SIC'] ?? 0) > 0;

    $hasPrivate = false;
    foreach ($meta->product_names ?? [] as $name) {
        if (stripos($name, 'private') !== false) {
            $hasPrivate = true;
            break;
        }
    }

    if ($hasSic && $hasPrivate) {
        return 'mix';
    }
    if ($hasSic) {
        return 'sic';
    }
    if ($hasPrivate) {
        return 'private';
    }
    return 'neither';
}

/**
 * Findings-report endpoint: outcome bucketing, per-product acceptance rate,
 * top routes, trip-length distribution, AU/NZ/Mixed region split, and hotel
 * patterns â€” mirrors eda_notebook.ipynb / OBJECTIVE_2_REPORT.md exactly, so
 * these numbers agree with the written report. One pass over all quotes.
 *
 * Accepts the same ?city=/?stage=/?category= filters as quotes_analytics(),
 * so this section reacts to the same clicks as the charts above it instead
 * of always showing the unfiltered whole-book numbers.
 */
function quotes_outcomes(array $config): void
{
    $f = quotes_read_filters();

    // Eligibility (the >=30 decided-quotes floor) must be judged against a
    // product's TOTAL decided volume, not the stage-filtered slice â€” clicking
    // "Accepted" shouldn't make every product look like it has too little
    // data just because filtering to one stage necessarily shrinks its count.
    // Same technique as quotes_analytics()'s $fExceptStage.
    $fForEligibility = $f;
    $fForEligibility['stage'] = '';

    // Stage can no longer be filtered at the SQL level: every row is fetched,
    // and stage qualification happens in PHP alongside the other dimensions
    // (same reasoning/pattern as quotes_analytics()).
    $sql = 'SELECT quotestage, data FROM quotes';
    $stmt = db($config)->prepare($sql);
    $stmt->execute();

    $outcomeCounts = ['Accepted' => 0, 'Rejected' => 0, 'In progress / other' => 0];
    $tripLengths   = [];
    $routeCounts   = [];   // route string => count
    $regionCounts  = ['AU only' => 0, 'NZ only' => 0, 'Mixed AU/NZ' => 0];
    $productDecided = []; // nameKey => ['n' => int, 'accepted' => int, 'byStage' => [], 'nAllStages' => int]
    $hotelNameCounts = []; // hotel nameKey (deduped per quote) => count
    $hotelsPerQuote  = [];
    $productLineItemCounts = []; // nameKey => raw line-item count (all quotes, for the Pareto/catalog view)
    // Case/whitespace variants of the same real-world product/hotel name are
    // merged into one grouping key (quotes_norm_name_key) â€” these track the
    // most-common ORIGINAL casing per key for display, same pattern as
    // $cityDisplay in quotes_analytics().
    $productNameDisplay = []; // nameKey => [rawName => count]
    $hotelNameDisplay    = []; // nameKey => [rawName => count]

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $obj      = json_decode($row['data']);
        $products = $obj->products ?? [];

        $stage   = $row['quotestage'] !== '' ? $row['quotestage'] : 'Unknown';
        $stageOk = $f['stage'] === '' || $f['stage'] === $stage;

        $qualifiesFull         = $stageOk && quotes_matches_filters($products, $f);
        $qualifiesForEligibility = quotes_matches_filters($products, $fForEligibility);

        // Eligibility tracking: total decided volume across ALL stages (but
        // still respecting city/category/product/state filters), used only
        // to decide whether a product clears the >=30 floor â€” never used for
        // the displayed n/rate, which stay stage-filtered.
        if ($qualifiesForEligibility) {
            $outcomeForEligibility = quotes_outcome_bucket($stage);
            if ($outcomeForEligibility !== 'In progress / other') {
                $seenProductsElig = [];
                foreach ($products as $p) {
                    if (!quotes_line_matches_filters($p, $fForEligibility)) {
                        continue;
                    }
                    $name = trim((string) ($p->productname ?? ''));
                    $nameKey = quotes_norm_name_key($name);
                    if ($nameKey === '' || isset($seenProductsElig[$nameKey])) {
                        continue;
                    }
                    $seenProductsElig[$nameKey] = true;
                    if (!isset($productDecided[$nameKey])) {
                        $productDecided[$nameKey] = ['n' => 0, 'accepted' => 0, 'byStage' => [], 'nAllStages' => 0];
                    }
                    $productDecided[$nameKey]['nAllStages']++;
                    $productNameDisplay[$nameKey][$name] = ($productNameDisplay[$nameKey][$name] ?? 0) + 1;
                }
            }
        }

        if (!$qualifiesFull) {
            continue;
        }

        $outcome = quotes_outcome_bucket($stage);
        $outcomeCounts[$outcome]++;

        foreach ($products as $p) {
            if (!quotes_line_matches_filters($p, $f)) {
                continue;
            }
            $name = trim((string) ($p->productname ?? ''));
            $nameKey = quotes_norm_name_key($name);
            if ($nameKey !== '') {
                $productLineItemCounts[$nameKey] = ($productLineItemCounts[$nameKey] ?? 0) + 1;
                $productNameDisplay[$nameKey][$name] = ($productNameDisplay[$nameKey][$name] ?? 0) + 1;
            }
        }

        // Trip length: max day, deduplicated consecutive city sequence, region.
        // Same typo guard as the notebook (day=100 for TDU34206) â€” day values
        // above 30 are excluded from the trip-length distribution as suspect,
        // not silently trusted as genuine 30-100 day trips.
        $maxDay = 0;
        $seq = [];
        $citiesSeen = [];
        foreach ($products as $p) {
            $day = (int) ($p->day ?? 0);
            if ($day > $maxDay && $day <= 30) {
                $maxDay = $day;
            }
            $cityClean = quotes_norm_city((string) ($p->city ?? ''));
            if ($cityClean === null) {
                continue;
            }
            $citiesSeen[$cityClean] = true;
            if (empty($seq) || $seq[count($seq) - 1] !== $cityClean) {
                $seq[] = $cityClean;
            }
        }
        if ($maxDay > 0) {
            $tripLengths[] = $maxDay;
        }
        if ($seq) {
            $routeCounts[implode(' â†’ ', $seq)] = ($routeCounts[implode(' â†’ ', $seq)] ?? 0) + 1;
        }

        $hasNz = false;
        $hasAu = false;
        $nzCities = quotes_nz_cities();
        foreach (array_keys($citiesSeen) as $c) {
            if (in_array(mb_strtolower($c), $nzCities, true)) {
                $hasNz = true;
            } else {
                $hasAu = true;
            }
        }
        if ($hasNz && $hasAu) {
            $regionCounts['Mixed AU/NZ']++;
        } elseif ($hasNz) {
            $regionCounts['NZ only']++;
        } elseif ($hasAu) {
            $regionCounts['AU only']++;
        }

        // Per-product acceptance rate (decided quotes only), one entry per
        // (quote, product) so a product quoted twice in one quote doesn't
        // double-count that quote's outcome â€” same as the notebook. Only
        // lines matching the active filters count, same as the volume loop above.
        if ($outcome !== 'In progress / other') {
            $seenProducts = [];
            foreach ($products as $p) {
                if (!quotes_line_matches_filters($p, $f)) {
                    continue;
                }
                $name = trim((string) ($p->productname ?? ''));
                $nameKey = quotes_norm_name_key($name);
                if ($nameKey === '' || isset($seenProducts[$nameKey])) {
                    continue;
                }
                $seenProducts[$nameKey] = true;
                if (!isset($productDecided[$nameKey])) {
                    $productDecided[$nameKey] = ['n' => 0, 'accepted' => 0, 'byStage' => [], 'nAllStages' => 0];
                }
                $productDecided[$nameKey]['n']++;
                $productNameDisplay[$nameKey][$name] = ($productNameDisplay[$nameKey][$name] ?? 0) + 1;
                if ($outcome === 'Accepted') {
                    $productDecided[$nameKey]['accepted']++;
                }
                // Raw stage (not the 3-way bucket) â€” lets the "rate by stage"
                // table show a product's Rejected %, Auto Rejected %, etc.
                // separately, not just Accepted vs everything-else.
                $productDecided[$nameKey]['byStage'][$stage] = ($productDecided[$nameKey]['byStage'][$stage] ?? 0) + 1;
            }
        }

        // Hotels: dedupe by productid within this quote (one row per night ->
        // one row per distinct hotel), same rule as df_hotels_dedup. City and
        // state filters both scope to that hotel's own location â€” state was
        // previously missing here entirely, so clicking a state filter (e.g.
        // TAS) showed hotels from every qualifying quote regardless of which
        // state that specific hotel was actually in, not just Tasmania's own
        // hotels. Category and product have no hotel analog so they don't
        // apply here (same reasoning as quotes_analytics()).
        $seenHotelIds = [];
        foreach (($obj->hotels ?? []) as $h) {
            $hCityClean = quotes_norm_city((string) ($h->city ?? ''));
            $hCityKey = $hCityClean !== null ? mb_strtolower($hCityClean) : null;
            if ($f['city'] !== '' && $hCityKey !== $f['cityKey']) {
                continue;
            }
            if ($f['state'] !== '' && ($hCityKey === null || quotes_state_of_city($hCityKey) !== $f['state'])) {
                continue;
            }
            $hid = (string) ($h->productid ?? '');
            if ($hid !== '' && isset($seenHotelIds[$hid])) {
                continue;
            }
            if ($hid !== '') {
                $seenHotelIds[$hid] = true;
            }
            $hname = trim((string) ($h->productname ?? ''));
            [$hnameKey, $hnameCanonical] = quotes_hotel_fix(quotes_norm_name_key($hname));
            if ($hnameKey !== '') {
                $hotelNameCounts[$hnameKey] = ($hotelNameCounts[$hnameKey] ?? 0) + 1;
                $hotelNameDisplay[$hnameKey][$hname] = ($hotelNameDisplay[$hnameKey][$hname] ?? 0) + 1;
                // Known-typo merges also get their correct spelling counted
                // as a heavily-weighted vote, so the display name resolves
                // to the correct spelling even on a tiny sample where the
                // typo'd raw string alone could otherwise "win" the tie.
                if ($hnameCanonical !== null) {
                    $hotelNameDisplay[$hnameKey][$hnameCanonical] = ($hotelNameDisplay[$hnameKey][$hnameCanonical] ?? 0) + 1000;
                }
            }
        }
        if ($seenHotelIds) {
            $hotelsPerQuote[] = count($seenHotelIds);
        }
    }

    // Decided-quotes rate.
    $decided = $outcomeCounts['Accepted'] + $outcomeCounts['Rejected'];
    $acceptRate = $decided ? round($outcomeCounts['Accepted'] / $decided * 100, 1) : 0;

    // Trip length percentiles (25th/median/75th), matching pandas .describe().
    sort($tripLengths);
    $pct = function (array $sorted, float $p) {
        if (!$sorted) {
            return 0;
        }
        $idx = $p * (count($sorted) - 1);
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        if ($lo === $hi) {
            return $sorted[$lo];
        }
        return round($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo), 1);
    };

    // Resolve each nameKey to its most-common original casing for display â€”
    // same arsort()+array_key_first() pattern as $cityDisplay in
    // quotes_analytics().
    $productDisplayName = function (string $nameKey) use ($productNameDisplay): string {
        if (!isset($productNameDisplay[$nameKey]) || !$productNameDisplay[$nameKey]) {
            return $nameKey;
        }
        $votes = $productNameDisplay[$nameKey];
        arsort($votes);
        return array_key_first($votes);
    };
    $hotelDisplayName = function (string $nameKey) use ($hotelNameDisplay): string {
        if (!isset($hotelNameDisplay[$nameKey]) || !$hotelNameDisplay[$nameKey]) {
            return $nameKey;
        }
        $votes = $hotelNameDisplay[$nameKey];
        arsort($votes);
        return array_key_first($votes);
    };

    // Per-product acceptance rate, restricted to n >= 30 decided quotes
    // (same threshold as the notebook, so results aren't ranked on noise).
    $productRates = [];
    foreach ($productDecided as $nameKey => $stats) {
        if ($stats['nAllStages'] >= 30 && $stats['n'] > 0) {
            $productRates[] = [
                'productname' => $productDisplayName($nameKey),
                'n' => $stats['n'],
                'acceptRate' => round($stats['accepted'] / $stats['n'] * 100, 1),
            ];
        }
    }
    usort($productRates, fn ($a, $b) => $b['acceptRate'] <=> $a['acceptRate']);
    $topAccepting = array_slice($productRates, 0, 10);
    $bottomAccepting = array_slice(array_reverse($productRates), 0, 10);

    // Rate-by-stage: same idea as acceptance rate above, but selectable by
    // ANY raw stage (Rejected, Auto Rejected, etc.), not just Accepted â€”
    // "for products with >=30 decided quotes, what % of THIS product's
    // decided quotes landed in THIS stage." One ranked list per stage that
    // actually appears often enough to be worth its own view (>=30 decided
    // quotes total, same floor as the per-product threshold, so a stage with
    // only 1-2 quotes overall doesn't get its own near-empty table).
    $stageTotals = [];
    foreach ($productDecided as $stats) {
        foreach ($stats['byStage'] as $stageName => $n) {
            $stageTotals[$stageName] = ($stageTotals[$stageName] ?? 0) + $n;
        }
    }
    $eligibleStages = array_keys(array_filter($stageTotals, fn ($n) => $n >= 30));

    $rateByStage = [];
    foreach ($eligibleStages as $stageName) {
        $rows = [];
        foreach ($productDecided as $nameKey => $stats) {
            if ($stats['nAllStages'] >= 30 && $stats['n'] > 0) {
                $rows[] = [
                    'productname' => $productDisplayName($nameKey),
                    'n' => $stats['n'],
                    'rate' => round(($stats['byStage'][$stageName] ?? 0) / $stats['n'] * 100, 1),
                ];
            }
        }
        usort($rows, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        // Full ranked list, not capped â€” the frontend paginates through it
        // (same pattern as catalog.byVolumeRanked), so every qualifying
        // product is reachable, not just the top/bottom 10.
        $rateByStage[$stageName] = $rows;
    }

    arsort($routeCounts);
    arsort($hotelNameCounts);

    // Resolve hotel keys to display casing, preserving arsort() rank order.
    $hotelNameCountsDisplay = [];
    foreach ($hotelNameCounts as $nameKey => $count) {
        $hotelNameCountsDisplay[$hotelDisplayName($nameKey)] = $count;
    }

    // Pareto curve: products ranked by line-item volume, cumulative share of
    // all product line items â€” "what % of products account for 80% of what
    // we actually sell". Sampled to ~40 points so the response stays small;
    // the 80% crossing point is tracked exactly, not interpolated from the sample.
    arsort($productLineItemCounts);
    $totalLineItems = array_sum($productLineItemCounts);
    $distinctProducts = count($productLineItemCounts);
    $pareto = [];
    $cum = 0;
    $rank = 0;
    $eightyPct = null;
    $sampleEvery = max(1, (int) ceil($distinctProducts / 200));
    foreach ($productLineItemCounts as $count) {
        $rank++;
        $cum += $count;
        $cumPct = round($cum / $totalLineItems * 100, 2);
        $rankPct = round($rank / $distinctProducts * 100, 2);
        if ($eightyPct === null && $cumPct >= 80) {
            $eightyPct = $rankPct;
        }
        if ($rank % $sampleEvery === 0 || $rank === $distinctProducts) {
            $pareto[] = ['rankPct' => $rankPct, 'cumPct' => $cumPct];
        }
    }

    // Resolve keys to display casing, preserving arsort() rank order â€” used
    // by topByVolume and byVolumeRanked below.
    $productLineItemCountsDisplay = [];
    foreach ($productLineItemCounts as $nameKey => $count) {
        $productLineItemCountsDisplay[$productDisplayName($nameKey)] = $count;
    }

    echo json_encode([
        'outcomeBuckets' => $outcomeCounts,
        'decidedQuotes'  => $decided,
        'acceptRate'     => $acceptRate,
        'topRoutes'      => array_slice($routeCounts, 0, 15, true),
        'tripLength'     => [
            'min' => $tripLengths ? $tripLengths[0] : 0,
            'p25' => $pct($tripLengths, 0.25),
            'median' => $pct($tripLengths, 0.5),
            'p75' => $pct($tripLengths, 0.75),
            'max' => $tripLengths ? $tripLengths[count($tripLengths) - 1] : 0,
        ],
        'regionSplit'    => $regionCounts,
        'productsWithEnoughData' => count($productRates),
        'topAcceptingProducts'    => $topAccepting,
        'bottomAcceptingProducts' => $bottomAccepting,
        // Same idea as acceptance rate above, but selectable by any decided
        // stage (Rejected, Auto Rejected, etc.) instead of only Accepted â€”
        // stageTotals lets the frontend build a dropdown with real counts,
        // rateByStage[stageName] is that stage's top-10 ranked product list.
        'stageTotals'   => $stageTotals,
        'rateByStage'   => $rateByStage,
        'topHotels'      => array_slice($hotelNameCountsDisplay, 0, 10, true),
        'hotelsPerQuote' => [
            'median' => $pct((function () use ($hotelsPerQuote) { sort($hotelsPerQuote); return $hotelsPerQuote; })(), 0.5),
            'total' => array_sum($hotelsPerQuote),
        ],
        'catalog' => [
            'distinctProducts' => $distinctProducts,
            'totalLineItems' => $totalLineItems,
            'pareto' => $pareto,
            'eightyPctAtRankPct' => $eightyPct,
            'topByVolume' => array_slice($productLineItemCountsDisplay, 0, 10, true),
            // Full rank-ordered list (all $distinctProducts entries) so the
            // frontend can page through every rank, not just the top 10 â€”
            // same size class as allCities in quotes_analytics(), which is
            // already sent in full and paginated client-side.
            'byVolumeRanked' => $productLineItemCountsDisplay,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// â”€â”€ seasonality / demand (travel dates recovered from hotel check-ins) â”€â”€â”€â”€â”€â”€â”€

/**
 * Parses a hotel checkin/checkout string against the same tolerant set of
 * formats used in the source team's own EDA (build_db.py's DATE_FORMATS),
 * so results agree with that analysis. Returns a Unix timestamp or null.
 */
function quotes_parse_date(?string $raw): ?int
{
    if ($raw === null) {
        return null;
    }
    $s = trim($raw);
    if ($s === '' || strtolower($s) === 'null') {
        return null;
    }
    static $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd M Y', 'Y-m-d H:i:s', 'd-M-Y', 'm/d/Y'];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d !== false) {
            $errors = DateTime::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $d->getTimestamp();
            }
        }
    }
    return null;
}

/**
 * Earliest recoverable travel month for one cleaned quote â€” same "earliest
 * parseable hotel check-in" rule quotes_clean_seasonality() uses to build
 * the "Quotes by travel month" chart, factored out so a ?month= filter can
 * check the SAME definition of "this quote's travel month" a click on that
 * chart is asking about. Returns null if the quote has no hotel line with a
 * parseable check-in date (not every quote has a recoverable travel date).
 * Requires the full quotes_clean/ quote object (travel date isn't in the
 * fast metadata files), so this is deliberately only ever called as a
 * second-pass check on quotes that already passed every metadata-only
 * filter â€” same cost tradeoff as the existing product/hotel-name filters.
 */
function quotes_travel_month(object $quote): ?string
{
    static $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $earliest = null;
    foreach (($quote->hotels ?? []) as $h) {
        $ts = quotes_parse_date($h->checkin ?? null);
        if ($ts !== null && ($earliest === null || $ts < $earliest)) {
            $earliest = $ts;
        }
    }
    if ($earliest === null) {
        return null;
    }
    return $months[(int) date('n', $earliest)];
}

/**
 * Same NZ_CITIES set as quotes_nz_cities(), used here to derive country per
 * quote for the AU-vs-NZ seasonality split.
 */
function quotes_country_of_city(string $cityLower): ?string
{
    static $nz = null;
    if ($nz === null) {
        $nz = array_flip(quotes_nz_cities());
    }
    if ($cityLower === '') {
        return null;
    }
    return isset($nz[$cityLower]) ? 'NZ' : 'AU';
}

/**
 * Demand/seasonality view, ported from the source team's demand_view.py:
 * travel dates recovered from hotel check-ins (earliest per quote), monthly
 * volume, AU-vs-NZ seasonal share, city x month heatmap, and the loss-rate-
 * by-month check that shows the apparent "seasonality" in win rate is
 * actually snapshot-maturity, not a real seasonal effect.
 *
 * Coverage caveat (same as the source analysis): only quotes with >=1
 * parseable hotel check-in get a travel date â€” quotes with no hotel line
 * (short single-city trips are more likely among these) are absent from
 * every cut below. This is a real gap, not a bug â€” stated explicitly in
 * the response so the frontend can carry the same caveat, not hide it.
 */
function quotes_seasonality(array $config): void
{
    $f = quotes_read_filters();
    $sql = 'SELECT quote_no, quotestage, data FROM quotes';
    $params = [];
    if ($f['stage'] !== '') {
        $sql .= ' WHERE quotestage = ?';
        $params[] = $f['stage'];
    }
    $stmt = db($config)->prepare($sql);
    $stmt->execute($params);

    $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $seasonOf = [12 => 'Summer', 1 => 'Summer', 2 => 'Summer', 3 => 'Autumn', 4 => 'Autumn', 5 => 'Autumn',
                 6 => 'Winter', 7 => 'Winter', 8 => 'Winter', 9 => 'Spring', 10 => 'Spring', 11 => 'Spring'];

    $totalQuotes  = 0;
    $datedQuotes  = 0;
    $byMonth      = array_fill(1, 12, 0);
    $byMonthYear  = []; // year => [month => count]
    $bySeason     = ['Summer' => 0, 'Autumn' => 0, 'Winter' => 0, 'Spring' => 0];
    $byCountryMonth = ['AU' => array_fill(1, 12, 0), 'NZ' => array_fill(1, 12, 0)];
    $countryTotals  = ['AU' => 0, 'NZ' => 0];
    $cityMonthCounts = []; // cityLower => [month => count]
    $cityTotals      = []; // cityLower => count of dated quotes touching it
    $cityDisplayVotes = []; // cityLower => [displayVariant => count], resolved to the most common casing at the end
    $lossByMonth2026 = []; // month => ['lost'=>n,'open'=>n,'total'=>n], travel_year==2026 only

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];

        if (!quotes_matches_filters($products, $f)) {
            continue;
        }
        $totalQuotes++;

        // Earliest parseable check-in across this quote's hotels = travel start.
        $earliest = null;
        foreach (($obj->hotels ?? []) as $h) {
            $ts = quotes_parse_date($h->checkin ?? null);
            if ($ts !== null && ($earliest === null || $ts < $earliest)) {
                $earliest = $ts;
            }
        }
        if ($earliest === null) {
            continue;
        }

        $datedQuotes++;
        $year  = (int) date('Y', $earliest);
        $month = (int) date('n', $earliest);
        $byMonth[$month]++;
        $byMonthYear[$year][$month] = ($byMonthYear[$year][$month] ?? 0) + 1;
        $bySeason[$seasonOf[$month]]++;

        if ($year === 2026) {
            $stage = $row['quotestage'] !== '' ? $row['quotestage'] : 'Unknown';
            $outcome = quotes_outcome_bucket($stage);
            if (!isset($lossByMonth2026[$month])) {
                $lossByMonth2026[$month] = ['lost' => 0, 'open' => 0, 'total' => 0];
            }
            $lossByMonth2026[$month]['total']++;
            if ($outcome === 'Rejected') {
                $lossByMonth2026[$month]['lost']++;
            } elseif ($outcome === 'In progress / other') {
                $lossByMonth2026[$month]['open']++;
            }
        }

        // Country + city x month, from this quote's distinct product cities
        // (case-insensitive, same normalisation as the other quotes endpoints).
        // Only lines matching the active filters count.
        $citiesInQuote = [];
        foreach ($products as $p) {
            if (!quotes_line_matches_filters($p, $f)) {
                continue;
            }
            $cityClean = quotes_norm_city((string) ($p->city ?? ''));
            if ($cityClean !== null) {
                $citiesInQuote[mb_strtolower($cityClean)] = $cityClean;
            }
        }
        $countriesInQuote = [];
        foreach ($citiesInQuote as $cityLower => $cityDisplay) {
            $country = quotes_country_of_city($cityLower);
            if ($country !== null) {
                $countriesInQuote[$country] = true;
            }
            if (!isset($cityMonthCounts[$cityLower])) {
                $cityMonthCounts[$cityLower] = array_fill(1, 12, 0);
            }
            $cityMonthCounts[$cityLower][$month]++;
            $cityTotals[$cityLower] = ($cityTotals[$cityLower] ?? 0) + 1;
            $cityDisplayVotes[$cityLower][$cityDisplay] = ($cityDisplayVotes[$cityLower][$cityDisplay] ?? 0) + 1;
        }
        foreach (array_keys($countriesInQuote) as $country) {
            $byCountryMonth[$country][$month]++;
            $countryTotals[$country]++;
        }
    }

    // AU/NZ seasonal share (% of that country's dated quotes, not raw count â€”
    // AU carries ~3x NZ's volume, so raw counts wouldn't be comparable).
    $seasonalityByCountry = [];
    foreach (['AU', 'NZ'] as $c) {
        if ($countryTotals[$c] === 0) {
            continue;
        }
        $seasonalityByCountry[$c] = [];
        for ($m = 1; $m <= 12; $m++) {
            $seasonalityByCountry[$c][$months[$m]] = round($byCountryMonth[$c][$m] / $countryTotals[$c] * 100, 1);
        }
    }

    // Top 12 cities, % of that city's dated quotes per month (heatmap).
    // Display name resolved to whichever casing variant was seen most often
    // (same "Gold Coast" vs "Gold coast" issue as quotes_analytics()).
    arsort($cityTotals);
    $topCities = array_slice($cityTotals, 0, 12, true);
    $cityHeatmap = [];
    foreach ($topCities as $cityLower => $n) {
        arsort($cityDisplayVotes[$cityLower]);
        $displayName = array_key_first($cityDisplayVotes[$cityLower]);
        $cityHeatmap[$displayName] = ['n' => $n, 'byMonth' => []];
        for ($m = 1; $m <= 12; $m++) {
            $cityHeatmap[$displayName]['byMonth'][$months[$m]] = round($cityMonthCounts[$cityLower][$m] / $n * 100, 1);
        }
    }

    // Loss rate by travel month, 2026 only (year-mix held constant) â€” shows
    // the monotonic fall is snapshot maturity, not seasonality.
    $lossRateByMonth = [];
    for ($m = 1; $m <= 12; $m++) {
        if (!isset($lossByMonth2026[$m]) || $lossByMonth2026[$m]['total'] === 0) {
            continue;
        }
        $t = $lossByMonth2026[$m]['total'];
        $lossRateByMonth[$months[$m]] = [
            'n' => $t,
            'lostPct' => round($lossByMonth2026[$m]['lost'] / $t * 100, 1),
            'openPct' => round($lossByMonth2026[$m]['open'] / $t * 100, 1),
        ];
    }

    $byMonthNamed = [];
    for ($m = 1; $m <= 12; $m++) {
        $byMonthNamed[$months[$m]] = $byMonth[$m];
    }

    $years = array_keys($byMonthYear);
    sort($years);
    $byYearMonth = [];
    foreach ($years as $y) {
        $byYearMonth[(string) $y] = array_sum($byMonthYear[$y]);
    }

    echo json_encode([
        'totalQuotes'      => $totalQuotes,
        'datedQuotes'      => $datedQuotes,
        'coveragePct'      => $totalQuotes ? round($datedQuotes / $totalQuotes * 100, 1) : 0,
        'byMonth'          => $byMonthNamed,
        'bySeason'         => $bySeason,
        'byYear'           => $byYearMonth,
        'seasonalityByCountry' => $seasonalityByCountry,
        'cityHeatmap'      => $cityHeatmap,
        'lossRateByMonth2026' => $lossRateByMonth,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// â”€â”€ itinerary templates (route + trip-length clustering) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Trip-length band â€” consultants quote in bands, not exact days. Same bands
 * as the source team's build_templates.py (band()).
 */
function quotes_trip_band(?int $days): ?string
{
    if ($days === null) {
        return null;
    }
    if ($days <= 4) {
        return '1-4d';
    }
    if ($days <= 7) {
        return '5-7d';
    }
    if ($days <= 10) {
        return '8-10d';
    }
    if ($days <= 13) {
        return '11-13d';
    }
    if ($days <= 16) {
        return '14-16d';
    }
    return '17d+';
}

/**
 * A handful of hand-identified city misspellings/synonyms found in the
 * source data (same fixes as the source team's CITY_FIX, kept to the ones
 * that clearly matter for route grouping â€” a full canonical-city gazetteer
 * is future work, not required for this feature).
 */
function quotes_city_fix(string $cityTitleCase): string
{
    static $fix = [
        'Syndey' => 'Sydney', 'Sydneypoint' => 'Sydney',
        'Carins' => 'Cairns', 'Cairns City' => 'Cairns',
        'Gold Caost' => 'Gold Coast', 'Melbourne Cbd' => 'Melbourne',
        'Franz Joseph' => 'Franz Josef', 'Franz Josef Glacier' => 'Franz Josef',
        'Mt Cook' => 'Mount Cook', 'Harvey Bay' => 'Hervey Bay',
        'Bunderberg' => 'Bundaberg', 'Quesnstown' => 'Queenstown',
        'Lake Tekapo' => 'Tekapo', 'Whitsundays' => 'Whitsunday',
        'Me;Bourne' => 'Melbourne', 'Melbourbe' => 'Melbourne',
        'Melbounre' => 'Melbourne', 'Melboune' => 'Melbourne',
        // "City, Region"-style values where the real city is the first part
        // and the rest is a region/nearby-larger-city qualifier someone
        // typed alongside it â€” keep the actual city, drop the qualifier.
        'Margaret River, Perth' => 'Margaret River',
        'Paihia, Northland' => 'Paihia',
        // Short-form / alternate spelling of an existing distinct entry.
        'Airlie' => 'Airlie Beach',
        'Tanglooma' => 'Tangalooma Island',
    ];
    return $fix[$cityTitleCase] ?? $cityTitleCase;
}

function quotes_norm_city(string $raw): ?string
{
    $c = trim(preg_replace('/\s+/', ' ', $raw));
    if ($c === '') {
        return null;
    }
    static $junk = [
        '150', 'q', '', '-', 'n/a', 'na', 'null', '0',
        // Country/state/region names, not cities â€” a "city" field holding
        // one of these means the actual city wasn't recorded, not that the
        // country itself is the city. Also covers stray non-AU/NZ entries
        // (e.g. "Bali") that shouldn't be in this dataset's city field at
        // all â€” excluded rather than mis-mapped to an AU/NZ city.
        'australia', 'new zealand', 'queensland', 'tasmania', 'waikato', 'bali',
        // Ambiguous multi-city value â€” not really one city, and both halves
        // already exist as their own separate, correctly-counted entries.
        'brisbane/gold coast',
    ];
    if (in_array(mb_strtolower($c), $junk, true)) {
        return null;
    }
    $c = mb_convert_case($c, MB_CASE_TITLE);
    return quotes_city_fix($c);
}

/**
 * Category cleanup â€” there are only ~8 real category values in this data,
 * so this only needs a typo map, not a junk list. Blank categories are left
 * as-is (returns '') since a blank category is a real "not recorded" gap,
 * not a typo â€” callers already fall back to "Uncategorised" for that case.
 */
function quotes_norm_category(string $raw): string
{
    $c = trim($raw);
    static $fix = ['Tours' => 'Tour'];
    return $fix[$c] ?? $c;
}

/**
 * Merge hotel/product names that are the exact same real-world thing but
 * differ only in case or stray whitespace (e.g. "NOVOTEL SURFERS PARADISE"
 * vs "Novotel Surfers Paradise" vs a version with a trailing space) â€” this
 * was silently splitting one hotel/product into 2-5 undercounted entries.
 * Returns a lowercase, whitespace-collapsed GROUPING KEY, not a display
 * name â€” callers should track the most-common original casing themselves
 * for display (same pattern quotes_analytics() already uses for city
 * casing), since deciding which casing variant is "correct" isn't this
 * function's job. Deliberately does NOT attempt to merge names that differ
 * by actual wording (e.g. "Reef View Hotel" vs a full package description
 * containing it) â€” that's a genuinely ambiguous judgment call, not a
 * mechanical whitespace/case fix, and merging it automatically risks
 * combining two different things.
 */
function quotes_norm_name_key(string $raw): string
{
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $raw)));
}

/**
 * Hotel-specific typo/variant fixes, applied ON TOP of quotes_norm_name_key()
 * at hotel-grouping sites only (not products â€” a wrong merge there risks
 * bigger blast radius across the acceptance-rate/Pareto/pairing tables).
 * Deliberately narrow and manually curated: each entry here is a specific,
 * human-confirmed "these are the same real hotel" call, not a fuzzy/
 * automatic match â€” same reasoning as quotes_city_fix()'s typo map.
 *
 * Returns [mergedKey, canonicalDisplayName]. The grouping key comes from
 * quotes_norm_name_key() first (caller's job), then this maps known-typo
 * keys onto the SAME key as their correct counterpart so counts combine â€”
 * but merging the count isn't enough on its own: with only 1-2 records for
 * a rare hotel, the typo'd spelling can just as easily "win" the most-common-
 * casing vote as the correct one (a coin flip on tiny samples), showing the
 * misspelled name to the user even though the count is right. So a merge
 * entry ALSO supplies its own correctly-spelled display string, added as an
 * extra weighted "vote" at the call site â€” see how $displayNameHint is used
 * where this function is called.
 */
function quotes_hotel_fix(string $key): array
{
    static $fix = [
        'hyatt canbera' => 'Hyatt Hotel Canberra with Breakfast',
        'grand chancellor adelaide' => 'Hotel Grand Chancellor Adelaide with Breakfast',
        'voayges - desert garden hotel standard room' => 'Voyages Desert Gardens Hotel - Garden View Room',
    ];
    if (!isset($fix[$key])) {
        return [$key, null];
    }
    return [quotes_norm_name_key($fix[$key]), $fix[$key]];
}

/**
 * Mines an itinerary template library, ported from the source team's
 * build_templates.py: quotes are grouped by (route, trip-length band) â€”
 * the pair a consultant actually starts from ("11 days, Melbourne > Gold
 * Coast > Sydney") â€” then every product in a group is scored by how many
 * of that group's quotes include it. Products at >=40% support become the
 * template's "core" (auto-populate); 15-40% become optional add-ons.
 * Groups need >=12 quotes to surface at all â€” small groups aren't a
 * reusable pattern, just a handful of one-off trips.
 *
 * Outcome bucketing uses quotes_outcome_bucket() (this dashboard's existing
 * Accepted/Rejected/In-progress definition), NOT the source team's WON/LOST
 * split â€” kept consistent with every other number in this app rather than
 * introducing a second, disagreeing definition of "accepted".
 */
function quotes_templates(array $config): void
{
    // Default stays 12 â€” the live dashboard's Templates tab calls this with
    // no query param, so its behavior (and reliability floor) is completely
    // unchanged. ?minQuotes=1 (used by the standalone itinerary mock page)
    // shows every route+trip-band pattern with no floor at all, including
    // ones built from a single real quote â€” deliberately noisier, meant
    // for "show every itinerary shape that exists," not "show only the
    // patterns reliable enough to auto-populate a quote from."
    $MIN_QUOTES_PER_TEMPLATE = max(1, (int) ($_GET['minQuotes'] ?? 12));
    $CORE_SUPPORT = 0.40;
    $ADDON_SUPPORT = 0.15;

    // source=clean: read from quotes_clean/ (the day-numbering/route-cleaned
    // 3,847-quote set) instead of the live, uncleaned quotes table. Used
    // ONLY by the standalone quotes_itinerary_mock.html Patterns tab â€” the
    // LIVE dashboard's existing Templates tab calls this with no params and
    // is deliberately left completely unaffected (same query, same table,
    // same behavior as before this flag existed).
    $useCleanSource = ($_GET['source'] ?? '') === 'clean';

    $rows = []; // normalized to [['quote_no'=>.., 'quotestage'=>.., 'data'=>json-string], ...]
    if ($useCleanSource) {
        foreach (glob(__DIR__ . '/quotes_clean/*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            $obj = json_decode($raw);
            if (!is_object($obj) || empty($obj->quote_no)) {
                continue;
            }
            $rows[] = ['quote_no' => $obj->quote_no, 'quotestage' => $obj->quotestage ?? '', 'data' => $raw];
        }
    } else {
        $stmt = db($config)->query('SELECT quote_no, quotestage, data FROM quotes');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = $row;
        }
    }

    $groups = []; // "route|band" => [quote_no, ...]
    $groupMeta = []; // "route|band" => ['route'=>.., 'band'=>.., 'legs'=>[..]]
    $quoteData = []; // quote_no => decoded object, kept only for grouped quotes' later pass
    $totalQuotes = 0;

    foreach ($rows as $row) {
        $totalQuotes++;
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];

        // Route: day-ordered (day=100-style outliers >30 excluded, matching
        // the typo guard used elsewhere), consecutive duplicate cities collapsed.
        $ordered = $products;
        usort($ordered, function ($a, $b) {
            $da = (int) ($a->day ?? 9999);
            $db = (int) ($b->day ?? 9999);
            return $da <=> $db;
        });
        $legs = [];
        $maxDay = null;
        foreach ($ordered as $p) {
            $day = (int) ($p->day ?? 0);
            if ($day > 0 && $day <= 30 && ($maxDay === null || $day > $maxDay)) {
                $maxDay = $day;
            }
            $city = quotes_norm_city((string) ($p->city ?? ''));
            if ($city !== null && (empty($legs) || end($legs) !== $city)) {
                $legs[] = $city;
            }
        }
        if (!$legs || $maxDay === null) {
            continue;
        }
        $band = quotes_trip_band($maxDay);
        $route = implode(' > ', $legs);
        $key = $route . '|' . $band;

        $groups[$key][] = $row['quote_no'];
        if (!isset($groupMeta[$key])) {
            $groupMeta[$key] = ['route' => $route, 'band' => $band, 'legs' => $legs];
        }
        $quoteData[$row['quote_no']] = ['stage' => $row['quotestage'], 'obj' => $obj, 'maxDay' => $maxDay];
    }

    $templates = [];
    foreach ($groups as $key => $quoteNos) {
        $n = count($quoteNos);
        if ($n < $MIN_QUOTES_PER_TEMPLATE) {
            continue;
        }
        $meta = $groupMeta[$key];

        $productAppears = []; // nameKey => quotes containing it (this group)
        $productMeta = [];
        $productDays = []; // nameKey => [day, ...]
        $hotelAppears = [];
        $hotelMeta = [];
        // Case/whitespace variants merged via quotes_norm_name_key(); track
        // most-common original casing per key for display, same pattern as
        // $cityDisplay in quotes_analytics().
        $productNameDisplay = []; // nameKey => [rawName => count]
        $hotelNameDisplay = []; // nameKey => [rawName => count]
        $outcomeMix = ['Accepted' => 0, 'Rejected' => 0, 'In progress / other' => 0];
        $tripDaysList = [];

        foreach ($quoteNos as $qno) {
            $qd = $quoteData[$qno];
            $outcomeMix[quotes_outcome_bucket($qd['stage'] !== '' ? $qd['stage'] : 'Unknown')]++;
            $tripDaysList[] = $qd['maxDay'];

            $seenProducts = [];
            foreach (($qd['obj']->products ?? []) as $p) {
                $name = trim((string) ($p->productname ?? ''));
                $nameKey = quotes_norm_name_key($name);
                if ($nameKey === '' || isset($seenProducts[$nameKey])) {
                    continue;
                }
                $seenProducts[$nameKey] = true;
                $productAppears[$nameKey] = ($productAppears[$nameKey] ?? 0) + 1;
                $productNameDisplay[$nameKey][$name] = ($productNameDisplay[$nameKey][$name] ?? 0) + 1;
                $productMeta[$nameKey] = [
                    'category' => quotes_norm_category((string) ($p->category ?? '')) ?: null,
                    'city' => quotes_norm_city((string) ($p->city ?? '')),
                ];
                $day = (int) ($p->day ?? 0);
                if ($day > 0) {
                    $productDays[$nameKey][] = $day;
                }
            }

            $seenHotels = [];
            foreach (($qd['obj']->hotels ?? []) as $h) {
                $name = trim((string) ($h->productname ?? ''));
                [$nameKey, $nameCanonical] = quotes_hotel_fix(quotes_norm_name_key($name));
                if ($nameKey === '' || isset($seenHotels[$nameKey])) {
                    continue;
                }
                $seenHotels[$nameKey] = true;
                $hotelAppears[$nameKey] = ($hotelAppears[$nameKey] ?? 0) + 1;
                $hotelNameDisplay[$nameKey][$name] = ($hotelNameDisplay[$nameKey][$name] ?? 0) + 1;
                if ($nameCanonical !== null) {
                    $hotelNameDisplay[$nameKey][$nameCanonical] = ($hotelNameDisplay[$nameKey][$nameCanonical] ?? 0) + 1000;
                }
                $hotelMeta[$nameKey] = ['city' => quotes_norm_city((string) ($h->city ?? ''))];
            }
        }

        $median = function (array $vals) {
            if (!$vals) {
                return null;
            }
            sort($vals);
            $mid = (int) floor((count($vals) - 1) / 2);
            return count($vals) % 2 === 0
                ? round(($vals[$mid] + $vals[$mid + 1]) / 2)
                : $vals[$mid];
        };

        $core = [];
        $addons = [];
        foreach ($productAppears as $nameKey => $c) {
            $support = $c / $n;
            if ($support < $ADDON_SUPPORT) {
                continue;
            }
            $votes = $productNameDisplay[$nameKey];
            arsort($votes);
            $displayName = array_key_first($votes);
            $entry = [
                'productname' => $displayName,
                'category' => $productMeta[$nameKey]['category'],
                'city' => $productMeta[$nameKey]['city'],
                'support' => round($support, 3),
                'nQuotes' => $c,
                'typicalDay' => isset($productDays[$nameKey]) ? $median($productDays[$nameKey]) : null,
            ];
            if ($support >= $CORE_SUPPORT) {
                $core[] = $entry;
            } else {
                $addons[] = $entry;
            }
        }
        usort($core, fn ($a, $b) => ($a['typicalDay'] ?? 99) <=> ($b['typicalDay'] ?? 99) ?: $b['support'] <=> $a['support']);
        usort($addons, fn ($a, $b) => $b['support'] <=> $a['support']);

        $hotels = [];
        foreach ($hotelAppears as $nameKey => $c) {
            $support = $c / $n;
            if ($support >= $ADDON_SUPPORT) {
                $hvotes = $hotelNameDisplay[$nameKey];
                arsort($hvotes);
                $hDisplayName = array_key_first($hvotes);
                $hotels[] = [
                    'productname' => $hDisplayName,
                    'city' => $hotelMeta[$nameKey]['city'],
                    'support' => round($support, 3),
                    'nQuotes' => $c,
                ];
            }
        }
        usort($hotels, fn ($a, $b) => $b['support'] <=> $a['support']);

        $decided = $outcomeMix['Accepted'] + $outcomeMix['Rejected'];
        $templates[] = [
            'route' => $meta['route'],
            'legs' => $meta['legs'],
            'tripBand' => $meta['band'],
            'medianDays' => $median($tripDaysList),
            'nQuotes' => $n,
            'outcomeMix' => $outcomeMix,
            'rejectRate' => $decided ? round($outcomeMix['Rejected'] / $decided * 100, 1) : null,
            'coreProducts' => $core,
            'optionalAddons' => array_slice($addons, 0, 15),
            'typicalHotels' => $hotels,
        ];
    }

    usort($templates, fn ($a, $b) => $b['nQuotes'] <=> $a['nQuotes']);
    foreach ($templates as $i => $t) {
        $templates[$i]['templateId'] = 'T' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
    }

    $covered = array_sum(array_column($templates, 'nQuotes'));

    echo json_encode([
        'templateCount' => count($templates),
        'totalQuotes' => $totalQuotes,
        'coveredQuotes' => $covered,
        'coveragePct' => $totalQuotes ? round($covered / $totalQuotes * 100, 1) : 0,
        'minQuotesPerTemplate' => $MIN_QUOTES_PER_TEMPLATE,
        'coreSupportThreshold' => $CORE_SUPPORT,
        'addonSupportThreshold' => $ADDON_SUPPORT,
        'templates' => $templates,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * New, separate endpoint for the standalone quotes_dashboard.html "Quotes"
 * tab: filter-driven itinerary patterns (state/city/category/day-range in,
 * matching route+trip-band template groups out â€” core products, add-ons,
 * typical hotels). Deliberately NOT a modification of quotes_templates()
 * above (which the live dashboard and quotes_itinerary_mock.html both call
 * unfiltered) â€” this is its own function/route so neither of those existing
 * callers can be affected by anything here. Reuses the same clustering
 * logic (route+band grouping, 40%/15% core/addon support thresholds) since
 * that logic is already correct, just applies a metadata-only pre-filter
 * (same fields/pattern as quotes_export_clean()'s pass 1) before grouping.
 */
function quotes_itinerary_patterns(): void
{
    $MIN_QUOTES_PER_TEMPLATE = max(1, (int) ($_GET['minQuotes'] ?? 1));
    $CORE_SUPPORT = 0.40;
    $ADDON_SUPPORT = 0.15;

    $stateFilter = mb_strtoupper(trim((string) ($_GET['state'] ?? '')));
    $cityFilter = quotes_norm_city((string) ($_GET['city'] ?? ''));
    $cityFilterKey = $cityFilter !== null ? mb_strtolower($cityFilter) : null;
    $categoryFilter = trim((string) ($_GET['category'] ?? ''));
    $minDaysFilter = isset($_GET['minDays']) && $_GET['minDays'] !== '' ? (int) $_GET['minDays'] : null;
    $maxDaysFilter = isset($_GET['maxDays']) && $_GET['maxDays'] !== '' ? (int) $_GET['maxDays'] : null;

    $cleanDir = __DIR__ . '/quotes_clean';
    $metaDir = __DIR__ . '/quotes_metadata';

    // Pass 1: metadata-only filter â€” never opens quotes_clean/ here, same
    // reasoning as quotes_export_clean()'s pass 1 (metadata files are a
    // fraction of the size of the full quote files).
    $allowedQuoteNos = [];
    foreach (glob($metaDir . '/*.json') ?: [] as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        if ($stateFilter !== '' && !in_array($stateFilter, $meta->states_touched ?? [], true)) {
            continue;
        }
        if ($minDaysFilter !== null && (int) ($meta->total_days ?? 0) < $minDaysFilter) {
            continue;
        }
        if ($maxDaysFilter !== null && (int) ($meta->total_days ?? 0) > $maxDaysFilter) {
            continue;
        }
        if ($categoryFilter !== '') {
            $rawCatMap = $meta->category_mix ?? [];
            $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            if (!array_key_exists($categoryFilter, $catMap)) {
                continue;
            }
        }
        if ($cityFilterKey !== null) {
            $rawCityMap = $meta->days_per_city ?? [];
            $cityNames = is_array($rawCityMap) ? array_keys($rawCityMap) : array_keys(get_object_vars($rawCityMap));
            $cities = array_map('mb_strtolower', $cityNames);
            if (!in_array($cityFilterKey, $cities, true)) {
                continue;
            }
        }
        $allowedQuoteNos[$meta->quote_no ?? basename($file, '.json')] = true;
    }

    // Pass 2: load only the allowed quotes' full data (products/hotels),
    // group by route+trip-band, score products/hotels by support â€” same
    // clustering logic as quotes_templates().
    $groups = [];
    $groupMeta = [];
    $quoteData = [];
    $totalQuotes = 0;

    foreach (array_keys($allowedQuoteNos) as $quoteNo) {
        $file = $cleanDir . '/' . $quoteNo . '.json';
        if (!is_file($file)) {
            continue;
        }
        $obj = json_decode(file_get_contents($file));
        if (!is_object($obj)) {
            continue;
        }
        $totalQuotes++;
        $products = $obj->products ?? [];

        $ordered = $products;
        usort($ordered, function ($a, $b) {
            $da = (int) ($a->day ?? 9999);
            $db = (int) ($b->day ?? 9999);
            return $da <=> $db;
        });
        $legs = [];
        $maxDay = null;
        foreach ($ordered as $p) {
            $day = (int) ($p->day ?? 0);
            if ($day > 0 && $day <= 30 && ($maxDay === null || $day > $maxDay)) {
                $maxDay = $day;
            }
            $city = quotes_norm_city((string) ($p->city ?? ''));
            if ($city !== null && (empty($legs) || end($legs) !== $city)) {
                $legs[] = $city;
            }
        }
        if (!$legs || $maxDay === null) {
            continue;
        }
        $band = quotes_trip_band($maxDay);
        $route = implode(' > ', $legs);
        $key = $route . '|' . $band;

        $groups[$key][] = $quoteNo;
        if (!isset($groupMeta[$key])) {
            $groupMeta[$key] = ['route' => $route, 'band' => $band, 'legs' => $legs];
        }
        $quoteData[$quoteNo] = ['stage' => $obj->quotestage ?? '', 'obj' => $obj, 'maxDay' => $maxDay];
    }

    $templates = [];
    foreach ($groups as $key => $quoteNos) {
        $n = count($quoteNos);
        if ($n < $MIN_QUOTES_PER_TEMPLATE) {
            continue;
        }
        $meta = $groupMeta[$key];

        $productAppears = [];
        $productMeta = [];
        $productDays = [];
        $hotelAppears = [];
        $hotelMeta = [];
        $productNameDisplay = [];
        $hotelNameDisplay = [];
        $outcomeMix = ['Accepted' => 0, 'Rejected' => 0, 'In progress / other' => 0];
        $tripDaysList = [];

        foreach ($quoteNos as $qno) {
            $qd = $quoteData[$qno];
            $outcomeMix[quotes_outcome_bucket($qd['stage'] !== '' ? $qd['stage'] : 'Unknown')]++;
            $tripDaysList[] = $qd['maxDay'];

            $seenProducts = [];
            foreach (($qd['obj']->products ?? []) as $p) {
                $name = trim((string) ($p->productname ?? ''));
                $nameKey = quotes_norm_name_key($name);
                if ($nameKey === '' || isset($seenProducts[$nameKey])) {
                    continue;
                }
                $seenProducts[$nameKey] = true;
                $productAppears[$nameKey] = ($productAppears[$nameKey] ?? 0) + 1;
                $productNameDisplay[$nameKey][$name] = ($productNameDisplay[$nameKey][$name] ?? 0) + 1;
                $productMeta[$nameKey] = [
                    'category' => quotes_norm_category((string) ($p->category ?? '')) ?: null,
                    'city' => quotes_norm_city((string) ($p->city ?? '')),
                ];
                $day = (int) ($p->day ?? 0);
                if ($day > 0) {
                    $productDays[$nameKey][] = $day;
                }
            }

            $seenHotels = [];
            foreach (($qd['obj']->hotels ?? []) as $h) {
                $name = trim((string) ($h->productname ?? ''));
                [$nameKey, $nameCanonical] = quotes_hotel_fix(quotes_norm_name_key($name));
                if ($nameKey === '' || isset($seenHotels[$nameKey])) {
                    continue;
                }
                $seenHotels[$nameKey] = true;
                $hotelAppears[$nameKey] = ($hotelAppears[$nameKey] ?? 0) + 1;
                $hotelNameDisplay[$nameKey][$name] = ($hotelNameDisplay[$nameKey][$name] ?? 0) + 1;
                if ($nameCanonical !== null) {
                    $hotelNameDisplay[$nameKey][$nameCanonical] = ($hotelNameDisplay[$nameKey][$nameCanonical] ?? 0) + 1000;
                }
                $hotelMeta[$nameKey] = ['city' => quotes_norm_city((string) ($h->city ?? ''))];
            }
        }

        $median = function (array $vals) {
            if (!$vals) {
                return null;
            }
            sort($vals);
            $mid = (int) floor((count($vals) - 1) / 2);
            return count($vals) % 2 === 0
                ? round(($vals[$mid] + $vals[$mid + 1]) / 2)
                : $vals[$mid];
        };

        $core = [];
        $addons = [];
        foreach ($productAppears as $nameKey => $c) {
            $support = $c / $n;
            if ($support < $ADDON_SUPPORT) {
                continue;
            }
            $votes = $productNameDisplay[$nameKey];
            arsort($votes);
            $displayName = array_key_first($votes);
            $prodCity = $productMeta[$nameKey]['city'];
            $entry = [
                'productname' => $displayName,
                'category' => $productMeta[$nameKey]['category'],
                'city' => $prodCity,
                'state' => $prodCity !== null ? quotes_state_of_city(mb_strtolower($prodCity)) : null,
                'support' => round($support, 3),
                'nQuotes' => $c,
                'typicalDay' => isset($productDays[$nameKey]) ? $median($productDays[$nameKey]) : null,
            ];
            if ($support >= $CORE_SUPPORT) {
                $core[] = $entry;
            } else {
                $addons[] = $entry;
            }
        }
        usort($core, fn ($a, $b) => ($a['typicalDay'] ?? 99) <=> ($b['typicalDay'] ?? 99) ?: $b['support'] <=> $a['support']);
        usort($addons, fn ($a, $b) => $b['support'] <=> $a['support']);

        $hotels = [];
        foreach ($hotelAppears as $nameKey => $c) {
            $support = $c / $n;
            if ($support >= $ADDON_SUPPORT) {
                $hvotes = $hotelNameDisplay[$nameKey];
                arsort($hvotes);
                $hDisplayName = array_key_first($hvotes);
                $hCity = $hotelMeta[$nameKey]['city'];
                $hotels[] = [
                    'productname' => $hDisplayName,
                    'city' => $hCity,
                    'state' => $hCity !== null ? quotes_state_of_city(mb_strtolower($hCity)) : null,
                    'support' => round($support, 3),
                    'nQuotes' => $c,
                ];
            }
        }
        usort($hotels, fn ($a, $b) => $b['support'] <=> $a['support']);

        $decided = $outcomeMix['Accepted'] + $outcomeMix['Rejected'];
        $templates[] = [
            'route' => $meta['route'],
            'legs' => $meta['legs'],
            'tripBand' => $meta['band'],
            'medianDays' => $median($tripDaysList),
            'nQuotes' => $n,
            'outcomeMix' => $outcomeMix,
            'rejectRate' => $decided ? round($outcomeMix['Rejected'] / $decided * 100, 1) : null,
            'coreProducts' => $core,
            'optionalAddons' => array_slice($addons, 0, 15),
            'typicalHotels' => $hotels,
        ];
    }

    usort($templates, fn ($a, $b) => $b['nQuotes'] <=> $a['nQuotes']);
    foreach ($templates as $i => $t) {
        $templates[$i]['templateId'] = 'T' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
    }

    $covered = array_sum(array_column($templates, 'nQuotes'));

    echo json_encode([
        'templateCount' => count($templates),
        'totalQuotes' => $totalQuotes,
        'coveredQuotes' => $covered,
        'coveragePct' => $totalQuotes ? round($covered / $totalQuotes * 100, 1) : 0,
        'minQuotesPerTemplate' => $MIN_QUOTES_PER_TEMPLATE,
        'coreSupportThreshold' => $CORE_SUPPORT,
        'addonSupportThreshold' => $ADDON_SUPPORT,
        'templates' => $templates,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Product co-occurrence / "if A then B" rules, ported from build_templates.py.
 * Lift = P(A and B) / (P(A) * P(B)) â€” a pair with lift 1.0 is no more
 * associated than chance; the source team's thresholds (support >= 25
 * quotes, lift >= 3.0) are kept as-is so results agree with their report.
 * Runs over ALL quotes (not just those grouped into a template), since
 * pairing is a product-level signal independent of route/length banding.
 */
/**
 * Detects the "mechanically obvious" transfer-pair pattern that dominates
 * the top of the product-cooccurrence ranking: an outbound transfer/charter
 * and its own return leg (e.g. "Perth Airport to Perth City Hotel" <->
 * "Perth City Hotel to Perth Airport"). High lift here tells you almost
 * nothing new â€” of course booking a transfer one way predicts booking it
 * back â€” so these are flagged separately rather than mixed in with genuine
 * cross-product signals (e.g. two different day-tours that tend to be
 * booked together, which IS an interesting finding).
 *
 * Heuristic: both names look like a transfer/charter product (contain one
 * of a small set of transport words), AND swapping which of the two names'
 * two location-like halves comes first turns one into (approximately) the
 * other â€” i.e. "A to B" vs "B to A". Deliberately conservative: only flags
 * pairs matching this specific reversed-leg shape, not every transfer that
 * happens to appear in a pair (a transfer paired with an unrelated tour is
 * NOT flagged â€” only transfer-vs-its-own-reverse-leg is).
 */
function quotes_is_reverse_transfer_pair(string $a, string $b): bool
{
    $transportWords = ['transfer', 'charter', 'coach', 'sedan', 'van', 'minibus', 'mini bus', 'shuttle'];
    $looksLikeTransport = function (string $s) use ($transportWords): bool {
        $sl = mb_strtolower($s);
        foreach ($transportWords as $w) {
            if (mb_strpos($sl, $w) !== false) {
                return true;
            }
        }
        return false;
    };
    if (!$looksLikeTransport($a) || !$looksLikeTransport($b)) {
        return false;
    }
    // Split each name on the word "to" (case-insensitive) â€” transfer names
    // are near-universally "<location/place> to <location/place>" plus
    // vehicle/pax details. If A's "before to" chunk resembles B's "after to"
    // chunk (and vice versa), it's the same leg reversed.
    $splitOnTo = function (string $s): ?array {
        if (!preg_match('/^(.*?)\bto\b(.*)$/i', $s, $m)) {
            return null;
        }
        return [trim($m[1]), trim($m[2])];
    };
    $partsA = $splitOnTo($a);
    $partsB = $splitOnTo($b);
    if ($partsA === null || $partsB === null) {
        return false;
    }
    // Compare only the first ~20 chars of each location chunk, lowercased,
    // punctuation-stripped â€” enough to catch "Perth Airport" vs "Perth
    // Airport -  Sedan..." matching, without needing exact full-string
    // equality (vehicle/pax suffixes differ between the two directions).
    $norm = function (string $s): string {
        return mb_substr(preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)), 0, 20);
    };
    $a1 = $norm($partsA[0]);
    $a2 = $norm($partsA[1]);
    $b1 = $norm($partsB[0]);
    $b2 = $norm($partsB[1]);
    if ($a1 === '' || $a2 === '') {
        return false;
    }
    return ($a1 === $b2 && $a2 === $b1);
}

function quotes_cooccurrence(array $config): void
{
    $MIN_PAIR_SUPPORT = 25;
    $MIN_LIFT = 3.0;

    $stmt = db($config)->query('SELECT data FROM quotes');

    $support = []; // nameKey => n quotes containing it
    $pairs = [];   // "a|||b" (a < b, by nameKey) => n quotes containing both
    $totalQuotes = 0;
    // Case/whitespace variants merged via quotes_norm_name_key(); track
    // most-common original casing per key for display, same pattern as
    // $cityDisplay in quotes_analytics().
    $nameDisplay = []; // nameKey => [rawName => count]

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $totalQuotes++;
        $obj = json_decode($row['data']);
        $names = [];
        foreach (($obj->products ?? []) as $p) {
            $name = trim((string) ($p->productname ?? ''));
            $nameKey = quotes_norm_name_key($name);
            if ($nameKey !== '') {
                $names[$nameKey] = true;
                $nameDisplay[$nameKey][$name] = ($nameDisplay[$nameKey][$name] ?? 0) + 1;
            }
        }
        $names = array_keys($names);
        sort($names);
        foreach ($names as $nameKey) {
            $support[$nameKey] = ($support[$nameKey] ?? 0) + 1;
        }
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $key = $names[$i] . "\x1F" . $names[$j];
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
            }
        }
    }

    $displayName = function (string $nameKey) use ($nameDisplay): string {
        if (!isset($nameDisplay[$nameKey]) || !$nameDisplay[$nameKey]) {
            return $nameKey;
        }
        $votes = $nameDisplay[$nameKey];
        arsort($votes);
        return array_key_first($votes);
    };

    $cooc = [];
    foreach ($pairs as $key => $c) {
        if ($c < $MIN_PAIR_SUPPORT) {
            continue;
        }
        [$a, $b] = explode("\x1F", $key);
        $lift = ($c / $totalQuotes) / (($support[$a] / $totalQuotes) * ($support[$b] / $totalQuotes));
        if ($lift < $MIN_LIFT) {
            continue;
        }
        $aName = $displayName($a);
        $bName = $displayName($b);
        $cooc[] = [
            'a' => $aName, 'b' => $bName,
            'nTogether' => $c,
            'lift' => round($lift, 2),
            'confAToB' => round($c / $support[$a], 3),
            'confBToA' => round($c / $support[$b], 3),
            'isReverseTransferPair' => quotes_is_reverse_transfer_pair($aName, $bName),
        ];
    }
    usort($cooc, fn ($x, $y) => $y['lift'] <=> $x['lift']);

    // Same ranking, minus the mechanically-obvious reverse-transfer-leg
    // pairs â€” the signal actually worth acting on (which DIFFERENT products
    // tend to be booked together), not "outbound predicts return leg."
    $meaningfulPairs = array_values(array_filter($cooc, fn ($c) => !$c['isReverseTransferPair']));

    // High-confidence one-way rules: "if A present, B is almost always expected".
    $rules = [];
    foreach ($cooc as $c) {
        if ($c['confAToB'] >= 0.75) {
            $rules[] = ['confidence' => $c['confAToB'], 'n' => $c['nTogether'], 'if' => $c['a'], 'then' => $c['b']];
        }
        if ($c['confBToA'] >= 0.75) {
            $rules[] = ['confidence' => $c['confBToA'], 'n' => $c['nTogether'], 'if' => $c['b'], 'then' => $c['a']];
        }
    }
    usort($rules, fn ($x, $y) => $y['confidence'] <=> $x['confidence']);

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'pairsRetained' => count($cooc),
        'minPairSupport' => $MIN_PAIR_SUPPORT,
        'minLift' => $MIN_LIFT,
        'topPairs' => array_slice($cooc, 0, 20),
        'highConfidenceRules' => array_slice($rules, 0, 20),
        'totalRulesCount' => count($rules),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * City co-occurrence â€” same lift/confidence math as quotes_cooccurrence(),
 * applied to cities instead of products: which two cities most often appear
 * on the same quote (a multi-city itinerary), not just which are each
 * individually popular. Requested by the business as a follow-up to the
 * product-pairing view â€” same "if A then B" framing, e.g. "if a quote
 * touches Sydney, does it also touch Melbourne 60% of the time?".
 *
 * Lower support/lift floor than the product version (support >= 15, lift >=
 * 1.3) because there are far fewer distinct cities than products, so pairs
 * naturally hit higher support and lift is naturally closer to 1.0 even for
 * a real pattern â€” the product thresholds would filter out almost
 * everything here. Cities are normalised via quotes_norm_city(), same
 * cleanup used everywhere else city names are grouped in this file.
 */
function quotes_city_cooccurrence(array $config): void
{
    $MIN_PAIR_SUPPORT = 15;
    $MIN_LIFT = 1.3;

    $stmt = db($config)->query('SELECT data FROM quotes');

    $support = []; // city => n quotes containing it
    $pairs = [];   // "a\x1Fb" (a < b) => n quotes containing both
    $totalQuotes = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $totalQuotes++;
        $obj = json_decode($row['data']);
        $cities = [];
        foreach (($obj->products ?? []) as $p) {
            $city = quotes_norm_city((string) ($p->city ?? ''));
            if ($city !== null) {
                $cities[$city] = true;
            }
        }
        $cities = array_keys($cities);
        sort($cities);
        foreach ($cities as $city) {
            $support[$city] = ($support[$city] ?? 0) + 1;
        }
        $count = count($cities);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $key = $cities[$i] . "\x1F" . $cities[$j];
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
            }
        }
    }

    $cooc = [];
    foreach ($pairs as $key => $c) {
        if ($c < $MIN_PAIR_SUPPORT) {
            continue;
        }
        [$a, $b] = explode("\x1F", $key);
        $lift = ($c / $totalQuotes) / (($support[$a] / $totalQuotes) * ($support[$b] / $totalQuotes));
        if ($lift < $MIN_LIFT) {
            continue;
        }
        $cooc[] = [
            'a' => $a, 'b' => $b,
            'nTogether' => $c,
            'lift' => round($lift, 2),
            'confAToB' => round($c / $support[$a], 3),
            'confBToA' => round($c / $support[$b], 3),
        ];
    }
    usort($cooc, fn ($x, $y) => $y['lift'] <=> $x['lift']);

    $rules = [];
    foreach ($cooc as $c) {
        if ($c['confAToB'] >= 0.5) {
            $rules[] = ['confidence' => $c['confAToB'], 'n' => $c['nTogether'], 'if' => $c['a'], 'then' => $c['b']];
        }
        if ($c['confBToA'] >= 0.5) {
            $rules[] = ['confidence' => $c['confBToA'], 'n' => $c['nTogether'], 'if' => $c['b'], 'then' => $c['a']];
        }
    }
    usort($rules, fn ($x, $y) => $y['confidence'] <=> $x['confidence']);

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'pairsRetained' => count($cooc),
        'minPairSupport' => $MIN_PAIR_SUPPORT,
        'minLift' => $MIN_LIFT,
        'topPairs' => array_slice($cooc, 0, 20),
        'highConfidenceRules' => array_slice($rules, 0, 20),
        'totalRulesCount' => count($rules),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_cooccurrence() â€” same lift/support/confidence math as
 * quotes_cooccurrence() above, but reads ONLY quotes_clean/ (3,847 quotes)
 * instead of the live table. Entirely quotestage-free in the original too â€”
 * pure product-presence association-rule mining, nothing here needed
 * reframing. Used by the standalone quotes_itinerary_mock.html Analytics tab.
 */
function quotes_clean_cooccurrence(): void
{
    $MIN_PAIR_SUPPORT = 25;
    $MIN_LIFT = 3.0;

    $support = []; // nameKey => n quotes containing it
    $pairs = [];   // "a\x1Fb" (a < b) => n quotes containing both
    $totalQuotes = 0;
    $nameDisplay = []; // nameKey => [rawName => count]

    foreach (quotes_iter_clean_rows() as $row) {
        $totalQuotes++;
        $obj = json_decode($row['data']);
        $names = [];
        foreach (($obj->products ?? []) as $p) {
            $name = trim((string) ($p->productname ?? ''));
            $nameKey = quotes_norm_name_key($name);
            if ($nameKey !== '') {
                $names[$nameKey] = true;
                $nameDisplay[$nameKey][$name] = ($nameDisplay[$nameKey][$name] ?? 0) + 1;
            }
        }
        $names = array_keys($names);
        sort($names);
        foreach ($names as $nameKey) {
            $support[$nameKey] = ($support[$nameKey] ?? 0) + 1;
        }
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $key = $names[$i] . "\x1F" . $names[$j];
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
            }
        }
    }

    $displayName = function (string $nameKey) use ($nameDisplay): string {
        if (!isset($nameDisplay[$nameKey]) || !$nameDisplay[$nameKey]) {
            return $nameKey;
        }
        $votes = $nameDisplay[$nameKey];
        arsort($votes);
        return array_key_first($votes);
    };

    $cooc = [];
    foreach ($pairs as $key => $c) {
        if ($c < $MIN_PAIR_SUPPORT) {
            continue;
        }
        [$a, $b] = explode("\x1F", $key);
        $lift = ($c / $totalQuotes) / (($support[$a] / $totalQuotes) * ($support[$b] / $totalQuotes));
        if ($lift < $MIN_LIFT) {
            continue;
        }
        $aName = $displayName($a);
        $bName = $displayName($b);
        $cooc[] = [
            'a' => $aName, 'b' => $bName,
            'nTogether' => $c,
            'lift' => round($lift, 2),
            'confAToB' => round($c / $support[$a], 3),
            'confBToA' => round($c / $support[$b], 3),
            'isReverseTransferPair' => quotes_is_reverse_transfer_pair($aName, $bName),
        ];
    }
    usort($cooc, fn ($x, $y) => $y['lift'] <=> $x['lift']);

    $rules = [];
    foreach ($cooc as $c) {
        if ($c['confAToB'] >= 0.75) {
            $rules[] = ['confidence' => $c['confAToB'], 'n' => $c['nTogether'], 'if' => $c['a'], 'then' => $c['b']];
        }
        if ($c['confBToA'] >= 0.75) {
            $rules[] = ['confidence' => $c['confBToA'], 'n' => $c['nTogether'], 'if' => $c['b'], 'then' => $c['a']];
        }
    }
    usort($rules, fn ($x, $y) => $y['confidence'] <=> $x['confidence']);

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'pairsRetained' => count($cooc),
        'minPairSupport' => $MIN_PAIR_SUPPORT,
        'minLift' => $MIN_LIFT,
        'topPairs' => array_slice($cooc, 0, 20),
        'highConfidenceRules' => array_slice($rules, 0, 20),
        'totalRulesCount' => count($rules),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_city_cooccurrence() â€” same as quotes_city_cooccurrence()
 * above, but reads ONLY quotes_clean/ instead of the live table.
 */
function quotes_clean_city_cooccurrence(): void
{
    $MIN_PAIR_SUPPORT = 15;
    $MIN_LIFT = 1.3;

    $support = []; // city => n quotes containing it
    $pairs = [];   // "a\x1Fb" (a < b) => n quotes containing both
    $totalQuotes = 0;

    foreach (quotes_iter_clean_rows() as $row) {
        $totalQuotes++;
        $obj = json_decode($row['data']);
        $cities = [];
        foreach (($obj->products ?? []) as $p) {
            $city = quotes_norm_city((string) ($p->city ?? ''));
            if ($city !== null) {
                $cities[$city] = true;
            }
        }
        $cities = array_keys($cities);
        sort($cities);
        foreach ($cities as $city) {
            $support[$city] = ($support[$city] ?? 0) + 1;
        }
        $count = count($cities);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $key = $cities[$i] . "\x1F" . $cities[$j];
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
            }
        }
    }

    $cooc = [];
    foreach ($pairs as $key => $c) {
        if ($c < $MIN_PAIR_SUPPORT) {
            continue;
        }
        [$a, $b] = explode("\x1F", $key);
        $lift = ($c / $totalQuotes) / (($support[$a] / $totalQuotes) * ($support[$b] / $totalQuotes));
        if ($lift < $MIN_LIFT) {
            continue;
        }
        $cooc[] = [
            'a' => $a, 'b' => $b,
            'nTogether' => $c,
            'lift' => round($lift, 2),
            'confAToB' => round($c / $support[$a], 3),
            'confBToA' => round($c / $support[$b], 3),
        ];
    }
    usort($cooc, fn ($x, $y) => $y['lift'] <=> $x['lift']);

    $rules = [];
    foreach ($cooc as $c) {
        if ($c['confAToB'] >= 0.5) {
            $rules[] = ['confidence' => $c['confAToB'], 'n' => $c['nTogether'], 'if' => $c['a'], 'then' => $c['b']];
        }
        if ($c['confBToA'] >= 0.5) {
            $rules[] = ['confidence' => $c['confBToA'], 'n' => $c['nTogether'], 'if' => $c['b'], 'then' => $c['a']];
        }
    }
    usort($rules, fn ($x, $y) => $y['confidence'] <=> $x['confidence']);

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'pairsRetained' => count($cooc),
        'minPairSupport' => $MIN_PAIR_SUPPORT,
        'minLift' => $MIN_LIFT,
        'topPairs' => array_slice($cooc, 0, 20),
        'highConfidenceRules' => array_slice($rules, 0, 20),
        'totalRulesCount' => count($rules),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// â”€â”€ AU state breakdown + Board/Competitor cross-reference â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Australian-state view of the quotes data, plus a three-way comparison
 * against the Board (what tourism boards promote) and Competitor (what
 * operators actually sell) data already imported into the states/tour_rows
 * tables â€” the same data the rest of the app (Opportunities tab) is built
 * on. This is the piece that ties Quotes back into the rest of the
 * dashboard instead of sitting as its own island.
 *
 * State is derived per product line item via quotes_state_of_city() â€” a
 * small, hand-verified map covering the real AU cities present in this
 * data (see that function's docblock for why no existing mapping could be
 * reused). NZ cities and anything unmapped are excluded from every figure
 * here and reported separately as "unmapped share", never silently folded
 * into a state.
 */
/**
 * Full data export for external use (feeding the upcoming itinerary-building
 * work) â€” every quote's raw products/hotels, unchanged, PLUS two new derived
 * fields the product owner asked for:
 *
 *   - Per quote: 'total_days' (max day number across products, same "day=100
 *     typo guard" used elsewhere: values above 30 are excluded as suspect,
 *     not trusted as genuine), and 'states_touched' (every distinct AU state
 *     the quote's product cities map to, via quotes_state_of_city() â€” NZ
 *     cities and anything unmapped are simply absent from this list, not
 *     guessed at, same transparency rule used throughout this file).
 *   - Per product line item: 'state' (that single line's own city mapped to
 *     a state, or null if NZ/unmapped) sitting alongside the existing raw
 *     'city' field â€” deliberately redundant with states_touched, kept for
 *     easier day-by-day reading/presentation of one quote's route.
 *
 * Optional query params â€” ALL OFF BY DEFAULT, so calling this with no
 * params behaves exactly as before (full unfiltered dump), which is what
 * the file-export CLI script relies on:
 *   ?page=N&pageSize=M   â€” paginate instead of returning all 6,534 at once
 *     (page is 1-indexed; omit for the full unpaginated dump)
 *   ?state=XX            â€” only quotes whose states_touched includes XX
 *   ?city=NAME           â€” only quotes with >=1 product/hotel line in that
 *                           city (case-insensitive, via quotes_norm_city)
 *   ?stage=NAME          â€” exact match against quotestage
 *   ?q=TEXT               â€” quote_no contains TEXT (case-insensitive)
 * Response shape changes slightly when ?page= is present: instead of a
 * bare array, returns {quotes: [...], page, pageSize, totalMatching}.
 */
function quotes_export_enriched(array $config): void
{
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : null;
    $pageSize = max(1, (int) ($_GET['pageSize'] ?? 25));
    $stateFilter = mb_strtoupper(trim((string) ($_GET['state'] ?? '')));
    $cityFilter = quotes_norm_city((string) ($_GET['city'] ?? ''));
    $cityFilterKey = $cityFilter !== null ? mb_strtolower($cityFilter) : null;
    $stageFilter = trim((string) ($_GET['stage'] ?? ''));
    $qFilter = mb_strtolower(trim((string) ($_GET['q'] ?? '')));

    $sql = 'SELECT quote_no, quotestage, data FROM quotes';
    $params = [];
    if ($stageFilter !== '') {
        $sql .= ' WHERE quotestage = ?';
        $params[] = $stageFilter;
    }
    $sql .= ' ORDER BY quote_no ASC';
    $stmt = db($config)->prepare($sql);
    $stmt->execute($params);

    $matching = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($qFilter !== '' && mb_strpos(mb_strtolower($row['quote_no']), $qFilter) === false) {
            continue;
        }
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];
        $hotels = is_array($obj->hotels ?? null) ? $obj->hotels : [];

        $maxDay = 0;
        $statesTouched = [];
        $cityMatch = $cityFilterKey === null;
        foreach ($products as $p) {
            $day = (int) ($p->day ?? 0);
            if ($day > $maxDay && $day <= 30) {
                $maxDay = $day;
            }
            $cityClean = quotes_norm_city((string) ($p->city ?? ''));
            $state = $cityClean !== null ? quotes_state_of_city(mb_strtolower($cityClean)) : null;
            $p->state = $state; // per-line-item field, alongside the existing 'city'
            if ($state !== null) {
                $statesTouched[$state] = true;
            }
            if (!$cityMatch && $cityClean !== null && mb_strtolower($cityClean) === $cityFilterKey) {
                $cityMatch = true;
            }
        }
        foreach ($hotels as $h) {
            $cityClean = quotes_norm_city((string) ($h->city ?? ''));
            $state = $cityClean !== null ? quotes_state_of_city(mb_strtolower($cityClean)) : null;
            $h->state = $state;
            if ($state !== null) {
                $statesTouched[$state] = true;
            }
            if (!$cityMatch && $cityClean !== null && mb_strtolower($cityClean) === $cityFilterKey) {
                $cityMatch = true;
            }
        }

        if (!$cityMatch) {
            continue;
        }
        if ($stateFilter !== '' && !isset($statesTouched[$stateFilter])) {
            continue;
        }

        $obj->total_days = $maxDay > 0 ? $maxDay : null;
        $obj->states_touched = array_keys($statesTouched);
        $matching[] = $obj;
    }

    if ($page === null) {
        echo json_encode($matching, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $totalMatching = count($matching);
    $offset = ($page - 1) * $pageSize;
    $pageItems = array_slice($matching, $offset, $pageSize);

    echo json_encode([
        'quotes' => $pageItems,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalMatching' => $totalMatching,
        'totalPages' => (int) ceil($totalMatching / $pageSize),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Same paginated browse/filter shape as quotes_export_enriched(), but reads
 * from quotes_clean/ (the 3,847 quotes that passed clean_quotes.php's
 * day-numbering/route checks) instead of the live database â€” so the
 * itinerary-building mock page can browse only quotes that are actually
 * trustworthy for route/pattern work, not the full unfiltered 6,534.
 *
 * Every returned quote also carries a 'has_metadata' flag (always true
 * here, since every quotes_clean/ file has a matching quotes_metadata/
 * file by construction â€” included anyway so the frontend has an explicit
 * signal rather than assuming, in case the two folders ever drift out of
 * sync from a partial re-run).
 *
 * Params: ?page=&pageSize=&state=&city=&stage=&q= â€” same meaning as
 * quotes_export_enriched(). No ?page= means: return every clean quote
 * (3,847) as a bare array, matching that endpoint's "no params = full
 * dump" convention.
 */
function quotes_export_clean(): void
{
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : null;
    $pageSize = max(1, (int) ($_GET['pageSize'] ?? 25));
    // state/category accept either a single value ("VIC") or a comma-list
    // ("VIC,NSW" — matches ANY of them by default) — comma-list support
    // added for the Quotes tab's multi-select chips; every existing
    // single-value caller (AnalyticsExploreTab, InlineQuoteList,
    // QuoteBrowserTab) keeps working unchanged since a single value is
    // just a one-item list here. ?stateMode=and switches a multi-state
    // list to "must touch EVERY selected state" instead of "any one".
    $stateFilters = array_filter(array_map('trim', explode(',', mb_strtoupper((string) ($_GET['state'] ?? '')))));
    $stateMode = ($_GET['stateMode'] ?? '') === 'and' ? 'and' : 'or';
    $categoryFilters = array_filter(array_map('trim', explode(',', (string) ($_GET['category'] ?? ''))));
    $cityFilter = quotes_norm_city((string) ($_GET['city'] ?? ''));
    $cityFilterKey = $cityFilter !== null ? mb_strtolower($cityFilter) : null;
    $cityRules = quotes_parse_city_rules();
    $numCitiesMode = trim((string) ($_GET['numCitiesMode'] ?? ''));
    $numCitiesFilter = isset($_GET['numCities']) && $_GET['numCities'] !== '' ? (int) $_GET['numCities'] : null;
    $stageFilter = trim((string) ($_GET['stage'] ?? ''));
    $qFilter = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $daysFilter = isset($_GET['days']) && $_GET['days'] !== '' ? (int) $_GET['days'] : null;
    $minDaysFilter = isset($_GET['minDays']) && $_GET['minDays'] !== '' ? (int) $_GET['minDays'] : null;
    $maxDaysFilter = isset($_GET['maxDays']) && $_GET['maxDays'] !== '' ? (int) $_GET['maxDays'] : null;
    $routeFilter = trim((string) ($_GET['route'] ?? ''));
    // ?routes= (plural) is a comma-list of exact route strings, "matches
    // ANY of them" — used by the routes table's city-set grouping mode,
    // where one group can contain several distinct orderings ("Sydney>GC>
    // Cairns" and "Sydney>Cairns>GC") and drilling into the GROUP should
    // show itineraries from every ordering underneath it, not just one.
    $routesFilter = array_filter(array_map('trim', explode(',', (string) ($_GET['routes'] ?? ''))));
    $routesFilterSet = array_fill_keys($routesFilter, true);
    $productFilter = trim((string) ($_GET['product'] ?? ''));
    $hotelFilter = trim((string) ($_GET['hotel'] ?? ''));
    $bandFilter = trim((string) ($_GET['band'] ?? ''));
    $monthFilter = trim((string) ($_GET['month'] ?? ''));
    // hasHotels=1: only quotes with at least one hotel line item recorded
    // (~22% of the cleaned dataset has none — transfer/day-trip-only quotes,
    // not a data-quality gap) — lets a caller exclude those explicitly.
    $hasHotelsFilter = isset($_GET['hasHotels']) && $_GET['hasHotels'] !== '' && $_GET['hasHotels'] !== '0';
    // 'sic'/'mix'/'private'/'neither' — see quotes_transfer_style().
    $transferStyleFilter = trim((string) ($_GET['transferStyle'] ?? ''));
    // duplicatesOnly=1: keep ONLY quotes that are part of a duplicate group
    // (2+ quotes sharing the exact same day+product itinerary) — every
    // member of the group, canonical included, not just the flagged copies.
    $duplicatesOnlyFilter = isset($_GET['duplicatesOnly']) && $_GET['duplicatesOnly'] !== '' && $_GET['duplicatesOnly'] !== '0';
    // originalsOnly=1: the opposite of duplicatesOnly — drop every quote
    // FLAGGED as a duplicate, keep only canonicals. Needed so a caller that
    // wants "one card per distinct itinerary" gets a clean, consistently-
    // sized page (fetching a fixed page of mixed originals+duplicates and
    // filtering client-side made page sizes vary — some pages had fewer
    // cards than others depending on how many duplicates happened to land
    // on that page).
    $originalsOnlyFilter = isset($_GET['originalsOnly']) && $_GET['originalsOnly'] !== '' && $_GET['originalsOnly'] !== '0';
    // light=1: skip loading quotes_clean/ entirely â€” only metadata fields
    // (quote_no/route/total_days/trip_band/states_touched), for callers
    // (e.g. an inline "quotes matching this filter" preview list) that don't
    // need full products/hotels and want the fastest possible response.
    $light = isset($_GET['light']) && $_GET['light'] !== '' && $_GET['light'] !== '0';

    $cleanDir = __DIR__ . '/quotes_clean';
    $metaDir  = __DIR__ . '/quotes_metadata';
    $files = glob($metaDir . '/*.json') ?: [];
    sort($files);

    // Pass 1: filter using ONLY the small metadata files (states_touched,
    // days_per_city's keys as the city list, quotestage, quote_no) â€” never
    // opens the much larger quotes_clean/ file during filtering. This is
    // the fix for a real ~10s response time the naive "read every full
    // quote to filter" version had; metadata files are a fraction of the
    // size and contain everything needed to filter without touching the
    // full product/hotel data at all.
    $matchingQuoteNos = [];
    $duplicateOfByQuoteNo = [];
    $isDuplicateByQuoteNo = [];
    foreach ($files as $file) {
        $quoteNo = basename($file, '.json');
        if ($qFilter !== '' && mb_strpos(mb_strtolower($quoteNo), $qFilter) === false) {
            continue;
        }
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        if ($stageFilter !== '' && ($meta->quotestage ?? '') !== $stageFilter) {
            continue;
        }
        if ($stateFilters) {
            $touchesAllSelected = !array_diff($stateFilters, $meta->states_touched ?? []);
            $touchesAnySelected = (bool) array_intersect($stateFilters, $meta->states_touched ?? []);
            $stateOk = $stateMode === 'and' ? $touchesAllSelected : $touchesAnySelected;
            if (!$stateOk) {
                continue;
            }
        }
        if ($daysFilter !== null && (int) ($meta->total_days ?? -1) !== $daysFilter) {
            continue;
        }
        if ($minDaysFilter !== null && (int) ($meta->total_days ?? 0) < $minDaysFilter) {
            continue;
        }
        if ($maxDaysFilter !== null && (int) ($meta->total_days ?? 0) > $maxDaysFilter) {
            continue;
        }
        if ($bandFilter !== '' && ($meta->trip_band ?? '') !== $bandFilter) {
            continue;
        }
        if ($transferStyleFilter !== '' && quotes_transfer_style($meta) !== $transferStyleFilter) {
            continue;
        }
        if ($routeFilter !== '' && ($meta->route ?? null) !== $routeFilter) {
            continue;
        }
        if ($routesFilterSet && !isset($routesFilterSet[$meta->route ?? ''])) {
            continue;
        }
        if ($categoryFilters) {
            $rawCatMap = $meta->category_mix ?? [];
            $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            if (!array_intersect($categoryFilters, array_keys($catMap))) {
                continue;
            }
        }
        if ($productFilter !== '' && !in_array($productFilter, $meta->product_names ?? [], true)) {
            continue;
        }
        if ($hotelFilter !== '' && !in_array($hotelFilter, $meta->hotel_names ?? [], true)) {
            continue;
        }
        if ($hasHotelsFilter && (int) ($meta->total_hotels ?? 0) === 0) {
            continue;
        }
        if ($cityFilterKey !== null) {
            // json_decode() without the assoc flag returns an EMPTY JSON
            // object ("{}") as a plain array (PHP can't tell an empty
            // object from an empty array), but a non-empty one as
            // stdClass â€” so days_per_city can legitimately be either type
            // depending on whether this quote touched any city at all.
            $rawCityMap = $meta->days_per_city ?? [];
            $cityNames = is_array($rawCityMap) ? array_keys($rawCityMap) : array_keys(get_object_vars($rawCityMap));
            $cities = array_map('mb_strtolower', $cityNames);
            if (!in_array($cityFilterKey, $cities, true)) {
                continue;
            }
        }
        if (!quotes_city_rules_match($cityRules, $meta)) {
            continue;
        }
        if ($numCitiesFilter !== null) {
            $distinctCities = (int) ($meta->distinct_cities ?? 0);
            $numCitiesOk = $numCitiesMode === 'exactly'
                ? $distinctCities === $numCitiesFilter
                : $distinctCities >= $numCitiesFilter;
            if (!$numCitiesOk) {
                continue;
            }
        }
        if ($monthFilter !== '') {
            // Travel month isn't in the metadata files (it comes from
            // parsing hotel check-in dates), so this is the one filter here
            // that has to open the full quote â€” only paid for quotes that
            // already survived every other, cheaper check above.
            $fullQuote = json_decode(file_get_contents($cleanDir . '/' . $quoteNo . '.json'));
            if (!is_object($fullQuote) || quotes_travel_month($fullQuote) !== $monthFilter) {
                continue;
            }
        }
        $matchingQuoteNos[] = $quoteNo;
        $duplicateOfByQuoteNo[$quoteNo] = $meta->duplicate_of ?? $quoteNo;
        $isDuplicateByQuoteNo[$quoteNo] = $meta->is_duplicate_itinerary ?? false;
    }

    // Group each duplicate right next to its canonical quote instead of
    // wherever it happens to fall in quote_no order — duplicates are
    // usually created much later than the original, so without this a
    // quote and its duplicates could be dozens of pages apart, making it
    // impossible to see "how many duplicates does this itinerary have"
    // without paging through the whole result set.
    $groupSizeByCanonical = [];
    foreach ($matchingQuoteNos as $qno) {
        $canonical = $duplicateOfByQuoteNo[$qno];
        $groupSizeByCanonical[$canonical] = ($groupSizeByCanonical[$canonical] ?? 0) + 1;
    }

    if ($duplicatesOnlyFilter) {
        $matchingQuoteNos = array_values(array_filter(
            $matchingQuoteNos,
            fn ($qno) => $groupSizeByCanonical[$duplicateOfByQuoteNo[$qno]] >= 2
        ));
    }
    if ($originalsOnlyFilter) {
        $matchingQuoteNos = array_values(array_filter(
            $matchingQuoteNos,
            fn ($qno) => !$isDuplicateByQuoteNo[$qno]
        ));
    }

    // Sort: groups with 2+ quotes (real duplicate clusters) first, so they
    // surface immediately without the user having to dig through single-
    // quote results to find them; within that, group by (canonical
    // quote_no, own quote_no) so every group's members sit together.
    usort($matchingQuoteNos, function ($a, $b) use ($duplicateOfByQuoteNo, $groupSizeByCanonical) {
        $canonicalA = $duplicateOfByQuoteNo[$a];
        $canonicalB = $duplicateOfByQuoteNo[$b];
        $isDupGroupA = $groupSizeByCanonical[$canonicalA] >= 2 ? 0 : 1;
        $isDupGroupB = $groupSizeByCanonical[$canonicalB] >= 2 ? 0 : 1;
        return [$isDupGroupA, $canonicalA, $a] <=> [$isDupGroupB, $canonicalB, $b];
    });

    $totalMatching = count($matchingQuoteNos);
    // Split for the UI: totalMatching is originals + duplicates combined —
    // shown on its own that number reads as "N distinct itineraries", which
    // is wrong whenever any duplicates are present. Callers should show
    // totalOriginals ("N itineraries") and totalDuplicates ("M duplicates")
    // separately rather than just totalMatching.
    $totalDuplicates = count(array_filter($matchingQuoteNos, fn ($qno) => $isDuplicateByQuoteNo[$qno]));
    $totalOriginals = $totalMatching - $totalDuplicates;

    // Pass 2: only NOW read the full quotes_clean/ file (products/hotels),
    // and only for the quotes actually being returned â€” the whole page for
    // an unpaginated dump, or just the current page's slice otherwise.
    $toLoad = $page === null ? $matchingQuoteNos : array_slice($matchingQuoteNos, ($page - 1) * $pageSize, $pageSize);
    $result = [];
    foreach ($toLoad as $quoteNo) {
        $meta = json_decode(file_get_contents($metaDir . '/' . $quoteNo . '.json'));
        if (!is_object($meta)) {
            continue;
        }
        if ($light) {
            $result[] = [
                'quote_no' => $meta->quote_no ?? $quoteNo,
                'quotestage' => $meta->quotestage ?? '',
                'total_days' => $meta->total_days ?? null,
                'trip_band' => $meta->trip_band ?? null,
                'route' => $meta->route ?? null,
                'states_touched' => $meta->states_touched ?? [],
                'total_products' => $meta->total_products ?? 0,
                'total_hotels' => $meta->total_hotels ?? 0,
                'duplicate_of' => $meta->duplicate_of ?? ($meta->quote_no ?? $quoteNo),
                'is_duplicate_itinerary' => $meta->is_duplicate_itinerary ?? false,
                'has_metadata' => true,
            ];
            continue;
        }
        $obj = json_decode(file_get_contents($cleanDir . '/' . $quoteNo . '.json'));
        if (!is_object($obj)) {
            continue;
        }
        $obj->total_days = $meta->total_days ?? null;
        $obj->trip_band = $meta->trip_band ?? null;
        $obj->route = $meta->route ?? null;
        $obj->states_touched = $meta->states_touched ?? [];
        // Embedded so the itinerary card can show this directly instead of
        // needing a separate quotes/metadata/{quote_no} fetch per card —
        // $meta is already loaded here for the fields above, this is free.
        $obj->category_mix = $meta->category_mix ?? new stdClass();
        $obj->days_per_city = $meta->days_per_city ?? new stdClass();
        $obj->days_per_state = $meta->days_per_state ?? new stdClass();
        $obj->distinct_cities = $meta->distinct_cities ?? 0;
        $obj->distinct_products = $meta->distinct_products ?? 0;
        $obj->distinct_hotels = $meta->distinct_hotels ?? 0;
        $obj->has_metadata = true;
        $result[] = $obj;
    }

    if ($page === null) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    echo json_encode([
        'quotes' => $result,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalMatching' => $totalMatching,
        'totalOriginals' => $totalOriginals,
        'totalDuplicates' => $totalDuplicates,
        'totalPages' => (int) ceil($totalMatching / $pageSize),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * New endpoint for the standalone quotes_dashboard.html Quotes tab: the
 * PRIMARY table in its filter -> routes -> itineraries drill-down. Applies
 * the same sidebar filters as quotes_export_clean() (state/city/category/
 * minDays/maxDays), then groups the matching quotes by their exact ordered
 * route (same route string quotes_templates()/quotes_itinerary_patterns()
 * already use — direction matters, "A > B" and "B > A" are different
 * rows), returning one row per distinct route with how many itineraries
 * follow it and how many of those are duplicates of each other.
 *
 * Rationale (per the product owner): a first-time visitor to Australia
 * doesn't know city names well enough to search for them directly — they
 * need to see WHICH city combinations exist for their filters before
 * picking one, rather than being shown a flat list of itineraries with
 * unfamiliar city names sprinkled through it.
 *
 * Metadata-only — never opens quotes_clean/, same fast-filter pattern as
 * every other endpoint in this file.
 */
function quotes_routes(): void
{
    // state/category are comma-separated lists — "matches ANY of these" by
    // default (e.g. state=VIC,NSW means "touches VIC OR NSW"); ?stateMode=and
    // switches it to "touches VIC AND NSW" instead.
    $stateFilters = array_filter(array_map('trim', explode(',', mb_strtoupper((string) ($_GET['state'] ?? '')))));
    $stateMode = ($_GET['stateMode'] ?? '') === 'and' ? 'and' : 'or';
    $categoryFilters = array_filter(array_map('trim', explode(',', (string) ($_GET['category'] ?? ''))));
    $cityFilter = quotes_norm_city((string) ($_GET['city'] ?? ''));
    $cityFilterKey = $cityFilter !== null ? mb_strtolower($cityFilter) : null;
    // One or more {city, position, days} rules — see quotes_parse_city_rules().
    // Each rule is checked independently, so "Sydney first" and "Melbourne
    // last" can both be required on the same route at once.
    $cityRules = quotes_parse_city_rules();
    $minDaysFilter = isset($_GET['minDays']) && $_GET['minDays'] !== '' ? (int) $_GET['minDays'] : null;
    $maxDaysFilter = isset($_GET['maxDays']) && $_GET['maxDays'] !== '' ? (int) $_GET['maxDays'] : null;
    $hasHotelsFilter = isset($_GET['hasHotels']) && $_GET['hasHotels'] !== '' && $_GET['hasHotels'] !== '0';
    // Number of distinct cities on the route — 'atleast' or 'exactly'.
    $numCitiesMode = trim((string) ($_GET['numCitiesMode'] ?? ''));
    $numCitiesFilter = isset($_GET['numCities']) && $_GET['numCities'] !== '' ? (int) $_GET['numCities'] : null;
    // 'sic'/'mix'/'private'/'neither' — see quotes_transfer_style(). Empty
    // = no filter (all four styles included, matching current behavior).
    $transferStyleFilter = trim((string) ($_GET['transferStyle'] ?? ''));
    // groupBy=cityset: group by the SET of cities touched, ignoring visit
    // order and repeat visits — "Sydney > Gold Coast > Cairns" and
    // "Sydney > Cairns > Gold Coast" become one group (per the product
    // owner: these are "the same trip" for planning purposes, direction
    // doesn't matter). Default ('' / anything else) keeps the existing
    // exact-ordered-route grouping unchanged — this is an ADDITIVE mode,
    // not a replacement, so existing callers are unaffected.
    $groupByCitySet = ($_GET['groupBy'] ?? '') === 'cityset';

    $metaDir = __DIR__ . '/quotes_metadata';
    $files = glob($metaDir . '/*.json') ?: [];

    // Exact-route mode (default): route => ['legs'=>[..], 'quoteCount'=>, 'duplicateCount'=>].
    // City-set mode: cityKey (sorted, deduped cities joined by '|') =>
    // ['cities'=>[sorted display names], 'orderings'=>[route => count], 'quoteCount'=>, 'duplicateCount'=>].
    $routeGroups = [];
    $totalMatching = 0;
    // Per-filter-option match counts, shown on each sidebar chip (e.g. "VIC
    // 412") so a user sees how many results an option would give BEFORE
    // clicking it — same idea as PatternsTab's stateCounts/categoryCounts.
    // Each count is computed against every OTHER active filter (days,
    // hasHotels, city) but deliberately ignores state/category filters
    // themselves, so a chip's own count never drops to 0 just because the
    // user already selected it (matches PatternsTab's documented reasoning).
    $stateCounts = [];
    $categoryCounts = [];
    // transferStyle => count of quotes matching that style, computed
    // against every OTHER active filter (same "ignore this chip's own
    // filter" reasoning as stateCounts/categoryCounts above) — so the
    // sidebar can show e.g. "SIC 412 / Mix 88 / Private 950 / Neither 12"
    // before the user picks one.
    $transferStyleCounts = ['sic' => 0, 'mix' => 0, 'private' => 0, 'neither' => 0];
    // productname/city => count of MATCHING quotes (post every filter)
    // featuring it — the "what's popular in this slice" summary shown
    // above the routes table, before any route is picked.
    $topProductCounts = [];
    $topCityCounts = [];

    foreach ($files as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        $passesDays = ($minDaysFilter === null || (int) ($meta->total_days ?? 0) >= $minDaysFilter)
            && ($maxDaysFilter === null || (int) ($meta->total_days ?? 0) <= $maxDaysFilter);
        $passesHotels = !$hasHotelsFilter || (int) ($meta->total_hotels ?? 0) > 0;
        $transferStyle = quotes_transfer_style($meta);
        $passesTransferStyle = $transferStyleFilter === '' || $transferStyle === $transferStyleFilter;
        $passesCity = true;
        if ($cityFilterKey !== null) {
            $rawCityMap = $meta->days_per_city ?? [];
            $cityNames = is_array($rawCityMap) ? array_keys($rawCityMap) : array_keys(get_object_vars($rawCityMap));
            $cities = array_map('mb_strtolower', $cityNames);
            $passesCity = in_array($cityFilterKey, $cities, true);
        }
        $passesCity = $passesCity && quotes_city_rules_match($cityRules, $meta);

        $passesNumCities = true;
        if ($numCitiesFilter !== null) {
            $distinctCities = (int) ($meta->distinct_cities ?? 0);
            $passesNumCities = $numCitiesMode === 'exactly'
                ? $distinctCities === $numCitiesFilter
                : $distinctCities >= $numCitiesFilter;
        }

        if ($passesDays && $passesHotels && $passesCity && $passesNumCities) {
            foreach ($meta->states_touched ?? [] as $state) {
                $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;
            }
            $rawCatMap = $meta->category_mix ?? [];
            $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            foreach (array_keys($catMap) as $cat) {
                $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
            }
            $transferStyleCounts[$transferStyle]++;
        }

        if ($stateFilters) {
            $touchesAllSelected = !array_diff($stateFilters, $meta->states_touched ?? []);
            $touchesAnySelected = (bool) array_intersect($stateFilters, $meta->states_touched ?? []);
            $stateOk = $stateMode === 'and' ? $touchesAllSelected : $touchesAnySelected;
            if (!$stateOk) {
                continue;
            }
        }
        if (!$passesDays || !$passesHotels || !$passesCity || !$passesNumCities || !$passesTransferStyle) {
            continue;
        }
        if ($categoryFilters) {
            $rawCatMap = $meta->category_mix ?? [];
            $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            if (!array_intersect($categoryFilters, array_keys($catMap))) {
                continue;
            }
        }
        $route = $meta->route ?? null;
        if ($route === null) {
            continue;
        }
        $totalMatching++;
        $isDup = $meta->is_duplicate_itinerary ?? false;

        // "Higher level" summary analytics shown ABOVE the routes table as
        // soon as any sidebar filter narrows the result — a distinct/
        // "select 10 days" or "select Sydney" shouldn't require picking a
        // route first to see what's popular in that slice. Metadata-only
        // (product_names/days_per_city are already precomputed per quote),
        // so this stays fast even for a broad filter that would be
        // expensive to compute live from quotes_clean/.
        foreach ($meta->product_names ?? [] as $name) {
            $topProductCounts[$name] = ($topProductCounts[$name] ?? 0) + 1;
        }
        $rawCityMapForTop = $meta->days_per_city ?? [];
        $cityNamesForTop = is_array($rawCityMapForTop) ? array_keys($rawCityMapForTop) : array_keys(get_object_vars($rawCityMapForTop));
        foreach ($cityNamesForTop as $name) {
            $topCityCounts[$name] = ($topCityCounts[$name] ?? 0) + 1;
        }

        if ($groupByCitySet) {
            $legs = explode(' > ', $route);
            // Dedup (a route can legitimately revisit a city — see the
            // Brisbane>GoldCoast>Brisbane "visits" filter work) then sort
            // for an order-independent key. Casing preserved for display
            // via the first-seen spelling; key itself is lowercased.
            $uniqueLegsByKey = [];
            foreach ($legs as $leg) {
                $uniqueLegsByKey[mb_strtolower($leg)] = $leg;
            }
            $sortedKeys = array_keys($uniqueLegsByKey);
            sort($sortedKeys);
            $groupKey = implode('|', $sortedKeys);
            if (!isset($routeGroups[$groupKey])) {
                $sortedDisplayNames = array_map(fn ($k) => $uniqueLegsByKey[$k], $sortedKeys);
                $routeGroups[$groupKey] = ['cities' => $sortedDisplayNames, 'orderings' => [], 'quoteCount' => 0, 'duplicateCount' => 0];
            }
            $routeGroups[$groupKey]['orderings'][$route] = ($routeGroups[$groupKey]['orderings'][$route] ?? 0) + 1;
            $routeGroups[$groupKey]['quoteCount']++;
            if ($isDup) {
                $routeGroups[$groupKey]['duplicateCount']++;
            }
        } else {
            if (!isset($routeGroups[$route])) {
                $routeGroups[$route] = ['legs' => explode(' > ', $route), 'quoteCount' => 0, 'duplicateCount' => 0];
            }
            $routeGroups[$route]['quoteCount']++;
            if ($isDup) {
                // Counts quotes FLAGGED as a duplicate (matches the "Duplicate
                // of #X" badge shown on the itinerary card) — not the canonical
                // quote each of them points back to, so this number is "how
                // many extra copies of an itinerary exist on this route", not
                // "how many itineraries on this route have some duplicate".
                $routeGroups[$route]['duplicateCount']++;
            }
        }
    }

    $routes = [];
    foreach ($routeGroups as $key => $g) {
        // Naming is deliberate: totalCount is EVERY quote on this route
        // (originals + duplicates). originalCount + duplicateCount always
        // add up to totalCount — showing totalCount alone under a plain
        // "itineraries" label was confusing since it silently included
        // duplicates as if they were distinct trips.
        if ($groupByCitySet) {
            $orderings = [];
            foreach ($g['orderings'] as $orderingRoute => $count) {
                $orderings[] = ['route' => $orderingRoute, 'count' => $count];
            }
            usort($orderings, fn ($a, $b) => $b['count'] <=> $a['count']);
            $routes[] = [
                'route' => implode(' + ', $g['cities']), // order-independent display label
                'cities' => $g['cities'],
                'legs' => $g['cities'],
                'orderings' => $orderings,
                'totalCount' => $g['quoteCount'],
                'originalCount' => $g['quoteCount'] - $g['duplicateCount'],
                'duplicateCount' => $g['duplicateCount'],
            ];
        } else {
            $routes[] = [
                'route' => $key,
                'legs' => $g['legs'],
                'totalCount' => $g['quoteCount'],
                'originalCount' => $g['quoteCount'] - $g['duplicateCount'],
                'duplicateCount' => $g['duplicateCount'],
            ];
        }
    }

    usort($routes, fn ($a, $b) => $b['totalCount'] <=> $a['totalCount']);

    ksort($stateCounts);
    arsort($categoryCounts);

    $totalDuplicates = array_sum(array_column($routes, 'duplicateCount'));

    arsort($topProductCounts);
    $topProducts = [];
    $i = 0;
    foreach ($topProductCounts as $name => $count) {
        if ($i++ >= 10) {
            break;
        }
        $topProducts[] = ['productname' => $name, 'quotes' => $count];
    }
    arsort($topCityCounts);
    $topCities = [];
    $i = 0;
    foreach ($topCityCounts as $name => $count) {
        if ($i++ >= 10) {
            break;
        }
        $topCities[] = ['city' => $name, 'quotes' => $count];
    }
    // The 10 routes themselves, ranked by match count — same $routes array
    // the table below already shows, just capped/renamed here for a
    // "top 10 routes for this slice" summary card above the table.
    $topRoutes = array_slice($routes, 0, 10);

    echo json_encode([
        'routeCount' => count($routes),
        'totalMatching' => $totalMatching,
        'totalOriginals' => $totalMatching - $totalDuplicates,
        'totalDuplicates' => $totalDuplicates,
        'routes' => $routes,
        'stateCounts' => $stateCounts,
        'categoryCounts' => $categoryCounts,
        'transferStyleCounts' => $transferStyleCounts,
        'topRoutes' => $topRoutes,
        'topProducts' => $topProducts,
        'topCities' => $topCities,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_route_summary() — "what does a typical trip on THIS route look
 * like" analytics, shown once a route (or, in cityset grouping mode, a
 * route-GROUP) is selected. Two numbers per city:
 *   avgDaysThisRoute    — average days_per_city value for that city,
 *                         across only the itineraries matching the
 *                         selected route(s) + active sidebar filters.
 *   avgDaysAllRoutes    — the SAME city's average days_per_city value
 *                         across EVERY quote in the whole dataset that
 *                         touches that city at all (any route, any other
 *                         filter) — a fixed baseline to compare against,
 *                         e.g. "this route spends 2 more days in Sydney
 *                         than the typical Sydney-touching trip".
 * Selects itineraries the same way quotes/export-clean does: ?route=
 * (single exact route) or ?routes= (comma-list, for a cityset group's
 * combined orderings) — same sidebar filters (state/cityRules/category/
 * minDays/maxDays/hasHotels/numCities) also narrow which quotes count.
 */
function quotes_route_summary(): void
{
    $routeFilter = trim((string) ($_GET['route'] ?? ''));
    $routesFilter = array_filter(array_map('trim', explode(',', (string) ($_GET['routes'] ?? ''))));
    $routesFilterSet = array_fill_keys($routesFilter, true);
    if ($routeFilter === '' && !$routesFilterSet) {
        http_response_code(400);
        echo json_encode(['error' => 'route or routes is required']);
        return;
    }

    $stateFilters = array_filter(array_map('trim', explode(',', mb_strtoupper((string) ($_GET['state'] ?? '')))));
    $stateMode = ($_GET['stateMode'] ?? '') === 'and' ? 'and' : 'or';
    $categoryFilters = array_filter(array_map('trim', explode(',', (string) ($_GET['category'] ?? ''))));
    $cityRules = quotes_parse_city_rules();
    $minDaysFilter = isset($_GET['minDays']) && $_GET['minDays'] !== '' ? (int) $_GET['minDays'] : null;
    $maxDaysFilter = isset($_GET['maxDays']) && $_GET['maxDays'] !== '' ? (int) $_GET['maxDays'] : null;
    $hasHotelsFilter = isset($_GET['hasHotels']) && $_GET['hasHotels'] !== '' && $_GET['hasHotels'] !== '0';
    $numCitiesMode = trim((string) ($_GET['numCitiesMode'] ?? ''));
    $numCitiesFilter = isset($_GET['numCities']) && $_GET['numCities'] !== '' ? (int) $_GET['numCities'] : null;
    $transferStyleFilter = trim((string) ($_GET['transferStyle'] ?? ''));

    $metaDir = __DIR__ . '/quotes_metadata';
    $files = glob($metaDir . '/*.json') ?: [];

    // This-route sums: cityKey => ['name'=>display, 'sum'=>, 'count'=>]
    // (count = how many of THIS route's quotes touch that city — not
    // every quote, since not every city in the group appears in every
    // quote when routes= combines multiple orderings of different sets... but
    // in practice a cityset group's orderings all share the same city SET,
    // so every quote should touch every city; kept as a real per-city
    // count rather than assumed for correctness/robustness).
    $thisRouteSums = [];
    // All-dataset baseline sums, keyed the same way, ignoring route/sidebar
    // filters entirely — computed in the SAME pass for efficiency (single
    // file read), just gated on a different (much looser) condition.
    $baselineSums = [];
    $matchingQuoteCount = 0;

    foreach ($files as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        $rawCityMap = $meta->days_per_city ?? [];
        $cityDaysMap = is_array($rawCityMap) ? [] : get_object_vars($rawCityMap);

        // Baseline: every quote, unconditionally, contributes to the
        // dataset-wide per-city average — this is deliberately NOT scoped
        // to the selected route's cities only, so it stays a stable
        // reference figure fetched once per city as needed below.
        foreach ($cityDaysMap as $city => $days) {
            $cityKey = mb_strtolower($city);
            if (!isset($baselineSums[$cityKey])) {
                $baselineSums[$cityKey] = ['name' => $city, 'sum' => 0, 'count' => 0];
            }
            $baselineSums[$cityKey]['sum'] += (int) $days;
            $baselineSums[$cityKey]['count']++;
        }

        // This-route: only quotes matching the selected route(s) AND every
        // active sidebar filter (same checks as quotes_routes()).
        $route = $meta->route ?? null;
        if ($route === null) {
            continue;
        }
        if ($routeFilter !== '' && $route !== $routeFilter) {
            continue;
        }
        if ($routesFilterSet && !isset($routesFilterSet[$route])) {
            continue;
        }
        $passesDays = ($minDaysFilter === null || (int) ($meta->total_days ?? 0) >= $minDaysFilter)
            && ($maxDaysFilter === null || (int) ($meta->total_days ?? 0) <= $maxDaysFilter);
        if (!$passesDays) {
            continue;
        }
        if ($hasHotelsFilter && (int) ($meta->total_hotels ?? 0) === 0) {
            continue;
        }
        if ($transferStyleFilter !== '' && quotes_transfer_style($meta) !== $transferStyleFilter) {
            continue;
        }
        if ($stateFilters) {
            $touchesAllSelected = !array_diff($stateFilters, $meta->states_touched ?? []);
            $touchesAnySelected = (bool) array_intersect($stateFilters, $meta->states_touched ?? []);
            $stateOk = $stateMode === 'and' ? $touchesAllSelected : $touchesAnySelected;
            if (!$stateOk) {
                continue;
            }
        }
        if ($categoryFilters) {
            $rawCatMap = $meta->category_mix ?? [];
            $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            if (!array_intersect($categoryFilters, array_keys($catMap))) {
                continue;
            }
        }
        if (!quotes_city_rules_match($cityRules, $meta)) {
            continue;
        }
        if ($numCitiesFilter !== null) {
            $distinctCities = (int) ($meta->distinct_cities ?? 0);
            $numCitiesOk = $numCitiesMode === 'exactly'
                ? $distinctCities === $numCitiesFilter
                : $distinctCities >= $numCitiesFilter;
            if (!$numCitiesOk) {
                continue;
            }
        }

        $matchingQuoteCount++;
        foreach ($cityDaysMap as $city => $days) {
            $cityKey = mb_strtolower($city);
            if (!isset($thisRouteSums[$cityKey])) {
                $thisRouteSums[$cityKey] = ['name' => $city, 'sum' => 0, 'count' => 0];
            }
            $thisRouteSums[$cityKey]['sum'] += (int) $days;
            $thisRouteSums[$cityKey]['count']++;
        }
    }

    $cities = [];
    foreach ($thisRouteSums as $cityKey => $s) {
        $baseline = $baselineSums[$cityKey] ?? null;
        $cities[] = [
            'city' => $s['name'],
            'avgDaysThisRoute' => $s['count'] ? round($s['sum'] / $s['count'], 1) : 0,
            'quotesInThisRoute' => $s['count'],
            'avgDaysAllRoutes' => $baseline && $baseline['count'] ? round($baseline['sum'] / $baseline['count'], 1) : null,
            'quotesAllRoutes' => $baseline ? $baseline['count'] : 0,
        ];
    }
    usort($cities, fn ($a, $b) => $b['avgDaysThisRoute'] <=> $a['avgDaysThisRoute']);

    echo json_encode([
        'matchingQuoteCount' => $matchingQuoteCount,
        'cities' => $cities,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_route_similarity() — groups NEAR-duplicate itineraries (product
 * sets that overlap heavily, not necessarily identical) within one
 * selected route/route-group. Distinct from is_duplicate_itinerary (exact
 * day+product signature match, computed once by clean_quotes.php) — this
 * catches quotes that are "basically the same trip" with minor product
 * swaps, e.g. 9 of 10 products match but one attraction differs.
 *
 * Similarity metric (confirmed with product owner, 2026): Jaccard index of
 * each quote's DISTINCT product set, using the same normalized name-key
 * merging as quotes_norm_name_key() elsewhere in this app (so "Novotel
 * Melbourne" and "Novotel Melbourne with Breakfast" count as the same
 * product if they'd already be merged anywhere else in the dashboard).
 * Threshold is 80% by default — a deliberately named constant below, not
 * hardcoded inline, since the product owner explicitly expects to tune
 * this number later.
 *
 * Clustering: greedy, NOT transitive closure — if A~B >=80% and B~C >=80%
 * but A~C <80%, A/B/C do NOT all become one cluster. Each quote is
 * assigned to the FIRST cluster (in file order) whose representative it's
 * >=80% similar to; a quote that doesn't match any existing cluster
 * becomes a new cluster's representative. This is a real simplification —
 * a stricter mutual/clique-based grouping would be more "correct" but is
 * unnecessary complexity for a first version; flagged here for awareness
 * if the product owner later wants stricter grouping.
 *
 * Deliberately scoped to ONE route/route-group's own quotes (not
 * dataset-wide) — same ?route=/?routes=/sidebar-filter selection as
 * quotes_route_summary() — since comparing every quote against every
 * other quote in the whole 3,760-quote dataset is O(n^2) and would be far
 * too slow; within one route's typically-dozens-of-quotes set, it's cheap.
 */
function quotes_route_similarity(): void
{
    $SIMILARITY_THRESHOLD = 0.8; // tune here if the product owner wants a different cutoff

    $routeFilter = trim((string) ($_GET['route'] ?? ''));
    $routesFilter = array_filter(array_map('trim', explode(',', (string) ($_GET['routes'] ?? ''))));
    $routesFilterSet = array_fill_keys($routesFilter, true);
    if ($routeFilter === '' && !$routesFilterSet) {
        http_response_code(400);
        echo json_encode(['error' => 'route or routes is required']);
        return;
    }

    $stateFilters = array_filter(array_map('trim', explode(',', mb_strtoupper((string) ($_GET['state'] ?? '')))));
    $stateMode = ($_GET['stateMode'] ?? '') === 'and' ? 'and' : 'or';
    $categoryFilters = array_filter(array_map('trim', explode(',', (string) ($_GET['category'] ?? ''))));
    $cityRules = quotes_parse_city_rules();
    $minDaysFilter = isset($_GET['minDays']) && $_GET['minDays'] !== '' ? (int) $_GET['minDays'] : null;
    $maxDaysFilter = isset($_GET['maxDays']) && $_GET['maxDays'] !== '' ? (int) $_GET['maxDays'] : null;
    $hasHotelsFilter = isset($_GET['hasHotels']) && $_GET['hasHotels'] !== '' && $_GET['hasHotels'] !== '0';
    $numCitiesMode = trim((string) ($_GET['numCitiesMode'] ?? ''));
    $numCitiesFilter = isset($_GET['numCities']) && $_GET['numCities'] !== '' ? (int) $_GET['numCities'] : null;
    $transferStyleFilter = trim((string) ($_GET['transferStyle'] ?? ''));

    $metaDir = __DIR__ . '/quotes_metadata';
    $files = glob($metaDir . '/*.json') ?: [];
    sort($files);

    // quoteNo => ['route'=>, 'productKeys'=>Set(normalized name-key), 'isDup'=>bool, 'duplicateOf'=>]
    $matching = [];

    foreach ($files as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        $route = $meta->route ?? null;
        if ($route === null) {
            continue;
        }
        if ($routeFilter !== '' && $route !== $routeFilter) {
            continue;
        }
        if ($routesFilterSet && !isset($routesFilterSet[$route])) {
            continue;
        }
        $passesDays = ($minDaysFilter === null || (int) ($meta->total_days ?? 0) >= $minDaysFilter)
            && ($maxDaysFilter === null || (int) ($meta->total_days ?? 0) <= $maxDaysFilter);
        if (!$passesDays) {
            continue;
        }
        if ($hasHotelsFilter && (int) ($meta->total_hotels ?? 0) === 0) {
            continue;
        }
        if ($transferStyleFilter !== '' && quotes_transfer_style($meta) !== $transferStyleFilter) {
            continue;
        }
        if ($stateFilters) {
            $touchesAllSelected = !array_diff($stateFilters, $meta->states_touched ?? []);
            $touchesAnySelected = (bool) array_intersect($stateFilters, $meta->states_touched ?? []);
            $stateOk = $stateMode === 'and' ? $touchesAllSelected : $touchesAnySelected;
            if (!$stateOk) {
                continue;
            }
        }
        if ($categoryFilters) {
            $rawCatMap = $meta->category_mix ?? [];
            $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            if (!array_intersect($categoryFilters, array_keys($catMap))) {
                continue;
            }
        }
        if (!quotes_city_rules_match($cityRules, $meta)) {
            continue;
        }
        if ($numCitiesFilter !== null) {
            $distinctCities = (int) ($meta->distinct_cities ?? 0);
            $numCitiesOk = $numCitiesMode === 'exactly'
                ? $distinctCities === $numCitiesFilter
                : $distinctCities >= $numCitiesFilter;
            if (!$numCitiesOk) {
                continue;
            }
        }

        $quoteNo = $meta->quote_no ?? basename($file, '.json');
        // Keyed by normalized name-key (for the Jaccard set math), but ALSO
        // keeps one real display name per key — needed to show a human-
        // readable "what's different" diff between two similar quotes,
        // not just a similarity percentage.
        $productKeys = [];
        $productDisplayByKey = [];
        foreach ($meta->product_names ?? [] as $name) {
            $key = quotes_norm_name_key($name);
            $productKeys[$key] = true;
            if (!isset($productDisplayByKey[$key])) {
                $productDisplayByKey[$key] = $name;
            }
        }
        $matching[$quoteNo] = [
            'quoteNo' => $quoteNo,
            'route' => $route,
            'totalDays' => $meta->total_days ?? null,
            'productKeys' => $productKeys,
            'productDisplayByKey' => $productDisplayByKey,
            'isDup' => $meta->is_duplicate_itinerary ?? false,
            'duplicateOf' => $meta->duplicate_of ?? $quoteNo,
        ];
    }

    $quoteNos = array_keys($matching);
    $n = count($quoteNos);
    $assignedTo = []; // quoteNo => cluster index
    $clusters = []; // index => ['representative'=>quoteNo, 'members'=>[quoteNo => similarity]]

    for ($i = 0; $i < $n; $i++) {
        $qnoA = $quoteNos[$i];
        if (isset($assignedTo[$qnoA])) {
            continue;
        }
        $clusterIndex = count($clusters);
        $clusters[$clusterIndex] = ['representative' => $qnoA, 'members' => []];
        $assignedTo[$qnoA] = $clusterIndex;
        $keysA = $matching[$qnoA]['productKeys'];

        for ($j = $i + 1; $j < $n; $j++) {
            $qnoB = $quoteNos[$j];
            if (isset($assignedTo[$qnoB])) {
                continue;
            }
            $keysB = $matching[$qnoB]['productKeys'];
            $intersectCount = count(array_intersect_key($keysA, $keysB));
            $unionCount = count($keysA) + count($keysB) - $intersectCount;
            $similarity = $unionCount > 0 ? $intersectCount / $unionCount : 1.0;
            if ($similarity >= $SIMILARITY_THRESHOLD) {
                $clusters[$clusterIndex]['members'][$qnoB] = round($similarity * 100, 1);
                $assignedTo[$qnoB] = $clusterIndex;
            }
        }
    }

    // Only clusters with 2+ quotes are "similar groups" — a cluster of 1
    // (no other quote met the threshold) isn't a grouping, just a quote on
    // its own; still returned so the caller has a complete picture of
    // every matching quote, but flagged via hasSimilar=false.
    $groups = [];
    foreach ($clusters as $c) {
        $rep = $matching[$c['representative']];
        $members = [];
        foreach ($c['members'] as $qno => $similarity) {
            $m = $matching[$qno];
            // What actually makes this quote DIFFERENT from the
            // representative — the real, human-readable signal at 80-99%
            // similarity (a bare percentage doesn't say what changed).
            // "missing" = rep has it, this one doesn't. "extra" = this one
            // has it, rep doesn't. Capped at 5 each so one heavily-
            // mismatched pair (still >=80% on a big product list) doesn't
            // produce a wall of text — the count beyond the cap is kept
            // so the UI can say "+ 2 more" instead of silently truncating.
            $missingKeys = array_diff_key($rep['productKeys'], $m['productKeys']);
            $extraKeys = array_diff_key($m['productKeys'], $rep['productKeys']);
            $missingNames = array_map(fn ($k) => $rep['productDisplayByKey'][$k], array_keys($missingKeys));
            $extraNames = array_map(fn ($k) => $m['productDisplayByKey'][$k], array_keys($extraKeys));
            $members[] = [
                'quoteNo' => $qno,
                'totalDays' => $m['totalDays'],
                'similarity' => $similarity,
                'isDuplicateItinerary' => $m['isDup'],
                'missingProducts' => array_slice($missingNames, 0, 5),
                'missingProductsTotal' => count($missingNames),
                'extraProducts' => array_slice($extraNames, 0, 5),
                'extraProductsTotal' => count($extraNames),
            ];
        }
        usort($members, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $groups[] = [
            'representative' => $rep['quoteNo'],
            'representativeTotalDays' => $rep['totalDays'],
            'hasSimilar' => count($members) > 0,
            'members' => $members,
        ];
    }
    usort($groups, fn ($a, $b) => count($b['members']) <=> count($a['members']));

    echo json_encode([
        'threshold' => $SIMILARITY_THRESHOLD,
        'matchingQuoteCount' => $n,
        'groupCount' => count($groups),
        'groupsWithSimilarCount' => count(array_filter($groups, fn ($g) => $g['hasSimilar'])),
        'groups' => $groups,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_analytics() — aggregate breakdowns across the cleaned quote
 * set, computed from quotes_metadata/ for everything except product
 * popularity and per-route product-combo uniqueness (those two need the
 * actual product names/day-by-day layout, so they read quotes_clean/ too â€”
 * still cheap since it's a single pass over <=3,847 small files).
 * Deliberately ignores quotestage throughout â€” these quotes were built
 * manually via questionnaires, not real customer transactions, so
 * accept/reject is not a meaningful signal here.
 *
 * Supports optional drill-down filters, so the frontend can click a bar in
 * one chart and see every OTHER panel recompute for just that slice:
 *   ?days=N        â€” only quotes whose total_days === N
 *   ?minDays=N     â€” only quotes whose total_days >= N
 *   ?city=Name     â€” only quotes that touch this city (case-insensitive)
 *   ?route=A>B>C   â€” only quotes whose exact route equals this string
 *
 * IMPORTANT: tripLengthHistogram, cityFrequency and stateFrequency are
 * ALWAYS computed over the FULL unfiltered dataset, deliberately ignoring
 * every filter above â€” these three are meant to be a stable reference list
 * you browse (e.g. "how many quotes touch Melbourne, in general"), not
 * numbers that silently redefine themselves depending on what else is
 * selected. Two concrete bugs this avoids: (1) filtering the trip-length
 * histogram BY exact day count collapsed it to one giant bar for that day
 * with every other bar gone, which read as "the chart broke"; (2) clicking
 * one city to filter recomputed every OTHER city's count as "how many
 * THIS-CITY quotes also touch that city" instead of that city's real
 * total, which is a different, confusing question. Everything else below
 * (categoryMix, routeUniqueness, dayCoverage, matching-quote count,
 * popularProducts) DOES respect every filter, since those ARE the "drill
 * into this slice" panels, not reference lists.
 *
 * Returns:
 *   totalQuotes           â€” count AFTER filters are applied
 *   totalQuotesUnfiltered â€” count before filters (for "X of Y" context)
 *   tripLengthHistogram   â€” [{days, count}] over ALL quotes, unfiltered by
 *                            day/city/route/category (see note above) â€” the
 *                            stable reference chart
 *   tripLengthHistogramFiltered â€” [{days, count}] SAME shape, but respects
 *                            city/route/category filters (just not the day
 *                            filters themselves, so it stays a clickable
 *                            multi-bar chart instead of collapsing to one
 *                            bar) â€” for a caller that wants "day breakdown
 *                            of my current non-day filters", e.g. Explore
 *   tripBandBreakdown     â€” [{band, count}], respects filters
 *   cityFrequency         â€” [{city, quotes, avgTripDays}] over ALL quotes,
 *                            unfiltered (see note above). avgTripDays is the
 *                            average TOTAL trip length (not days spent in
 *                            that city specifically) among quotes touching it
 *   stateFrequency        â€” same shape, per state, also always unfiltered
 *   categoryMix           â€” [{category, lines}], also always unfiltered,
 *                            same reasoning (a stable reference list, not a
 *                            drill-down slice)
 *   productVariety        â€” {avgProductsPerQuote, avgDistinctProductsPerQuote,
 *                            avgHotelsPerQuote, avgDistinctHotelsPerQuote}
 *   popularProducts       â€” [{productname, quotes}] top real bookable
 *                            products (Transfers category excluded â€” airport
 *                            pickups etc. are logistics, not a "popular
 *                            tour"), ranked by how many distinct quotes
 *                            include them at least once
 *   routeUniqueness       â€” {totalQuotes, distinctRoutes, topRoutes:
 *                            [{route, count}]} â€” repetition in the exact
 *                            CITY SEQUENCE (e.g. "Melbourne > Cairns >
 *                            Sydney" is one such route) â€” a breadth signal,
 *                            not a popularity ranking
 *   routeProductUniqueness â€” for each of the same top routes, how many
 *                            DISTINCT product/day combinations exist among
 *                            the quotes that follow it (e.g. 128 quotes
 *                            follow a route, but only 40 truly different
 *                            product mixes exist within it) â€” same city
 *                            sequence, different question (variety WITHIN
 *                            a route, not repetition OF routes)
 *   dayCoverage           â€” [{day, quotes}] "quotes running >= day N", for
 *                            a day-slider UI
 */
function quotes_clean_analytics(): void
{
    $metaDir = __DIR__ . '/quotes_metadata';
    $cleanDir = __DIR__ . '/quotes_clean';
    $files = glob($metaDir . '/*.json') ?: [];

    $daysFilter = isset($_GET['days']) && $_GET['days'] !== '' ? (int) $_GET['days'] : null;
    $minDaysFilter = isset($_GET['minDays']) && $_GET['minDays'] !== '' ? (int) $_GET['minDays'] : null;
    $cityFilter = quotes_norm_city((string) ($_GET['city'] ?? ''));
    $cityFilterKey = $cityFilter !== null ? mb_strtolower($cityFilter) : null;
    $routeFilter = trim((string) ($_GET['route'] ?? ''));
    $categoryFilter = trim((string) ($_GET['category'] ?? ''));
    $stateFilter = mb_strtoupper(trim((string) ($_GET['state'] ?? '')));
    $productFilter = trim((string) ($_GET['product'] ?? ''));
    $hotelFilter = trim((string) ($_GET['hotel'] ?? ''));
    $bandFilter = trim((string) ($_GET['band'] ?? ''));
    $monthFilter = trim((string) ($_GET['month'] ?? ''));

    $totalUnfiltered = 0;
    $total = 0;
    $tripLengthCounts = []; // days => count
    $tripBandCounts = []; // band => count
    $cityQuotes = []; // city => count of quotes touching it
    $cityTripDaysSum = []; // city => sum of the FULL quote's total_days, across quotes touching it (for an average)
    $stateQuotes = [];
    $stateTripDaysSum = [];
    $categoryLines = []; // category => total line count
    $routeCounts = []; // route string => count
    $sumProducts = 0;
    $sumHotels = 0;
    $sumDistinctProducts = 0;
    $sumDistinctHotels = 0;
    $matchingQuoteNos = [];

    // Track day counts unfiltered too â€” dayCoverage's "quotes running >= N"
    // slider question is separate from the histogram's "exact day" question,
    // and dayCoverage DOES still respect city/route/category filters (just
    // not the day filters themselves, for the same collapsing-chart reason).
    $tripLengthCountsUnfilteredByDays = []; // days => count, ignoring day filters but respecting city/route/category

    foreach ($files as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        $totalUnfiltered++;

        $days = $meta->total_days ?? null;
        $rawCityMap = $meta->days_per_city ?? [];
        $cityMap = is_array($rawCityMap) ? [] : get_object_vars($rawCityMap);
        $route = $meta->route ?? null;

        // tripLengthHistogram, cityFrequency and stateFrequency are ALWAYS
        // computed over every quote, completely ignoring every filter â€” see
        // the function docblock for why (they're a stable reference list,
        // not a drill-down slice).
        if ($days !== null) {
            $tripLengthCounts[$days] = ($tripLengthCounts[$days] ?? 0) + 1;
        }
        foreach ($cityMap as $city => $dayCount) {
            $cityQuotes[$city] = ($cityQuotes[$city] ?? 0) + 1;
            $cityTripDaysSum[$city] = ($cityTripDaysSum[$city] ?? 0) + (int) ($days ?? 0);
        }
        $rawStateMap = $meta->days_per_state ?? [];
        $stateMap = is_array($rawStateMap) ? [] : get_object_vars($rawStateMap);
        foreach ($stateMap as $state => $dayCount) {
            $stateQuotes[$state] = ($stateQuotes[$state] ?? 0) + 1;
            $stateTripDaysSum[$state] = ($stateTripDaysSum[$state] ?? 0) + (int) ($days ?? 0);
        }
        // categoryMix is also always-unfiltered, same reasoning as city/
        // state/histogram above â€” clicking "Tour" was recomputing every
        // OTHER category's count as "how many Tour-quotes also have this
        // category" instead of that category's real total.
        $rawCatMapAlways = $meta->category_mix ?? [];
        $catMapAlways = is_array($rawCatMapAlways) ? [] : get_object_vars($rawCatMapAlways);
        foreach ($catMapAlways as $cat => $lines) {
            $categoryLines[$cat] = ($categoryLines[$cat] ?? 0) + $lines;
        }

        // Everything below (matching count, band breakdown, routes,
        // dayCoverage, popular products) IS a real drill-down slice and
        // respects every filter, including city/route/category.
        $passesNonDayFilters = true;
        if ($cityFilterKey !== null) {
            $cities = array_map('mb_strtolower', array_keys($cityMap));
            if (!in_array($cityFilterKey, $cities, true)) {
                $passesNonDayFilters = false;
            }
        }
        if ($passesNonDayFilters && $stateFilter !== '' && !array_key_exists($stateFilter, $stateMap)) {
            $passesNonDayFilters = false;
        }
        if ($passesNonDayFilters && $bandFilter !== '' && ($meta->trip_band ?? '') !== $bandFilter) {
            $passesNonDayFilters = false;
        }
        if ($passesNonDayFilters && $routeFilter !== '' && $route !== $routeFilter) {
            $passesNonDayFilters = false;
        }
        if ($passesNonDayFilters && $categoryFilter !== '') {
            $rawCatMap = $meta->category_mix ?? [];
            $catMapForFilter = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
            if (!array_key_exists($categoryFilter, $catMapForFilter)) {
                $passesNonDayFilters = false;
            }
        }
        if ($passesNonDayFilters && $productFilter !== '' && !in_array($productFilter, $meta->product_names ?? [], true)) {
            $passesNonDayFilters = false;
        }
        if ($passesNonDayFilters && $hotelFilter !== '' && !in_array($hotelFilter, $meta->hotel_names ?? [], true)) {
            $passesNonDayFilters = false;
        }
        if ($passesNonDayFilters && $monthFilter !== '') {
            // Travel month isn't in metadata â€” only opens the full quote
            // file for quotes that already survived every cheaper check
            // above, same tradeoff as the product/hotel-name filters.
            $fullQuote = json_decode(file_get_contents($cleanDir . '/' . basename($file, '.json') . '.json'));
            if (!is_object($fullQuote) || quotes_travel_month($fullQuote) !== $monthFilter) {
                $passesNonDayFilters = false;
            }
        }
        if (!$passesNonDayFilters) {
            continue;
        }

        // tripLengthHistogramFiltered / dayCoverage respect city/route/
        // category (just checked above) but NOT the day filters below â€”
        // must be tallied here, BEFORE the day-filter continues, or this
        // "unfiltered by days" histogram silently collapses to whatever
        // day filter is active too (a real bug: it defeated the entire
        // point of this second histogram, which is to stay multi-bar and
        // clickable even while a day filter is selected).
        if ($days !== null) {
            $tripLengthCountsUnfilteredByDays[$days] = ($tripLengthCountsUnfilteredByDays[$days] ?? 0) + 1;
        }

        if ($daysFilter !== null && $days !== $daysFilter) {
            continue;
        }
        if ($minDaysFilter !== null && ((int) ($days ?? 0)) < $minDaysFilter) {
            continue;
        }

        $total++;
        $matchingQuoteNos[] = basename($file, '.json');

        $band = $meta->trip_band ?? 'Unknown';
        $tripBandCounts[$band] = ($tripBandCounts[$band] ?? 0) + 1;

        if ($route) {
            $routeCounts[$route] = ($routeCounts[$route] ?? 0) + 1;
        }

        $sumProducts += (int) ($meta->total_products ?? 0);
        $sumHotels += (int) ($meta->total_hotels ?? 0);
        $sumDistinctProducts += (int) ($meta->distinct_products ?? 0);
        $sumDistinctHotels += (int) ($meta->distinct_hotels ?? 0);
    }

    ksort($tripLengthCounts, SORT_NUMERIC);
    $tripLengthHistogram = [];
    foreach ($tripLengthCounts as $days => $count) {
        $tripLengthHistogram[] = ['days' => $days, 'count' => $count];
    }

    // tripLengthHistogramFiltered: DOES respect city/route/category filters
    // (unlike tripLengthHistogram above, which is always the full dataset)
    // but still ignores the day filters themselves, so clicking a bar in it
    // never collapses the chart to one bar â€” for callers like the Explore
    // tab that want "day breakdown of my current city/category/route
    // selection" as a genuinely filtered, still-clickable chart.
    ksort($tripLengthCountsUnfilteredByDays, SORT_NUMERIC);
    $tripLengthHistogramFiltered = [];
    foreach ($tripLengthCountsUnfilteredByDays as $days => $count) {
        $tripLengthHistogramFiltered[] = ['days' => $days, 'count' => $count];
    }

    $bandOrder = ['1-4d', '5-7d', '8-10d', '11-13d', '14-16d', '17d+', 'Unknown'];
    $tripBandBreakdown = [];
    foreach ($bandOrder as $band) {
        if (isset($tripBandCounts[$band])) {
            $tripBandBreakdown[] = ['band' => $band, 'count' => $tripBandCounts[$band]];
        }
    }

    $cityFrequency = [];
    foreach ($cityQuotes as $city => $count) {
        $cityFrequency[] = [
            'city' => $city,
            'quotes' => $count,
            'avgTripDays' => $count ? round(($cityTripDaysSum[$city] ?? 0) / $count, 1) : 0,
        ];
    }
    usort($cityFrequency, fn ($a, $b) => $b['quotes'] - $a['quotes']);

    $stateFrequency = [];
    foreach ($stateQuotes as $state => $count) {
        $stateFrequency[] = [
            'state' => $state,
            'quotes' => $count,
            'avgTripDays' => $count ? round(($stateTripDaysSum[$state] ?? 0) / $count, 1) : 0,
        ];
    }
    usort($stateFrequency, fn ($a, $b) => $b['quotes'] - $a['quotes']);

    $categoryMix = [];
    foreach ($categoryLines as $cat => $lines) {
        $categoryMix[] = ['category' => $cat, 'lines' => $lines];
    }
    usort($categoryMix, fn ($a, $b) => $b['lines'] - $a['lines']);

    arsort($routeCounts);
    $distinctRoutes = count($routeCounts);
    $topRoutes = [];
    $i = 0;
    foreach ($routeCounts as $route => $count) {
        if ($i++ >= 25) {
            break;
        }
        $topRoutes[] = ['route' => $route, 'count' => $count];
    }

    // dayCoverage: for a day-slider UI â€” "how many quotes have any activity
    // scheduled on day N" for every N from 1 up to the longest trip seen,
    // respecting city/route/category filters (like the matching count
    // does) but not the day filters themselves.
    $maxDay = $tripLengthCountsUnfilteredByDays ? max(array_keys($tripLengthCountsUnfilteredByDays)) : 0;
    $dayCoverage = [];
    if ($maxDay > 0) {
        $cumulative = 0;
        for ($d = $maxDay; $d >= 1; $d--) {
            $cumulative += $tripLengthCountsUnfilteredByDays[$d] ?? 0;
            $dayCoverage[$d] = $cumulative;
        }
        ksort($dayCoverage, SORT_NUMERIC);
        $out = [];
        foreach ($dayCoverage as $day => $quotes) {
            $out[] = ['day' => $day, 'quotes' => $quotes];
        }
        $dayCoverage = $out;
    }

    // popularProducts + routeProductUniqueness both need actual product
    // names/day layout, which only quotes_clean/ has. For the UNFILTERED
    // case (the common one â€” loading the tab fresh) this is precomputed by
    // generate_clean_analytics_cache.php and just read from disk here
    // (instant). Filters change the matching-quote subset, so the whole-
    // dataset cache would answer the wrong question â€” and a live per-quote
    // pass over the filtered subset is NOT reliably fast, since a broad
    // filter (e.g. category=Tour, which matches ~87% of quotes) still means
    // reading almost every full quote file (~19s, confirmed). Rather than
    // just disabling popularProducts under ANY filter, the cache also
    // carries popularProductsByCategory/ByCity/ByBand â€” precomputed slices
    // for each SINGLE filter value â€” so the common case of exactly one of
    // those three filters active still gets an instant precomputed answer.
    // Computing every possible COMBINATION (category+city+band+...) isn't
    // feasible (combinatorial blow-up), so 2+ of these filters combined (or
    // any of the filters this cache doesn't slice by â€” days/route/state/
    // product/hotel/month) still falls back to "not available".
    $noFiltersApplied = $daysFilter === null && $minDaysFilter === null && $cityFilterKey === null && $routeFilter === '' && $categoryFilter === '' && $stateFilter === '' && $productFilter === '' && $hotelFilter === '' && $bandFilter === '' && $monthFilter === '';
    $slicableFiltersActive = ($categoryFilter !== '' ? 1 : 0) + ($cityFilterKey !== null ? 1 : 0) + ($bandFilter !== '' ? 1 : 0);
    $onlyOneSlicableFilter = $slicableFiltersActive === 1
        && $daysFilter === null && $minDaysFilter === null && $routeFilter === '' && $stateFilter === '' && $productFilter === '' && $hotelFilter === '' && $monthFilter === '';
    $cacheFile = __DIR__ . '/quotes_clean_analytics_cache.json';
    $popularProducts = [];
    $routeProductUniqueness = [];
    $productMetricsAvailable = false;
    if (($noFiltersApplied || $onlyOneSlicableFilter) && is_file($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile));
        if (is_object($cache)) {
            if ($noFiltersApplied) {
                $popularProducts = $cache->popularProducts ?? [];
                $routeProductUniqueness = $cache->routeProductUniqueness ?? [];
                $productMetricsAvailable = true;
            } elseif ($categoryFilter !== '') {
                $byCategory = (array) ($cache->popularProductsByCategory ?? []);
                if (isset($byCategory[$categoryFilter])) {
                    $popularProducts = $byCategory[$categoryFilter];
                    $productMetricsAvailable = true;
                }
            } elseif ($cityFilterKey !== null) {
                $byCity = (array) ($cache->popularProductsByCity ?? []);
                if (isset($byCity[$cityFilterKey])) {
                    $popularProducts = $byCity[$cityFilterKey];
                    $productMetricsAvailable = true;
                }
            } elseif ($bandFilter !== '') {
                $byBand = (array) ($cache->popularProductsByBand ?? []);
                if (isset($byBand[$bandFilter])) {
                    $popularProducts = $byBand[$bandFilter];
                    $productMetricsAvailable = true;
                }
            }
        }
    }

    echo json_encode([
        'totalQuotes' => $total,
        'totalQuotesUnfiltered' => $totalUnfiltered,
        'tripLengthHistogram' => $tripLengthHistogram,
        'tripLengthHistogramFiltered' => $tripLengthHistogramFiltered,
        'tripBandBreakdown' => $tripBandBreakdown,
        'cityFrequency' => $cityFrequency,
        'stateFrequency' => $stateFrequency,
        'categoryMix' => $categoryMix,
        'productVariety' => [
            'avgProductsPerQuote' => $total ? round($sumProducts / $total, 1) : 0,
            'avgDistinctProductsPerQuote' => $total ? round($sumDistinctProducts / $total, 1) : 0,
            'avgHotelsPerQuote' => $total ? round($sumHotels / $total, 1) : 0,
            'avgDistinctHotelsPerQuote' => $total ? round($sumDistinctHotels / $total, 1) : 0,
        ],
        'popularProducts' => $popularProducts,
        'productMetricsAvailable' => $productMetricsAvailable,
        'routeUniqueness' => [
            'totalQuotes' => $total,
            'distinctRoutes' => $distinctRoutes,
            'topRoutes' => $topRoutes,
        ],
        'routeProductUniqueness' => $routeProductUniqueness,
        'dayCoverage' => $dayCoverage,
        'appliedFilters' => [
            'days' => $daysFilter,
            'minDays' => $minDaysFilter,
            'city' => $cityFilter,
            'state' => $stateFilter !== '' ? $stateFilter : null,
            'route' => $routeFilter !== '' ? $routeFilter : null,
            'category' => $categoryFilter !== '' ? $categoryFilter : null,
            'product' => $productFilter !== '' ? $productFilter : null,
            'hotel' => $hotelFilter !== '' ? $hotelFilter : null,
            'band' => $bandFilter !== '' ? $bandFilter : null,
            'month' => $monthFilter !== '' ? $monthFilter : null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_overview() â€” front-page KPI tiles + category pie-chart data
 * + a compact overall trip-length box plot, for the new standalone
 * "Overview" tab in quotes_itinerary_mock.html. Deliberately separate from
 * quotes_clean_analytics() above (which serves the deep-dive Analytics
 * tab's filterable charts) â€” this endpoint is unfiltered, fixed-shape, and
 * fast (metadata-only), meant to render instantly on first load without any
 * interaction. Entirely quotestage-free.
 */
function quotes_clean_overview(): void
{
    $files = glob(__DIR__ . '/quotes_metadata/*.json') ?: [];

    $totalQuotes = 0;
    $totalProducts = 0;
    $totalHotels = 0;
    $distinctProductsSum = 0;
    $distinctHotelsSum = 0;
    $categoryLines = []; // category => total line count
    $distinctCities = [];
    $distinctStates = [];
    $tripDays = [];

    foreach ($files as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        $totalQuotes++;
        $totalProducts += (int) ($meta->total_products ?? 0);
        $totalHotels += (int) ($meta->total_hotels ?? 0);
        $distinctProductsSum += (int) ($meta->distinct_products ?? 0);
        $distinctHotelsSum += (int) ($meta->distinct_hotels ?? 0);

        $rawCatMap = $meta->category_mix ?? [];
        $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
        foreach ($catMap as $cat => $lines) {
            $categoryLines[$cat] = ($categoryLines[$cat] ?? 0) + $lines;
        }

        $rawCityMap = $meta->days_per_city ?? [];
        $cityMap = is_array($rawCityMap) ? [] : get_object_vars($rawCityMap);
        foreach (array_keys($cityMap) as $city) {
            $distinctCities[$city] = true;
        }
        foreach ($meta->states_touched ?? [] as $state) {
            $distinctStates[$state] = true;
        }

        if (isset($meta->total_days) && $meta->total_days !== null) {
            $tripDays[] = (int) $meta->total_days;
        }
    }

    arsort($categoryLines);
    $totalCategoryLines = array_sum($categoryLines);
    $categoryPie = [];
    foreach ($categoryLines as $cat => $lines) {
        $categoryPie[] = ['category' => $cat, 'lines' => $lines, 'pct' => $totalCategoryLines ? round($lines / $totalCategoryLines * 100, 1) : 0];
    }

    sort($tripDays);
    $tripLengthBox = [
        'n' => count($tripDays),
        'min' => $tripDays ? $tripDays[0] : 0,
        'p25' => quotes_percentile($tripDays, 0.25),
        'median' => quotes_percentile($tripDays, 0.5),
        'p75' => quotes_percentile($tripDays, 0.75),
        'max' => $tripDays ? $tripDays[count($tripDays) - 1] : 0,
    ];

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'totalProducts' => $totalProducts,
        'totalHotels' => $totalHotels,
        'avgProductsPerQuote' => $totalQuotes ? round($totalProducts / $totalQuotes, 1) : 0,
        'avgHotelsPerQuote' => $totalQuotes ? round($totalHotels / $totalQuotes, 1) : 0,
        'avgDistinctProductsPerQuote' => $totalQuotes ? round($distinctProductsSum / $totalQuotes, 1) : 0,
        'avgDistinctHotelsPerQuote' => $totalQuotes ? round($distinctHotelsSum / $totalQuotes, 1) : 0,
        'distinctCities' => count($distinctCities),
        'distinctStates' => count($distinctStates),
        'categoryPie' => $categoryPie,
        'tripLengthBox' => $tripLengthBox,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function quotes_states(array $config): void
{
    $f = quotes_read_filters();
    $sql = 'SELECT quotestage, data FROM quotes';
    $params = [];
    if ($f['stage'] !== '') {
        $sql .= ' WHERE quotestage = ?';
        $params[] = $f['stage'];
    }
    $stmt = db($config)->prepare($sql);
    $stmt->execute($params);

    $byStateQuotes   = []; // state => set of quote_no (count distinct quotes touching that state)
    $byStateProducts = []; // state => line-item count
    $byStateCategory = []; // state => [category => count]
    $unmappedLineItems = 0;
    $totalLineItems = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];

        if (!quotes_matches_filters($products, $f)) {
            continue;
        }

        $statesInQuote = [];
        foreach ($products as $p) {
            if (!quotes_line_matches_filters($p, $f)) {
                continue;
            }
            $totalLineItems++;
            $cleanCity = quotes_norm_city((string) ($p->city ?? ''));
            $state = $cleanCity !== null ? quotes_state_of_city(mb_strtolower($cleanCity)) : null;
            if ($state === null) {
                $unmappedLineItems++;
                continue;
            }
            $statesInQuote[$state] = true;
            $byStateProducts[$state] = ($byStateProducts[$state] ?? 0) + 1;
            $cat = quotes_norm_category((string) ($p->category ?? '')) ?: 'Uncategorised';
            $byStateCategory[$state][$cat] = ($byStateCategory[$state][$cat] ?? 0) + 1;
        }
        foreach (array_keys($statesInQuote) as $state) {
            $byStateQuotes[$state] = ($byStateQuotes[$state] ?? 0) + 1;
        }
    }

    // Board/Competitor cross-reference, from the same tour_rows table the
    // Opportunities tab reads â€” one row per scraped tour-day, already
    // imported by app/pipeline into this same database.
    $refStmt = db($config)->query(
        "SELECT state, type, COUNT(DISTINCT tour) AS n_tours
         FROM tour_rows GROUP BY state, type"
    );
    $boardTours = [];
    $competitorTours = [];
    while ($r = $refStmt->fetch(PDO::FETCH_ASSOC)) {
        if ($r['type'] === 'Board') {
            $boardTours[$r['state']] = (int) $r['n_tours'];
        } elseif ($r['type'] === 'Competitor') {
            $competitorTours[$r['state']] = (int) $r['n_tours'];
        }
    }

    $stateOrder = ['NSW', 'VIC', 'QLD', 'WA', 'SA', 'TAS', 'NT', 'ACT'];
    $comparison = [];
    foreach ($stateOrder as $state) {
        $comparison[] = [
            'state' => $state,
            'quotesTouching' => $byStateQuotes[$state] ?? 0,
            'quoteProductLines' => $byStateProducts[$state] ?? 0,
            'boardTours' => $boardTours[$state] ?? 0,
            'competitorTours' => $competitorTours[$state] ?? 0,
        ];
    }
    // Sort by our own quote volume, descending â€” the reader's own activity
    // is the primary sort key; board/competitor columns ride along for comparison.
    usort($comparison, fn ($a, $b) => $b['quoteProductLines'] <=> $a['quoteProductLines']);

    $byStateProductsSorted = $byStateProducts;
    arsort($byStateProductsSorted);

    $byStateCategorySorted = [];
    foreach ($byStateCategory as $state => $cats) {
        arsort($cats);
        $byStateCategorySorted[$state] = $cats;
    }

    echo json_encode([
        'totalLineItems' => $totalLineItems,
        'unmappedLineItems' => $unmappedLineItems,
        'unmappedPct' => $totalLineItems ? round($unmappedLineItems / $totalLineItems * 100, 1) : 0,
        'byState' => $byStateProductsSorted,
        'byStateCategory' => $byStateCategorySorted,
        'comparison' => $comparison,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// â”€â”€ product/category deep-dive (trip-length mix, city concentration, comments) â”€

/**
 * Three product/category angles not covered elsewhere in this dashboard:
 *   1. Category mix by trip-length band â€” does a short trip look different
 *      from a long one? (uses the same bands as quotes_trip_band().)
 *   2. Product city-concentration â€” is a popular product basically
 *      single-city, or does it spread across many cities? (a product sold
 *      in 1 city at high volume behaves very differently from one spread
 *      thin across 10 â€” same raw count, different real-world pattern.)
 *   3. Comment-field mining â€” which products/categories most often carry a
 *      free-text comment. Comments are mostly empty (sampled ~7.7% of line
 *      items have one); a product that reliably attracts one is a signal of
 *      complexity or a common customisation request, not itself a full
 *      analysis of what the comment SAYS (free text at this volume isn't
 *      reliably summarisable without an LLM pass â€” out of scope here).
 */
function quotes_products(array $config): void
{
    $f = quotes_read_filters();
    quotes_products_impl($f, quotes_iter_live_rows($config));
}

/**
 * quotes_clean_products() â€” same three angles as quotes_products() above
 * (category-by-trip-band, product city-concentration, comment mining), but
 * reads ONLY the cleaned dataset (quotes_clean/, 3,847 quotes) instead of
 * the live uncleaned table. Used by the standalone quotes_itinerary_mock.html
 * Analytics tab, ported from the live dashboard's "Cumulative Analytics"
 * tab. Entirely quotestage-free â€” no accept/reject framing anywhere here,
 * matching this dataset's own product owner's instruction that quotestage
 * isn't a meaningful signal (these quotes were built manually via
 * questionnaires, not real customer decisions).
 */
function quotes_clean_products(): void
{
    $f = quotes_read_filters();
    quotes_products_impl($f, quotes_iter_clean_rows());
}

/**
 * quotes_iter_live_rows()/quotes_iter_clean_rows() â€” both yield the same
 * shape (['data' => json-string]) so quotes_products_impl() can run
 * identically over either source. Generators, not arrays, so the live path
 * still streams straight off the PDO cursor rather than materializing all
 * 6,534 rows at once (matching the original function's memory behavior).
 */
function quotes_iter_live_rows(array $config)
{
    $stmt = db($config)->query('SELECT data FROM quotes');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        yield $row;
    }
}
function quotes_iter_clean_rows()
{
    foreach (glob(__DIR__ . '/quotes_clean/*.json') ?: [] as $file) {
        yield ['data' => file_get_contents($file)];
    }
}

/**
 * Linear-interpolation percentile, matching pandas' .describe() â€” same
 * function as the live dashboard's quotes_outcomes() uses for its trip-
 * length box plot, ported verbatim since the math itself has nothing to do
 * with quotestage.
 */
function quotes_percentile(array $sorted, float $p): float
{
    if (!$sorted) {
        return 0;
    }
    $idx = $p * (count($sorted) - 1);
    $lo = (int) floor($idx);
    $hi = (int) ceil($idx);
    if ($lo === $hi) {
        return $sorted[$lo];
    }
    return round($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo), 1);
}

/**
 * quotes_clean_spread() â€” trip-length box-plot statistics (min/p25/median/
 * p75/max), ported from the live dashboard's Analytics tab box plot
 * (quotes_outcomes()' $pct percentile function + QaRangeBar). That original
 * box plot was ONE overall figure for the whole dataset; this adds a
 * per-state breakdown too (same percentile math, grouped by
 * states_touched), since it's essentially free once total_days is already
 * being read from quotes_metadata/. Entirely quotestage-free â€” the box plot
 * itself never touched quotestage in the original either, only nearby
 * tables (acceptance rate, rate-by-stage) that are deliberately NOT ported
 * here, since quotestage isn't a meaningful signal for this dataset.
 */
function quotes_clean_spread(): void
{
    $files = glob(__DIR__ . '/quotes_metadata/*.json') ?: [];

    $overall = [];
    $byState = []; // state => [days, ...]
    $byBand = []; // band => [days, ...]

    foreach ($files as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta) || !isset($meta->total_days) || $meta->total_days === null) {
            continue;
        }
        $days = (int) $meta->total_days;
        $overall[] = $days;

        $band = $meta->trip_band ?? 'Unknown';
        $byBand[$band][] = $days;

        foreach ($meta->states_touched ?? [] as $state) {
            $byState[$state][] = $days;
        }
    }

    $boxStats = function (array $vals) {
        sort($vals);
        return [
            'n' => count($vals),
            'min' => $vals ? $vals[0] : 0,
            'p25' => quotes_percentile($vals, 0.25),
            'median' => quotes_percentile($vals, 0.5),
            'p75' => quotes_percentile($vals, 0.75),
            'max' => $vals ? $vals[count($vals) - 1] : 0,
        ];
    };

    $stateStats = [];
    foreach ($byState as $state => $vals) {
        $stateStats[] = ['state' => $state] + $boxStats($vals);
    }
    usort($stateStats, fn ($a, $b) => $b['n'] - $a['n']);

    $bandOrder = ['1-4d', '5-7d', '8-10d', '11-13d', '14-16d', '17d+', 'Unknown'];
    $bandStats = [];
    foreach ($bandOrder as $band) {
        if (isset($byBand[$band])) {
            $bandStats[] = ['band' => $band] + $boxStats($byBand[$band]);
        }
    }

    echo json_encode([
        'overall' => $boxStats($overall),
        'byState' => $stateStats,
        'byBand' => $bandStats,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_seasonality() â€” travel-date-derived demand view, ported from
 * the live dashboard's quotes_seasonality(): monthly/seasonal volume,
 * AU-vs-NZ seasonal share, and a cityÃ—month heatmap (row-normalized: each
 * cell is that city's share of ITS OWN dated quotes falling in that month,
 * so a low-volume and high-volume city are still visually comparable).
 * Reads ONLY quotes_clean/ (3,847 quotes). Deliberately drops the original's
 * loss-rate-by-month-2026 table â€” that one used quotestage, and even though
 * the original itself already flagged it as a snapshot-maturity artifact
 * rather than real seasonality, it's not worth porting a quotestage-based
 * metric into a project explicitly told quotestage isn't meaningful here.
 *
 * Coverage caveat (same as the original): only quotes with >=1 parseable
 * hotel check-in get a travel date â€” quotes with no hotel line are absent
 * from every cut below. Real gap, not a bug, carried through in the
 * response so the frontend states it rather than hiding it.
 */
function quotes_clean_seasonality(): void
{
    $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $seasonOf = [12 => 'Summer', 1 => 'Summer', 2 => 'Summer', 3 => 'Autumn', 4 => 'Autumn', 5 => 'Autumn',
                 6 => 'Winter', 7 => 'Winter', 8 => 'Winter', 9 => 'Spring', 10 => 'Spring', 11 => 'Spring'];

    $cityFilter = quotes_norm_city((string) ($_GET['city'] ?? ''));
    $cityFilterKey = $cityFilter !== null ? mb_strtolower($cityFilter) : null;
    $stateFilter = mb_strtoupper(trim((string) ($_GET['state'] ?? '')));

    $totalQuotes = 0;
    $datedQuotes = 0;
    $byMonth = array_fill(1, 12, 0);
    $bySeason = ['Summer' => 0, 'Autumn' => 0, 'Winter' => 0, 'Spring' => 0];
    $byCountryMonth = ['AU' => array_fill(1, 12, 0), 'NZ' => array_fill(1, 12, 0)];
    $countryTotals = ['AU' => 0, 'NZ' => 0];
    $cityMonthCounts = []; // cityLower => [month => count]
    $cityTotals = []; // cityLower => count of dated quotes touching it
    $cityDisplayVotes = []; // cityLower => [displayVariant => count]

    foreach (glob(__DIR__ . '/quotes_clean/*.json') ?: [] as $file) {
        $obj = json_decode(file_get_contents($file));
        if (!is_object($obj)) {
            continue;
        }
        $products = $obj->products ?? [];

        if ($cityFilterKey !== null || $stateFilter !== '') {
            $matchesCity = $cityFilterKey === null;
            $matchesState = $stateFilter === '';
            foreach ($products as $p) {
                $cityClean = quotes_norm_city((string) ($p->city ?? ''));
                if ($cityClean === null) {
                    continue;
                }
                if (!$matchesCity && mb_strtolower($cityClean) === $cityFilterKey) {
                    $matchesCity = true;
                }
                if (!$matchesState && quotes_state_of_city(mb_strtolower($cityClean)) === $stateFilter) {
                    $matchesState = true;
                }
            }
            if (!$matchesCity || !$matchesState) {
                continue;
            }
        }

        $totalQuotes++;

        $earliest = null;
        foreach (($obj->hotels ?? []) as $h) {
            $ts = quotes_parse_date($h->checkin ?? null);
            if ($ts !== null && ($earliest === null || $ts < $earliest)) {
                $earliest = $ts;
            }
        }
        if ($earliest === null) {
            continue;
        }

        $datedQuotes++;
        $month = (int) date('n', $earliest);
        $byMonth[$month]++;
        $bySeason[$seasonOf[$month]]++;

        $citiesInQuote = [];
        foreach ($products as $p) {
            $cityClean = quotes_norm_city((string) ($p->city ?? ''));
            if ($cityClean !== null) {
                $citiesInQuote[mb_strtolower($cityClean)] = $cityClean;
            }
        }
        $countriesInQuote = [];
        foreach ($citiesInQuote as $cityLower => $cityDisplay) {
            $country = quotes_country_of_city($cityLower);
            if ($country !== null) {
                $countriesInQuote[$country] = true;
            }
            if (!isset($cityMonthCounts[$cityLower])) {
                $cityMonthCounts[$cityLower] = array_fill(1, 12, 0);
            }
            $cityMonthCounts[$cityLower][$month]++;
            $cityTotals[$cityLower] = ($cityTotals[$cityLower] ?? 0) + 1;
            $cityDisplayVotes[$cityLower][$cityDisplay] = ($cityDisplayVotes[$cityLower][$cityDisplay] ?? 0) + 1;
        }
        foreach (array_keys($countriesInQuote) as $country) {
            $byCountryMonth[$country][$month]++;
            $countryTotals[$country]++;
        }
    }

    $seasonalityByCountry = [];
    foreach (['AU', 'NZ'] as $c) {
        if ($countryTotals[$c] === 0) {
            continue;
        }
        $seasonalityByCountry[$c] = [];
        for ($m = 1; $m <= 12; $m++) {
            $seasonalityByCountry[$c][$months[$m]] = round($byCountryMonth[$c][$m] / $countryTotals[$c] * 100, 1);
        }
    }

    arsort($cityTotals);
    $topCities = array_slice($cityTotals, 0, 12, true);
    $cityHeatmap = [];
    foreach ($topCities as $cityLower => $n) {
        arsort($cityDisplayVotes[$cityLower]);
        $displayName = array_key_first($cityDisplayVotes[$cityLower]);
        $cityHeatmap[$displayName] = ['n' => $n, 'byMonth' => []];
        for ($m = 1; $m <= 12; $m++) {
            $cityHeatmap[$displayName]['byMonth'][$months[$m]] = round($cityMonthCounts[$cityLower][$m] / $n * 100, 1);
        }
    }

    $byMonthNamed = [];
    for ($m = 1; $m <= 12; $m++) {
        $byMonthNamed[$months[$m]] = $byMonth[$m];
    }

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'datedQuotes' => $datedQuotes,
        'coveragePct' => $totalQuotes ? round($datedQuotes / $totalQuotes * 100, 1) : 0,
        'byMonth' => $byMonthNamed,
        'bySeason' => $bySeason,
        'seasonalityByCountry' => $seasonalityByCountry,
        'countryTotals' => $countryTotals,
        'cityHeatmap' => $cityHeatmap,
        'appliedFilters' => [
            'city' => $cityFilter,
            'state' => $stateFilter !== '' ? $stateFilter : null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_hotels() â€” hotel-specific breakdown, requested to fill a gap
 * every other deep-dive covers for PRODUCTS but not hotels: most common
 * hotels overall, hotels-per-quote distribution, and hotel city-
 * concentration (single-city vs. spread across many cities â€” same idea as
 * quotes_products_impl()'s product concentration, applied to hotels
 * instead). Reads quotes_clean/ only. Quotestage-free.
 */
function quotes_clean_hotels(): void
{
    // ?category=/?product=/?state= still mean "quote has >=1 PRODUCT line
    // matching" (quotes_matches_filters()'s usual semantics) — but ?city=
    // is deliberately handled DIFFERENTLY here: a "most common hotels"
    // list filtered to "Brisbane" should mean hotels physically IN
    // Brisbane, not "any hotel appearing in a quote that happened to also
    // visit Brisbane on a different leg" (that quote-level meaning was
    // silently including Sydney/Gold Coast hotels under a Brisbane filter
    // whenever the trip visited multiple cities — wrong answer for a
    // hotel-city breakdown specifically, even though it's the right
    // meaning for every OTHER panel's ?city= filter). So city is checked
    // per HOTEL LINE ITEM below (via its own $h->city), not against the
    // quote's product lines via quotes_matches_filters().
    $f = quotes_read_filters();
    $fNoCity = $f;
    $fNoCity['city'] = '';
    $fNoCity['cityKey'] = '';
    $hotelCityFilterKey = $f['cityKey'];
    // ?hotel= is a separate exact hotel-name filter, same as every other
    // endpoint that supports it (not part of quotes_read_filters() itself,
    // which only knows about product-line filters).
    $hotelFilter = mb_strtolower(trim((string) ($_GET['hotel'] ?? '')));

    $hotelAppears = []; // nameKey => n distinct quotes featuring it
    $hotelNameDisplay = []; // nameKey => [rawName => count]
    $hotelCities = []; // nameKey => [cityLower => count]
    $hotelTotal = []; // nameKey => total line items
    $hotelsPerQuote = []; // total hotel LINE-ITEMS per quote (not distinct)
    $distinctHotelsPerQuote = [];
    $totalQuotes = 0;

    foreach (quotes_iter_clean_rows() as $row) {
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];
        if (!quotes_matches_filters($products, $fNoCity)) {
            continue;
        }
        $allHotels = is_array($obj->hotels ?? null) ? $obj->hotels : [];
        // Only hotel lines actually located in the filtered city count —
        // a quote surviving this filter can still have OTHER hotels (in
        // other cities) in $allHotels; those are excluded from every
        // count below, not just left in under the wrong city's tally.
        $hotels = $hotelCityFilterKey === ''
            ? $allHotels
            : array_values(array_filter($allHotels, function ($h) use ($hotelCityFilterKey) {
                $cityClean = quotes_norm_city((string) ($h->city ?? ''));
                return $cityClean !== null && mb_strtolower($cityClean) === $hotelCityFilterKey;
            }));
        if ($hotelCityFilterKey !== '' && !$hotels) {
            continue;
        }
        if ($hotelFilter !== '' && !array_reduce($hotels, fn ($carry, $h) => $carry || mb_strtolower(trim((string) ($h->productname ?? ''))) === $hotelFilter, false)) {
            continue;
        }
        $totalQuotes++;
        $hotelsPerQuote[] = count($hotels);

        $seen = [];
        foreach ($hotels as $h) {
            $name = trim((string) ($h->productname ?? ''));
            if ($name === '') {
                continue;
            }
            [$nameKey, $canonical] = quotes_hotel_fix(quotes_norm_name_key($name));
            $hotelTotal[$nameKey] = ($hotelTotal[$nameKey] ?? 0) + 1;
            $hotelNameDisplay[$nameKey][$name] = ($hotelNameDisplay[$nameKey][$name] ?? 0) + 1;
            if ($canonical !== null) {
                $hotelNameDisplay[$nameKey][$canonical] = ($hotelNameDisplay[$nameKey][$canonical] ?? 0) + 1000;
            }
            $cityClean = quotes_norm_city((string) ($h->city ?? ''));
            if ($cityClean !== null) {
                $cityKey = mb_strtolower($cityClean);
                $hotelCities[$nameKey][$cityKey] = ($hotelCities[$nameKey][$cityKey] ?? 0) + 1;
            }
            if (!isset($seen[$nameKey])) {
                $seen[$nameKey] = true;
                $hotelAppears[$nameKey] = ($hotelAppears[$nameKey] ?? 0) + 1;
            }
        }
        $distinctHotelsPerQuote[] = count($seen);
    }

    $displayName = function (string $nameKey) use ($hotelNameDisplay): string {
        if (!isset($hotelNameDisplay[$nameKey]) || !$hotelNameDisplay[$nameKey]) {
            return $nameKey;
        }
        $votes = $hotelNameDisplay[$nameKey];
        arsort($votes);
        return array_key_first($votes);
    };

    arsort($hotelAppears);
    $mostCommon = [];
    $i = 0;
    foreach ($hotelAppears as $nameKey => $n) {
        if ($i++ >= 25) {
            break;
        }
        $mostCommon[] = ['hotelname' => $displayName($nameKey), 'quotes' => $n];
    }

    $concentration = [];
    foreach ($hotelTotal as $nameKey => $total) {
        if ($total < 10 || !isset($hotelCities[$nameKey])) {
            continue;
        }
        $cities = $hotelCities[$nameKey];
        arsort($cities);
        $topCity = array_key_first($cities);
        $topCityCount = reset($cities);
        $concentration[] = [
            'hotelname' => $displayName($nameKey),
            'totalLineItems' => $total,
            'topCity' => $topCity,
            'topCityShare' => round($topCityCount / $total * 100, 1),
            'distinctCities' => count($cities),
        ];
    }
    usort($concentration, fn ($a, $b) => $b['topCityShare'] <=> $a['topCityShare']);
    $mostConcentrated = array_slice($concentration, 0, 10);
    usort($concentration, fn ($a, $b) => $a['topCityShare'] <=> $b['topCityShare']);
    $mostSpread = array_slice($concentration, 0, 10);

    sort($hotelsPerQuote);
    sort($distinctHotelsPerQuote);
    $countByN = []; // n hotels => n quotes with that many
    foreach ($hotelsPerQuote as $n) {
        $countByN[$n] = ($countByN[$n] ?? 0) + 1;
    }
    ksort($countByN, SORT_NUMERIC);
    $hotelsPerQuoteHistogram = [];
    foreach ($countByN as $n => $count) {
        $hotelsPerQuoteHistogram[] = ['hotels' => $n, 'count' => $count];
    }

    echo json_encode([
        'totalQuotes' => $totalQuotes,
        'mostCommonHotels' => $mostCommon,
        'distinctHotelsSeen' => count($hotelAppears),
        'hotelsPerQuoteHistogram' => $hotelsPerQuoteHistogram,
        'medianHotelsPerQuote' => $hotelsPerQuote ? quotes_percentile($hotelsPerQuote, 0.5) : 0,
        'medianDistinctHotelsPerQuote' => $distinctHotelsPerQuote ? quotes_percentile($distinctHotelsPerQuote, 0.5) : 0,
        'cityConcentration' => [
            'hotelsWithEnoughData' => count($concentration),
            'mostConcentrated' => $mostConcentrated,
            'mostSpread' => $mostSpread,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * quotes_clean_extra() â€” three more metadata-only breakdowns, all cheap
 * (quotes_metadata/ only, no full quote files needed):
 *   1. categoryByState â€” does Queensland skew Tour/Attraction while NSW
 *      skews SIC, etc.? Category-mix line-item counts crossed with
 *      states_touched, expressed as each state's own % share so states
 *      with very different volumes stay comparable.
 *   2. citySplit â€” % of quotes that are single-city vs multi-city, broken
 *      down by trip-length band (does a longer trip more reliably mean
 *      multiple cities, or do people still do long single-city trips?).
 *   3. distinctProductsByBand â€” median DISTINCT product count per
 *      trip-length band, i.e. does a 14-day trip actually use meaningfully
 *      more distinct products, or mostly just repeat the same ones over
 *      more days? (distinct_products/total_days per quote from metadata,
 *      grouped by band â€” a genuinely different question from
 *      quotes_clean_products()'s category-mix-by-band, since that one
 *      tracks WHICH categories, this one tracks HOW MUCH real variety.)
 *   4. productsPerDayByBand â€” median (total_products / total_days) per
 *      trip-length band, i.e. pace of activity: does a longer trip band
 *      pack MORE into each day, or just add more idle/travel days at the
 *      same pace? Deliberately not "days" on either axis (unlike a plain
 *      trip-length-by-band box plot, which is tautological â€” band IS a
 *      day-range, so median days per band tells you nothing new).
 *   5. productsPerDayByState â€” same pace-of-activity metric as #4, cut by
 *      state instead of band: does one state's typical trip pack in more
 *      per day than another (a busier, activity-dense state) vs. a state
 *      that's more about relaxed/longer stays? A quote touching multiple
 *      states contributes its one pace value to every state it touches.
 * Quotestage-free.
 */
function quotes_clean_extra(): void
{
    $bandOrder = ['1-4d', '5-7d', '8-10d', '11-13d', '14-16d', '17d+', 'Unknown'];

    $categoryByState = []; // state => [category => lines]
    $cityCountsByBand = []; // band => ['single' => n, 'multi' => n]
    $distinctProductsByBand = []; // band => [distinct_products, ...]
    $productsPerDayByBand = []; // band => [total_products/total_days, ...] â€” pace of activity, not variety or day-count
    $productsPerDayByState = []; // state => [total_products/total_days, ...] â€” same pace metric, cut by state instead of band
    $total = 0;

    foreach (glob(__DIR__ . '/quotes_metadata/*.json') ?: [] as $file) {
        $meta = json_decode(file_get_contents($file));
        if (!is_object($meta)) {
            continue;
        }
        $total++;
        $band = $meta->trip_band ?? 'Unknown';

        $rawCatMap = $meta->category_mix ?? [];
        $catMap = is_array($rawCatMap) ? [] : get_object_vars($rawCatMap);
        foreach ($meta->states_touched ?? [] as $state) {
            foreach ($catMap as $cat => $lines) {
                $categoryByState[$state][$cat] = ($categoryByState[$state][$cat] ?? 0) + $lines;
            }
        }

        $distinctCities = (int) ($meta->distinct_cities ?? 0);
        if ($distinctCities > 0) {
            if (!isset($cityCountsByBand[$band])) {
                $cityCountsByBand[$band] = ['single' => 0, 'multi' => 0];
            }
            $cityCountsByBand[$band][$distinctCities === 1 ? 'single' : 'multi']++;
        }

        if (isset($meta->distinct_products)) {
            $distinctProductsByBand[$band][] = (int) $meta->distinct_products;
        }

        $totalDays = (int) ($meta->total_days ?? 0);
        if ($totalDays > 0 && isset($meta->total_products)) {
            $totalProducts = (int) $meta->total_products;
            $pace = round($totalProducts / $totalDays, 2);
            // Keep the actual products/days pair alongside the rate, not just
            // the bare ratio â€” a median RATE on its own doesn't tell a reader
            // what a real quote behind it looks like, and a single quote's
            // ratio can't be scaled up to explain every other quote's number.
            // So each group later reports one REAL quote whose own ratio
            // lands closest to the group's median, as a concrete example.
            $paceRow = ['pace' => $pace, 'products' => $totalProducts, 'days' => $totalDays];
            $productsPerDayByBand[$band][] = $paceRow;
            foreach ($meta->states_touched ?? [] as $state) {
                $productsPerDayByState[$state][] = $paceRow;
            }
        }
    }

    $categoryByStatePct = [];
    foreach ($categoryByState as $state => $cats) {
        $stateTotal = array_sum($cats);
        arsort($cats);
        $pct = [];
        foreach ($cats as $cat => $lines) {
            $pct[$cat] = $stateTotal ? round($lines / $stateTotal * 100, 1) : 0;
        }
        $categoryByStatePct[$state] = ['totalLineItems' => $stateTotal, 'categoryShare' => $pct];
    }

    $citySplit = [];
    foreach ($bandOrder as $band) {
        if (!isset($cityCountsByBand[$band])) {
            continue;
        }
        $single = $cityCountsByBand[$band]['single'];
        $multi = $cityCountsByBand[$band]['multi'];
        $bandTotal = $single + $multi;
        $citySplit[] = [
            'band' => $band,
            'single' => $single,
            'multi' => $multi,
            'multiPct' => $bandTotal ? round($multi / $bandTotal * 100, 1) : 0,
        ];
    }

    $distinctProductsBands = [];
    foreach ($bandOrder as $band) {
        if (!isset($distinctProductsByBand[$band])) {
            continue;
        }
        $vals = $distinctProductsByBand[$band];
        sort($vals);
        $distinctProductsBands[] = [
            'band' => $band,
            'n' => count($vals),
            'median' => quotes_percentile($vals, 0.5),
        ];
    }

    // Summarize a group's [{pace, products, days}, ...] rows as a median
    // rate PLUS one real example row whose own pace lands closest to that
    // median â€” so "1.25/day" is never shown as a bare, unanchored ratio.
    $summarizePace = function (array $rows): array {
        $paces = array_map(fn ($r) => $r['pace'], $rows);
        sort($paces);
        $median = quotes_percentile($paces, 0.5);
        $closest = null;
        $bestDiff = INF;
        foreach ($rows as $r) {
            $diff = abs($r['pace'] - $median);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $closest = $r;
            }
        }
        return [
            'n' => count($rows),
            'median' => $median,
            'example' => $closest ? ['products' => $closest['products'], 'days' => $closest['days']] : null,
        ];
    };

    $productsPerDayBands = [];
    foreach ($bandOrder as $band) {
        if (!isset($productsPerDayByBand[$band])) {
            continue;
        }
        $productsPerDayBands[] = ['band' => $band] + $summarizePace($productsPerDayByBand[$band]);
    }

    $productsPerDayStates = [];
    foreach ($productsPerDayByState as $state => $rows) {
        $productsPerDayStates[] = ['state' => $state] + $summarizePace($rows);
    }
    usort($productsPerDayStates, fn ($a, $b) => $b['median'] <=> $a['median']);

    echo json_encode([
        'totalQuotes' => $total,
        'categoryByState' => $categoryByStatePct,
        'citySplitByBand' => $citySplit,
        'distinctProductsByBand' => $distinctProductsBands,
        'productsPerDayByBand' => $productsPerDayBands,
        'productsPerDayByState' => $productsPerDayStates,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function quotes_products_impl(array $f, iterable $rows): void
{
    $categoryByBand = []; // band => [category => count]
    $productCities  = []; // nameKey => [cityLower => count]
    $productTotal   = []; // nameKey => total line items (for concentration ranking)
    $productComments = []; // nameKey => ['withComment' => n, 'total' => n]
    $categoryComments = []; // category => ['withComment' => n, 'total' => n]
    $commentLengths = [];
    // Case/whitespace variants merged via quotes_norm_name_key(); track
    // most-common original casing per key for display, same pattern as
    // $cityDisplay in quotes_analytics().
    $productNameDisplay = []; // nameKey => [rawName => count]
    // Per-city view: how many product LINE ITEMS a city has (raw booking
    // volume), how many DISTINCT products that breaks down into (a city
    // could have 500 lines but only 3 distinct products — very different
    // from 500 lines across 80 distinct products), and the category mix
    // within that one city. cityDisplay tracks the most-common original
    // casing per city, same pattern as productNameDisplay above.
    $cityLineItems  = []; // cityLower => total line items
    $cityProducts   = []; // cityLower => [nameKey => count]
    $cityCategory   = []; // cityLower => [category => count]
    $cityDisplay    = []; // cityLower => [rawCity => count]
    $cityCategoryProducts = []; // cityLower => [category => [nameKey => true]]

    foreach ($rows as $row) {
        $obj = json_decode($row['data']);
        $products = $obj->products ?? [];

        if (!quotes_matches_filters($products, $f)) {
            continue;
        }

        $maxDay = 0;
        foreach ($products as $p) {
            $day = (int) ($p->day ?? 0);
            if ($day > $maxDay && $day <= 30) {
                $maxDay = $day;
            }
        }
        $band = $maxDay > 0 ? quotes_trip_band($maxDay) : null;

        foreach ($products as $p) {
            if (!quotes_line_matches_filters($p, $f)) {
                continue;
            }
            $name = trim((string) ($p->productname ?? ''));
            $nameKey = quotes_norm_name_key($name);
            $cat  = quotes_norm_category((string) ($p->category ?? '')) ?: 'Uncategorised';
            $cityClean = quotes_norm_city((string) ($p->city ?? ''));
            $cityLower = $cityClean !== null ? mb_strtolower($cityClean) : '';
            $comment = trim((string) ($p->comment ?? ''));

            if ($band !== null) {
                $categoryByBand[$band][$cat] = ($categoryByBand[$band][$cat] ?? 0) + 1;
            }

            if ($nameKey !== '') {
                $productTotal[$nameKey] = ($productTotal[$nameKey] ?? 0) + 1;
                $productNameDisplay[$nameKey][$name] = ($productNameDisplay[$nameKey][$name] ?? 0) + 1;
                if ($cityLower !== '') {
                    $productCities[$nameKey][$cityLower] = ($productCities[$nameKey][$cityLower] ?? 0) + 1;
                }
                if (!isset($productComments[$nameKey])) {
                    $productComments[$nameKey] = ['withComment' => 0, 'total' => 0];
                }
                $productComments[$nameKey]['total']++;
                if ($comment !== '') {
                    $productComments[$nameKey]['withComment']++;
                    $commentLengths[] = mb_strlen($comment);
                }
            }

            if ($cityLower !== '' && $nameKey !== '') {
                $cityLineItems[$cityLower] = ($cityLineItems[$cityLower] ?? 0) + 1;
                $cityProducts[$cityLower][$nameKey] = ($cityProducts[$cityLower][$nameKey] ?? 0) + 1;
                $cityCategory[$cityLower][$cat] = ($cityCategory[$cityLower][$cat] ?? 0) + 1;
                $cityDisplay[$cityLower][$cityClean] = ($cityDisplay[$cityLower][$cityClean] ?? 0) + 1;
                // Which DISTINCT products (by nameKey) fall in this category,
                // within this city — separate from cityCategory above, which
                // counts every LINE ITEM (booking instance). A category can
                // dominate one of these counts without dominating the other
                // (e.g. Transfers: few distinct products, each booked very
                // often, vs. Tour: many distinct products, each booked rarely).
                $cityCategoryProducts[$cityLower][$cat][$nameKey] = true;
            }

            if (!isset($categoryComments[$cat])) {
                $categoryComments[$cat] = ['withComment' => 0, 'total' => 0];
            }
            $categoryComments[$cat]['total']++;
            if ($comment !== '') {
                $categoryComments[$cat]['withComment']++;
            }
        }
    }

    // 1. Category mix by trip-length band, as % share within that band (so
    // bands with very different total volumes are still comparable).
    $bandOrder = ['1-4d', '5-7d', '8-10d', '11-13d', '14-16d', '17d+'];
    $categoryByBandPct = [];
    foreach ($bandOrder as $band) {
        if (!isset($categoryByBand[$band])) {
            continue;
        }
        $total = array_sum($categoryByBand[$band]);
        $cats = $categoryByBand[$band];
        arsort($cats);
        $pct = [];
        foreach ($cats as $cat => $n) {
            $pct[$cat] = round($n / $total * 100, 1);
        }
        $categoryByBandPct[$band] = ['totalLineItems' => $total, 'categoryShare' => $pct];
    }

    // 2. Product city-concentration: for products with meaningful volume
    // (>=30 line items, same floor used for acceptance-rate reliability
    // elsewhere in this app), what % of its volume sits in its single
    // biggest city? High % = effectively a single-city product; low % =
    // genuinely spread across the network.
    $concentration = [];
    foreach ($productTotal as $nameKey => $total) {
        if ($total < 30 || !isset($productCities[$nameKey])) {
            continue;
        }
        $cities = $productCities[$nameKey];
        arsort($cities);
        $topCity = array_key_first($cities);
        $topCityCount = reset($cities);
        $votes = $productNameDisplay[$nameKey];
        arsort($votes);
        $concentration[] = [
            'productname' => array_key_first($votes),
            'totalLineItems' => $total,
            'topCity' => $topCity,
            'topCityShare' => round($topCityCount / $total * 100, 1),
            'distinctCities' => count($cities),
        ];
    }
    usort($concentration, fn ($a, $b) => $b['topCityShare'] <=> $a['topCityShare']);
    $mostConcentrated = array_slice($concentration, 0, 10);
    usort($concentration, fn ($a, $b) => $a['topCityShare'] <=> $b['topCityShare']);
    $mostSpread = array_slice($concentration, 0, 10);

    // 3. Comment-field mining: products/categories most likely to carry a
    // comment at all (>=30 line items floor, same reasoning as above).
    $commentRates = [];
    foreach ($productComments as $nameKey => $stats) {
        if ($stats['total'] < 30) {
            continue;
        }
        $votes = $productNameDisplay[$nameKey];
        arsort($votes);
        $commentRates[] = [
            'productname' => array_key_first($votes),
            'total' => $stats['total'],
            'commentRate' => round($stats['withComment'] / $stats['total'] * 100, 1),
        ];
    }
    usort($commentRates, fn ($a, $b) => $b['commentRate'] <=> $a['commentRate']);
    $topCommentProducts = array_slice($commentRates, 0, 10);

    $categoryCommentRates = [];
    foreach ($categoryComments as $cat => $stats) {
        $categoryCommentRates[$cat] = $stats['total'] ? round($stats['withComment'] / $stats['total'] * 100, 1) : 0;
    }
    arsort($categoryCommentRates);

    sort($commentLengths);
    $medianCommentLen = $commentLengths ? $commentLengths[intdiv(count($commentLengths), 2)] : 0;

    // 4. Per-city breakdown: total product line items (raw volume), distinct
    // products (how many different things are actually sold there — a city
    // with 500 lines but 3 distinct products is a very different story from
    // 500 lines across 80 products), and the category mix within that city —
    // reported TWO ways, deliberately distinct so a reader never has to
    // guess which one a given % refers to:
    //   categoryShareByVolume    — % of LINE ITEMS (bookings) in each
    //                              category. A category with few distinct
    //                              products that each get booked constantly
    //                              (e.g. Transfers) can dominate this number.
    //   categoryShareByVariety   — % of DISTINCT PRODUCTS in each category.
    //                              A category with many different products
    //                              that are each booked rarely (e.g. Tour)
    //                              can dominate THIS number instead, even
    //                              with a modest volume share above.
    $byCity = [];
    foreach ($cityLineItems as $cityLower => $total) {
        $votes = $cityDisplay[$cityLower] ?? [];
        arsort($votes);
        $displayName = $votes ? array_key_first($votes) : $cityLower;

        $cats = $cityCategory[$cityLower] ?? [];
        arsort($cats);
        $catPctByVolume = [];
        $catCountByVolume = [];
        foreach ($cats as $cat => $n) {
            $catPctByVolume[$cat] = round($n / $total * 100, 1);
            $catCountByVolume[$cat] = $n;
        }

        $distinctTotal = count($cityProducts[$cityLower] ?? []);
        $catCounts = $cityCategoryProducts[$cityLower] ?? [];
        $catPctByVariety = [];
        $catCountByVariety = [];
        foreach ($catCounts as $cat => $products) {
            $catPctByVariety[$cat] = $distinctTotal ? round(count($products) / $distinctTotal * 100, 1) : 0;
            $catCountByVariety[$cat] = count($products);
        }
        arsort($catPctByVariety);

        $byCity[] = [
            'city' => $displayName,
            'totalLineItems' => $total,
            'distinctProducts' => $distinctTotal,
            'categoryShareByVolume' => $catPctByVolume,
            'categoryShareByVariety' => $catPctByVariety,
            // Raw counts behind each % above — e.g. "412 bookings" alongside
            // "40%" — so a reader can see the number the percentage was
            // computed from, not just the ratio.
            'categoryCountByVolume' => $catCountByVolume,
            'categoryCountByVariety' => $catCountByVariety,
        ];
    }
    usort($byCity, fn ($a, $b) => $b['totalLineItems'] <=> $a['totalLineItems']);

    echo json_encode([
        'categoryByTripBand' => $categoryByBandPct,
        'cityConcentration' => [
            'productsWithEnoughData' => count($concentration),
            'mostConcentrated' => $mostConcentrated,
            'mostSpread' => $mostSpread,
        ],
        'comments' => [
            'productsWithEnoughData' => count($commentRates),
            'topCommentProducts' => $topCommentProducts,
            'categoryCommentRates' => $categoryCommentRates,
            'medianCommentLength' => $medianCommentLen,
            'totalWithComment' => count($commentLengths),
        ],
        'byCity' => $byCity,
        // Dataset-wide distinct product count — a UNION across every city,
        // not a sum of each city's own count (a product sold in 5 cities
        // counts once here, not 5 times). $productTotal is already keyed
        // by distinct product across the whole filtered dataset.
        'distinctProductsOverall' => count($productTotal),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// â”€â”€ AI prediction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function predict(array $config): void
{
    $payload    = read_json_body();
    $itinerary  = $payload['itinerary']  ?? [];
    $marketData = $payload['marketData'] ?? [];
    $boardData  = $payload['boardData']  ?? [];

    $systemPrompt =
        'You are a tourism market analyst for TDU (Turtle Down Under), an Australian inbound tour operator. ' .
        'Predict which international markets an itinerary would appeal to. ' .
        'Respond with valid JSON only â€” no explanation, no markdown.';

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
            // with a 403 (error 1010) â€” a browser-like UA is required.
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
            '- %s: %s total tours, avg duration %s days, state visits â€” %s',
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
            '%s: %s board tours â€” top products: %s',
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
            '- %s: %s days â€” Products/Places: %s',
            $r['state'] ?? '',
            $r['days'] ?? '',
            $productsStr
        );
    }
    $regionsBlock = implode("\n", $regionLines);

    $spanLine = count($regions) > 1
        ? "Multi-state itinerary â€” score based on the FULL itinerary across all regions "
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