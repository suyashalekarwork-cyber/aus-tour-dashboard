<?php
/**
 * import.php — loads the data team's JSON exports from imports/ into MySQL.
 *
 * Run it after setup/install.php on first deploy, and again every time the
 * data team delivers new data. This file STAYS on the server; it is protected
 * by the 'import_key' from config.php.
 *
 * How to run:  https://yourdomain/import.php?key=YOUR_IMPORT_KEY
 *        (or:  php import.php YOUR_IMPORT_KEY  from SSH)
 *
 * Delivery format: one json.dump per file (indent=2, ensure_ascii=False):
 *
 *   themes.json     — ["Nature & Scenery", ...]
 *   data.json       — {"WA": {...}, "NSW": {...}, ...}
 *   templates.json  — [{...}, {...}, ...]
 *   market.json     — [{...}, ...]
 *   tokens.json     — [{...}, ...]
 *
 * themes/data/templates form ONE dataset and must be delivered together;
 * market.json and tokens.json are independent — any subset may be present.
 *
 * Each dataset found fully REPLACES its tables (inside a transaction, so a
 * failed import leaves the old data live), bumps its version in the settings
 * table, and invalidates the cache/ files that dataset.php serves. The two
 * big files are parsed line-by-line — that's why indent=2 is required — so
 * they never sit in server memory at once.
 */

set_time_limit(600);
header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/config.php';

// ── access check ──────────────────────────────────────────────────────────────
$given = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['key'] ?? '');
if (empty($config['import_key']) || $config['import_key'] === 'CHANGE_ME_TOO'
    || !hash_equals($config['import_key'], $given)) {
    http_response_code(403);
    echo "Forbidden.\n";
    echo "Set a unique 'import_key' in config.php and call import.php?key=<that key>.\n";
    exit;
}

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$importsDir = __DIR__ . '/imports';
$cacheDir   = __DIR__ . '/cache';

echo "TDU Itinerary Builder — data import\n";
echo "====================================\n\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    echo "[FAIL] Could not connect to MySQL: {$e->getMessage()}\n";
    exit(1);
}

$didAnything = false;

// ── themes/data/templates → settings(themes) + states + tour_rows + templates ─

if (is_readable("$importsDir/data.json")) {
    foreach (['themes.json', 'templates.json'] as $required) {
        if (!is_readable("$importsDir/$required")) {
            fail("data.json is present but $required is missing — "
                . 'themes.json + data.json + templates.json must be delivered together');
        }
    }
    echo "Importing themes.json + data.json + templates.json ...\n";
    import_data($pdo, $importsDir);
    bump_version($pdo, 'data_version');
    $didAnything = true;
} else {
    echo "[skip] no imports/data.json found\n";
}

// ── market.json → market_tours ────────────────────────────────────────────────

if (is_readable("$importsDir/market.json")) {
    echo "Importing market.json ...\n";
    $n = import_array($pdo, "$importsDir/market.json", 'market_tours',
        'INSERT INTO market_tours (seq, tour_name, source, source_type, market, state, data)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        fn ($o) => [
            trunc($o->tour_name ?? '', 255),
            trunc($o->source ?? '', 64),
            trunc($o->source_type ?? '', 32),
            trunc($o->market ?? '', 64),
            trunc($o->state ?? '', 8),
        ]);
    echo "[ok] market_tours: $n rows\n";
    bump_version($pdo, 'market_version');
    $didAnything = true;
} else {
    echo "[skip] no imports/market.json found\n";
}

// ── tokens.json → tokens ──────────────────────────────────────────────────────

if (is_readable("$importsDir/tokens.json")) {
    echo "Importing tokens.json ...\n";
    $n = import_array($pdo, "$importsDir/tokens.json", 'tokens',
        'INSERT INTO tokens (seq, name, state, issue, data) VALUES (?, ?, ?, ?, ?)',
        fn ($o) => [
            trunc($o->name ?? '', 255),
            trunc($o->state ?? '', 64),
            trunc($o->issue ?? '', 32),
        ]);
    echo "[ok] tokens: $n rows\n";
    bump_version($pdo, 'tokens_version');
    $didAnything = true;
} else {
    echo "[skip] no imports/tokens.json found\n";
}

// ── finish: invalidate the serving cache ──────────────────────────────────────

if ($didAnything) {
    $n = 0;
    foreach (glob("$cacheDir/*.js") ?: [] as $f) {
        if (@unlink($f)) {
            $n++;
        }
    }
    echo "\n[ok] Cache invalidated ($n file(s) removed) — dataset.php will rebuild on next page load\n";
    echo "Done.\n";
} else {
    echo "\nNothing imported — put the data team's JSON files into the imports/ folder first.\n";
}

// ══════════════════════════════════════════════════════════════════════════════

function fail(string $msg): void
{
    echo "[FAIL] $msg\n";
    exit(1);
}

function trunc($s, int $len): string
{
    return mb_substr((string) $s, 0, $len);
}

function bump_version(PDO $pdo, string $name): void
{
    $pdo->prepare(
        "INSERT INTO settings (name, value) VALUES (?, '1')
         ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1"
    )->execute([$name]);
}

function set_setting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$name, $value]);
}

/**
 * Replaces $table with the objects from a JSON-array file. $indexCols maps
 * each object to its indexed column values; seq and the full JSON go in
 * automatically. Returns the row count.
 */
function import_array(PDO $pdo, string $file, string $table, string $insertSql, callable $indexCols): int
{
    $items = json_decode(file_get_contents($file));
    if (!is_array($items)) {
        fail(basename($file) . ': not a JSON array (' . json_last_error_msg() . ')');
    }
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM $table");
    $stmt = $pdo->prepare($insertSql);
    foreach ($items as $i => $o) {
        $stmt->execute(array_merge([$i], $indexCols($o), [json_encode($o, JSON_FLAGS)]));
    }
    $pdo->commit();
    return count($items);
}

/**
 * Imports themes.json + data.json + templates.json as one dataset.
 *
 * The two big files are streamed one top-level entry at a time, relying on
 * json.dump(indent=2) formatting: each entry starts at indent 2 and its
 * closing brace is alone on its line (nested lines are indented deeper).
 */
function import_data(PDO $pdo, string $dir): void
{
    $themes = json_decode(file_get_contents("$dir/themes.json"));
    if (!is_array($themes)) {
        fail('themes.json: invalid JSON (' . json_last_error_msg() . ')');
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM states');
    $pdo->exec('DELETE FROM tour_rows');
    $pdo->exec('DELETE FROM templates');
    set_setting($pdo, 'themes', json_encode($themes, JSON_FLAGS));

    $insState = $pdo->prepare(
        'INSERT INTO states (abbr, seq, name, cities, products, seed, templates)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insRow = $pdo->prepare(
        'INSERT INTO tour_rows (state, seq, tour, source, type, day, city, row)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insTpl = $pdo->prepare(
        'INSERT INTO templates (seq, name, source, type, data) VALUES (?, ?, ?, ?, ?)'
    );

    // data.json — {"WA": {...}, ...}, one state at a time
    $stateSeq = 0;
    $rowCount = 0;
    stream_entries("$dir/data.json", '/^  "([A-Za-z]+)": \{$/',
        function (stdClass $st, ?string $abbr) use ($insState, $insRow, &$stateSeq, &$rowCount) {
            $insState->execute([
                $abbr,
                $stateSeq++,
                (string) ($st->name ?? $abbr),
                json_encode($st->cities ?? new stdClass(), JSON_FLAGS),
                json_encode($st->products ?? new stdClass(), JSON_FLAGS),
                json_encode($st->seed ?? new stdClass(), JSON_FLAGS),
                json_encode($st->templates ?? [], JSON_FLAGS),
            ]);
            $n = 0;
            foreach (($st->allRows ?? []) as $i => $row) {
                $insRow->execute([
                    $abbr,
                    $i,
                    trunc($row->tour ?? '', 255),
                    trunc($row->source ?? '', 64),
                    trunc($row->type ?? '', 32),
                    (int) ($row->day ?? 0),
                    trunc($row->city ?? '', 128),
                    json_encode($row, JSON_FLAGS),
                ]);
                $n++;
            }
            $rowCount += $n;
            echo "  [ok] $abbr: $n rows\n";
        });

    // templates.json — [{...}, ...], one template at a time
    $tplSeq = 0;
    stream_entries("$dir/templates.json", '/^  \{$/',
        function (stdClass $tpl) use ($insTpl, &$tplSeq) {
            $insTpl->execute([
                $tplSeq++,
                trunc($tpl->name ?? '', 255),
                trunc($tpl->source ?? '', 64),
                trunc($tpl->type ?? '', 32),
                json_encode($tpl, JSON_FLAGS),
            ]);
        });

    if ($stateSeq === 0 || $tplSeq === 0) {
        fail("parsing finished but something is missing (states: $stateSeq, templates: $tplSeq) — "
            . 'are the files pretty-printed with json.dump(..., indent=2)?');
    }

    $pdo->commit();
    echo "[ok] states: $stateSeq · tour_rows: $rowCount · templates: $tplSeq\n";
}

/**
 * Streams a big pretty-printed JSON file entry by entry. $startPattern marks
 * an entry's opening line (its first capture group, if any, is passed to
 * $handle as the entry's key); a line that is exactly `  }` or `  },` ends it.
 */
function stream_entries(string $file, string $startPattern, callable $handle): void
{
    $fh = fopen($file, 'r');
    if (!$fh) {
        fail('could not open ' . basename($file));
    }
    $buf = null;
    $key = null;
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($buf === null) {
            if (preg_match($startPattern, $line, $m)) {
                $key = $m[1] ?? null;
                $buf = "{\n";
            }
            continue;
        }
        if ($line === '  }' || $line === '  },') {
            $entry = json_decode($buf . '}');
            if (!is_object($entry)) {
                fail(basename($file) . ': entry' . ($key !== null ? " \"$key\"" : '')
                    . ' is not valid JSON (' . json_last_error_msg() . ')');
            }
            $handle($entry, $key);
            $buf = null;
            $key = null;
        } else {
            $buf .= $line . "\n";
        }
    }
    fclose($fh);
    if ($buf !== null) {
        fail(basename($file) . ': ends mid-entry — truncated upload?');
    }
}
