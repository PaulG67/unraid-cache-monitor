<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/view.php';

$cfg = cm_load_cfg();
$notice = '';
$error = '';
if (isset($csrf_token) && $csrf_token !== '') {
    $GLOBALS['cm_csrf'] = $csrf_token;
} elseif (isset($var) && is_array($var) && !empty($var['csrf_token'])) {
    $GLOBALS['cm_csrf'] = $var['csrf_token'];
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cm_settings'])) {
    $posted = $cfg;
    $keys = array('WARN_PERCENT', 'CRIT_PERCENT', 'META_PERCENT', 'FORECAST_DAYS', 'SCAN_HOURS', 'DEPTH', 'NOTIFY', 'POOL');
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            $posted[$key] = (string) $_POST[$key];
        }
    }
    if (cm_save_cfg($posted)) {
        $cfg = cm_load_cfg();
        $notice = 'Einstellungen gespeichert.';
    } else {
        $error = 'Einstellungen konnten nicht gespeichert werden.';
    }
}

if (isset($_GET['scan']) && $_GET['scan'] === '1') {
    if (cm_page_scan_running()) {
        $notice = 'Es läuft bereits eine Messung.';
    } else {
        $script = '/usr/local/emhttp/plugins/cache-monitor/scripts/collect.php';
        $cmd = 'nohup php -f ' . escapeshellarg($script) . ' -- --full >/dev/null 2>&1 &';
        @exec($cmd);
        $notice = 'Messung gestartet. Ordner und große Dateien können einige Minuten brauchen. Danach neu laden.';
    }
}

cm_render($cfg, $notice, $error);

function cm_page_scan_running() {
    $path = cm_paths()['lock'];
    if (!is_file($path)) {
        return false;
    }
    $pid = (int) trim((string) @file_get_contents($path));
    return $pid > 0 && is_dir('/proc/' . $pid);
}
