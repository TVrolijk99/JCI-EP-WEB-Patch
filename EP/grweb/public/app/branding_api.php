<?php
/*
 * JCI-EP-WEB-Patch — HTTP API voor branding-edits (titel + logo upload/delete).
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
 *   GET  ?cmd=get                                      -> { title, logo_url }
 *                                                         (any signed-in user)
 *   POST cmd=save-title&title=...                      -> { ok, branding }
 *                                                         (admin only)
 *   POST cmd=upload-logo  (multipart/form-data, file)  -> { ok, branding }
 *                                                         (admin only)
 *   POST cmd=delete-logo                               -> { ok, branding }
 *                                                         (admin only)
 *
 * For multipart/form-data uploads we read $_POST + $_FILES directly —
 * BaseController's HTML-escaping of $_POST is harmless for short
 * scalar fields like `cmd`.
 */
include_once "db.php";
include_once "settings.php";
include_once "feature_control.php";
include_once "base_controller.php";
include_once "branding_helpers.php";

class BrandingApiController extends BaseController {
    protected function signinRequired() { return true; }
    protected function authKeyRequired() { return false; }

    protected function doAjaxGet()  { $this->doGet();  }
    protected function doAjaxPost() { $this->doPost(); }

    private function brandingPayload() {
        $cfg = jciBrandingLoad();
        return array(
            'title'         => $cfg['title'],
            'logo_url'      => jciBrandingLogoUrl(),
            'logo_is_custom'=> jciBrandingHasCustomLogo(),
        );
    }

    protected function doGet() {
        header('Content-type: application/json; charset=utf-8');
        $cmd = isset($_GET['cmd']) ? $_GET['cmd'] : '';
        if ($cmd !== 'get') {
            http_response_code(400);
            echo json_encode(array('error' => 'unknown cmd'));
            return;
        }
        echo json_encode(array('branding' => $this->brandingPayload()));
    }

    protected function doPost() {
        header('Content-type: application/json; charset=utf-8');
        $u = $this->curUser();
        if (!$u || !$u->isAdmin()) {
            http_response_code(403);
            echo json_encode(array('error' => 'admin only'));
            return;
        }

        $cmd = isset($_POST['cmd']) ? $_POST['cmd'] : (isset($_GET['cmd']) ? $_GET['cmd'] : '');
        switch ($cmd) {
            case 'save-title':
                // BaseController HTML-escapes $_POST. For a short title
                // the escaping shows up in the JSON store as &amp; etc,
                // so read the raw body for fidelity.
                $rawBody = file_get_contents('php://input');
                $body = array();
                parse_str($rawBody, $body);
                $title = isset($body['title']) ? (string)$body['title'] : '';
                $title = trim($title);
                if ($title === '') {
                    http_response_code(400);
                    echo json_encode(array('error' => 'title required'));
                    return;
                }
                if (mb_strlen($title) > 64) {
                    $title = mb_substr($title, 0, 64);
                }
                $cfg = jciBrandingLoad();
                $cfg['title'] = $title;
                if (!jciBrandingSave($cfg)) {
                    http_response_code(500);
                    echo json_encode(array('error' => 'save failed'));
                    return;
                }
                echo json_encode(array('ok' => true, 'branding' => $this->brandingPayload()));
                return;

            case 'upload-logo':
                if (!isset($_FILES['logo'])) {
                    http_response_code(400);
                    echo json_encode(array('error' => 'no file (field name must be "logo")'));
                    return;
                }
                $res = jciBrandingSaveUploadedLogo($_FILES['logo']);
                if (!$res[0]) {
                    http_response_code(400);
                    echo json_encode(array('error' => $res[1]));
                    return;
                }
                echo json_encode(array('ok' => true, 'branding' => $this->brandingPayload()));
                return;

            case 'delete-logo':
                if (!jciBrandingClearLogo()) {
                    http_response_code(500);
                    echo json_encode(array('error' => 'delete failed'));
                    return;
                }
                echo json_encode(array('ok' => true, 'branding' => $this->brandingPayload()));
                return;

            default:
                http_response_code(400);
                echo json_encode(array('error' => 'unknown cmd'));
                return;
        }
    }
}

$controller = new BrandingApiController();
$controller->run();
