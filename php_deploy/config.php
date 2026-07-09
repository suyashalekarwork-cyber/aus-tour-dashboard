<?php
/**
 * config.php — fill in your cPanel MySQL details before uploading.
 *
 * Create the database and user in cPanel → "MySQL Databases", grant the user
 * ALL PRIVILEGES on the database, then put the three values here. On cPanel
 * both the database and the user are prefixed with your account name,
 * e.g. account name "tdu" → database "tdu_itinerary", user "tdu_appuser".
 */
return [
    // ── MySQL (cPanel) ────────────────────────────────────────────────
    'db_host' => 'localhost',          // almost always "localhost" on cPanel
    'db_name' => 'CPANELUSER_tdu',     // ← change
    'db_user' => 'CPANELUSER_tdu',     // ← change
    'db_pass' => 'CHANGE_ME',          // ← change

    // ── Data imports ──────────────────────────────────────────────────
    // Required as ?key=... when running import.php (so only your team can
    // trigger a data reload). Change it to any random string.
    'import_key' => 'testkey123',

    // ── AI prediction proxy (same values as the old server.py) ───────
    'proxy_url' => 'https://gtxn8n.yourbestwayhome.com.au/webhook/openai-response-proxy',
    'proxy_key' => 'Gtx1234*',
];
