<?php
require_once dirname(__DIR__) . '/src/usr/local/emhttp/plugins/cache-monitor/include/common.php';

$failed = 0;

function expect($label, $condition) {
    global $failed;
    if (!$condition) {
        echo "FAIL $label\n";
        $failed++;
        return;
    }
    echo "ok   $label\n";
}

$btrfs = <<<'TXT'
Overall:
    Device size:                  2000000000000
    Device allocated:             1200000000000
    Device unallocated:           800000000000
    Device missing:                        0
    Used:                          900000000000
    Free (estimated):              550000000000      (min: 500000000000)
    Free (statfs, df):             540000000000
    Data ratio:                               2.00
    Metadata ratio:                           2.00

Data,RAID1: Size:590000000000, Used:440000000000
Metadata,RAID1: Size:2000000000, Used:1700000000
System,RAID1: Size:8000000, Used:16000
Data,single: Size:1000, Used:500
TXT;

$parsed = cm_parse_btrfs_usage($btrfs);
expect('btrfs device size', $parsed['device_size'] === 2000000000000);
expect('btrfs free min', $parsed['free_min'] === 500000000000);
expect('btrfs unallocated', $parsed['unallocated'] === 800000000000);
expect('btrfs data summed', $parsed['data_size'] === 590000001000);
expect('btrfs meta used', $parsed['meta_used'] === 1700000000);
expect('btrfs profiles', in_array('Data RAID1', $parsed['profiles'], true) && in_array('Data single', $parsed['profiles'], true));

$df = "Filesystem     1B-blocks         Used    Available Use% Mounted on\n/dev/nvme0n1p1 1000000000000 800000000000 200000000000  80% /mnt/cache\n";
$dfParsed = cm_parse_df($df);
expect('df used', $dfParsed['used'] === 800000000000);
expect('df free', $dfParsed['free'] === 200000000000);
expect('df mount', $dfParsed['mount'] === '/mnt/cache');

$du = "10\t/mnt/cache\n40\t/mnt/cache/appdata\n15\t/mnt/cache/appdata/mein plex\n5\t/mnt/cache/system\n";
$dirs = cm_parse_du($du, '/mnt/cache');
expect('du skips root', !isset($dirs['']));
expect('du appdata', $dirs['appdata'] === 40);
expect('du spaced child', $dirs['appdata/mein plex'] === 15);
$trimmed = cm_trim_dirs($dirs);
expect('trim keeps top', isset($trimmed['appdata']) && isset($trimmed['system']));

$stats = "[/dev/nvme0n1p1].write_io_errs    1\n[/dev/nvme0n1p1].read_io_errs     0\n[/dev/nvme0n1p1].corruption_errs  2\n";
$devices = cm_parse_btrfs_stats($stats);
expect('device errors', $devices['/dev/nvme0n1p1'] === 3);
expect('nvme base', cm_disk_base('/dev/nvme0n1p1') === 'nvme0n1');
expect('sd base', cm_disk_base('/dev/sdb1') === 'sdb');

$files = cm_parse_find("100\t/mnt/cache/klein\n900\t/mnt/cache/gross.img\n");
expect('find order', $files[0]['path'] === '/mnt/cache/gross.img' && $files[0]['bytes'] === 900);

expect('scrub empty', cm_parse_scrub('no stats available') === 'noch kein Scrub');
expect('scrub status', cm_parse_scrub("Status: finished\nError summary: no errors found") === 'finished');
expect('mode label', cm_mode_label('prefer') === 'Cache bevorzugen');
expect('pool name', cm_valid_pool_name('cache') && !cm_valid_pool_name('../x'));

$days = cm_forecast_days(200000, 100000, 86400, 100000);
expect('forecast 1 day', $days !== null && abs($days - 1) < 0.01);
expect('forecast shrink', cm_forecast_days(100, 200, 86400, 50) === null);

$cfg = cm_defaults();
$ok = cm_assess(array('mounted' => true, 'used' => 10, 'size' => 100, 'free' => 90, 'meta_used' => 1, 'meta_size' => 10, 'error_sum' => 0), $cfg, 40);
expect('assess ok', $ok['level'] === 'ok');
$warn = cm_assess(array('mounted' => true, 'used' => 85, 'size' => 100, 'free' => 15, 'error_sum' => 0), $cfg, null);
expect('assess warn', $warn['level'] === 'warn');
$crit = cm_assess(array(
    'mounted' => true,
    'used' => 50,
    'size' => 100,
    'free' => 50,
    'meta_used' => 90,
    'meta_size' => 100,
    'unallocated' => 1000,
    'error_sum' => 0,
), $cfg, null);
expect('assess meta crit', $crit['level'] === 'crit');

$history = array('version' => 1, 'snapshots' => array());
$history = cm_history_add($history, array('ts' => 1000, 'pool' => 'cache', 'used' => 1, 'size' => 2, 'free' => 1, 'dirs' => array()));
$history = cm_history_add($history, array('ts' => 1200, 'pool' => 'cache', 'used' => 5, 'size' => 2, 'free' => 1, 'dirs' => array('appdata' => 4)));
expect('history hourly collapse', count($history['snapshots']) === 1 && $history['snapshots'][0]['used'] === 5);
$history = cm_history_add($history, array('ts' => 1000 + 7200, 'pool' => 'cache', 'used' => 9, 'size' => 10, 'free' => 1, 'dirs' => array('appdata' => 8)));
expect('history keeps next hour', count($history['snapshots']) === 2);
$found = cm_find_dir_snapshot($history['snapshots'], 'cache', 1000 + 7200, 10000);
expect('dir snapshot', $found && isset($found['dirs']['appdata']));

$normalized = cm_normalize_cfg(array(
    'WARN_PERCENT' => '10',
    'CRIT_PERCENT' => '10',
    'META_PERCENT' => '101',
    'FORECAST_DAYS' => '0',
    'SCAN_HOURS' => '999',
    'DEPTH' => '9',
    'NOTIFY' => 'no',
    'POOL' => '../etc',
));
expect('cfg floor', $normalized['WARN_PERCENT'] === '50' && $normalized['CRIT_PERCENT'] === '55');
expect('cfg caps', $normalized['SCAN_HOURS'] === '168' && $normalized['DEPTH'] === '2' && $normalized['POOL'] === '' && $normalized['NOTIFY'] === 'no');

if ($failed > 0) {
    echo "$failed failed\n";
    exit(1);
}
echo "all passed\n";
exit(0);
