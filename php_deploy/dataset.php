<?php
/**
 * dataset.php — serves the app's data as JavaScript, built from MySQL.
 *
 * Replaces the old static files:
 *   dataset.php?f=data    → const THEMES / const DATA / const TEMPLATES   (old data.js)
 *   dataset.php?f=market  → window.MARKET_DATA                            (old market.js)
 *   dataset.php?f=tokens  → const TOKENS + window.TOKENS                  (old tokens.js)
 *
 * How it stays fast despite ~26 MB of data:
 *   - The JS is assembled from the DB only once per import, into cache/<f>.v<N>.js
 *     (rows are stored as JSON strings, so assembly is pure concatenation —
 *     nothing big is ever decoded or held in memory).
 *   - Every response carries an ETag of the dataset version. Browsers
 *     revalidate and get a tiny 304 instead of re-downloading until the data
 *     team's next import bumps the version.
 */

$config = require __DIR__ . '/config.php';

$f = $_GET['f'] ?? '';
if (!in_array($f, ['data', 'market', 'tokens'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Unknown dataset';
    exit;
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $version = get_setting($pdo, $f . '_version');
    if ($version === null) {
        http_response_code(503);
        header('Content-Type: text/plain');
        echo "Dataset '$f' has not been imported yet — run import.php first.";
        exit;
    }

    // 304 if the browser already has this version
    $etag = "\"$f-v$version\"";
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }

    $cacheDir  = __DIR__ . '/cache';
    $cacheFile = "$cacheDir/$f.v$version.js";
    if (!is_file($cacheFile)) {
        build_cache($pdo, $f, $cacheFile);
        // drop caches of older versions of this dataset
        foreach (glob("$cacheDir/$f.v*.js") ?: [] as $old) {
            if ($old !== $cacheFile) {
                @unlink($old);
            }
        }
    }

    header('Content-Type: application/javascript; charset=utf-8');
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache');   // may reuse cached copy after a 304 revalidate
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'dataset.php error: ' . $e->getMessage();
}

// ══════════════════════════════════════════════════════════════════════════════

function get_setting(PDO $pdo, string $name): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute([$name]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

function build_cache(PDO $pdo, string $f, string $cacheFile): void
{
    set_time_limit(300);
    $tmp = $cacheFile . '.' . uniqid('', true) . '.tmp';
    $out = fopen($tmp, 'w');
    if (!$out) {
        throw new RuntimeException('cache/ folder is not writable');
    }

    if ($f === 'data') {
        $themes = get_setting($pdo, 'themes') ?? '[]';
        fwrite($out, "const THEMES = $themes;\nconst DATA = {\n");

        $states = $pdo->query('SELECT abbr, name, cities, products, seed, templates FROM states ORDER BY seq')
                      ->fetchAll(PDO::FETCH_ASSOC);
        $rowStmt = $pdo->prepare('SELECT row FROM tour_rows WHERE state = ? ORDER BY seq');

        foreach ($states as $i => $st) {
            fwrite($out, ($i ? ",\n" : '')
                . json_encode($st['abbr']) . ': {'
                . '"name": ' . json_encode($st['name'], JSON_UNESCAPED_UNICODE)
                . ', "abbr": ' . json_encode($st['abbr'])
                . ', "allRows": [');
            $rowStmt->execute([$st['abbr']]);
            $first = true;
            while (($row = $rowStmt->fetchColumn()) !== false) {
                fwrite($out, ($first ? '' : ",\n") . $row);
                $first = false;
            }
            fwrite($out, ']'
                . ', "cities": ' . $st['cities']
                . ', "products": ' . $st['products']
                . ', "seed": ' . $st['seed']
                . ', "templates": ' . $st['templates']
                . '}');
        }
        fwrite($out, "\n};\nconst TEMPLATES = [");
        stream_rows($pdo, $out, 'SELECT data FROM templates ORDER BY seq');
        fwrite($out, "];\n");
    } elseif ($f === 'market') {
        fwrite($out, 'window.MARKET_DATA = [');
        stream_rows($pdo, $out, 'SELECT data FROM market_tours ORDER BY seq');
        fwrite($out, "];\n");
    } else { // tokens
        fwrite($out, 'const TOKENS = [');
        stream_rows($pdo, $out, 'SELECT data FROM tokens ORDER BY seq');
        fwrite($out, "];\nwindow.TOKENS = TOKENS;\n");
    }

    fclose($out);
    if (!rename($tmp, $cacheFile)) {   // atomic-ish swap; loser of a race just overwrites
        @unlink($tmp);
        throw new RuntimeException('could not move cache file into place');
    }
}

function stream_rows(PDO $pdo, $out, string $sql): void
{
    $stmt = $pdo->query($sql);
    $first = true;
    while (($json = $stmt->fetchColumn()) !== false) {
        fwrite($out, ($first ? '' : ",\n") . $json);
        $first = false;
    }
}
