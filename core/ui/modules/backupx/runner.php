<?php
/* Scheduled backup runner - cron (hourly check; runs when due). Full server backup: /var/www + all databases,
 * with retention, then optional upload to a remote destination. Standalone (no panel session). */
if (!defined('DATA_DIR')) define('DATA_DIR', '/opt/orizen/data');
$cfg = json_decode(@file_get_contents(DATA_DIR.'/config.json') ?: '{}', true) ?: [];
$bx  = $cfg['backupx'] ?? [];
$forceNow = isset($argv[1]) && $argv[1] === 'now';   // manual "Back up now" always runs
if (empty($bx['enabled']) && !$forceNow) exit;

// respect the chosen frequency (daily/hourly/weekly) using a last-run stamp
$dir = DATA_DIR.'/backups'; @mkdir($dir, 0775, true);
$freq = $bx['freq'] ?? 'daily';
$every = ['hourly'=>3600, 'daily'=>86400, 'weekly'=>604800][$freq] ?? 86400;
$stampF = $dir.'/.backupx-last';
$last = is_file($stampF) ? (int)@file_get_contents($stampF) : 0;
if (!(isset($argv[1]) && $argv[1] === 'now') && (time() - $last) < $every - 60) exit;   // not due yet
@file_put_contents($stampF, (string)time());

$ts = date('Ymd-His'); $stage = $dir.'/.bxstage-'.$ts; @mkdir($stage.'/databases', 0775, true);
$u = $cfg['db_user'] ?? 'root'; $p = $cfg['db_pass'] ?? ''; $h = $cfg['db_host'] ?? 'localhost';
$dbs = []; @exec('mysql -h'.escapeshellarg($h).' -u'.escapeshellarg($u).' -p'.escapeshellarg($p).' -N -e "SHOW DATABASES" 2>/dev/null', $dbs);
foreach ($dbs as $db) {
    if (in_array($db, ['mysql','information_schema','performance_schema','sys'], true)) continue;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) continue;
    @exec('mysqldump -h'.escapeshellarg($h).' -u'.escapeshellarg($u).' -p'.escapeshellarg($p).' --single-transaction --routines '.escapeshellarg($db).' > '.escapeshellarg($stage.'/databases/'.$db.'.sql').' 2>/dev/null');
}
$webroot = $cfg['webroot_base'] ?? '/var/www';
$out = $dir.'/scheduled-'.$ts.'.tar.gz';
@exec('tar -czf '.escapeshellarg($out).' -C '.escapeshellarg($webroot).' . -C '.escapeshellarg($stage).' databases 2>/dev/null');
// clean stage
foreach (glob($stage.'/databases/*') ?: [] as $f) @unlink($f);
@rmdir($stage.'/databases'); @rmdir($stage);

// retention
$keep = max(1, (int)($bx['retention'] ?? 7));
$files = glob($dir.'/scheduled-*.tar.gz') ?: []; usort($files, fn($a,$b)=>filemtime($a)-filemtime($b));
while (count($files) > $keep) { $f = array_shift($files); @unlink($f); }

// upload to a remote destination (optional, free protocols)
$uploaded = 'no destination';
$d = $bx['dest'] ?? null;
if ($d && !empty($d['type']) && !empty($d['host'])) {
    $remote = rtrim($d['path'] ?? '/', '/').'/'.basename($out);
    $creds = ''; if (!empty($d['user'])) $creds = '--user '.escapeshellarg($d['user'].':'.($d['pass'] ?? ''));
    $url = '';
    switch ($d['type']) {
        case 'ftp':    $url = 'ftp://'.$d['host'].$remote; break;
        case 'ftps':   $url = 'ftps://'.$d['host'].$remote; break;
        case 'sftp':   $url = 'sftp://'.$d['host'].$remote; break;
        case 'webdav': $url = (preg_match('~^https?://~',$d['host']) ? $d['host'] : 'https://'.$d['host']).$remote; break;
    }
    if ($url) { $rc = 0; @exec('curl -s --max-time 600 -k '.$creds.' -T '.escapeshellarg($out).' '.escapeshellarg($url).' 2>/dev/null', $o, $rc); $uploaded = ($rc === 0) ? ('uploaded to '.$d['type']) : ('upload failed (rc '.$rc.')'); }
}
@file_put_contents($dir.'/backupx.log', date('c').' created '.basename($out).' ('.(int)@filesize($out).' bytes) - '.$uploaded."\n", FILE_APPEND | LOCK_EX);
