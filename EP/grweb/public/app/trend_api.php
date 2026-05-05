<?php
/*
 * JCI-EP-WEB-Patch — HTTP API voor trend (list / schema / query / config).
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
 *   GET ?cmd=list-tables                            -> { tables: [...] }
 *   GET ?cmd=schema&table=<name>                    -> { schema: [{name,type,...}] }
 *   GET ?cmd=query&table=<name>&from=&to=&cols=...  -> { rows: [{dt,col1,col2}] }
 *
 * `from` / `to` are SQL datetime strings ("YYYY-MM-DD HH:MM:SS").
 * `cols` is a comma-separated list of column names; `dt` is always returned.
 */
include_once "db.php";
include_once "settings.php";
include_once "feature_control.php";
include_once "base_controller.php";
include_once "trend_helpers.php";
include_once "user_perms_helpers.php";

class TrendApiController extends BaseController {
    protected function signinRequired() { return true; }
    protected function authKeyRequired() { return false; }

    // jQuery $.ajax sends X-Requested-With: XMLHttpRequest, which the
    // controller framework dispatches to doAjaxGet. Without this override
    // AJAX requests get an empty response while direct browser hits work.
    protected function doAjaxGet() { $this->doGet(); }
    protected function doAjaxPost() { $this->doPost(); }

    protected function doPost() {
        $cmd = isset($_POST['cmd']) ? $_POST['cmd'] : (isset($_GET['cmd']) ? $_GET['cmd'] : '');
        header('Content-type: application/json; charset=utf-8');
        switch ($cmd) {
            case 'save-config':
                // Per-user permission gate: only users with trend_edit (or
                // admins) may write the per-table config. Default = allowed,
                // so existing setups keep working until an admin restricts.
                $u = $this->curUser();
                $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
                if (!($u && $u->isAdmin()) && !jciUserCan($userId, 'trend_edit')) {
                    http_response_code(403);
                    echo json_encode(array('error' => 'forbidden: trend_edit permission required'));
                    return;
                }

                // BaseController auto-encodes $_POST (HTML-escapes quotes,
                // strips non-ASCII), which corrupts JSON payloads. Read the
                // raw urlencoded body and parse it ourselves.
                $rawBody = file_get_contents('php://input');
                $body = array();
                parse_str($rawBody, $body);

                $table = isset($body['table']) ? (string)$body['table'] : '';
                if ($table === '') {
                    http_response_code(400);
                    echo json_encode(array('error' => 'missing table'));
                    return;
                }

                $rawVisible    = isset($body['visible'])    ? $body['visible']    : '';
                $rawUnits      = isset($body['units'])      ? $body['units']      : '';
                $rawStateTexts = isset($body['stateTexts']) ? $body['stateTexts'] : '';
                $decVisible    = json_decode($rawVisible,    true);
                $decUnits      = json_decode($rawUnits,      true);
                $decStateTexts = json_decode($rawStateTexts, true);

                $cfg = array(
                    'visible'    => is_array($decVisible)    ? $decVisible    : array(),
                    'units'      => is_array($decUnits)      ? $decUnits      : array(),
                    'stateTexts' => is_array($decStateTexts) ? $decStateTexts : array()
                );
                $ok = jciTrendSetTableConfig($table, $cfg);
                if (!$ok) {
                    http_response_code(500);
                    echo json_encode(array('error' => 'save failed (check write permissions on ' . jciTrendConfigPath() . ')'));
                    return;
                }
                echo json_encode(array(
                    'ok' => true,
                    'config' => jciTrendTableConfig($table)
                ));
                return;

            case 'save-visible':
                // Visibility-only update. Permitted for any signed-in user
                // regardless of trend_edit, because visibility is the user's
                // chosen-lines preference (just happens to be persisted
                // server-side here so all browsers see the same defaults).
                // Units / state-texts stay locked behind trend_edit via the
                // separate save-config command.
                $rawBody = file_get_contents('php://input');
                $body = array();
                parse_str($rawBody, $body);

                $table = isset($body['table']) ? (string)$body['table'] : '';
                if ($table === '') {
                    http_response_code(400);
                    echo json_encode(array('error' => 'missing table'));
                    return;
                }
                $rawVisible = isset($body['visible']) ? $body['visible'] : '';
                $decVisible = json_decode($rawVisible, true);
                if (!is_array($decVisible)) {
                    http_response_code(400);
                    echo json_encode(array('error' => 'visible must be a JSON object'));
                    return;
                }
                // Merge into existing table config so units / state-texts
                // are preserved.
                $existing = jciTrendTableConfig($table);
                $existing['visible'] = $decVisible;
                $ok = jciTrendSetTableConfig($table, $existing);
                if (!$ok) {
                    http_response_code(500);
                    echo json_encode(array('error' => 'save failed (check write permissions on ' . jciTrendConfigPath() . ')'));
                    return;
                }
                echo json_encode(array(
                    'ok' => true,
                    'config' => jciTrendTableConfig($table)
                ));
                return;

            default:
                http_response_code(400);
                echo json_encode(array('error' => 'unknown cmd'));
                return;
        }
    }

    protected function doGet() {
        $cmd = isset($_GET['cmd']) ? $_GET['cmd'] : '';
        header('Content-type: application/json; charset=utf-8');
        switch ($cmd) {
            case 'list-tables':
                echo json_encode(array(
                    'tables' => jciTrendListTables()
                ));
                return;
            case 'schema':
                $table = isset($_GET['table']) ? $_GET['table'] : '';
                echo json_encode(array(
                    'schema' => jciTrendSchema($table)
                ));
                return;
            case 'query':
                $table = isset($_GET['table']) ? $_GET['table'] : '';
                $from  = isset($_GET['from'])  ? $_GET['from']  : date('Y-m-d 00:00:00');
                $to    = isset($_GET['to'])    ? $_GET['to']    : date('Y-m-d 23:59:59');
                $cols  = isset($_GET['cols'])  ? array_filter(explode(',', $_GET['cols'])) : array();
                // Browser's UTC offset in minutes — used to shift the dt
                // column from UTC storage to the user's local time.
                $tz    = isset($_GET['tz'])    ? (int)$_GET['tz'] : 0;
                echo json_encode(array(
                    'rows' => jciTrendQuery($table, $from, $to, $cols, $tz)
                ));
                return;

            case 'get-config':
                $table = isset($_GET['table']) ? $_GET['table'] : '';
                if ($table === '') {
                    echo json_encode(array('config' => jciTrendLoadConfig()));
                } else {
                    echo json_encode(array('config' => jciTrendTableConfig($table)));
                }
                return;
            case 'debug':
                $configured = jciTrendDbPath();
                $resolved = jciTrendResolvePath();
                $info = array(
                    'configured_path' => $configured,
                    'resolved_path'   => $resolved,
                    'document_root'   => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '',
                    'candidates' => array(
                        'literal' => array(
                            'path'   => $configured,
                            'exists' => file_exists($configured),
                            'is_readable' => @is_readable($configured),
                            'size'   => @filesize($configured)
                        ),
                        'doc_root_prefixed' => array(
                            'path'   => isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $configured : '',
                            'exists' => isset($_SERVER['DOCUMENT_ROOT']) ? file_exists(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $configured) : false
                        )
                    ),
                    'common_locations' => array()
                );
                $tries = array(
                    '/easyio.db',
                    '/sdcard/easyio.db',
                    '/sdcard/server/easyio.db',
                    '/sdcard/cpt/easyio.db',
                    '/sdcard/server/grweb/public/easyio.db'
                );
                foreach ($tries as $t) {
                    $info['common_locations'][$t] = array(
                        'exists' => file_exists($t),
                        'readable' => @is_readable($t),
                        'size' => @filesize($t)
                    );
                }
                if ($resolved) {
                    try {
                        $db = new PDO("sqlite:" . $resolved);
                        $stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type IN ('table','view') ORDER BY name");
                        $info['db_objects'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
                    } catch (Exception $e) {
                        $info['db_error'] = $e->getMessage();
                    }
                }
                $cfgPath = jciTrendConfigPath();
                $info['config_file'] = array(
                    'path' => $cfgPath,
                    'exists' => file_exists($cfgPath),
                    'is_readable' => @is_readable($cfgPath),
                    'is_writable' => @is_writable($cfgPath),
                    'dir_writable' => @is_writable(dirname($cfgPath)),
                    'size' => @filesize($cfgPath),
                    'contents' => file_exists($cfgPath) ? json_decode(@file_get_contents($cfgPath), true) : null
                );
                echo json_encode($info, JSON_PRETTY_PRINT);
                return;

            default:
                http_response_code(400);
                echo json_encode(array('error' => 'unknown cmd'));
                return;
        }
    }
}

$controller = new TrendApiController();
$controller->run();
