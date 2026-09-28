<?php
/**
 * install.php — ONE-TIME setup script. Run once after uploading, then DELETE
 * the whole setup/ folder from the server.
 *
 * What it does:
 *   1. Creates all tables (from schema.sql, if they don't exist yet)
 *   2. Imports the old saved_itineraries.json into the itineraries table
 *      (skips ids that are already in the database, so re-running is safe)
 *
 * How to run: open  https://yourdomain/setup/install.php  in the browser
 *        (or:  php install.php  from an SSH terminal, inside setup/)
 */

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../config.php';

echo "TDU Itinerary Builder — one-time install\n";
echo "=========================================\n\n";

// ── 1. connect ────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "[ok] Connected to database '{$config['db_name']}'\n";
} catch (Throwable $e) {
    echo "[FAIL] Could not connect to MySQL: {$e->getMessage()}\n";
    echo "       → check db_name / db_user / db_pass in config.php\n";
    exit(1);
}

// ── 2. create tables (from schema.sql) ────────────────────────────────────────
$schemaFile = __DIR__ . '/schema.sql';
if (!is_readable($schemaFile)) {
    echo "[FAIL] schema.sql not found next to this script\n";
    exit(1);
}
$sql = file_get_contents($schemaFile);
// strip "-- ..." comment lines, then run each ;-terminated statement
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$created = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    try {
        $pdo->exec($stmt);
        $created++;
    } catch (Throwable $e) {
        echo "[FAIL] Statement failed: {$e->getMessage()}\n";
        echo "       → " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 100) . "...\n";
        exit(1);
    }
}
echo "[ok] Schema applied ($created statements) — all tables ready\n";

// ── 3. import saved_itineraries.json ──────────────────────────────────────────
$seedFile = __DIR__ . '/saved_itineraries.json';

if (!is_readable($seedFile)) {
    echo "[skip] No saved_itineraries.json found next to this script — nothing to import\n";
} else {
    $seed = json_decode(file_get_contents($seedFile), true);
    if (!is_array($seed)) {
        echo "[FAIL] saved_itineraries.json is not valid JSON — fix the file and re-run\n";
        exit(1);
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO itineraries (id, data) VALUES (?, ?)');
    $imported = 0;
    $skipped  = 0;
    $invalid  = 0;
    foreach ($seed as $item) {
        if (!is_array($item) || empty($item['id'])) {
            $invalid++;
            continue;
        }
        $stmt->execute([
            (string) $item['id'],
            json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        // rowCount is 0 when INSERT IGNORE hit an id that already exists
        $stmt->rowCount() > 0 ? $imported++ : $skipped++;
    }
    echo "[ok] Import from saved_itineraries.json: $imported imported, "
       . "$skipped already in database, $invalid without an id (skipped)\n";
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM itineraries')->fetchColumn();
echo "\nDone. The database now holds $total itinerarie(s).\n";
echo "\nNext: run ../import.php?key=YOUR_IMPORT_KEY to load the app data\n";
echo "(data.js / market.js / tokens.js from the imports/ folder) into MySQL.\n";
echo "\n>>> When both scripts have run, DELETE the whole setup/ folder from the server. <<<\n";
