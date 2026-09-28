<?php
// index.php — gated entry point for the Itinerary Builder.
// Runs the login + TOTP 2FA gate, then serves the app (builder.html, which
// stays a plain static file; direct access to it is blocked in .htaccess).
require __DIR__ . '/auth_guard.php'; // main-dashboard login + TOTP 2FA gate; sets $IS_ADMIN = true

include __DIR__ . '/../dbconn.php';
require_once __DIR__ . '/../product-management/activity_log.php';
log_view($conn, 'Itinerary Builder');

readfile(__DIR__ . '/builder.html');
