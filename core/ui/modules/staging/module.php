<?php
/*
 * Orizen module: Staging
 * Make a staging copy of a live site (files + database), test changes safely,
 * then push it back to production. Production is backed up before every push.
 */
function stgFile(): string { return DATA_DIR.'/staging.json'; }
function stgLoad(): array { $j = loadJson(stgFile(), []); return is_array($j) ? $j : []; }
function stgSave(array $d): bool { return saveJson(stgFile(), array_values($d)); }
function stgFind(string $id): ?array { foreach (stgLoad() as $s) if (($s['id'] ?? '') === $id) return $s; return null; }
function stgDbList(): array {
    $pdo = db(); $out = []; if (!$pdo) return $out;
    try { foreach ($pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN) as $d) if (!in_array($d,['mysql','information_schema','performance_schema','sys'],true)) $out[] = $d; } catch (Exception $e) {}
    return $out;
}
function stgDbCreds(): array { return ['u'=>cfgGet('db_user','root'),'p'=>cfgGet('db_pass',''),'h'=>cfgGet('db_host','localhost')]; }
/** Copy a database into another (created if needed), replacing $from->$to text in the dump (for app URLs). */
function stgCloneDb(string $src, string $dst, string $from = '', string $to = ''): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/',$src) || !preg_match('/^[A-Za-z0-9_]+$/',$dst)) return false;
    $pdo = db(); if (!$pdo) return false;
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dst` CHARACTER SET utf8mb4");
    $c = stgDbCreds(); $tmp = sys_get_temp_dir().'/ozstg-'.bin2hex(random_bytes(4)).'.sql';
    @exec('mysqldump -h'.escapeshellarg($c['h']).' -u'.escapeshellarg($c['u']).' -p'.escapeshellarg($c['p']).' --single-transaction --routines '.escapeshellarg($src).' > '.escapeshellarg($tmp).' 2>/dev/null', $o, $rc);
    if ($rc !== 0 || !is_file($tmp)) return false;
    if ($from !== '' && $to !== '') { $sql = file_get_contents($tmp); $sql = str_replace($from, $to, $sql); file_put_contents($tmp, $sql); }
    @exec('mysql -h'.escapeshellarg($c['h']).' -u'.escapeshellarg($c['u']).' -p'.escapeshellarg($c['p']).' '.escapeshellarg($dst).' < '.escapeshellarg($tmp).' 2>/dev/null', $o2, $rc2);
    @unlink($tmp); return $rc2 === 0;
}
function stgWpConfigDb(string $docroot, string $db): void {
    $f = $docroot.'/wp-config.php'; if (!is_file($f)) return;
    $c = file_get_contents($f);
    $c = preg_replace("/(define\(\s*'DB_NAME'\s*,\s*')[^']*(')/", '${1}'.$db.'${2}', $c);
    @file_put_contents($f, $c);
}

function stgApiData(): array {
    $sites = []; foreach (loadJson(SITES_FILE, []) as $s) $sites[] = ['domain'=>$s['domain'] ?? '','docroot'=>$s['docroot'] ?? ''];
    return ['ok'=>true, 'sites'=>$sites, 'dbs'=>stgDbList(), 'stagings'=>stgLoad()];
}
function stgApiCreate(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    @set_time_limit(0);
    $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
    $srcdoc = (string)($_POST['docroot'] ?? ''); $srcdb = (string)($_POST['db'] ?? '');
    $site = null; foreach (loadJson(SITES_FILE, []) as $s) if (($s['docroot']??'')===$srcdoc) $site = $s;
    if (!$site) return ['ok'=>false,'error'=>'Pick a source website.'];
    if (!preg_match('/^[a-z0-9.-]{2,80}$/',$domain)) return ['ok'=>false,'error'=>'Enter a staging domain (e.g. staging.example.com).'];
    if ($srcdb !== '' && !preg_match('/^[A-Za-z0-9_]+$/',$srcdb)) return ['ok'=>false,'error'=>'Bad database name.'];
    $base = cfgGet('webroot_base','/var/www'); $stgdoc = "$base/$domain/public";
    $wu = cfgGet('web_user','www-data');
    // create staging site (vhost + docroot), then mirror prod files in
    $r = helper('create-site', [$domain, $stgdoc]); if (($r['code']??1)!==0) return ['ok'=>false,'error'=>'Could not create staging site: '.trim((string)$r['out'])];
    $r2 = helper('site-sync', [$srcdoc, $stgdoc, $wu]); if (($r2['code']??1)!==0) return ['ok'=>false,'error'=>'File copy failed: '.trim((string)$r2['out'])];
    // clone db
    $stgdb = '';
    if ($srcdb !== '') {
        $stgdb = substr(preg_replace('/[^A-Za-z0-9_]/','',$srcdb),0,48).'_stg';
        if (!stgCloneDb($srcdb, $stgdb, $site['domain'], $domain)) return ['ok'=>false,'error'=>'Database clone failed.'];
        stgWpConfigDb($stgdoc, $stgdb);
    }
    // Provision like a real site: proxied Cloudflare DNS + lifetime HTTPS, so the staging URL just works.
    $cfOk = false; $cfErr = '';
    if (function_exists('cfConnected') && cfConnected()) {
        $cf = cfOnboardSite($domain, (string)cfgGet('server_ip'));
        if (!empty($cf['ok'])) { $cfOk = true; helper('site-ssl', [$domain, (string)cfgGet('admin_email', 'admin@' . $domain)]); }
        else $cfErr = (string)($cf['error'] ?? '');
    }
    // register site + staging record
    $sites = loadJson(SITES_FILE, []); if (!array_filter($sites, fn($s)=>($s['domain']??'')===$domain)) { $sites[] = ['domain'=>$domain,'docroot'=>$stgdoc,'ssl'=>$cfOk,'staging'=>true,'created'=>date('c')]; saveJson(SITES_FILE,$sites); }
    $all = stgLoad(); $all[] = ['id'=>bin2hex(random_bytes(5)),'name'=>$domain,'prod_domain'=>$site['domain'],'prod_docroot'=>$srcdoc,'prod_db'=>$srcdb,'stg_domain'=>$domain,'stg_docroot'=>$stgdoc,'stg_db'=>$stgdb,'created'=>date('c')]; stgSave($all);
    auditLog('staging_create',$site['domain'].' -> '.$domain);
    if ($cfOk) $msg = 'Staging live at https://'.$domain.' - Cloudflare DNS (proxied) and HTTPS are set up. It comes online within a minute.';
    elseif ($cfErr !== '') $msg = 'Staging created at '.$domain.'. Cloudflare: '.$cfErr.' Point its DNS A-record at '.cfgGet('server_ip').' to preview.';
    else $msg = 'Staging created at '.$domain.'. Point its DNS A-record at '.cfgGet('server_ip').' (or edit your hosts file) to preview, then push to production when ready.';
    return ['ok'=>true,'msg'=>$msg];
}
function stgApiPush(): array {
    $s = stgFind((string)($_POST['id'] ?? '')); if (!$s) return ['ok'=>false,'error'=>'Staging not found.'];
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'helper unavailable'];
    @set_time_limit(0);
    // back up production first (files + db) into the backups dir
    $bk = DATA_DIR.'/backups'; @mkdir($bk,0775,true); $ts=date('Ymd-His');
    @exec('tar -czf '.escapeshellarg($bk.'/preprod-'.$ts.'.tar.gz').' -C '.escapeshellarg($s['prod_docroot']).' . 2>/dev/null');
    if (!empty($s['prod_db'])) stgCloneDb($s['prod_db'], $s['prod_db'].'_bak_'.substr($ts,-6));
    // sync files staging -> prod
    $r = helper('site-sync', [$s['stg_docroot'], $s['prod_docroot'], cfgGet('web_user','www-data')]);
    if (($r['code']??1)!==0) return ['ok'=>false,'error'=>'Push failed: '.trim((string)$r['out'])];
    // push db staging -> prod (replace staging domain back to prod domain)
    if (!empty($s['stg_db']) && !empty($s['prod_db'])) { stgCloneDb($s['stg_db'], $s['prod_db'], $s['stg_domain'], $s['prod_domain']); stgWpConfigDb($s['prod_docroot'], $s['prod_db']); }
    auditLog('staging_push',$s['stg_domain'].' -> '.$s['prod_domain']);
    return ['ok'=>true,'msg'=>'Pushed to production. A pre-push backup was saved in Backups.'];
}
function stgApiDelete(): array {
    $s = stgFind((string)($_POST['id'] ?? '')); if (!$s) return ['ok'=>false,'error'=>'Not found.'];
    // remove staging vhost + files + db
    if (is_file(HELPER)) helper('delete-site', [$s['stg_domain']]);
    if (!empty($s['stg_db']) && preg_match('/^[A-Za-z0-9_]+$/',$s['stg_db']) && db()) { try { db()->exec("DROP DATABASE IF EXISTS `{$s['stg_db']}`"); } catch (Exception $e) {} }
    $sites = array_filter(loadJson(SITES_FILE, []), fn($x)=>($x['domain']??'')!==$s['stg_domain']); saveJson(SITES_FILE, array_values($sites));
    stgSave(array_filter(stgLoad(), fn($x)=>($x['id']??'')!==$s['id']));
    return ['ok'=>true,'msg'=>'Staging removed.'];
}

function stagingPage(): void { ?>
<?=helpBox('Staging environments', 'Make a safe <b>copy of a live site</b> (its files and database) to try changes, updates or a new theme without touching the real site. When it looks good, <b>push to production</b> - Orizen backs up the live site first. For WordPress, links are adjusted to the staging domain automatically.')?>
<div class="card">
  <h3>Create a staging copy</h3>
  <div class="row">
    <div style="flex:2"><label>Source website</label><select id="stSrc" onchange="stAutoName()"></select></div>
    <div style="flex:2"><label>Its database (optional)</label><select id="stDb"></select></div>
    <div style="flex:1.4"><label>Staging address</label>
      <select id="stType" onchange="stTypeChange()">
        <option value="auto">Auto subdomain</option>
        <option value="sub">Custom subdomain</option>
        <option value="domain">Custom domain</option>
      </select></div>
    <div style="flex:2"><label>Staging domain</label><input id="stDom" placeholder="staging.example.com"></div>
    <button class="btn btn-p" onclick="stCreate()">Create staging</button>
  </div>
  <div class="xs muted" style="margin-top:8px" id="stHint">Auto uses <b>staging.&lt;your-domain&gt;</b>. If a Cloudflare token is connected, DNS and HTTPS are set up automatically.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Staging sites</h3></div>
  <table><thead><tr><th>Staging</th><th>From</th><th>Database</th><th>Actions</th></tr></thead><tbody id="stList"><tr><td colspan="4" class="empty">none</td></tr></tbody></table>
</div>
<script>
function stEsc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
function stProd(){ var s=document.getElementById('stSrc'); var o=s.options[s.selectedIndex]; return o?o.textContent.trim():''; }
function stAutoName(){ if(document.getElementById('stType').value==='auto'){ var p=stProd(); document.getElementById('stDom').value = p?('staging.'+p):''; } }
function stTypeChange(){
  var t=document.getElementById('stType').value, dom=document.getElementById('stDom'), h=document.getElementById('stHint');
  if(t==='auto'){ dom.readOnly=true; stAutoName(); h.innerHTML='Auto uses <b>staging.&lt;your-domain&gt;</b>. If a Cloudflare token is connected, DNS and HTTPS are set up automatically.'; }
  else if(t==='sub'){ dom.readOnly=false; dom.value=''; dom.placeholder='dev.example.com'; h.innerHTML='Enter a subdomain of your site, e.g. <b>dev.example.com</b> or <b>preview.example.com</b>.'; }
  else { dom.readOnly=false; dom.value=''; dom.placeholder='staging-mysite.com'; h.innerHTML='Enter a full custom domain you own and have added to Cloudflare.'; }
}
function stLoad(){ api('stg_data',{}).then(function(r){ if(!r.ok)return;
  document.getElementById('stSrc').innerHTML=r.sites.filter(function(s){return !/staging/.test(s.domain);}).map(function(s){return '<option value="'+stEsc(s.docroot)+'">'+stEsc(s.domain)+'</option>';}).join('');
  stAutoName();
  document.getElementById('stDb').innerHTML='<option value="">- no database -</option>'+r.dbs.map(function(d){return '<option value="'+stEsc(d)+'">'+stEsc(d)+'</option>';}).join('');
  document.getElementById('stList').innerHTML=(r.stagings&&r.stagings.length)?r.stagings.map(function(s){return '<tr><td><b>'+stEsc(s.stg_domain)+'</b></td><td class="xs mono muted">'+stEsc(s.prod_domain)+'</td><td class="xs mono">'+stEsc(s.stg_db||'-')+'</td><td class="flex"><button class="btn btn-p btn-xs" onclick="stPush(\''+s.id+'\')">Push to production</button><button class="btn btn-d btn-xs" onclick="stDel(\''+s.id+'\')">Delete</button></td></tr>';}).join(''):'<tr><td colspan="4" class="empty">none</td></tr>'; }); }
function stCreate(){ var d=document.getElementById('stDom').value.trim(); if(!d){toast('Enter a staging domain','e');return;} toast('Creating staging (copying files + database)...'); api('stg_create',{docroot:document.getElementById('stSrc').value,db:document.getElementById('stDb').value,domain:d}).then(function(r){ toast(r.ok?'Done':(r.error||'Failed'), r.ok?'':'e'); if(r.ok){uiAlert(r.msg);} stLoad(); }); }
function stPush(id){ uiConfirm('Push this staging site to production? The live site is backed up first, then overwritten with the staging copy.',function(){ toast('Pushing...'); api('stg_push',{id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }); }
function stDel(id){ uiConfirm('Delete this staging site, its files and its database?',function(){ api('stg_delete',{id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); stLoad(); }); }); }
stTypeChange(); stLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'staging','name'=>'Staging','desc'=>'Clone a live site (files + database) to test changes, then push back to production safely.','feature'=>'enableStaging'],
        'pages' => ['staging'=>['title'=>'Staging','section'=>'DOMAINS','feature'=>'enableStaging','render'=>'stagingPage']],
        'api'   => ['stg_data'=>'stgApiData','stg_create'=>'stgApiCreate','stg_push'=>'stgApiPush','stg_delete'=>'stgApiDelete'],
    ]);
}
