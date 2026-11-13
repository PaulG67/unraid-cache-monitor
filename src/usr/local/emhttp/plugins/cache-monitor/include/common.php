<?php

function cm_version() {
    $file = dirname(__DIR__) . '/VERSION';
    if (is_file($file)) {
        $version = trim((string) file_get_contents($file));
        if ($version !== '') {
            return $version;
        }
    }
    return '2026.11.13';
}

function cm_paths() {
    return array(
        'cfg' => '/boot/config/plugins/cache-monitor/cache-monitor.cfg',
        'history' => '/boot/config/plugins/cache-monitor/history.json',
        'latest' => '/boot/config/plugins/cache-monitor/latest.json',
        'alert' => '/boot/config/plugins/cache-monitor/alert.json',
        'lock' => '/var/run/cache-monitor.lock',
        'log' => '/var/log/cache-monitor.log',
    );
}

function cm_defaults() {
    return array(
        'WARN_PERCENT' => '80',
        'CRIT_PERCENT' => '90',
        'META_PERCENT' => '85',
        'FORECAST_DAYS' => '14',
        'SCAN_HOURS' => '6',
        'DEPTH' => '2',
        'NOTIFY' => 'yes',
        'POOL' => '',
    );
}

function cm_valid_pool_name($name) {
    return is_string($name) && $name !== '' && (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
}

function cm_load_cfg() {
    $cfg = cm_defaults();
    $path = cm_paths()['cfg'];
    if (is_file($path)) {
        $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (is_array($parsed)) {
            foreach ($cfg as $key => $value) {
                if (isset($parsed[$key]) && $parsed[$key] !== '') {
                    $cfg[$key] = (string) $parsed[$key];
                }
            }
        }
    }
    return cm_normalize_cfg($cfg);
}

function cm_normalize_cfg($cfg) {
    $warn = (int) $cfg['WARN_PERCENT'];
    $crit = (int) $cfg['CRIT_PERCENT'];
    $meta = (int) $cfg['META_PERCENT'];
    $forecast = (int) $cfg['FORECAST_DAYS'];
    $scan = (int) $cfg['SCAN_HOURS'];
    $depth = (int) $cfg['DEPTH'];
    if ($warn < 50) {
        $warn = 50;
    }
    if ($warn > 98) {
        $warn = 98;
    }
    if ($crit <= $warn) {
        $crit = min(99, $warn + 5);
    }
    if ($crit > 99) {
        $crit = 99;
    }
    if ($meta < 50) {
        $meta = 50;
    }
    if ($meta > 99) {
        $meta = 99;
    }
    if ($forecast < 1) {
        $forecast = 1;
    }
    if ($forecast > 365) {
        $forecast = 365;
    }
    if ($scan < 1) {
        $scan = 1;
    }
    if ($scan > 168) {
        $scan = 168;
    }
    if ($depth < 1) {
        $depth = 1;
    }
    if ($depth > 2) {
        $depth = 2;
    }
    $pool = $cfg['POOL'];
    if (!cm_valid_pool_name($pool)) {
        $pool = '';
    }
    return array(
        'WARN_PERCENT' => (string) $warn,
        'CRIT_PERCENT' => (string) $crit,
        'META_PERCENT' => (string) $meta,
        'FORECAST_DAYS' => (string) $forecast,
        'SCAN_HOURS' => (string) $scan,
        'DEPTH' => (string) $depth,
        'NOTIFY' => ($cfg['NOTIFY'] === 'no') ? 'no' : 'yes',
        'POOL' => $pool,
    );
}

function cm_save_cfg($cfg) {
    $cfg = cm_normalize_cfg($cfg);
    $path = cm_paths()['cfg'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $lines = array();
    foreach ($cfg as $key => $value) {
        $lines[] = $key . '="' . str_replace(array("\n", "\r", '"'), '', $value) . '"';
    }
    $payload = implode("\n", $lines) . "\n";
    $tmp = '/tmp/cache-monitor-cfg-' . getmypid();
    if (@file_put_contents($tmp, $payload) === false) {
        return false;
    }
    $ok = @copy($tmp, $path);
    @unlink($tmp);
    return $ok;
}

function cm_load_latest() {
    $best = null;
    $bestTs = -1;
    $paths = array(cm_paths()['latest'], '/var/local/cache-monitor/latest.json');
    foreach ($paths as $path) {
        $data = cm_read_json($path);
        $ts = (is_array($data) && isset($data['generated'])) ? (int) $data['generated'] : -1;
        if ($ts >= $bestTs) {
            $best = $data;
            $bestTs = $ts;
        }
    }
    return $best;
}

function cm_read_json($path) {
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cm_write_json($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = '/tmp/cache-monitor-' . md5($path) . '-' . getmypid() . '.json';
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    $ok = @copy($tmp, $path);
    @unlink($tmp);
    return $ok;
}

function cm_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cm_bytes($bytes) {
    $sign = '';
    if ($bytes < 0) {
        $sign = '−';
        $bytes = abs($bytes);
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $value = (float) $bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    if ($index === 0 || $value >= 100) {
        $digits = 0;
    } elseif ($value >= 10) {
        $digits = 1;
    } else {
        $digits = 2;
    }
    return $sign . number_format($value, $digits, ',', '.') . ' ' . $units[$index];
}

function cm_pct($used, $size) {
    if ($size === null || $used === null || $size <= 0) {
        return null;
    }
    return ($used / $size) * 100;
}

function cm_pct_text($pct) {
    if ($pct === null) {
        return '–';
    }
    $digits = $pct >= 10 ? 0 : 1;
    return number_format($pct, $digits, ',', '.') . ' %';
}

function cm_disk_base($path) {
    $base = basename((string) $path);
    if (strpos($base, 'nvme') === 0) {
        return preg_replace('/p\d+$/', '', $base);
    }
    return preg_replace('/\d+$/', '', $base);
}

function cm_parse_df($text) {
    $result = null;
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        if (preg_match('/\s(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\S+)\s*$/', $line, $match)) {
            $result = array(
                'size' => (int) $match[1],
                'used' => (int) $match[2],
                'free' => (int) $match[3],
                'percent' => (int) $match[4],
                'mount' => $match[5],
            );
        }
    }
    return $result;
}

function cm_parse_inodes($text) {
    $result = null;
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        if (preg_match('/\s(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\S+)\s*$/', $line, $match)) {
            $result = array(
                'total' => (int) $match[1],
                'used' => (int) $match[2],
                'free' => (int) $match[3],
                'percent' => (int) $match[4],
            );
        }
    }
    return $result;
}

function cm_parse_btrfs_usage($text) {
    $result = array(
        'device_size' => null,
        'allocated' => null,
        'unallocated' => null,
        'used' => null,
        'free' => null,
        'free_min' => null,
        'data_size' => 0,
        'data_used' => 0,
        'meta_size' => 0,
        'meta_used' => 0,
        'system_size' => 0,
        'system_used' => 0,
        'data_profile' => array(),
        'meta_profile' => array(),
        'profiles' => array(),
        'seen' => false,
    );
    if (preg_match('/Device size:\s+(\d+)/', $text, $match)) {
        $result['device_size'] = (int) $match[1];
        $result['seen'] = true;
    }
    if (preg_match('/Device allocated:\s+(\d+)/', $text, $match)) {
        $result['allocated'] = (int) $match[1];
    }
    if (preg_match('/Device unallocated:\s+(\d+)/', $text, $match)) {
        $result['unallocated'] = (int) $match[1];
    }
    if (preg_match('/^\s+Used:\s+(\d+)/m', $text, $match)) {
        $result['used'] = (int) $match[1];
    }
    if (preg_match('/Free \(estimated\):\s+(\d+)(?:\s+\(min:\s+(\d+)\))?/', $text, $match)) {
        $result['free'] = (int) $match[1];
        if (!empty($match[2])) {
            $result['free_min'] = (int) $match[2];
        }
    }
    if (preg_match_all('/^(Data|Metadata|System),([^:]+):\s+Size:(\d+),\s+Used:(\d+)/m', $text, $rows, PREG_SET_ORDER)) {
        foreach ($rows as $row) {
            $kind = strtolower($row[1]);
            $key = ($kind === 'metadata') ? 'meta' : (($kind === 'system') ? 'system' : 'data');
            $result[$key . '_size'] += (int) $row[3];
            $result[$key . '_used'] += (int) $row[4];
            $profile = trim($row[2]);
            $result[$key . '_profile'][] = $profile;
            $result['profiles'][] = $row[1] . ' ' . $profile;
            $result['seen'] = true;
        }
    }
    return $result;
}

function cm_parse_btrfs_stats($text) {
    $devices = array();
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        if (!preg_match('/^\[(.+)\]\.(\S+)\s+(\d+)/', trim($line), $match)) {
            continue;
        }
        $dev = $match[1];
        if (!isset($devices[$dev])) {
            $devices[$dev] = 0;
        }
        if (substr($match[2], -5) === '_errs') {
            $devices[$dev] += (int) $match[3];
        }
    }
    return $devices;
}

function cm_parse_du($text, $mount) {
    $mount = rtrim((string) $mount, '/');
    $dirs = array();
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        if ($line === '' || !preg_match('/^(\d+)[ \t]+(.+)$/', $line, $match)) {
            continue;
        }
        $path = $match[2];
        if ($path === $mount || strpos($path, $mount . '/') !== 0) {
            continue;
        }
        $rel = substr($path, strlen($mount) + 1);
        if ($rel === '' || strpos($rel, "\n") !== false) {
            continue;
        }
        $dirs[$rel] = (int) $match[1];
    }
    return $dirs;
}

function cm_trim_dirs($dirs) {
    $top = array();
    $nested = array();
    foreach ($dirs as $path => $bytes) {
        if (strpos($path, '/') === false) {
            $top[$path] = $bytes;
        } else {
            $nested[$path] = $bytes;
        }
    }
    arsort($nested);
    $nested = array_slice($nested, 0, 80, true);
    $merged = $top + $nested;
    arsort($merged);
    return $merged;
}

function cm_parse_find($text) {
    $files = array();
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        if ($line === '' || !preg_match('/^(\d+)\t(.+)$/', $line, $match)) {
            continue;
        }
        $files[] = array('bytes' => (int) $match[1], 'path' => $match[2]);
    }
    usort($files, function ($a, $b) {
        if ($a['bytes'] === $b['bytes']) {
            return 0;
        }
        return ($a['bytes'] > $b['bytes']) ? -1 : 1;
    });
    return array_slice($files, 0, 20);
}

function cm_parse_scrub($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (stripos($text, 'no stats available') !== false) {
        return 'noch kein Scrub';
    }
    $status = 'unbekannt';
    if (preg_match('/Status:\s*(.+)/i', $text, $match)) {
        $status = trim($match[1]);
    } elseif (stripos($text, 'running') !== false) {
        $status = 'läuft';
    }
    $errors = '';
    if (preg_match('/Error summary:\s*(.+)/i', $text, $match)) {
        $errors = trim($match[1]);
    }
    if ($errors !== '' && stripos($errors, 'no errors') === false) {
        return $status . ', ' . $errors;
    }
    return $status;
}

function cm_mode_label($mode) {
    $map = array(
        'only' => 'Nur Cache',
        'prefer' => 'Cache bevorzugen',
        'yes' => 'Cache, dann Array',
        'no' => 'Kein Cache',
    );
    return isset($map[$mode]) ? $map[$mode] : $mode;
}

function cm_forecast_days($usedNow, $usedThen, $elapsedSec, $free) {
    if ($elapsedSec < 6 * 3600 || $free === null || $free < 0) {
        return null;
    }
    $delta = $usedNow - $usedThen;
    if ($delta <= 0) {
        return null;
    }
    $perSecond = $delta / $elapsedSec;
    if ($perSecond <= 0) {
        return null;
    }
    return ($free / $perSecond) / 86400;
}

function cm_find_dir_snapshot($snapshots, $pool, $targetTs, $tolerance) {
    $best = null;
    $bestGap = null;
    foreach ($snapshots as $point) {
        if (!isset($point['pool']) || $point['pool'] !== $pool || empty($point['dirs']) || !is_array($point['dirs']) || !isset($point['ts'])) {
            continue;
        }
        if ($point['ts'] > $targetTs) {
            continue;
        }
        $gap = $targetTs - $point['ts'];
        if ($gap > $tolerance) {
            continue;
        }
        if ($bestGap === null || $gap < $bestGap) {
            $best = $point;
            $bestGap = $gap;
        }
    }
    return $best;
}

function cm_find_snapshot($snapshots, $pool, $targetTs, $tolerance) {
    $best = null;
    $bestGap = null;
    foreach ($snapshots as $point) {
        if (!isset($point['pool']) || $point['pool'] !== $pool || !isset($point['ts']) || !isset($point['used'])) {
            continue;
        }
        if ($point['ts'] > $targetTs) {
            continue;
        }
        $gap = $targetTs - $point['ts'];
        if ($gap > $tolerance) {
            continue;
        }
        if ($bestGap === null || $gap < $bestGap) {
            $best = $point;
            $bestGap = $gap;
        }
    }
    return $best;
}

function cm_assess($pool, $cfg, $forecastDays) {
    $level = 'ok';
    $reasons = array();
    $raise = function ($to) use (&$level) {
        $rank = array('ok' => 0, 'warn' => 1, 'crit' => 2);
        if ($rank[$to] > $rank[$level]) {
            $level = $to;
        }
    };
    $pct = cm_pct(isset($pool['used']) ? $pool['used'] : null, isset($pool['size']) ? $pool['size'] : null);
    $warn = (int) $cfg['WARN_PERCENT'];
    $crit = (int) $cfg['CRIT_PERCENT'];
    $metaLimit = (int) $cfg['META_PERCENT'];
    $horizon = (int) $cfg['FORECAST_DAYS'];
    if ($pct !== null && $pct >= $crit) {
        $raise('crit');
        $reasons[] = 'Belegung ' . cm_pct_text($pct) . ' liegt über der kritischen Schwelle.';
    } elseif ($pct !== null && $pct >= $warn) {
        $raise('warn');
        $reasons[] = 'Belegung ' . cm_pct_text($pct) . ' liegt über der Warnschwelle.';
    }
    $metaPct = cm_pct(isset($pool['meta_used']) ? $pool['meta_used'] : null, isset($pool['meta_size']) ? $pool['meta_size'] : null);
    $unallocated = isset($pool['unallocated']) ? $pool['unallocated'] : null;
    if ($metaPct !== null && $metaPct >= $metaLimit) {
        if ($unallocated !== null && $unallocated < 2 * 1024 * 1024 * 1024) {
            $raise('crit');
            $reasons[] = 'Btrfs-Metadaten sind zu ' . cm_pct_text($metaPct) . ' voll und es ist kaum unalloziierter Platz übrig.';
        } else {
            $raise('warn');
            $reasons[] = 'Btrfs-Metadaten sind zu ' . cm_pct_text($metaPct) . ' belegt.';
        }
    }
    if ($forecastDays !== null && $forecastDays <= 3) {
        $raise('crit');
        $reasons[] = 'Bei gleichem Zuwachs ist der Pool in etwa ' . cm_day_phrase($forecastDays) . ' voll.';
    } elseif ($forecastDays !== null && $forecastDays <= $horizon) {
        $raise('warn');
        $reasons[] = 'Bei gleichem Zuwachs ist der Pool in etwa ' . cm_day_phrase($forecastDays) . ' voll.';
    }
    if (!empty($pool['floor']) && isset($pool['free']) && $pool['free'] < $pool['floor']) {
        $raise('warn');
        $reasons[] = 'Freier Platz liegt unter dem Unraid-Cache-Floor von ' . cm_bytes($pool['floor']) . '.';
    }
    $inodePct = isset($pool['inode_percent']) ? $pool['inode_percent'] : null;
    if ($inodePct !== null && $inodePct >= 90) {
        $raise('warn');
        $reasons[] = 'Inodes sind zu ' . cm_pct_text($inodePct) . ' belegt.';
    }
    if (!empty($pool['error_sum'])) {
        $raise('crit');
        $reasons[] = 'Am Dateisystem oder an einem Gerät gibt es ' . (int) $pool['error_sum'] . ' Fehler.';
    }
    if (empty($pool['mounted'])) {
        $raise('crit');
        $reasons[] = 'Der Pool ist nicht eingehängt.';
    }
    if (!$reasons) {
        $reasons[] = 'Belegung und Zuwachs liegen im Rahmen.';
    }
    return array('level' => $level, 'reasons' => $reasons, 'percent' => $pct, 'meta_percent' => $metaPct);
}

function cm_day_phrase($days) {
    if ($days < 1) {
        return 'weniger als einem Tag';
    }
    $rounded = (int) round($days);
    if ($rounded === 1) {
        return 'einem Tag';
    }
    return $rounded . ' Tagen';
}

function cm_history_add($history, $point) {
    if (!is_array($history)) {
        $history = array('version' => 1, 'snapshots' => array());
    }
    if (!isset($history['snapshots']) || !is_array($history['snapshots'])) {
        $history['snapshots'] = array();
    }
    $history['snapshots'][] = $point;
    $cutoff = $point['ts'] - 90 * 86400;
    $kept = array();
    $lastTs = array();
    foreach ($history['snapshots'] as $item) {
        if (!isset($item['ts']) || $item['ts'] < $cutoff || !isset($item['pool'])) {
            continue;
        }
        $bucket = $item['pool'] . ':' . (int) floor($item['ts'] / 3600);
        if (isset($lastTs[$bucket])) {
            $kept[$lastTs[$bucket]] = $item;
            continue;
        }
        $lastTs[$bucket] = count($kept);
        $kept[] = $item;
    }
    $history['snapshots'] = array_values($kept);
    $history['version'] = 1;
    return $history;
}

function cm_pool_names_from_disk() {
    $names = array();
    foreach (glob('/boot/config/pools/*.cfg') ?: array() as $file) {
        $name = basename($file, '.cfg');
        if (cm_valid_pool_name($name)) {
            $names[] = $name;
        }
    }
    if (!$names && (is_dir('/mnt/cache') || is_file('/boot/config/shares/cache.cfg'))) {
        $names[] = 'cache';
    }
    return $names;
}
