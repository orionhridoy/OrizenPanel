<?php
/*
 * Orizen module: Migration Tools
 * Import a website from another control panel's backup. cPanel backups
 * (cpmove-*.tar.gz / backup-*.tar.gz) are supported end to end: it restores the
 * public_html files and imports the account's MySQL databases. Upload the backup
 * with the File Manager first, then point this at it.
 */
function mgSafeArchive(string $p): bool {
    return (strpos($p,'..') === false) && preg_match('~^(/var/www/|/opt/orizen/|/home/)[A-Za-z0-9._/-]+\.(tar\.gz|tgz|tar)$~', $p) && is_file($p);
}
function mgApiScan(): array {
    $p = (string)($_POST['path'] ?? ''); if (!mgSafeArchive($p)) return ['ok'=>false,'error'=>'Give a path to a .tar.gz backup under /var/www, /home or /opt/orizen (upload it with the File Manager first).'];
    $tmp = sys_get_temp_dir().'/ozmig-'.bin2hex(random_bytes(4)); @mkdir($tmp,0775,true);
    @exec('tar -xzf '.escapeshellarg($p).' -C '.escapeshellarg($tmp).' 2>/dev/null', $o, $rc);
    if ($rc !== 0) { @exec('rm -rf '.escapeshellarg($tmp)); return ['ok'=>false,'error'=>'Could not extract the archive (is it a cPanel .tar.gz?).']; }
    $ph = trim((string)@shell_exec('find '.escapeshellarg($tmp).' -maxdepth 4 -type d -name public_html 2>/dev/null | head -1'));
    $dbs = array_filter(explode("\n", (string)@shell_exec('find '.escapeshellarg($tmp).' -path "*/mysql/*.sql" 2>/dev/null')));
    $names = array_map(fn($f)=>basename($f,'.sql'), $dbs);
    @exec('rm -rf '.escapeshellarg($tmp));
    return ['ok'=>true, 'hasFiles'=>($ph!==''), 'dbCount'=>count($names), 'dbs'=>array_values($names)];
}
function mgApiImport(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    @set_time_limit(0);
    $p = (string)($_POST['path'] ?? ''); $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
    if (!mgSafeArchive($p)) return ['ok'=>false,'error'=>'Invalid backup path.'];
    if (!preg_match('/^[a-z0-9.-]{2,80}$/',$domain)) return ['ok'=>false,'error'=>'Enter the domain to import into (e.g. example.com).'];
    $tmp = sys_get_temp_dir().'/ozmig-'.bin2hex(random_bytes(4)); @mkdir($tmp,0775,true);
    @exec('tar -xzf '.escapeshellarg($p).' -C '.escapeshellarg($tmp).' 2>/dev/null', $o, $rc);
    if ($rc !== 0) { @exec('rm -rf '.escapeshellarg($tmp)); return ['ok'=>false,'error'=>'Extract failed.']; }
    $steps = [];
    // 1) files
    $ph = trim((string)@shell_exec('find '.escapeshellarg($tmp).' -maxdepth 4 -type d -name public_html 2>/dev/null | head -1'));
    $base = cfgGet('webroot_base','/var/www'); $docroot = "$base/$domain/public";
    $r = helper('create-site', [$domain, $docroot]);
    if (($r['code'] ?? 1) !== 0) { @exec('rm -rf '.escapeshellarg($tmp)); return ['ok'=>false,'error'=>'Could not create site: '.trim((string)$r['out'])]; }
    if ($ph !== '') { @exec('cp -a '.escapeshellarg($ph.'/.').' '.escapeshellarg($docroot.'/').' 2>/dev/null'); helper('perm-repair',[$docroot,cfgGet('web_user','www-data')]); $steps[] = 'files restored'; }
    else $steps[] = 'no public_html found (files skipped)';
    // 2) databases
    $dbs = array_filter(explode("\n", (string)@shell_exec('find '.escapeshellarg($tmp).' -path "*/mysql/*.sql" 2>/dev/null')));
    $c = ['u'=>cfgGet('db_user','root'),'p'=>cfgGet('db_pass',''),'h'=>cfgGet('db_host','localhost')]; $imported = 0;
    foreach ($dbs as $sql) {
        $name = basename($sql,'.sql'); if (!preg_match('/^[A-Za-z0-9_]+$/',$name)) continue;
        if (db()) { try { db()->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4"); } catch (Exception $e) {} }
        @exec('mysql -h'.escapeshellarg($c['h']).' -u'.escapeshellarg($c['u']).' -p'.escapeshellarg($c['p']).' '.escapeshellarg($name).' < '.escapeshellarg($sql).' 2>/dev/null', $oo, $rr);
        if ($rr === 0) $imported++;
    }
    $steps[] = $imported.' database(s) imported';
    // register site
    $sites = loadJson(SITES_FILE, []); if (!array_filter($sites, fn($s)=>($s['domain']??'')===$domain)) { $sites[] = ['domain'=>$domain,'docroot'=>$docroot,'ssl'=>false,'created'=>date('c'),'imported'=>true]; saveJson(SITES_FILE,$sites); }
    @exec('rm -rf '.escapeshellarg($tmp)); auditLog('migrate_import',$domain);
    return ['ok'=>true,'msg'=>'Imported '.$domain.': '.implode(', ',$steps).'. Point its DNS here, then secure it with HTTPS.'];
}

function migratePage(): void { ?>
<?=helpBox('Migrate from another panel', 'Bring a website over from <b>cPanel</b> (and cPanel-style backups from Plesk/CyberPanel/aaPanel/HestiaCP export as the same tarball). 1) Upload the account backup (a <span class="mono">.tar.gz</span>) using the <a href="?page=files">File Manager</a>. 2) Enter its path and the domain to import into. Orizen restores the files (public_html) and imports the databases. DNS, SSL and mailboxes are set up here afterwards.')?>
<div class="card">
  <h3>Import a cPanel backup</h3>
  <div class="row">
    <div style="flex:2"><label>Backup file path</label><input id="mgPath" placeholder="/var/www/uploads/cpmove-user.tar.gz"></div>
    <button class="btn btn-g" onclick="mgScan()">Scan</button>
  </div>
  <div id="mgScanOut" class="sm mt"></div>
  <div class="row mt">
    <div style="flex:2"><label>Import into domain</label><input id="mgDom" placeholder="example.com"></div>
    <button class="btn btn-p" onclick="mgImport()">Import now</button>
  </div>
</div>
<div id="mgOut"></div>
<script>
function mgEsc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
function mgScan(){ document.getElementById('mgScanOut').textContent='Scanning...'; api('mg_scan',{path:document.getElementById('mgPath').value.trim()}).then(function(r){ if(!r.ok){document.getElementById('mgScanOut').innerHTML='<span style="color:var(--red)">'+mgEsc(r.error)+'</span>';return;} document.getElementById('mgScanOut').innerHTML='Files: '+(r.hasFiles?'<span class="badge bg-green">public_html found</span>':'<span class="badge bg-red">none</span>')+' . Databases: <b>'+r.dbCount+'</b> '+(r.dbs.length?'('+r.dbs.map(mgEsc).join(', ')+')':''); }); }
function mgImport(){ var d=document.getElementById('mgDom').value.trim(); if(!d){toast('Enter the target domain','e');return;} document.getElementById('mgOut').innerHTML='<div class="card"><div class="sm">Importing... this can take a while for large sites.</div></div>'; api('mg_import',{path:document.getElementById('mgPath').value.trim(),domain:d}).then(function(r){ toast(r.ok?'Imported':(r.error||'Failed'), r.ok?'':'e'); document.getElementById('mgOut').innerHTML='<div class="card"><div class="alert '+(r.ok?'alert-ok':'alert-e')+'">'+mgEsc(r.ok?r.msg:r.error)+'</div></div>'; }); }
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'migrate','name'=>'Migration Tools','desc'=>'Import websites + databases from a cPanel (or cPanel-style) backup.','feature'=>'enableMigration'],
        'pages' => ['migrate'=>['title'=>'Migrate','section'=>'DOMAINS','feature'=>'enableMigration','render'=>'migratePage']],
        'api'   => ['mg_scan'=>'mgApiScan','mg_import'=>'mgApiImport'],
    ]);
}
