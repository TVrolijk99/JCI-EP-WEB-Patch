<?php
/*
 * JCI-EP-WEB-Patch — SQLite + per-table-config helpers voor de trend-pagina.
 * Copyright (C) 2026 Dymotica B.V. <info@dymotica.nl>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 * ----
 *
 * Reads trend tables from a single SQLite database. Each table = one chart
 * with columns:
 *   - "dt"  TEXT (datetime)        -- timestamp
 *   - <name> REAL/FLOAT/...        -- analog points
 *   - <name> BOOL/BOOLEAN/INTEGER  -- binary points
 *
 * The sidebar surfaces a "Trend" submenu listing every non-empty table found
 * in this database; clicking a table opens app/trend.php?table=<name>.
 *
 * --- ADJUST THIS PATH IF YOUR TREND DATA IS ELSEWHERE ---
 */
if (!defined('JCI_TREND_DB_PATH')) {
    // Stored at <DOCUMENT_ROOT>/sdcard/easyio.db on the EasyIO controller
    // (e.g. /var/www/public/sdcard/easyio.db) — the same file phpliteadmin
    // serves via ./easyio.db relative to /sdcard/phpliteadmin.php.
    // Path is intentionally absolute-ish; jciTrendResolvePath() also tries
    // DOCUMENT_ROOT-prefixed if the literal isn't reachable.
    define('JCI_TREND_DB_PATH', '/sdcard/easyio.db');
}

function jciTrendDbPath() {
    return defined('JCI_TREND_DB_PATH') ? JCI_TREND_DB_PATH : '';
}

/** Path to the per-installation config file (units + state-texts +
 *  visibility per table). Lives inside app/grdata/ because the firmware's
 *  backup_restore.php whitelist only restores a fixed set of subdirs
 *  (grdata, graphics, cpt-web.db, ...). Anything written directly to app/
 *  is included in the BACKUP but NOT copied back during RESTORE — it would
 *  silently keep whatever the controller had before the restore. grdata is
 *  in the whitelist, so storing here makes backup→restore round-trip the
 *  units/stateTexts as expected. */
function jciTrendConfigPath() {
    return __DIR__ . '/grdata/trend_config.json';
}

/** Pre-2026-05 location. Migrated on first read/save so existing
 *  installations don't lose their config when this file ships. */
function jciTrendLegacyConfigPath() {
    return __DIR__ . '/trend_config.json';
}

/** If the new path is empty but the legacy file exists, move it once. */
function jciTrendMigrateLegacyConfig() {
    $new = jciTrendConfigPath();
    $old = jciTrendLegacyConfigPath();
    if (file_exists($new) || !file_exists($old)) return;
    $dir = dirname($new);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    if (@copy($old, $new)) @unlink($old);
}

/** Read the entire config object. Returns associative array keyed by table. */
function jciTrendLoadConfig() {
    jciTrendMigrateLegacyConfig();
    $p = jciTrendConfigPath();
    if (!file_exists($p) || !is_readable($p)) return array();
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') return array();
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

/** Persist the entire config object. Returns true on success. */
function jciTrendSaveConfig($all) {
    jciTrendMigrateLegacyConfig();
    $p = jciTrendConfigPath();
    $dir = dirname($p);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return false;
    $json = json_encode($all, JSON_PRETTY_PRINT);
    if ($json === false) return false;
    // Atomic write via temp file + rename.
    $tmp = $p . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) === false) return false;
    if (!@rename($tmp, $p)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Get config for a specific table, or empty defaults. */
function jciTrendTableConfig($table) {
    $all = jciTrendLoadConfig();
    if (isset($all[$table]) && is_array($all[$table])) return $all[$table];
    return array(
        'visible' => array(),
        'units' => array(),
        'stateTexts' => array()
    );
}

/** Set config for a specific table (merges into existing). */
function jciTrendSetTableConfig($table, $cfg) {
    $all = jciTrendLoadConfig();
    $all[$table] = array(
        'visible' => isset($cfg['visible']) && is_array($cfg['visible']) ? $cfg['visible'] : array(),
        'units' => isset($cfg['units']) && is_array($cfg['units']) ? $cfg['units'] : array(),
        'stateTexts' => isset($cfg['stateTexts']) && is_array($cfg['stateTexts']) ? $cfg['stateTexts'] : array()
    );
    return jciTrendSaveConfig($all);
}

/** Resolve the configured DB path. If the literal path doesn't exist, try
 *  DOCUMENT_ROOT-prefixed and a couple of common controller locations. */
function jciTrendResolvePath() {
    $p = jciTrendDbPath();
    if (!$p) return '';
    $candidates = array($p);
    if (isset($_SERVER['DOCUMENT_ROOT']) && $p !== '' && $p[0] === '/') {
        $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $p;
    }
    foreach ($candidates as $c) {
        if (file_exists($c) && is_readable($c)) return $c;
    }
    return '';
}

/** PDO connection or null if the database isn't reachable. */
function jciTrendOpenDb() {
    $path = jciTrendResolvePath();
    if (!$path) return null;
    try {
        $db = new PDO("sqlite:" . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        return $db;
    } catch (PDOException $e) {
        error_log('[trend] open db failed: ' . $e->getMessage());
        return null;
    }
}

/** List user tables in the trend DB, sorted alphabetically. */
function jciTrendListTables() {
    $db = jciTrendOpenDb();
    if (!$db) return array();
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    if (!$stmt) return array();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return is_array($rows) ? $rows : array();
}

/** Resolve a user-supplied table name to a real table in the DB.
 *  Returns the actual table name as stored, or '' if not found. Prevents
 *  SQL injection by whitelisting against sqlite_master. */
function jciTrendResolveTable($db, $name) {
    if (!$db || $name === '' || $name === null) return '';
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :n LIMIT 1");
    $stmt->bindValue(':n', (string)$name);
    if (!$stmt->execute()) return '';
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['name']) ? $row['name'] : '';
}

/** Escape an identifier for use inside double-quoted SQL by doubling any
 *  embedded double-quote. Use after the identifier is already validated. */
function jciTrendQuoteIdent($s) {
    return '"' . str_replace('"', '""', (string)$s) . '"';
}

/** PRAGMA table_info — returns array of {name, type, ...}. */
function jciTrendSchema($table) {
    $db = jciTrendOpenDb();
    if (!$db) return array();
    $real = jciTrendResolveTable($db, $table);
    if ($real === '') return array();
    $stmt = $db->query('PRAGMA table_info(' . jciTrendQuoteIdent($real) . ')');
    if (!$stmt) return array();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Run a windowed SELECT on the table. Column names (which may contain
 * spaces, accented chars, etc.) are validated against PRAGMA table_info to
 * prevent SQL injection while supporting any legitimate identifier.
 *
 * `$tzOffsetMin` is the browser's local UTC offset in minutes. The dt
 * column (stored as UTC on the controller) is shifted by that amount in
 * the SELECT so the returned strings are already in the operator's local
 * time — matching the convention used by the original sedona plugin.
 */
function jciTrendQuery($table, $from, $to, $cols, $tzOffsetMin = 0) {
    $db = jciTrendOpenDb();
    if (!$db) return array();
    $real = jciTrendResolveTable($db, $table);
    if ($real === '') return array();

    // Validate requested columns against the actual schema.
    $allowed = array();
    $schemaStmt = $db->query('PRAGMA table_info(' . jciTrendQuoteIdent($real) . ')');
    if ($schemaStmt) {
        foreach ($schemaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name'])) $allowed[$row['name']] = true;
        }
    }

    $colSql = '';
    if (is_array($cols)) {
        foreach ($cols as $c) {
            if ($c === 'dt' || $c === '' || !isset($allowed[$c])) continue;
            $colSql .= ', ' . jciTrendQuoteIdent($c);
        }
    }
    // Convert dt to user's local time on BOTH sides of the comparison: the
    // SELECT shifts dt for display, the WHERE shifts it for filtering. Both
    // `from`/`to` from the JS are wall-clock strings (matching the picker's
    // wall-clock display), so this keeps both sides in the same coordinate
    // space. Without this, an N-minute offset between picker and DB-UTC
    // bled into the query window, producing data shifted by tz_offset and
    // an empty band on the chart's left edge.
    $offset = (int)$tzOffsetMin;
    $offsetSql = ($offset >= 0 ? '+' : '') . $offset . ' minutes';
    $sql = 'SELECT datetime("dt", :tzoffset1) as "dt"' . $colSql .
           ' FROM ' . jciTrendQuoteIdent($real) .
           ' WHERE datetime("dt", :tzoffset2) >= :from AND datetime("dt", :tzoffset3) <= :to' .
           ' ORDER BY "dt" ASC LIMIT 50000';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':tzoffset1', $offsetSql);
    $stmt->bindValue(':tzoffset2', $offsetSql);
    $stmt->bindValue(':tzoffset3', $offsetSql);
    $stmt->bindValue(':from', (string)$from);
    $stmt->bindValue(':to',   (string)$to);
    if (!$stmt->execute()) return array();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
