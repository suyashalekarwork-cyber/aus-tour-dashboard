-- TDU Itinerary Builder — MySQL schema
--
-- OPTIONAL: install.php (the one-time setup script) creates all of these
-- tables AND imports the old saved_itineraries.json. Import this file in
-- phpMyAdmin only if you prefer to create the empty tables by hand instead.
--
-- App data (states/tour_rows/templates/market_tours/tokens) is loaded by
-- import.php from the data team's files in imports/.

CREATE TABLE IF NOT EXISTS itineraries (
    seq        INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,  -- also preserves save order
    id         VARCHAR(191) NOT NULL,
    data       LONGTEXT     NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
               ON UPDATE CURRENT_TIMESTAMP,
    -- id MUST stay unique: the save endpoint upserts on it, delete deletes by
    -- it, and the UI keys the list by it. Collision-proofing comes from the
    -- random suffix the frontend adds to each id, not from dropping this.
    UNIQUE KEY uq_id (id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── Phase 2: app data (from the data team's exports) ─────────────────────────

-- Small key/value store: dataset versions (data_version, market_version,
-- tokens_version) and the THEMES list.
CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64)  NOT NULL PRIMARY KEY,
    value      LONGTEXT     NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
               ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One row per Australian state (from DATA in data.js). The nested per-state
-- structures stay as JSON documents; the day-by-day rows live in tour_rows.
CREATE TABLE IF NOT EXISTS states (
    abbr      VARCHAR(8)   NOT NULL PRIMARY KEY,
    seq       INT          NOT NULL,             -- display order in the app
    name      VARCHAR(64)  NOT NULL,
    cities    LONGTEXT     NOT NULL,             -- JSON object
    products  LONGTEXT     NOT NULL,             -- JSON object
    seed      LONGTEXT     NOT NULL,             -- JSON object
    templates LONGTEXT     NOT NULL              -- JSON array (state-scoped)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One row per itinerary-day line (DATA[state].allRows).
CREATE TABLE IF NOT EXISTS tour_rows (
    id     INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    state  VARCHAR(8)   NOT NULL,
    seq    INT          NOT NULL,                -- order within the state
    tour   VARCHAR(255) NOT NULL DEFAULT '',
    source VARCHAR(64)  NOT NULL DEFAULT '',
    type   VARCHAR(32)  NOT NULL DEFAULT '',
    day    INT          NOT NULL DEFAULT 0,
    city   VARCHAR(128) NOT NULL DEFAULT '',
    row    LONGTEXT     NOT NULL,                -- full original JSON row
    KEY idx_state_seq (state, seq),
    KEY idx_tour (tour)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One row per global template (TEMPLATES in data.js).
CREATE TABLE IF NOT EXISTS templates (
    id     INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    seq    INT          NOT NULL,
    name   VARCHAR(255) NOT NULL DEFAULT '',
    source VARCHAR(64)  NOT NULL DEFAULT '',
    type   VARCHAR(32)  NOT NULL DEFAULT '',
    data   LONGTEXT     NOT NULL,                -- full original JSON template
    KEY idx_seq (seq)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One row per market-intelligence tour (window.MARKET_DATA in market.js).
CREATE TABLE IF NOT EXISTS market_tours (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    seq         INT          NOT NULL,
    tour_name   VARCHAR(255) NOT NULL DEFAULT '',
    source      VARCHAR(64)  NOT NULL DEFAULT '',
    source_type VARCHAR(32)  NOT NULL DEFAULT '',
    market      VARCHAR(64)  NOT NULL DEFAULT '',
    state       VARCHAR(8)   NOT NULL DEFAULT '',
    data        LONGTEXT     NOT NULL,           -- full original JSON object
    KEY idx_seq (seq),
    KEY idx_market (market)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One row per keyword token (TOKENS in tokens.js).
CREATE TABLE IF NOT EXISTS tokens (
    id    INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    seq   INT          NOT NULL,
    name  VARCHAR(255) NOT NULL DEFAULT '',
    state VARCHAR(64)  NOT NULL DEFAULT '',
    issue VARCHAR(32)  NOT NULL DEFAULT '',
    data  LONGTEXT     NOT NULL,                 -- full original JSON object
    KEY idx_seq (seq)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
