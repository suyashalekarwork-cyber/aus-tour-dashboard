<?php
/**
 * import.php — loads the data team's exports from imports/ into MySQL.
 *
 * Run it after install.php on first deploy, and again every time the data
 * team delivers new data. This file STAYS on the server (unlike install.php);
 * it is protected by the 'import_key' from config.php.
 *
 * How to run:  https://yourdomain/import.php?key=YOUR_IMPORT_KEY
 *        (or:  php import.php YOUR_IMPORT_KEY  from SSH)
 *
 * Files it looks for in imports/ (any subset may be present):
 *   data.js    — exactly what the data team's pipeline produces today:
 *                const THEMES = [...]; const DATA = {...}; const TEMPLATES = [...];
 *                plus the DATA_MODULE code at the end (extracted to data_module.js)
 *   market.js  — window.MARKET_DATA = [...];   (or market.json, a bare array)
 *   tokens.js  — const TOKENS = [...];         (or tokens.json, a bare array)
 *
 * Each dataset found fully REPLACES its tables, bumps its version in the
 * settings table, and invalidates the cache/ files that dataset.php serves.
 * data.js is parsed line-by-line so the 26 MB file never sits in memory.
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

// ── data.js → settings(themes) + states + tour_rows + templates ───────────────

$dataFile = first_existing(["$importsDir/data.js", "$importsDir/data.json"]);
if ($dataFile !== null) {
    echo 'Importing ' . basename($dataFile) . " ...\n";
    import_data_js($pdo, $dataFile);
    bump_version($pdo, 'data_version');
    $didAnything = true;
} else {
    echo "[skip] imports/data.js not found\n";
}

// ── market.js → market_tours ──────────────────────────────────────────────────

$marketFile = first_existing(["$importsDir/market.js", "$importsDir/market.json"]);
if ($marketFile !== null) {
    echo 'Importing ' . basename($marketFile) . " ...\n";
    $items = read_json_array($marketFile);
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM market_tours');
    $stmt = $pdo->prepare(
        'INSERT INTO market_tours (seq, tour_name, source, source_type, market, state, data)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $i => $o) {
        $stmt->execute([
            $i,
            trunc($o->tour_name ?? '', 255),
            trunc($o->source ?? '', 64),
            trunc($o->source_type ?? '', 32),
            trunc($o->market ?? '', 64),
            trunc($o->state ?? '', 8),
            json_encode($o, JSON_FLAGS),
        ]);
    }
    $pdo->commit();
    echo '[ok] market_tours: ' . count($items) . " rows\n";
    bump_version($pdo, 'market_version');
    $didAnything = true;
} else {
    echo "[skip] imports/market.js not found\n";
}

// ── tokens.js → tokens ────────────────────────────────────────────────────────

$tokensFile = first_existing(["$importsDir/tokens.js", "$importsDir/tokens.json"]);
if ($tokensFile !== null) {
    echo 'Importing ' . basename($tokensFile) . " ...\n";
    $items = read_json_array($tokensFile);
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM tokens');
    $stmt = $pdo->prepare(
        'INSERT INTO tokens (seq, name, state, issue, data) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($items as $i => $o) {
        $stmt->execute([
            $i,
            trunc($o->name ?? '', 255),
            trunc($o->state ?? '', 64),
            trunc($o->issue ?? '', 32),
            json_encode($o, JSON_FLAGS),
        ]);
    }
    $pdo->commit();
    echo '[ok] tokens: ' . count($items) . " rows\n";
    bump_version($pdo, 'tokens_version');
    $didAnything = true;
} else {
    echo "[skip] imports/tokens.js not found\n";
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
    echo "\nNothing imported — put the data team's files into the imports/ folder first.\n";
}

// ══════════════════════════════════════════════════════════════════════════════

function first_existing(array $paths): ?string
{
    foreach ($paths as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }
    return null;
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

/** Reads a JSON array from a bare .json file or a "window.X = [...];"-style .js file. */
function read_json_array(string $file): array
{
    $s = trim(file_get_contents($file));
    if ($s === '' || $s[0] !== '[') {
        $start = strpos($s, '[');
        $end   = strrpos($s, ']');
        if ($start === false || $end === false || $end <= $start) {
            fail(basename($file) . ': could not locate the JSON array');
        }
        $s = substr($s, $start, $end - $start + 1);
    }
    $items = json_decode($s);
    if (!is_array($items)) {
        fail(basename($file) . ': invalid JSON (' . json_last_error_msg() . ')');
    }
    return $items;
}

/**
 * Streaming line-parser for data.js. Relies on the file being generated by
 * the data team's pipeline (python json.dump, indent=2), which guarantees:
 *   - `const THEMES = [...];` on one line
 *   - inside DATA, each state starts with `  "XX": {` and ends with a line
 *     that is exactly `  }` or `  },` (nested lines are indented deeper)
 *   - inside TEMPLATES, each element starts `  {` and ends `  }` / `  },`
 *   - everything from `const _SK` down is the DATA_MODULE code
 */
function import_data_js(PDO $pdo, string $file): void
{
    $fh = fopen($file, 'r');
    if (!$fh) {
        fail('could not open ' . basename($file));
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM states');
    $pdo->exec('DELETE FROM tour_rows');
    $pdo->exec('DELETE FROM templates');

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

    $mode      = null;   // null | 'data' | 'templates' | 'module'
    $buf       = null;   // accumulating JSON text of one state / template
    $curState  = null;
    $stateSeq  = 0;
    $tplSeq    = 0;
    $rowCount  = 0;
    $module    = '';
    $gotThemes = false;

    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($mode === 'module') {
            $module .= $line . "\n";
            continue;
        }

        if ($mode === null) {
            if (strpos($line, 'const THEMES = ') === 0) {
                $themes = json_decode(rtrim(substr($line, 15), " ;"));
                if (!is_array($themes)) {
                    fail('data.js: could not parse the THEMES line');
                }
                set_setting($pdo, 'themes', json_encode($themes, JSON_FLAGS));
                $gotThemes = true;
            } elseif ($line === 'const DATA = {') {
                $mode = 'data';
            } elseif ($line === 'const TEMPLATES = [') {
                $mode = 'templates';
            } elseif (strpos($line, 'const _SK') === 0) {
                $mode   = 'module';
                $module = $line . "\n";
            }
            continue;
        }

        if ($mode === 'data') {
            if ($curState === null) {
                if (preg_match('/^  "([A-Za-z]+)": \{$/', $line, $m)) {
                    $curState = $m[1];
                    $buf      = "{\n";
                } elseif ($line === '};') {
                    $mode = null;
                }
                continue;
            }
            if ($line === '  }' || $line === '  },') {
                $st = json_decode($buf . '}');
                if (!is_object($st)) {
                    fail("data.js: state \"$curState\" is not valid JSON (" . json_last_error_msg() . ')');
                }
                $insState->execute([
                    $curState,
                    $stateSeq++,
                    (string) ($st->name ?? $curState),
                    json_encode($st->cities ?? new stdClass(), JSON_FLAGS),
                    json_encode($st->products ?? new stdClass(), JSON_FLAGS),
                    json_encode($st->seed ?? new stdClass(), JSON_FLAGS),
                    json_encode($st->templates ?? [], JSON_FLAGS),
                ]);
                foreach (($st->allRows ?? []) as $i => $row) {
                    $insRow->execute([
                        $curState,
                        $i,
                        trunc($row->tour ?? '', 255),
                        trunc($row->source ?? '', 64),
                        trunc($row->type ?? '', 32),
                        (int) ($row->day ?? 0),
                        trunc($row->city ?? '', 128),
                        json_encode($row, JSON_FLAGS),
                    ]);
                    $rowCount++;
                }
                echo "  [ok] $curState: " . count($st->allRows ?? []) . " rows\n";
                $curState = null;
                $buf      = null;
            } else {
                $buf .= $line . "\n";
            }
            continue;
        }

        if ($mode === 'templates') {
            if ($buf === null) {
                if ($line === '  {') {
                    $buf = "{\n";
                } elseif ($line === '];') {
                    $mode = null;
                }
                continue;
            }
            if ($line === '  }' || $line === '  },') {
                $tpl = json_decode($buf . '}');
                if (!is_object($tpl)) {
                    fail('data.js: template #' . ($tplSeq + 1) . ' is not valid JSON');
                }
                $insTpl->execute([
                    $tplSeq++,
                    trunc($tpl->name ?? '', 255),
                    trunc($tpl->source ?? '', 64),
                    trunc($tpl->type ?? '', 32),
                    json_encode($tpl, JSON_FLAGS),
                ]);
                $buf = null;
            } else {
                $buf .= $line . "\n";
            }
            continue;
        }
    }
    fclose($fh);

    if (!$gotThemes || $stateSeq === 0 || $tplSeq === 0) {
        fail('data.js: parsing finished but something is missing '
            . "(themes: " . ($gotThemes ? 'yes' : 'NO')
            . ", states: $stateSeq, templates: $tplSeq) — file format changed?");
    }

    $pdo->commit();
    echo "[ok] states: $stateSeq · tour_rows: $rowCount · templates: $tplSeq\n";

    if ($module !== '') {
        file_put_contents(__DIR__ . '/data_module.js', $module);
        echo "[ok] data_module.js regenerated from the module code in data.js\n";
    } else {
        echo "[note] no DATA_MODULE code found in data.js — kept the existing data_module.js\n";
    }
}

function set_setting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$name, $value]);
}

function fail(string $msg): void
{
    echo "[FAIL] $msg\n";
    exit(1);
}
