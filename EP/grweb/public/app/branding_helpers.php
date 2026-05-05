<?php
/*
 * JCI-EP-WEB-Patch — topbar branding (titel + logo) helpers.
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
 * Storage:
 *   app/grdata/branding.json         { title, logo_filename }
 *   app/grdata/branding_logo.<ext>   uploaded image, single file
 *
 * Both live under app/grdata/ so the firmware backup_restore.php
 * whitelist round-trips them on restore (same rationale as
 * trend_config.json / user_perms.json).
 *
 * Defaults: title = "Johnson Controls GBS", no logo. Used by
 * alarmdb.php / trend.php / graphic.php to render the topbar.
 */

const JCI_BRANDING_DEFAULT_TITLE = 'Johnson Controls GBS';
const JCI_BRANDING_LOGO_BASENAME = 'branding_logo';
const JCI_BRANDING_MAX_BYTES     = 1048576;  /* 1 MB */

/* Default logo shipped with the patch. Used when no custom logo has
 * been uploaded (or when the uploaded file is missing). Lives under
 * /img/ — the public asset folder, served as a static file. */
const JCI_BRANDING_DEFAULT_LOGO_URL  = '../img/top-bar-logo.png';
const JCI_BRANDING_DEFAULT_LOGO_FILE = '/../img/top-bar-logo.png';   /* relative to this file's dir */

/** Allowed upload extensions → MIME type pairs. Anything else is rejected. */
function jciBrandingAllowedTypes() {
    return array(
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
    );
}

function jciBrandingDir()        { return __DIR__ . '/grdata'; }
function jciBrandingConfigPath() { return jciBrandingDir() . '/branding.json'; }

/** Load the branding config. Always returns an array with both keys. */
function jciBrandingLoad() {
    $out = array(
        'title'         => JCI_BRANDING_DEFAULT_TITLE,
        'logo_filename' => '',
    );
    $p = jciBrandingConfigPath();
    if (!file_exists($p) || !is_readable($p)) return $out;
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') return $out;
    $data = json_decode($raw, true);
    if (!is_array($data)) return $out;
    if (isset($data['title']) && is_string($data['title']) && $data['title'] !== '') {
        $out['title'] = $data['title'];
    }
    if (isset($data['logo_filename']) && is_string($data['logo_filename'])) {
        $out['logo_filename'] = $data['logo_filename'];
    }
    return $out;
}

/** Atomic temp+rename write. Returns true on success. */
function jciBrandingSave($cfg) {
    $dir = jciBrandingDir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return false;
    $p = jciBrandingConfigPath();
    $json = json_encode($cfg, JSON_PRETTY_PRINT);
    if ($json === false) return false;
    $tmp = $p . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) === false) return false;
    if (!@rename($tmp, $p)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Resolve the public URL of the current logo. Returns the admin-
 *  uploaded image when one exists, otherwise falls back to the
 *  default `/img/top-bar-logo.png` shipped with the patch. Returns
 *  '' only if neither is reachable.
 *  Appends ?v=<mtime> for cache-busting after re-uploads. */
function jciBrandingLogoUrl() {
    $cfg = jciBrandingLoad();
    if (!empty($cfg['logo_filename'])) {
        $abs = jciBrandingDir() . '/' . $cfg['logo_filename'];
        if (file_exists($abs)) {
            $v = @filemtime($abs);
            return '../app/grdata/' . rawurlencode($cfg['logo_filename']) . ($v ? ('?v=' . $v) : '');
        }
        // Configured filename gone — fall through to default.
    }
    $defaultAbs = __DIR__ . JCI_BRANDING_DEFAULT_LOGO_FILE;
    if (file_exists($defaultAbs)) {
        $v = @filemtime($defaultAbs);
        return JCI_BRANDING_DEFAULT_LOGO_URL . ($v ? ('?v=' . $v) : '');
    }
    return '';
}

/** True if the current logo URL points at an admin-uploaded image
 *  rather than the packaged default. Used by the modal UI to decide
 *  whether "Logo verwijderen" is meaningful. */
function jciBrandingHasCustomLogo() {
    $cfg = jciBrandingLoad();
    if (empty($cfg['logo_filename'])) return false;
    $abs = jciBrandingDir() . '/' . $cfg['logo_filename'];
    return file_exists($abs);
}

/** Delete any existing logo files in grdata/ (one extension at a time
 *  in case the previous upload had a different extension). */
function jciBrandingDeleteLogoFiles() {
    $dir = jciBrandingDir();
    foreach (jciBrandingAllowedTypes() as $ext => $_) {
        $f = $dir . '/' . JCI_BRANDING_LOGO_BASENAME . '.' . $ext;
        if (file_exists($f)) @unlink($f);
    }
}

/** Persist an uploaded $_FILES entry as the new branding logo. Returns
 *  array(true, '<filename>') on success, or array(false, '<reason>'). */
function jciBrandingSaveUploadedLogo($file) {
    if (!is_array($file) || !isset($file['error'])) {
        return array(false, 'no upload');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return array(false, 'upload error code ' . (int)$file['error']);
    }
    if (!isset($file['size']) || $file['size'] <= 0) {
        return array(false, 'empty file');
    }
    if ($file['size'] > JCI_BRANDING_MAX_BYTES) {
        return array(false, 'file too large (max ' . JCI_BRANDING_MAX_BYTES . ' bytes)');
    }

    $name = isset($file['name']) ? (string)$file['name'] : '';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = jciBrandingAllowedTypes();
    if (!isset($allowed[$ext])) {
        return array(false, 'unsupported extension: ' . $ext);
    }

    // Best-effort MIME sanity check (won't catch a renamed PHP-as-PNG,
    // but the controller only serves /grdata/ static so it's not an
    // execution risk anyway).
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $file['tmp_name']);
            @finfo_close($finfo);
            // SVG can come through as text/xml — accept that too.
            $expectedMimes = array($allowed[$ext]);
            if ($ext === 'svg') $expectedMimes[] = 'text/xml';
            if ($ext === 'svg') $expectedMimes[] = 'text/plain';
            if ($mime && !in_array($mime, $expectedMimes, true)) {
                return array(false, 'mime mismatch (got ' . $mime . ')');
            }
        }
    }

    $dir = jciBrandingDir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return array(false, 'cannot create grdata/');
    }

    // Wipe any previous logo (different ext is possible) so we never
    // end up with two stale files on disk.
    jciBrandingDeleteLogoFiles();

    $newName = JCI_BRANDING_LOGO_BASENAME . '.' . $ext;
    $dest    = $dir . '/' . $newName;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return array(false, 'move_uploaded_file failed');
    }

    $cfg = jciBrandingLoad();
    $cfg['logo_filename'] = $newName;
    if (!jciBrandingSave($cfg)) {
        // Best-effort: keep the file even if json fails — better than a
        // half-disk-half-config state. Caller can retry the save.
        return array(false, 'logo saved but config write failed');
    }
    return array(true, $newName);
}

/** Drop any logo file + clear the filename in branding.json. */
function jciBrandingClearLogo() {
    jciBrandingDeleteLogoFiles();
    $cfg = jciBrandingLoad();
    $cfg['logo_filename'] = '';
    return jciBrandingSave($cfg);
}
