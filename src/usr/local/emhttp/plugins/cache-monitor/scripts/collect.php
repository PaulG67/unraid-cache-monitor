<?php
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "cli only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/include/common.php';

@set_time_limit(0);
cm_collect(in_array('--full', $argv, true));

function cm_log($message) {
    $line = date('Y-m-d H:i:s') . ' ' . $message . "\n";
    $path = cm_paths()['log'];
    if (is_file($path) && filesize($path) > 262144) {
        @unlink($path);
    }
    @file_put_contents($path, $line, FILE_APPEND);
}

function cm_exec($cmd) {
    $out = array();
    $code = 0;
    exec('export LANG=C LC_ALL=C; ' . $cmd, $out, $code);
    return array(implode("\n", $out), $code);
}

function cm_bin($name) {
    list($out, $code) = cm_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
    return $code === 0 && trim($out) !== '';
}

function cm_lock_acquire() {
    $path = cm_paths()['lock'];
    $handle = @fopen($path, 'c+');
    if (!$handle) {
        return null;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    return $handle;
}

function cm_collect($forceFull) {
    $lock = cm_lock_acquire();
    if (!$lock) {
        cm_log('Messung läuft bereits, dieser Lauf wird übersprungen.');
        return;
    }
    $cfg = cm_load_cfg();
    $latest = cm_load_latest();
    if (!is_array($latest)) {
        $latest = array('generated' => 0, 'full_ts' => 0, 'pools' => array());
    }
    $fullDue = (time() - (int) (isset($latest['full_ts']) ? $latest['full_ts'] : 0)) >= ((int) $cfg['SCAN_HOURS'] * 3600);
    $full = $forceFull || $fullDue || empty($latest['pools']);
    cm_log(($full ? 'Vollständige' : 'Leichte') . ' Messung startet.');
    $names = cm_pool_names_from_disk();
    $pools = array();
    foreach ($names as $name) {
        $pools[] = cm_collect_pool($name, $cfg, $full, $latest);
    }
    $payload = array(
        'generated' => time(),
        'full_ts' => $full ? time() : (int) (isset($latest['full_ts']) ? $latest['full_ts'] : 0),
        'full' => $full,
        'version' => cm_version(),
        'pools' => $pools,
    );
    cm_write_json('/var/local/cache-monitor/latest.json', $payload);
    if (cm_flash_latest_due($payload, $full)) {
        cm_write_json(cm_paths()['latest'], $payload);
    }
    cm_store_history($pools, $full);
    cm_notify($pools, $cfg);
    cm_log('Messung fertig, ' . count($pools) . ' Pool(s).');
    flock($lock, LOCK_UN);
    fclose($lock);
}

function cm_previous_pool($latest, $name) {
    if (!isset($latest['pools']) || !is_array($latest['pools'])) {
        return null;
    }
    foreach ($latest['pools'] as $pool) {
        if (isset($pool['name']) && $pool['name'] === $name) {
            return $pool;
        }
    }
    return null;
}

function cm_collect_pool($name, $cfg, $full, $latest) {
    $mount = '/mnt/' . $name;
    $previous = cm_previous_pool($latest, $name);
    $pool = array(
        'name' => $name,
        'mount' => $mount,
        'mounted' => is_dir($mount) && is_readable($mount),
        'fstype' => '',
        'profile' => '',
        'size' => null,
        'used' => null,
        'free' => null,
        'percent' => null,
        'free_min' => null,
        'unallocated' => null,
        'data_used' => null,
        'data_size' => null,
        'meta_used' => null,
        'meta_size' => null,
        'system_used' => null,
        'system_size' => null,
        'inode_used' => null,
        'inode_total' => null,
        'inode_percent' => null,
        'devices' => array(),
        'error_sum' => 0,
        'scrub' => '',
        'dirs' => array(),
        'files' => array(),
        'shares' => array(),
        'docker' => array(),
        'vms' => array(),
        'floor' => cm_cache_floor(),
        'scanned' => time(),
        'dirs_ts' => $previous && isset($previous['dirs_ts']) ? $previous['dirs_ts'] : 0,
    );
    if (!$pool['mounted']) {
        return $pool;
    }
    list($dfText) = cm_exec('df -B1 -P ' . escapeshellarg($mount));
    $df = cm_parse_df($dfText);
    if ($df) {
        $pool['size'] = $df['size'];
        $pool['used'] = $df['used'];
        $pool['free'] = $df['free'];
        $pool['percent'] = $df['percent'];
    }
    list($inodeText) = cm_exec('df -Pi ' . escapeshellarg($mount));
    $inodes = cm_parse_inodes($inodeText);
    if ($inodes) {
        $pool['inode_used'] = $inodes['used'];
        $pool['inode_total'] = $inodes['total'];
        $pool['inode_percent'] = $inodes['percent'];
    }
    list($fstype) = cm_exec('findmnt -n -o FSTYPE ' . escapeshellarg($mount));
    $pool['fstype'] = trim($fstype);
    if ($pool['fstype'] === 'btrfs' && cm_bin('btrfs')) {
        cm_collect_btrfs($pool, $mount);
    } elseif ($pool['fstype'] === 'zfs' && cm_bin('zfs')) {
        cm_collect_zfs($pool, $name);
    }
    if ($full) {
        $pool['dirs'] = cm_collect_dirs($mount, (int) $cfg['DEPTH']);
        $pool['files'] = cm_collect_files($mount);
        $pool['dirs_ts'] = time();
        $pool['docker'] = cm_collect_docker($mount);
        $pool['vms'] = cm_collect_vms($mount);
    } elseif ($previous) {
        $pool['dirs'] = isset($previous['dirs']) && is_array($previous['dirs']) ? $previous['dirs'] : array();
        $pool['files'] = isset($previous['files']) && is_array($previous['files']) ? $previous['files'] : array();
        $pool['docker'] = isset($previous['docker']) && is_array($previous['docker']) ? $previous['docker'] : array();
        $pool['vms'] = isset($previous['vms']) && is_array($previous['vms']) ? $previous['vms'] : array();
        $pool['dirs_ts'] = isset($previous['dirs_ts']) ? $previous['dirs_ts'] : 0;
    }
    $dirMap = array();
    foreach ($pool['dirs'] as $dir) {
        if (isset($dir['path'])) {
            $dirMap[$dir['path']] = $dir['bytes'];
        }
    }
    $pool['shares'] = cm_collect_shares($name, $dirMap);
    return $pool;
}

function cm_cache_floor() {
    $file = '/boot/config/share.cfg';
    if (!is_file($file)) {
        return 0;
    }
    $cfg = @parse_ini_file($file, false, INI_SCANNER_RAW);
    if (!is_array($cfg) || empty($cfg['shareCacheFloor']) || !is_numeric($cfg['shareCacheFloor'])) {
        return 0;
    }
    return (int) $cfg['shareCacheFloor'] * 1024;
}

function cm_collect_btrfs(&$pool, $mount) {
    list($usage) = cm_exec('btrfs filesystem usage -b ' . escapeshellarg($mount));
    $parsed = cm_parse_btrfs_usage($usage);
    if ($parsed['seen']) {
        $pool['unallocated'] = $parsed['unallocated'];
        $pool['free_min'] = $parsed['free_min'];
        $pool['data_used'] = $parsed['data_used'];
        $pool['data_size'] = $parsed['data_size'];
        $pool['meta_used'] = $parsed['meta_used'];
        $pool['meta_size'] = $parsed['meta_size'];
        $pool['system_used'] = $parsed['system_used'];
        $pool['system_size'] = $parsed['system_size'];
        $pool['profile'] = implode(', ', $parsed['data_profile']);
    }
    list($stats) = cm_exec('btrfs device stats ' . escapeshellarg($mount));
    $errors = cm_parse_btrfs_stats($stats);
    list($show) = cm_exec('btrfs filesystem show ' . escapeshellarg($mount));
    $devices = array();
    if (preg_match_all('/devid\s+\d+\s+size\s+\S+\s+used\s+\S+\s+path\s+(\S+)/', $show, $rows)) {
        foreach ($rows[1] as $path) {
            $devices[] = $path;
        }
    }
    if (!$devices) {
        $devices = array_keys($errors);
    }
    $disks = cm_load_disks();
    $sum = 0;
    foreach ($devices as $path) {
        $err = isset($errors[$path]) ? $errors[$path] : 0;
        $sum += $err;
        $info = cm_match_disk($disks, $path);
        $pool['devices'][] = array(
            'path' => $path,
            'temp' => $info['temp'],
            'errors' => $err,
            'label' => $info['label'],
            'smart' => $info['smart'],
        );
    }
    $pool['error_sum'] = $sum;
    list($scrub) = cm_exec('btrfs scrub status ' . escapeshellarg($mount));
    $pool['scrub'] = cm_parse_scrub($scrub);
}

function cm_collect_zfs(&$pool, $name) {
    list($list) = cm_exec('zpool list -Hp ' . escapeshellarg($name));
    $parts = preg_split('/\s+/', trim($list));
    if (count($parts) >= 4) {
        $pool['size'] = (int) $parts[1];
        $pool['used'] = (int) $parts[2];
        $pool['free'] = (int) $parts[3];
        $pool['percent'] = $pool['size'] > 0 ? (int) round($pool['used'] / $pool['size'] * 100) : null;
    }
    list($status) = cm_exec('zpool status -P ' . escapeshellarg($name));
    if (preg_match('/state:\s*(\S+)/i', $status, $match) && strtoupper($match[1]) !== 'ONLINE') {
        $pool['error_sum'] += 1;
    }
    $pool['profile'] = 'zfs';
    if (preg_match('/^\s+(raidz\d*|mirror)\s+/m', $status, $match)) {
        $pool['profile'] = $match[1];
    }
}

function cm_load_disks() {
    $file = '/var/local/emhttp/disks.ini';
    if (!is_file($file)) {
        return array();
    }
    $parsed = @parse_ini_file($file, true, INI_SCANNER_RAW);
    return is_array($parsed) ? $parsed : array();
}

function cm_match_disk($disks, $path) {
    $base = cm_disk_base($path);
    $found = null;
    foreach ($disks as $disk) {
        if (!is_array($disk)) {
            continue;
        }
        $device = isset($disk['device']) ? $disk['device'] : '';
        $name = isset($disk['name']) ? $disk['name'] : '';
        if ($device === $base || $name === $base || $device === basename($path)) {
            $found = $disk;
            break;
        }
    }
    $temp = null;
    $label = $base;
    $smart = '';
    if ($found) {
        if (isset($found['temp']) && is_numeric($found['temp'])) {
            $temp = (int) $found['temp'];
        }
        if (!empty($found['id'])) {
            $label = str_replace('_', ' ', $found['id']);
        } elseif (!empty($found['name'])) {
            $label = $found['name'];
        }
        if (!empty($found['numErrors']) && is_numeric($found['numErrors'])) {
            $smart = (int) $found['numErrors'] . ' I/O-Fehler';
        }
    }
    return array('temp' => $temp, 'label' => $label, 'smart' => $smart);
}

function cm_collect_dirs($mount, $depth) {
    $nice = '';
    if (cm_bin('ionice')) {
        $nice .= 'ionice -c 3 ';
    }
    if (cm_bin('nice')) {
        $nice .= 'nice -n 19 ';
    }
    list($text) = cm_exec($nice . 'du -B1 -x -d ' . (int) $depth . ' ' . escapeshellarg($mount));
    $dirs = cm_trim_dirs(cm_parse_du($text, $mount));
    $out = array();
    foreach ($dirs as $path => $bytes) {
        $out[] = array(
            'path' => $path,
            'bytes' => $bytes,
            'depth' => substr_count($path, '/') + 1,
        );
    }
    return $out;
}

function cm_collect_files($mount) {
    $nice = cm_bin('nice') ? 'nice -n 19 ' : '';
    list($text) = cm_exec($nice . 'find ' . escapeshellarg($mount) . ' -xdev -type f -size +512M -printf ' . escapeshellarg('%s\t%p\n'));
    $files = cm_parse_find($text);
    $prefix = rtrim($mount, '/') . '/';
    foreach ($files as $index => $file) {
        if (strpos($file['path'], $prefix) === 0) {
            $files[$index]['path'] = substr($file['path'], strlen($prefix));
        }
    }
    return $files;
}

function cm_collect_shares($pool, $dirMap) {
    $shares = array();
    foreach (glob('/boot/config/shares/*.cfg') ?: array() as $file) {
        $cfg = @parse_ini_file($file, false, INI_SCANNER_RAW);
        if (!is_array($cfg)) {
            continue;
        }
        $mode = isset($cfg['shareUseCache']) ? $cfg['shareUseCache'] : 'no';
        $which = isset($cfg['shareCachePool']) ? $cfg['shareCachePool'] : '';
        if ($which === '' && $pool === 'cache' && $mode !== 'no' && $mode !== '') {
            $which = 'cache';
        }
        if ($which !== $pool || $mode === 'no' || $mode === '') {
            continue;
        }
        $name = basename($file, '.cfg');
        $shares[] = array(
            'name' => $name,
            'mode' => $mode,
            'bytes' => isset($dirMap[$name]) ? $dirMap[$name] : null,
        );
    }
    usort($shares, function ($a, $b) {
        $av = $a['bytes'] === null ? -1 : $a['bytes'];
        $bv = $b['bytes'] === null ? -1 : $b['bytes'];
        if ($av === $bv) {
            return strcmp($a['name'], $b['name']);
        }
        return ($av > $bv) ? -1 : 1;
    });
    return $shares;
}

function cm_image_on_pool($path, $mount) {
    if (!$path) {
        return null;
    }
    if (strpos($path, '/mnt/user/') === 0) {
        $path = rtrim($mount, '/') . substr($path, strlen('/mnt/user'));
    }
    if (!is_file($path)) {
        return null;
    }
    $real = realpath($path);
    $root = realpath($mount);
    if (!$real || !$root || strpos($real, rtrim($root, '/') . '/') !== 0) {
        return null;
    }
    list($text) = cm_exec('du -B1 ' . escapeshellarg($real));
    $allocated = null;
    if (preg_match('/^(\d+)/', $text, $match)) {
        $allocated = (int) $match[1];
    }
    return array(
        'path' => $real,
        'apparent' => filesize($real),
        'allocated' => $allocated,
    );
}

function cm_collect_docker($mount) {
    $info = array();
    $cfg = array();
    if (is_file('/boot/config/docker.cfg')) {
        $parsed = @parse_ini_file('/boot/config/docker.cfg', false, INI_SCANNER_RAW);
        if (is_array($parsed)) {
            $cfg = $parsed;
        }
    }
    $image = isset($cfg['DOCKER_IMAGE_FILE']) ? $cfg['DOCKER_IMAGE_FILE'] : ($mount . '/system/docker/docker.img');
    $file = cm_image_on_pool($image, $mount);
    if ($file) {
        $info['image_path'] = $file['path'];
        $info['image_allocated'] = $file['allocated'];
        $info['image_apparent'] = $file['apparent'];
        $info['image_limit'] = isset($cfg['DOCKER_IMAGE_SIZE']) ? $cfg['DOCKER_IMAGE_SIZE'] : '';
    }
    if (cm_bin('docker')) {
        list($rows, $code) = cm_exec("docker system df --format '{{.Type}}|{{.TotalCount}}|{{.Active}}|{{.Size}}|{{.Reclaimable}}'");
        if ($code === 0 && trim($rows) !== '') {
            $info['usage'] = array();
            foreach (preg_split('/\r?\n/', trim($rows)) as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 4) {
                    $info['usage'][] = array(
                        'type' => $parts[0],
                        'total' => $parts[1],
                        'active' => $parts[2],
                        'size' => $parts[3],
                        'reclaim' => isset($parts[4]) ? $parts[4] : '',
                    );
                }
            }
        }
    }
    return $info;
}

function cm_collect_vms($mount) {
    $dir = $mount . '/domains';
    $cfgDir = '';
    if (is_file('/boot/config/domain.cfg')) {
        $parsed = @parse_ini_file('/boot/config/domain.cfg', false, INI_SCANNER_RAW);
        if (is_array($parsed) && !empty($parsed['DOMAIN_DIR'])) {
            $cfgDir = rtrim($parsed['DOMAIN_DIR'], '/');
        }
        if (is_array($parsed) && !empty($parsed['IMAGE_FILE'])) {
            $libvirt = cm_image_on_pool($parsed['IMAGE_FILE'], $mount);
        }
    }
    if ($cfgDir !== '' && strpos($cfgDir, rtrim($mount, '/')) === 0) {
        $dir = $cfgDir;
    }
    $vms = array();
    if (isset($libvirt) && $libvirt) {
        $vms[] = array(
            'path' => 'libvirt.img',
            'bytes' => $libvirt['allocated'] !== null ? $libvirt['allocated'] : $libvirt['apparent'],
        );
    }
    if (!is_dir($dir)) {
        return $vms;
    }
    $files = array();
    foreach (array('img', 'qcow2', 'vmdk', 'raw', 'iso') as $ext) {
        $found = glob($dir . '/*.' . $ext);
        if (is_array($found)) {
            $files = array_merge($files, $found);
        }
    }
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        list($text) = cm_exec('du -B1 ' . escapeshellarg($file));
        $bytes = null;
        if (preg_match('/^(\d+)/', $text, $match)) {
            $bytes = (int) $match[1];
        }
        $vms[] = array(
            'path' => substr($file, strlen(rtrim($mount, '/')) + 1),
            'bytes' => $bytes !== null ? $bytes : filesize($file),
        );
    }
    usort($vms, function ($a, $b) {
        if ($a['bytes'] === $b['bytes']) {
            return 0;
        }
        return ($a['bytes'] > $b['bytes']) ? -1 : 1;
    });
    return array_slice($vms, 0, 20);
}

function cm_flash_latest_due($payload, $full) {
    if ($full || !is_file(cm_paths()['latest'])) {
        return true;
    }
    $previous = cm_read_json(cm_paths()['latest']);
    if (!is_array($previous) || empty($previous['generated']) || !isset($previous['pools']) || !is_array($previous['pools'])) {
        return true;
    }
    if ((time() - (int) $previous['generated']) >= 6 * 3600) {
        return true;
    }
    $old = array();
    foreach ($previous['pools'] as $pool) {
        if (isset($pool['name']) && isset($pool['used'])) {
            $old[$pool['name']] = $pool['used'];
        }
    }
    foreach ($payload['pools'] as $pool) {
        if (!isset($pool['name']) || $pool['used'] === null) {
            continue;
        }
        if (!isset($old[$pool['name']]) || abs($pool['used'] - $old[$pool['name']]) >= 50 * 1024 * 1024) {
            return true;
        }
    }
    return false;
}

function cm_history_due($history, $pool) {
    $last = null;
    if (isset($history['snapshots']) && is_array($history['snapshots'])) {
        foreach ($history['snapshots'] as $point) {
            if (isset($point['pool']) && $point['pool'] === $pool['name'] && isset($point['ts'])) {
                if ($last === null || $point['ts'] > $last['ts']) {
                    $last = $point;
                }
            }
        }
    }
    if ($last === null) {
        return true;
    }
    if ((time() - $last['ts']) >= 6 * 3600) {
        return true;
    }
    return isset($last['used']) && abs($pool['used'] - $last['used']) >= 50 * 1024 * 1024;
}

function cm_store_history($pools, $full) {
    $path = cm_paths()['history'];
    $history = cm_read_json($path);
    $changed = false;
    foreach ($pools as $pool) {
        if (empty($pool['mounted']) || $pool['used'] === null) {
            continue;
        }
        if (!$full && !cm_history_due(is_array($history) ? $history : array(), $pool)) {
            continue;
        }
        $changed = true;
        $dirs = array();
        if ($full && !empty($pool['dirs'])) {
            foreach ($pool['dirs'] as $dir) {
                $dirs[$dir['path']] = $dir['bytes'];
            }
        }
        $history = cm_history_add($history, array(
            'ts' => time(),
            'pool' => $pool['name'],
            'used' => $pool['used'],
            'size' => $pool['size'],
            'free' => $pool['free'],
            'dirs' => $dirs,
        ));
    }
    if ($changed && $history) {
        cm_write_json($path, $history);
    }
}

function cm_notify($pools, $cfg) {
    if ($cfg['NOTIFY'] !== 'yes' || !is_file('/usr/local/emhttp/webGui/scripts/notify')) {
        return;
    }
    $history = cm_read_json(cm_paths()['history']);
    $snapshots = (is_array($history) && isset($history['snapshots'])) ? $history['snapshots'] : array();
    $state = cm_read_json(cm_paths()['alert']);
    if (!is_array($state)) {
        $state = array('level' => 'ok', 'sent' => 0);
    }
    $worst = 'ok';
    $lines = array();
    $rank = array('ok' => 0, 'warn' => 1, 'crit' => 2);
    foreach ($pools as $pool) {
        $then = cm_find_snapshot($snapshots, $pool['name'], time() - 7 * 86400, 36 * 3600);
        $forecast = null;
        if ($then && $pool['used'] !== null && $pool['free'] !== null) {
            $forecast = cm_forecast_days($pool['used'], $then['used'], time() - $then['ts'], $pool['free']);
        }
        $assess = cm_assess($pool, $cfg, $forecast);
        if ($rank[$assess['level']] > $rank[$worst]) {
            $worst = $assess['level'];
        }
        if ($assess['level'] !== 'ok') {
            $lines[] = $pool['name'] . ': ' . implode(' ', $assess['reasons']);
        }
    }
    $previous = isset($state['level']) ? $state['level'] : 'ok';
    $sent = isset($state['sent']) ? (int) $state['sent'] : 0;
    $repeat = ($worst !== 'ok' && (time() - $sent) >= 12 * 3600);
    $changed = $rank[$worst] !== $rank[$previous];
    if (!$changed && !$repeat) {
        return;
    }
    if ($worst === 'ok') {
        $subject = 'Cache wieder im Rahmen';
        $body = 'Die Cache-Pools liegen wieder unter den Warnschwellen.';
        $icon = 'normal';
    } else {
        $subject = ($worst === 'crit') ? 'Cache läuft voll' : 'Cache wird knapp';
        $body = implode("\n", $lines);
        $icon = ($worst === 'crit') ? 'alert' : 'warning';
    }
    cm_exec(
        '/usr/local/emhttp/webGui/scripts/notify -e ' . escapeshellarg('Cache Monitor')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($body)
        . ' -i ' . escapeshellarg($icon)
    );
    cm_write_json(cm_paths()['alert'], array('level' => $worst, 'sent' => time()));
}
