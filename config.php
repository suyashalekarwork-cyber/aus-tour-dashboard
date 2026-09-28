<?php
/**
 * config.php — fill in your cPanel MySQL details before uploading.
 *
 * Create the database and user in cPanel → "MySQL Databases", grant the user
 * ALL PRIVILEGES on the database, then put the three values here. On cPanel
 * both the database and the user are prefixed with your account name,
 * e.g. account name "tdu" → database "tdu_itinerary", user "tdu_appuser".
 */
// LOCAL DEV COPY (XAMPP on this machine) — not the production config.
// Uses the local `test` MySQL user/db that IT already set up in XAMPP.
return [
    // ── MySQL (local XAMPP) ───────────────────────────────────────────
    'db_host' => 'localhost',
    'db_name' => 'test',
    'db_user' => 'test',
    'db_pass' => 'Gtx1234*',

    // ── Data imports ──────────────────────────────────────────────────
    // Required as ?key=... when running import.php (so only your team can
    // trigger a data reload). Change it to any random string.
    'import_key' => 'local-dev-key',

    // ── AI prediction proxy (same values as the old server.py) ───────
    'proxy_url' => 'CHANGE_ME',
    'proxy_key' => 'CHANGE_ME',
];
