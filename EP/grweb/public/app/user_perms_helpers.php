<?php
/*
 * JCI-EP-WEB-Patch — per-user feature flags + cpt-web.db lookups.
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
 * Three boolean flags per user, on top of the JCI native account flags:
 *   - alarm_ack    : user may acknowledge alarms in the alarm manager
 *   - alarm_delete : user may delete alarms (history pane bulk delete + per-row)
 *   - trend_edit   : user may edit per-table units / state texts in the trend page
 *
 * Default for any user not present in the file = ALL TRUE. New installations
 * therefore behave like before until the admin starts restricting roles.
 *
 * Admins (User::isAdmin() === true) bypass every check — they always pass.
 *
 * Storage:
 *   app/grdata/user_perms.json (under grdata/ so the firmware's
 *   backup_restore.php whitelist round-trips it on restore — same reason
 *   as trend_config.json). Atomic temp+rename writes.
 *
 * Identifier:
 *   We key by user_id (the numeric id from the user DB, the same value
 *   bound to data-userid="…" in the Backbone account templates). It's
 *   stable across renames; the username is used only for display.
 */

/** Path to the per-installation perms file. */
function jciUserPermsPath() {
    return __DIR__ . '/grdata/user_perms.json';
}

/** Read the entire perms object. Returns associative array keyed by user_id (string). */
function jciUserPermsLoad() {
    $p = jciUserPermsPath();
    if (!file_exists($p) || !is_readable($p)) return array();
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') return array();
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

/** Persist the entire perms object atomically. Returns true on success. */
function jciUserPermsSave($all) {
    $p = jciUserPermsPath();
    $dir = dirname($p);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return false;
    $json = json_encode($all, JSON_PRETTY_PRINT);
    if ($json === false) return false;
    $tmp = $p . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) === false) return false;
    if (!@rename($tmp, $p)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Coerce anything truthy-ish into a bool. Strings 'true'/'1'/'on' = true. */
function jciUserPermsBool($v) {
    if (is_bool($v))   return $v;
    if (is_int($v))    return $v !== 0;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        return ($s === 'true' || $s === '1' || $s === 'on' || $s === 'yes');
    }
    return false;
}

/** Get effective perms for a user_id. Missing keys default to true. */
function jciUserPermsGet($userId) {
    $all = jciUserPermsLoad();
    $key = (string)$userId;
    $out = array(
        'alarm_ack'    => true,
        'alarm_delete' => true,
        'trend_edit'   => true,
    );
    if (isset($all[$key]) && is_array($all[$key])) {
        foreach ($out as $k => $_) {
            if (array_key_exists($k, $all[$key])) {
                $out[$k] = jciUserPermsBool($all[$key][$k]);
            }
        }
    }
    return $out;
}

/** Set perms for one user_id. $perms is an array with any of the three keys.
 *  Missing keys are kept as-is. Returns true on success. */
function jciUserPermsSet($userId, $perms) {
    $all = jciUserPermsLoad();
    $key = (string)$userId;
    $existing = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : array();
    foreach (array('alarm_ack', 'alarm_delete', 'trend_edit') as $k) {
        if (array_key_exists($k, $perms)) {
            $existing[$k] = jciUserPermsBool($perms[$k]);
        }
    }
    $all[$key] = $existing;
    return jciUserPermsSave($all);
}

/** Pure check — does NOT consider admin-override. Call-sites must combine
 *  with their own isAdmin() check. Default for unknown user_id = true. */
function jciUserCan($userId, $perm) {
    $p = jciUserPermsGet($userId);
    return !empty($p[$perm]);
}

/** Resolve the path to cpt-web.db on the controller. Probes the locations
 *  observed in the wild (the firmware's actual file is at
 *  DOCUMENT_ROOT/sdcard/cpt/app/cpt-web.db on this user's controller)
 *  and falls back to a couple of alternates seen on other firmware
 *  variants. Returns '' if no candidate is readable. */
function jciCptDbPath() {
    $candidates = array();
    if (isset($_SERVER['DOCUMENT_ROOT'])) {
        $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $candidates[] = $root . '/sdcard/cpt/app/cpt-web.db';
        $candidates[] = $root . '/sdcard/server/grweb/public/app/cpt-web.db';
        $candidates[] = $root . '/sdcard/cpt-web.db';
    }
    $candidates[] = __DIR__ . '/cpt-web.db';
    foreach ($candidates as $c) {
        if (file_exists($c) && is_readable($c)) return $c;
    }
    return '';
}

/** Open a read-only PDO handle on cpt-web.db. Returns null if not found
 *  or unopenable. Errors are silenced — call-sites already gracefully
 *  handle empty results (default to "no restriction"). */
function jciCptDbOpen() {
    $p = jciCptDbPath();
    if (!$p) return null;
    try {
        $db = new PDO('sqlite:' . $p);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

/** Return the list of grpaths the given user has explicit NoAccess on
 *  (readable='f' in cpt-web.db `permissions` table). Empty for admins,
 *  empty if the DB isn't reachable. Used by menu_load.js to hide
 *  entries from the SPACE tree. */
function jciCptNoAccessPaths($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return array();
    $db = jciCptDbOpen();
    if (!$db) return array();
    try {
        $stmt = $db->prepare("SELECT path FROM permissions WHERE user_id = :uid AND readable = 'f'");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        if (!$stmt->execute()) return array();
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return is_array($paths) ? $paths : array();
    } catch (Exception $e) {
        return array();
    }
}

/** Lookup the user's home_page string from cpt-web.db. Used as a
 *  fallback when BaseController::userHomePage() returns a URL we
 *  can't parse (or when we want the raw grpath, not a URL). */
function jciCptUserHomePath($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return '';
    $db = jciCptDbOpen();
    if (!$db) return '';
    try {
        $stmt = $db->prepare("SELECT home_page FROM users WHERE id = :uid LIMIT 1");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        if (!$stmt->execute()) return '';
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && isset($row['home_page'])) ? (string)$row['home_page'] : '';
    } catch (Exception $e) {
        return '';
    }
}
