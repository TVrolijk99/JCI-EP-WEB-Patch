<?php
/*
 * JCI-EP-WEB-Patch — HTTP API voor patch-permissies (alarm_ack / alarm_delete / trend_edit).
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
 * Endpoints:
 *   GET  ?cmd=get-mine                         -> { perms: {alarm_ack, alarm_delete, trend_edit}, is_admin: bool }
 *                                                 (any signed-in user; effective perms for self)
 *   GET  ?cmd=get-all                          -> { perms: { <user_id>: {...}, ... } }
 *                                                 (admin only)
 *   POST cmd=save&user_id=&alarm_ack=&...      -> { ok: true, perms: {...} }
 *                                                 (admin only)
 *
 * BaseController quirks (same as trend_api.php):
 *   - HTML-escapes $_POST -> for safety we read php://input + parse_str on POST.
 *   - Routes XHR to doAjax* -> we override both.
 */
include_once "db.php";
include_once "settings.php";
include_once "feature_control.php";
include_once "base_controller.php";
include_once "user_perms_helpers.php";

class UserPermsApiController extends BaseController {
    protected function signinRequired() { return true; }
    protected function authKeyRequired() { return false; }

    protected function doAjaxGet()  { $this->doGet();  }
    protected function doAjaxPost() { $this->doPost(); }

    protected function doGet() {
        header('Content-type: application/json; charset=utf-8');
        $cmd = isset($_GET['cmd']) ? $_GET['cmd'] : '';
        $u = $this->curUser();
        switch ($cmd) {
            case 'get-mine':
                $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
                $isAdmin = $u && $u->isAdmin();
                $perms = jciUserPermsGet($userId);
                if ($isAdmin) {
                    // Admins always pass every check; surface that to the
                    // client so UI hide-logic doesn't have to special-case.
                    $perms = array('alarm_ack' => true, 'alarm_delete' => true, 'trend_edit' => true);
                }
                echo json_encode(array(
                    'perms'    => $perms,
                    'is_admin' => $isAdmin,
                    'user_id'  => (string)$userId
                ));
                return;

            case 'get-all':
                if (!$u || !$u->isAdmin()) {
                    http_response_code(403);
                    echo json_encode(array('error' => 'admin only'));
                    return;
                }
                echo json_encode(array(
                    'perms' => jciUserPermsLoad()
                ));
                return;

            case 'get-grpath-noaccess':
                // Returns the list of grpaths the current user has
                // explicit NoAccess on (readable='f' in cpt-web.db).
                // menu_load.js consumes this to hide entries from the
                // SPACE tree. Admin = empty (sees everything).
                if (!$u) {
                    http_response_code(403);
                    echo json_encode(array('error' => 'no session'));
                    return;
                }
                if ($u->isAdmin()) {
                    echo json_encode(array('paths' => array()));
                    return;
                }
                $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
                echo json_encode(array('paths' => jciCptNoAccessPaths($userId)));
                return;

            case 'debug-cpt':
                // Diagnostic endpoint. Dumps cpt-web.db schema + a sample
                // of every table so we can build a reliable per-user grpath
                // permission filter for menu_load.js. Admin only.
                if (!$u || !$u->isAdmin()) {
                    http_response_code(403);
                    echo json_encode(array('error' => 'admin only'));
                    return;
                }
                $candidates = array(
                    __DIR__ . '/cpt-web.db',
                );
                if (isset($_SERVER['DOCUMENT_ROOT'])) {
                    $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/sdcard/server/grweb/public/app/cpt-web.db';
                    $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/sdcard/cpt-web.db';
                }
                $found = '';
                foreach ($candidates as $c) {
                    if (file_exists($c) && is_readable($c)) { $found = $c; break; }
                }
                $out = array(
                    'candidates' => $candidates,
                    'found'      => $found,
                    'session_user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
                );
                if ($found) {
                    try {
                        $db = new PDO('sqlite:' . $found);
                        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
                                     ->fetchAll(PDO::FETCH_COLUMN, 0);
                        $out['tables'] = array();
                        foreach ($tables as $t) {
                            $cols = $db->query('PRAGMA table_info(' . '"' . str_replace('"', '""', $t) . '"' . ')')
                                       ->fetchAll(PDO::FETCH_ASSOC);
                            $sample = $db->query('SELECT * FROM ' . '"' . str_replace('"', '""', $t) . '"' . ' LIMIT 5')
                                         ->fetchAll(PDO::FETCH_ASSOC);
                            $out['tables'][$t] = array(
                                'columns' => $cols,
                                'sample'  => $sample,
                            );
                        }
                    } catch (Exception $e) {
                        $out['error'] = $e->getMessage();
                    }
                }
                echo json_encode($out, JSON_PRETTY_PRINT);
                return;

            default:
                http_response_code(400);
                echo json_encode(array('error' => 'unknown cmd'));
                return;
        }
    }

    protected function doPost() {
        header('Content-type: application/json; charset=utf-8');
        $u = $this->curUser();
        if (!$u || !$u->isAdmin()) {
            http_response_code(403);
            echo json_encode(array('error' => 'admin only'));
            return;
        }

        // BaseController auto-encodes $_POST. Read the raw body for safety,
        // even though we only carry simple bool/string fields here.
        $rawBody = file_get_contents('php://input');
        $body = array();
        parse_str($rawBody, $body);

        $cmd = isset($body['cmd']) ? $body['cmd'] : (isset($_GET['cmd']) ? $_GET['cmd'] : '');
        if ($cmd !== 'save') {
            http_response_code(400);
            echo json_encode(array('error' => 'unknown cmd'));
            return;
        }

        $userId = isset($body['user_id']) ? (string)$body['user_id'] : '';
        if ($userId === '') {
            http_response_code(400);
            echo json_encode(array('error' => 'missing user_id'));
            return;
        }

        $patch = array();
        foreach (array('alarm_ack', 'alarm_delete', 'trend_edit') as $k) {
            if (array_key_exists($k, $body)) {
                $patch[$k] = jciUserPermsBool($body[$k]);
            }
        }
        if (empty($patch)) {
            http_response_code(400);
            echo json_encode(array('error' => 'no perms in payload'));
            return;
        }

        $ok = jciUserPermsSet($userId, $patch);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(array(
                'error' => 'save failed (check write permissions on ' . jciUserPermsPath() . ')'
            ));
            return;
        }
        echo json_encode(array(
            'ok'    => true,
            'perms' => jciUserPermsGet($userId)
        ));
    }
}

$controller = new UserPermsApiController();
$controller->run();
