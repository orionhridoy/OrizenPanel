<?php
/* Notifications checker - run every 5 min by cron. Evaluates events and alerts once per state change (no spam). */
if (!defined('DATA_DIR')) define('DATA_DIR', '/opt/orizen/data');
require __DIR__ . '/notifylib.php';

$c = ntCfg(); if (!$c['enabled']) exit;
$state = ntState(); $changed = false;

function ntFire(array &$state, bool &$changed, string $key, bool $bad, string $subject, string $body): void {
    $was = !empty($state[$key]);
    if ($bad && !$was)      { ntSend($subject, $body); $state[$key] = time(); $changed = true; }
    elseif (!$bad && $was)  { ntSend('Recovered: '.$subject, 'The issue has cleared.'); unset($state[$key]); $changed = true; }
}

// disk
if (!empty($c['events']['disk'])) {
    $dt = @disk_total_space('/') ?: 1; $df = @disk_free_space('/') ?: 0; $pct = (int)round(100 - $df/$dt*100);
    ntFire($state, $changed, 'disk', $pct >= $c['disk_pct'], 'Disk almost full', 'Root filesystem is at '.$pct.'% (threshold '.$c['disk_pct'].'%).');
}
// services down
if (!empty($c['events']['service'])) {
    foreach (['apache2'=>'Apache','mariadb'=>'MariaDB'] as $u=>$label) {
        $active = trim((string)@shell_exec('systemctl is-active '.escapeshellarg($u).' 2>/dev/null')) === 'active';
        ntFire($state, $changed, 'svc_'.$u, !$active, $label.' is down', $label.' ('.$u.') is not active on '.gethostname().'.');
    }
}
// memory
if (!empty($c['events']['ram'])) {
    $mi = @file_get_contents('/proc/meminfo'); $tot=0; $avail=0;
    if ($mi) { if (preg_match('/MemTotal:\s+(\d+)/',$mi,$x)) $tot=(int)$x[1]; if (preg_match('/MemAvailable:\s+(\d+)/',$mi,$x)) $avail=(int)$x[1]; }
    $pct = $tot>0 ? (int)round(100 - $avail/$tot*100) : 0;
    ntFire($state, $changed, 'ram', $pct >= $c['ram_pct'], 'High memory use', 'Memory at '.$pct.'% (threshold '.$c['ram_pct'].'%).');
}
// cpu (load-average based, no blocking sample)
if (!empty($c['events']['cpu'])) {
    $cores = (int)trim((string)@shell_exec('nproc 2>/dev/null')); if ($cores < 1) $cores = 1;
    $la = function_exists('sys_getloadavg') ? (@sys_getloadavg() ?: [0]) : [0];
    $pct = (int)min(100, round(($la[0] / $cores) * 100));
    ntFire($state, $changed, 'cpu', $pct >= $c['cpu_pct'], 'High CPU load', '1-minute load implies ~'.$pct.'% CPU (threshold '.$c['cpu_pct'].'%).');
}

if ($changed) ntSaveState($state);
