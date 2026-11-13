<?php

function cm_render($cfg, $notice, $error) {
    $latest = cm_load_latest();
    $history = cm_read_json(cm_paths()['history']);
    $snapshots = (is_array($history) && isset($history['snapshots']) && is_array($history['snapshots'])) ? $history['snapshots'] : array();
    $pools = (is_array($latest) && isset($latest['pools']) && is_array($latest['pools'])) ? $latest['pools'] : array();
    $names = cm_pool_names_from_disk();
    if (!$names) {
        foreach ($pools as $pool) {
            if (isset($pool['name'])) {
                $names[] = $pool['name'];
            }
        }
    }
    $requested = isset($_GET['pool']) ? (string) $_GET['pool'] : $cfg['POOL'];
    $selected = null;
    foreach ($pools as $pool) {
        if ($requested !== '' && isset($pool['name']) && $pool['name'] === $requested) {
            $selected = $pool;
            break;
        }
    }
    if (!$selected) {
        foreach ($pools as $pool) {
            if (isset($pool['name']) && $pool['name'] === 'cache') {
                $selected = $pool;
                break;
            }
        }
    }
    if (!$selected && $pools) {
        $selected = $pools[0];
    }
    $running = cm_pid_running();
    $generated = (is_array($latest) && !empty($latest['generated'])) ? (int) $latest['generated'] : 0;
    echo '<style>' . cm_css() . '</style>';
    echo '<div class="cm">';
    echo '<div class="cm-head"><div><h2>Cache Monitor</h2>';
    echo '<p class="cm-muted">Belegung, größte Ordner, Zuwachs und ob der Cache vollläuft. Version ' . cm_h(cm_version()) . '</p></div>';
    echo '<div class="cm-actions">';
    echo '<a class="cm-btn" href="/Settings/CacheMonitor?scan=1' . ($selected ? '&amp;pool=' . cm_h($selected['name']) : '') . '">Jetzt messen</a>';
    echo '<a class="cm-btn" href="/Settings/CacheMonitor' . ($selected ? '?pool=' . cm_h($selected['name']) : '') . '">Neu laden</a>';
    echo '</div></div>';
    if ($notice !== '') {
        echo '<p class="cm-note">' . cm_h($notice) . '</p>';
    }
    if ($error !== '') {
        echo '<p class="cm-note cm-bad">' . cm_h($error) . '</p>';
    }
    if (isset($_GET['scan'])) {
        $cleanPool = ($selected && cm_valid_pool_name($selected['name'])) ? ('?pool=' . rawurlencode($selected['name'])) : '';
        echo '<script>if (window.history && history.replaceState) { history.replaceState({}, "", "/Settings/CacheMonitor' . $cleanPool . '"); }</script>';
    }
    if ($running) {
        echo '<p class="cm-note">Eine Messung läuft. Ordner und große Dateien erscheinen, sobald sie fertig ist.</p>';
    }
    if (!$selected) {
        echo '<p>Noch keine Messung. Beim Installieren startet sie im Hintergrund. Wenn hier nach einigen Minuten nichts steht, „Jetzt messen“ wählen.</p>';
        if ($names) {
            echo '<p class="cm-muted">Gefundene Pools: ' . cm_h(implode(', ', $names)) . '</p>';
        } else {
            echo '<p class="cm-muted">Unter /boot/config/pools liegt kein Pool, und /mnt/cache gibt es nicht.</p>';
        }
        cm_render_settings($cfg, $names);
        echo '</div>';
        return;
    }
    if (count($pools) > 1) {
        echo '<p class="cm-switch">';
        foreach ($pools as $pool) {
            $active = ($pool['name'] === $selected['name']) ? ' cm-on' : '';
            echo '<a class="cm-btn' . $active . '" href="/Settings/CacheMonitor?pool=' . cm_h($pool['name']) . '">' . cm_h($pool['name']) . '</a> ';
        }
        echo '</p>';
    }
    cm_render_pool($selected, $cfg, $snapshots, $generated);
    cm_render_settings($cfg, $names);
    echo '<p class="cm-muted cm-foot">Ordnergrößen stammen von du und zählen belegte Blöcke. Bei Kompression, Snapshots oder RAID kann die Summe von der Dateisystem-Belegung abweichen. Verlauf und Schwellen liegen auf dem Flash unter config/plugins/cache-monitor.</p>';
    echo '</div>';
}

function cm_pid_running() {
    $path = cm_paths()['lock'];
    if (!is_file($path)) {
        return false;
    }
    $pid = (int) trim((string) @file_get_contents($path));
    return $pid > 0 && is_dir('/proc/' . $pid);
}

function cm_render_pool($pool, $cfg, $snapshots, $generated) {
    $name = $pool['name'];
    $thenDay = cm_find_snapshot($snapshots, $name, time() - 86400, 8 * 3600);
    $thenWeek = cm_find_snapshot($snapshots, $name, time() - 7 * 86400, 36 * 3600);
    $dirsDay = cm_find_dir_snapshot($snapshots, $name, time() - 86400, 18 * 3600);
    $dirsWeek = cm_find_dir_snapshot($snapshots, $name, time() - 7 * 86400, 48 * 3600);
    $forecast = null;
    $weekDelta = null;
    if ($thenWeek && $pool['used'] !== null && $pool['free'] !== null) {
        $weekDelta = $pool['used'] - $thenWeek['used'];
        $forecast = cm_forecast_days($pool['used'], $thenWeek['used'], time() - $thenWeek['ts'], $pool['free']);
    }
    $dayDelta = ($thenDay && $pool['used'] !== null) ? ($pool['used'] - $thenDay['used']) : null;
    $assess = cm_assess($pool, $cfg, $forecast);
    $title = array('ok' => 'Im Rahmen', 'warn' => 'Wird knapp', 'crit' => 'Läuft voll');
    echo '<section class="cm-banner cm-' . cm_h($assess['level']) . '">';
    echo '<div class="cm-banner-top"><strong>' . cm_h($title[$assess['level']]) . '</strong>';
    echo '<span>' . cm_h(cm_pct_text($assess['percent'])) . ' belegt</span></div>';
    echo cm_bar($assess['percent'], cm_usage_level($assess['percent'], $cfg));
    echo '<p>';
    if ($pool['used'] !== null && $pool['size'] !== null) {
        echo cm_h(cm_bytes($pool['used'])) . ' von ' . cm_h(cm_bytes($pool['size']));
        if ($pool['free'] !== null) {
            echo ', frei ' . cm_h(cm_bytes($pool['free']));
        }
    } else {
        echo 'Keine Belegungsdaten';
    }
    echo '</p><p>';
    if ($dayDelta !== null) {
        echo 'Zuwachs 24 Stunden: ' . cm_delta($dayDelta) . '. ';
    }
    if ($weekDelta !== null) {
        echo 'Zuwachs 7 Tage: ' . cm_delta($weekDelta) . '. ';
    }
    if ($forecast !== null) {
        echo 'Bei gleichem Zuwachs voll in etwa ' . cm_h(cm_day_phrase($forecast)) . '.';
    } elseif ($thenWeek && $weekDelta !== null && $weekDelta <= 0) {
        echo 'Der belegte Platz nimmt nicht zu.';
    } else {
        echo 'Für die Volllauf-Prognose fehlen noch Vergleichswerte.';
    }
    echo '</p><ul>';
    foreach ($assess['reasons'] as $reason) {
        echo '<li>' . cm_h($reason) . '</li>';
    }
    echo '</ul></section>';

    echo '<div class="cm-grid">';
    cm_fact('Dateisystem', trim($pool['fstype'] . ' ' . $pool['profile']));
    cm_fact('Eingehängt', !empty($pool['mounted']) ? $pool['mount'] : 'nein');
    cm_fact('Letzte Messung', $generated ? date('d.m.Y H:i', $generated) : '–');
    $dirsTs = !empty($pool['dirs_ts']) ? (int) $pool['dirs_ts'] : 0;
    cm_fact('Ordner gemessen', $dirsTs ? date('d.m.Y H:i', $dirsTs) : 'noch nicht');
    if ($pool['free_min'] !== null && $pool['free'] !== null && ($pool['free'] - $pool['free_min']) > (5 * 1024 * 1024 * 1024)) {
        cm_fact('Frei mindestens', cm_bytes($pool['free_min']) . ' (RAID unausgeglichen)');
    }
    if (!empty($pool['floor'])) {
        cm_fact('Cache-Floor', cm_bytes($pool['floor']));
    }
    if (!empty($pool['scrub'])) {
        cm_fact('Scrub', $pool['scrub']);
    }
    if ($pool['inode_percent'] !== null) {
        cm_fact('Inodes', cm_pct_text($pool['inode_percent']));
    }
    echo '</div>';

    if (!empty($pool['devices'])) {
        echo '<h3>Geräte</h3><table class="cm-table"><thead><tr><th>Gerät</th><th>Name</th><th class="cm-num">Temperatur</th><th class="cm-num">Fehler</th><th>Hinweis</th></tr></thead><tbody>';
        foreach ($pool['devices'] as $device) {
            echo '<tr><td>' . cm_h($device['path']) . '</td><td>' . cm_h($device['label']) . '</td><td class="cm-num">';
            echo ($device['temp'] === null ? '–' : cm_h($device['temp'] . ' °C'));
            echo '</td><td class="cm-num">' . cm_h((string) $device['errors']) . '</td><td>' . cm_h($device['smart']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if ($pool['data_size'] || $pool['meta_size']) {
        echo '<h3>Btrfs-Chunks</h3>';
        cm_chunk('Daten', $pool['data_used'], $pool['data_size'], $cfg);
        cm_chunk('Metadaten', $pool['meta_used'], $pool['meta_size'], $cfg);
        cm_chunk('System', $pool['system_used'], $pool['system_size'], $cfg);
        if ($pool['unallocated'] !== null) {
            echo '<p class="cm-muted">Unalloziert: ' . cm_h(cm_bytes($pool['unallocated'])) . '. Metadaten können volllaufen, obwohl df noch Platz zeigt, wenn dieser Rest zu klein ist.</p>';
        }
    }

    $currentDirs = array();
    if (!empty($pool['dirs']) && is_array($pool['dirs'])) {
        foreach ($pool['dirs'] as $dir) {
            if (isset($dir['path'])) {
                $currentDirs[$dir['path']] = $dir['bytes'];
            }
        }
    }
    echo '<h3>Was Platz braucht</h3>';
    if (!$currentDirs) {
        echo '<p class="cm-muted">Noch keine Ordnermessung. Sie läuft beim ersten Start und danach alle ' . cm_h($cfg['SCAN_HOURS']) . ' Stunden, oder über „Jetzt messen“.</p>';
    } else {
        echo '<table class="cm-table"><thead><tr><th>Ordner</th><th class="cm-num">Größe</th><th class="cm-num">Anteil</th><th class="cm-num">24 Stunden</th><th class="cm-num">7 Tage</th></tr></thead><tbody>';
        $tops = array();
        foreach ($currentDirs as $path => $bytes) {
            if (strpos($path, '/') === false) {
                $tops[$path] = $bytes;
            }
        }
        arsort($tops);
        $oldDay = ($dirsDay && isset($dirsDay['dirs'])) ? $dirsDay['dirs'] : array();
        $oldWeek = ($dirsWeek && isset($dirsWeek['dirs'])) ? $dirsWeek['dirs'] : array();
        foreach ($tops as $path => $bytes) {
            cm_dir_row($path, $bytes, $pool['used'], $oldDay, $oldWeek, false);
            $children = array();
            $prefix = $path . '/';
            foreach ($currentDirs as $child => $childBytes) {
                if (strpos($child, $prefix) === 0 && substr_count($child, '/') === 1) {
                    $children[$child] = $childBytes;
                }
            }
            arsort($children);
            $shown = 0;
            foreach ($children as $child => $childBytes) {
                if ($shown >= 8) {
                    break;
                }
                cm_dir_row($child, $childBytes, $pool['used'], $oldDay, $oldWeek, true);
                $shown++;
            }
        }
        echo '</tbody></table>';
        cm_render_growth($currentDirs, $oldWeek);
    }

    if (!empty($pool['files'])) {
        echo '<h3>Große Dateien ab 512 MB</h3><table class="cm-table"><thead><tr><th>Datei</th><th class="cm-num">Belegt</th></tr></thead><tbody>';
        foreach ($pool['files'] as $file) {
            echo '<tr><td>' . cm_h($file['path']) . '</td><td class="cm-num">' . cm_h(cm_bytes($file['bytes'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if (!empty($pool['shares'])) {
        echo '<h3>Shares auf diesem Pool</h3><table class="cm-table"><thead><tr><th>Share</th><th>Cache-Modus</th><th class="cm-num">Auf dem Pool</th></tr></thead><tbody>';
        foreach ($pool['shares'] as $share) {
            echo '<tr><td>' . cm_h($share['name']) . '</td><td>' . cm_h(cm_mode_label($share['mode'])) . '</td><td class="cm-num">';
            echo $share['bytes'] === null ? '–' : cm_h(cm_bytes($share['bytes']));
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if (!empty($pool['docker'])) {
        echo '<h3>Docker</h3><table class="cm-table"><tbody>';
        $docker = $pool['docker'];
        if (!empty($docker['image_path'])) {
            echo '<tr><td>Image-Datei</td><td>' . cm_h($docker['image_path']) . '</td></tr>';
            if (isset($docker['image_allocated'])) {
                echo '<tr><td>Davon belegt</td><td>' . cm_h(cm_bytes($docker['image_allocated'])) . '</td></tr>';
            }
            if (!empty($docker['image_limit'])) {
                echo '<tr><td>Eingestellte Größe</td><td>' . cm_h($docker['image_limit']) . ' GB</td></tr>';
            }
        }
        if (!empty($docker['usage'])) {
            foreach ($docker['usage'] as $row) {
                echo '<tr><td>' . cm_h($row['type']) . '</td><td>' . cm_h($row['size']);
                if (!empty($row['reclaim'])) {
                    echo ' <span class="cm-muted">freigebbar ' . cm_h($row['reclaim']) . '</span>';
                }
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    if (!empty($pool['vms'])) {
        echo '<h3>VM-Abbilder</h3><table class="cm-table"><thead><tr><th>Datei</th><th class="cm-num">Belegt</th></tr></thead><tbody>';
        foreach ($pool['vms'] as $vm) {
            echo '<tr><td>' . cm_h($vm['path']) . '</td><td class="cm-num">' . cm_h(cm_bytes($vm['bytes'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3>Verlauf der Belegung</h3>';
    echo cm_chart($snapshots, $name, (int) $cfg['WARN_PERCENT'], (int) $cfg['CRIT_PERCENT']);
}

function cm_dir_row($path, $bytes, $used, $oldDay, $oldWeek, $child) {
    $label = $child ? basename($path) : $path;
    $share = ($used > 0) ? ($bytes / $used) * 100 : null;
    echo '<tr' . ($child ? ' class="cm-child"' : '') . '><td>' . cm_h($label) . '</td>';
    echo '<td class="cm-num">' . cm_h(cm_bytes($bytes)) . '</td>';
    echo '<td class="cm-num">' . cm_h(cm_pct_text($share)) . '</td>';
    echo '<td class="cm-num">' . cm_dir_delta($path, $bytes, $oldDay, $child) . '</td>';
    echo '<td class="cm-num">' . cm_dir_delta($path, $bytes, $oldWeek, $child) . '</td></tr>';
}

function cm_dir_delta($path, $bytes, $old, $child) {
    if (!$old) {
        return '<span class="cm-muted">–</span>';
    }
    if (!array_key_exists($path, $old)) {
        if ($child) {
            return '<span class="cm-muted">–</span>';
        }
        return cm_delta($bytes);
    }
    return cm_delta($bytes - $old[$path]);
}

function cm_render_growth($current, $oldWeek) {
    if (!$oldWeek) {
        echo '<p class="cm-muted">Der Zuwachs je Ordner erscheint, sobald eine ältere Ordnermessung vorliegt.</p>';
        return;
    }
    $rows = array();
    $paths = array_unique(array_merge(array_keys($current), array_keys($oldWeek)));
    foreach ($paths as $path) {
        $now = isset($current[$path]) ? $current[$path] : 0;
        if (!array_key_exists($path, $oldWeek) && strpos($path, '/') !== false) {
            continue;
        }
        $then = array_key_exists($path, $oldWeek) ? $oldWeek[$path] : 0;
        $delta = $now - $then;
        if (abs($delta) < 50 * 1024 * 1024) {
            continue;
        }
        $rows[] = array('path' => $path, 'delta' => $delta);
    }
    usort($rows, function ($a, $b) {
        if ($a['delta'] === $b['delta']) {
            return 0;
        }
        return ($a['delta'] > $b['delta']) ? -1 : 1;
    });
    $rows = array_slice($rows, 0, 15);
    if (!$rows) {
        echo '<p class="cm-muted">In sieben Tagen hat kein gemessener Ordner um mehr als 50 MB zu- oder abgenommen.</p>';
        return;
    }
    echo '<h3>Wo sich der Platz verändert hat</h3><table class="cm-table"><thead><tr><th>Ordner</th><th class="cm-num">7 Tage</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . cm_h($row['path']) . '</td><td class="cm-num">' . cm_delta($row['delta']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function cm_delta($bytes) {
    if ($bytes === null) {
        return '<span class="cm-muted">–</span>';
    }
    $class = 'cm-muted';
    $prefix = '';
    if ($bytes > 0) {
        $class = 'cm-grow';
        $prefix = '+';
    } elseif ($bytes < 0) {
        $class = 'cm-shrink';
    }
    return '<span class="' . $class . '">' . $prefix . cm_h(cm_bytes($bytes)) . '</span>';
}

function cm_fact($label, $value) {
    echo '<div><span class="cm-muted">' . cm_h($label) . '</span><strong>' . cm_h($value) . '</strong></div>';
}

function cm_chunk($label, $used, $size, $cfg) {
    if (!$size) {
        return;
    }
    $pct = cm_pct($used, $size);
    echo '<div class="cm-chunk"><span>' . cm_h($label) . ' ' . cm_h(cm_pct_text($pct)) . '</span>';
    echo cm_bar($pct, cm_usage_level($pct, $cfg));
    echo '<span class="cm-muted">' . cm_h(cm_bytes($used)) . ' von ' . cm_h(cm_bytes($size)) . '</span></div>';
}

function cm_usage_level($pct, $cfg) {
    if ($pct === null) {
        return 'ok';
    }
    if ($pct >= (int) $cfg['CRIT_PERCENT']) {
        return 'crit';
    }
    if ($pct >= (int) $cfg['WARN_PERCENT']) {
        return 'warn';
    }
    return 'ok';
}

function cm_bar($pct, $level) {
    $width = ($pct === null) ? 0 : max(0, min(100, $pct));
    $color = '#3a7ca5';
    if ($level === 'warn') {
        $color = '#d08900';
    }
    if ($level === 'crit') {
        $color = '#c43b3b';
    }
    return '<div class="cm-bar" title="' . cm_h(cm_pct_text($pct)) . '"><span style="width:' . number_format($width, 1, '.', '') . '%;background:' . $color . '"></span></div>';
}

function cm_chart($snapshots, $pool, $warn, $crit) {
    $byDay = array();
    $limit = time() - 30 * 86400;
    foreach ($snapshots as $point) {
        if (!isset($point['pool']) || $point['pool'] !== $pool || !isset($point['ts']) || $point['ts'] < $limit) {
            continue;
        }
        if (empty($point['size']) || !isset($point['used'])) {
            continue;
        }
        $day = date('Y-m-d', $point['ts']);
        $byDay[$day] = array($point['ts'], ($point['used'] / $point['size']) * 100);
    }
    ksort($byDay);
    $points = array_values($byDay);
    if (count($points) < 2) {
        return '<p class="cm-muted">Der Verlauf beginnt, sobald Messungen an zwei verschiedenen Tagen vorliegen.</p>';
    }
    $width = 680;
    $height = 140;
    $pad = 16;
    $minTs = $points[0][0];
    $maxTs = $points[count($points) - 1][0];
    $span = max(1, $maxTs - $minTs);
    $coords = array();
    foreach ($points as $point) {
        $x = $pad + (($width - 2 * $pad) * (($point[0] - $minTs) / $span));
        $y = $pad + (($height - 2 * $pad) * (1 - max(0, min(100, $point[1])) / 100));
        $coords[] = round($x, 1) . ',' . round($y, 1);
    }
    $warnY = $pad + (($height - 2 * $pad) * (1 - max(0, min(100, $warn)) / 100));
    $critY = $pad + (($height - 2 * $pad) * (1 - max(0, min(100, $crit)) / 100));
    $svg = '<svg class="cm-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Belegung der letzten 30 Tage">';
    $svg .= '<line x1="' . $pad . '" y1="' . round($warnY, 1) . '" x2="' . ($width - $pad) . '" y2="' . round($warnY, 1) . '" class="cm-line-warn"/>';
    $svg .= '<line x1="' . $pad . '" y1="' . round($critY, 1) . '" x2="' . ($width - $pad) . '" y2="' . round($critY, 1) . '" class="cm-line-crit"/>';
    $svg .= '<polyline points="' . implode(' ', $coords) . '" class="cm-line"/>';
    $svg .= '</svg>';
    $svg .= '<p class="cm-muted">' . cm_h(date('d.m.Y', $minTs)) . ' bis ' . cm_h(date('d.m.Y', $maxTs)) . ', orange ' . (int) $warn . ' %, rot ' . (int) $crit . ' %.</p>';
    return $svg;
}

function cm_render_settings($cfg, $names) {
    $token = '';
    if (!empty($GLOBALS['cm_csrf'])) {
        $token = (string) $GLOBALS['cm_csrf'];
    } elseif (isset($GLOBALS['csrf_token'])) {
        $token = (string) $GLOBALS['csrf_token'];
    }
    echo '<h3>Einstellungen</h3>';
    echo '<form method="post" action="/Settings/CacheMonitor" class="cm-form">';
    if ($token !== '') {
        echo '<input type="hidden" name="csrf_token" value="' . cm_h($token) . '">';
    }
    echo '<input type="hidden" name="cm_settings" value="1">';
    echo '<label>Warnung ab %<input name="WARN_PERCENT" value="' . cm_h($cfg['WARN_PERCENT']) . '" inputmode="numeric"></label>';
    echo '<label>Kritisch ab %<input name="CRIT_PERCENT" value="' . cm_h($cfg['CRIT_PERCENT']) . '" inputmode="numeric"></label>';
    echo '<label>Metadaten-Warnung ab %<input name="META_PERCENT" value="' . cm_h($cfg['META_PERCENT']) . '" inputmode="numeric"></label>';
    echo '<label>Prognose-Horizont in Tagen<input name="FORECAST_DAYS" value="' . cm_h($cfg['FORECAST_DAYS']) . '" inputmode="numeric"></label>';
    echo '<label>Ordnerscan alle Stunden<input name="SCAN_HOURS" value="' . cm_h($cfg['SCAN_HOURS']) . '" inputmode="numeric"></label>';
    echo '<label>Ordertiefe<select name="DEPTH"><option value="1"' . ($cfg['DEPTH'] === '1' ? ' selected' : '') . '>Nur oberste Ebene</option><option value="2"' . ($cfg['DEPTH'] !== '1' ? ' selected' : '') . '>Mit Unterordnern</option></select></label>';
    echo '<label>Benachrichtigung<select name="NOTIFY"><option value="yes"' . ($cfg['NOTIFY'] !== 'no' ? ' selected' : '') . '>An</option><option value="no"' . ($cfg['NOTIFY'] === 'no' ? ' selected' : '') . '>Aus</option></select></label>';
    echo '<label>Standard-Pool<input name="POOL" value="' . cm_h($cfg['POOL']) . '" placeholder="' . cm_h($names ? implode(', ', $names) : 'cache') . '"></label>';
    echo '<button class="cm-btn cm-on" type="submit">Speichern</button>';
    echo '</form>';
    echo '<p class="cm-muted">Die stündliche Messung aktualisiert Belegung, Temperatur und Prognose. Der Ordnerscan ist seltener, weil er den Cache liest. Meldungen gehen über die Unraid-Benachrichtigung.</p>';
}

function cm_css() {
    return '
.cm{max-width:1080px}
.cm h2,.cm h3{margin:18px 0 8px}
.cm-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap}
.cm-muted{color:inherit;opacity:.75}
.cm-head p{margin:4px 0 0}
.cm-actions{display:flex;gap:8px;flex-wrap:wrap}
.cm-btn{display:inline-block;padding:6px 12px;border:1px solid rgba(127,127,127,.45);border-radius:6px;text-decoration:none;background:transparent;color:inherit;cursor:pointer}
.cm-on{font-weight:650}
.cm-note{padding:8px 0}
.cm-bad{font-weight:650}
.cm-banner{border-left:6px solid #2f8f4e;padding:4px 0 4px 14px;margin:14px 0}
.cm-banner.cm-warn{border-left-color:#d08900}
.cm-banner.cm-crit{border-left-color:#c43b3b}
.cm-banner-top{display:flex;justify-content:space-between;gap:12px;align-items:baseline}
.cm-banner ul{margin:8px 0 0;padding-left:18px}
.cm-bar{height:10px;background:rgba(127,127,127,.28);border-radius:99px;overflow:hidden;margin:8px 0}
.cm-bar>span{display:block;height:100%}
.cm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px 16px;margin:12px 0}
.cm-grid strong{display:block}
.cm-table{width:100%;border-collapse:collapse;margin:8px 0 16px}
.cm-table th,.cm-table td{text-align:left;padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.28);vertical-align:top}
.cm-num{text-align:right;white-space:nowrap}
.cm-child td:first-child{padding-left:22px;opacity:.9}
.cm-grow{color:#c43b3b}
.cm-shrink{color:#2f8f4e}
.cm-chunk{margin:8px 0}
.cm-chart{width:100%;height:auto;max-width:680px}
.cm-line{fill:none;stroke:#3a7ca5;stroke-width:2}
.cm-line-warn{stroke:#d08900;stroke-dasharray:4 4}
.cm-line-crit{stroke:#c43b3b;stroke-dasharray:4 4}
.cm-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;margin-bottom:8px}
.cm-form label{display:grid;gap:4px;font-size:.92rem}
.cm-form input,.cm-form select{padding:6px 8px}
.cm-switch{display:flex;gap:8px;flex-wrap:wrap}
.cm-foot{margin-top:18px}
';
}
