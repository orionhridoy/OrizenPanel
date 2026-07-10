<?php
/*
 * Orizen module: Website Tools
 * Per-site utilities: maintenance mode, permission repair, search & replace,
 * OPcache control, Redis/Memcached status, and site cloning.
 */

function wtSites(): array { $s = loadJson(SITES_FILE, []); return is_array($s) ? $s : []; }
function wtFindSite(string $docroot): ?array { foreach (wtSites() as $s) if (($s['docroot'] ?? '') === $docroot) return $s; return null; }
function wtSafe(string $docroot): bool { return (bool)wtFindSite($docroot); }   // only operate on known site docroots
function wtMaintFile(string $doc): string { return rtrim($doc,'/').'/.orizen-maint'; }

function wtMaintOn(string $doc): bool {
    $html = '<!doctype html><meta charset="utf-8"><title>Down for maintenance</title>'
          . '<style>body{font-family:system-ui,sans-serif;background:#0f1220;color:#e8e0f0;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>'
          . '<div><h1>We\'ll be right back</h1><p>This site is briefly down for maintenance.</p></div>';
    @file_put_contents(rtrim($doc,'/').'/maintenance.html', $html);
    $ht = rtrim($doc,'/').'/.htaccess'; $cur = is_file($ht) ? file_get_contents($ht) : '';
    $cur = preg_replace('/# ORIZEN-MAINT-START.*?# ORIZEN-MAINT-END\n?/s', '', $cur);
    $block = "# ORIZEN-MAINT-START\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_URI} !=/maintenance.html\nRewriteRule ^ /maintenance.html [R=503,L]\n</IfModule>\nErrorDocument 503 /maintenance.html\n# ORIZEN-MAINT-END\n";
    @file_put_contents(wtMaintFile($doc), date('c'));
    return @file_put_contents($ht, $block.$cur) !== false;
}
function wtMaintOff(string $doc): bool {
    $ht = rtrim($doc,'/').'/.htaccess';
    if (is_file($ht)) { $cur = file_get_contents($ht); $cur = preg_replace('/# ORIZEN-MAINT-START.*?# ORIZEN-MAINT-END\n?/s', '', $cur); @file_put_contents($ht, $cur); }
    @unlink(wtMaintFile($doc)); return true;
}
function wtIsMaint(string $doc): bool { return is_file(wtMaintFile($doc)); }

function wtWalk(string $dir, callable $fn, int &$budget): void {
    if ($budget <= 0) return;
    foreach (@scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.git' || $n === 'node_modules' || $n === 'vendor') continue;
        $p = $dir.'/'.$n;
        if (is_dir($p)) wtWalk($p, $fn, $budget);
        elseif (is_file($p)) { $fn($p); if (--$budget <= 0) return; }
    }
}
function wtIsText(string $p): bool {
    $sz = @filesize($p); if ($sz === false || $sz > 3*1024*1024) return false;
    $chunk = @file_get_contents($p, false, null, 0, 4096); if ($chunk === false) return false;
    return strpos($chunk, "\0") === false;
}

// ---- APIs ----
function wtApiSites(): array {
    $out = [];
    foreach (wtSites() as $s) $out[] = ['domain'=>$s['domain'] ?? '', 'docroot'=>$s['docroot'] ?? '', 'maint'=>wtIsMaint($s['docroot'] ?? '')];
    return ['ok'=>true, 'sites'=>$out];
}
function wtApiMaint(): array {
    $doc = (string)($_POST['docroot'] ?? ''); if (!wtSafe($doc)) return ['ok'=>false,'error'=>'Unknown site.'];
    $on = ($_POST['on'] ?? '') === '1';
    $ok = $on ? wtMaintOn($doc) : wtMaintOff($doc);
    return $ok ? ['ok'=>true,'maint'=>$on,'msg'=>$on?'Maintenance mode ON.':'Maintenance mode OFF.'] : ['ok'=>false,'error'=>'Could not write .htaccess (permissions?).'];
}
function wtApiPerms(): array {
    $doc = (string)($_POST['docroot'] ?? ''); if (!wtSafe($doc)) return ['ok'=>false,'error'=>'Unknown site.'];
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $r = helper('perm-repair', [$doc, cfgGet('web_user','www-data')]);
    return ($r['code'] ?? 1) === 0 ? ['ok'=>true,'msg'=>'Permissions repaired (dirs 755, files 644).'] : ['ok'=>false,'error'=>trim((string)$r['out'])];
}
function wtApiOpcache(): array {
    if (!function_exists('opcache_get_status')) return ['ok'=>true,'enabled'=>false,'msg'=>'OPcache is not installed.'];
    if (($_POST['reset'] ?? '') === '1') { $ok = @opcache_reset(); return ['ok'=>true,'reset'=>(bool)$ok,'msg'=>$ok?'OPcache cleared.':'OPcache not enabled.']; }
    $st = @opcache_get_status(false);
    if (!$st) return ['ok'=>true,'enabled'=>false,'msg'=>'OPcache is installed but not enabled for this SAPI.'];
    $m = $st['memory_usage'] ?? []; $s = $st['opcache_statistics'] ?? [];
    return ['ok'=>true,'enabled'=>true,
        'used'=>fmtSize($m['used_memory'] ?? 0), 'free'=>fmtSize($m['free_memory'] ?? 0),
        'hits'=>(int)($s['hits'] ?? 0), 'misses'=>(int)($s['misses'] ?? 0),
        'rate'=>round((float)($s['opcache_hit_rate'] ?? 0),1), 'cached'=>(int)($s['num_cached_scripts'] ?? 0)];
}
function wtApiCache(): array {
    $redis = ['ext'=>extension_loaded('redis'), 'svc'=>svcActive('redis-server','redis-server')||svcActive('redis','redis-server')];
    $memc  = ['ext'=>extension_loaded('memcached')||extension_loaded('memcache'), 'svc'=>svcActive('memcached','memcached')];
    return ['ok'=>true,'redis'=>$redis,'memcached'=>$memc];
}
function wtApiCacheInstall(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $which = ($_POST['which'] ?? ''); if (!in_array($which,['redis','memcached'],true)) return ['ok'=>false,'error'=>'bad target'];
    helper('pkg-ensure', [$which === 'redis' ? 'redis-server' : 'memcached']);
    $r = helper('cache-ext', [$which]);
    return ['ok'=>($r['code'] ?? 1)===0, 'msg'=>$which.' installed. Reload the page to see status.'];
}
function wtApiSearch(): array {
    $doc = (string)($_POST['docroot'] ?? ''); if (!wtSafe($doc)) return ['ok'=>false,'error'=>'Unknown site.'];
    $find = (string)($_POST['find'] ?? ''); if ($find === '') return ['ok'=>false,'error'=>'Enter text to search for.'];
    $matches = 0; $files = []; $budget = 4000;
    wtWalk($doc, function($p) use ($find, &$matches, &$files) {
        if (!wtIsText($p)) return; $c = @file_get_contents($p); if ($c === false) return;
        $n = substr_count($c, $find); if ($n > 0) { $matches += $n; if (count($files) < 25) $files[] = ['path'=>$p,'n'=>$n]; }
    }, $budget);
    return ['ok'=>true,'matches'=>$matches,'files'=>$files];
}
function wtApiReplace(): array {
    $doc = (string)($_POST['docroot'] ?? ''); if (!wtSafe($doc)) return ['ok'=>false,'error'=>'Unknown site.'];
    $find = (string)($_POST['find'] ?? ''); $rep = (string)($_POST['replace'] ?? '');
    if ($find === '') return ['ok'=>false,'error'=>'Enter text to search for.'];
    $changed = 0; $filesChanged = 0; $budget = 4000;
    wtWalk($doc, function($p) use ($find,$rep,&$changed,&$filesChanged) {
        if (!wtIsText($p)) return; $c = @file_get_contents($p); if ($c === false) return;
        $n = substr_count($c, $find); if ($n === 0) return;
        if (@file_put_contents($p, str_replace($find,$rep,$c)) !== false) { $changed += $n; $filesChanged++; }
    }, $budget);
    auditLog('webtools_replace', $doc.' ('.$changed.' in '.$filesChanged.' files)');
    return ['ok'=>true,'msg'=>"Replaced {$changed} occurrence(s) in {$filesChanged} file(s)."];
}
function wtApiClone(): array {
    $doc = (string)($_POST['docroot'] ?? ''); if (!wtSafe($doc)) return ['ok'=>false,'error'=>'Unknown site.'];
    $name = strtolower(trim((string)($_POST['name'] ?? '')));
    if (!preg_match('/^[a-z0-9.-]{1,60}$/', $name)) return ['ok'=>false,'error'=>'Enter a short name (letters/numbers/dashes).'];
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $dst = cfgGet('webroot_base','/var/www').'/'.$name.'/public';
    $r = helper('site-clone', [$doc, $dst, cfgGet('web_user','www-data')]);
    if (($r['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>trim((string)$r['out'])];
    return ['ok'=>true,'msg'=>'Files cloned to '.$dst.'. Add a website/domain pointing there to serve it.','dst'=>$dst];
}

// ---- page ----
function webToolsPage(): void { ?>
<?=helpBox('Website tools', 'Handy per-site utilities. Pick a site, then use a tool: put it in <b>maintenance mode</b>, <b>repair file permissions</b>, run a <b>search &amp; replace</b> across its files (great after moving a WordPress site), clear <b>OPcache</b>, check <b>Redis/Memcached</b>, or <b>clone</b> its files to a new site.')?>
<div class="card">
  <label>Website</label>
  <select id="wtSite" onchange="wtRefresh()" style="max-width:420px"></select>
  <span id="wtMaintBadge" class="ml"></span>
</div>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card"><h3>Maintenance mode</h3>
    <div class="sm muted mb">Show a friendly "be right back" page to visitors while you work.</div>
    <button class="btn btn-p" id="wtMaintBtn" onclick="wtMaint()">Turn on</button></div>
  <div class="card"><h3>Fix file permissions</h3>
    <div class="sm muted mb">Reset ownership to the web user and folders/files to 755/644.</div>
    <button class="btn btn-p" onclick="wtPerms()">Repair permissions</button></div>
  <div class="card"><h3>OPcache</h3>
    <div class="sm muted mb" id="wtOpc">...</div>
    <button class="btn btn-g" onclick="wtOpcache(true)">Clear OPcache</button></div>
  <div class="card"><h3>Object cache</h3>
    <div class="sm" id="wtCache">...</div></div>
</div>
<div class="card"><h3>Search &amp; replace</h3>
  <div class="row">
    <div style="flex:2"><label>Find</label><input id="wtFind" placeholder="http://old-domain.com"></div>
    <div style="flex:2"><label>Replace with</label><input id="wtRep" placeholder="https://new-domain.com"></div>
  </div>
  <div class="flex"><button class="btn btn-g" onclick="wtSearch()">Preview matches</button>
    <button class="btn btn-p" onclick="wtReplace()">Replace all</button></div>
  <div id="wtSearchOut" class="sm muted mt"></div>
</div>
<div class="card"><h3>Clone this site's files</h3>
  <div class="row"><div><label>New site name</label><input id="wtClone" placeholder="staging.example.com"></div>
    <button class="btn btn-p" onclick="wtCloneGo()">Clone files</button></div>
  <div class="xs muted mt">Copies the files to a new folder under the web root. Add a domain pointing there to serve it.</div>
</div>
<script>
function wtDoc(){ return document.getElementById('wtSite').value; }
function wtRefresh(){ wtOpcache(false); wtCacheStatus(); api('wt_sites',{}).then(function(r){ if(!r.ok)return; var s=document.getElementById('wtSite'); var cur=s.value; if(!s.options.length){ s.innerHTML=r.sites.map(function(x){return '<option value="'+x.docroot+'">'+x.domain+'</option>';}).join(''); } var site=r.sites.filter(function(x){return x.docroot===wtDoc();})[0]; var on=site&&site.maint; document.getElementById('wtMaintBadge').innerHTML=on?'<span class="badge bg-red">in maintenance</span>':'<span class="badge bg-green">live</span>'; document.getElementById('wtMaintBtn').textContent=on?'Turn off':'Turn on'; }); }
function wtMaint(){ var on=document.getElementById('wtMaintBtn').textContent==='Turn on'; api('wt_maint',{docroot:wtDoc(),on:on?'1':'0'}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); wtRefresh(); }); }
function wtPerms(){ toast('Repairing...'); api('wt_perms',{docroot:wtDoc()}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
function wtOpcache(reset){ api('wt_opcache', reset?{reset:'1'}:{}).then(function(r){ if(reset){toast(r.msg);} var e=document.getElementById('wtOpc'); if(!r.enabled){e.textContent=r.msg||'not enabled';return;} e.innerHTML='Hit rate <b>'+r.rate+'%</b> . '+r.cached+' scripts . used '+r.used+' / free '+r.free; }); }
function wtCacheStatus(){ api('wt_cache',{}).then(function(r){ if(!r.ok)return; function row(n,o){ return '<div>'+n+': '+(o.ext?'<span class="badge bg-green">PHP ext</span>':'<span class="badge bg-red">no ext</span>')+' '+(o.svc?'<span class="badge bg-green">running</span>':'<span class="badge bg-red">stopped</span>')+(!o.ext?' <button class="btn btn-g btn-xs" onclick="wtCacheInstall(\''+n.toLowerCase()+'\')">install</button>':'')+'</div>'; } document.getElementById('wtCache').innerHTML=row('Redis',r.redis)+row('Memcached',r.memcached); }); }
function wtCacheInstall(w){ toast('Installing '+w+'...'); api('wt_cache_install',{which:w}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
function wtSearch(){ if(!document.getElementById('wtFind').value){toast('Enter text to find','e');return;} document.getElementById('wtSearchOut').textContent='Searching...'; api('wt_search',{docroot:wtDoc(),find:document.getElementById('wtFind').value}).then(function(r){ if(!r.ok){document.getElementById('wtSearchOut').textContent=r.error;return;} document.getElementById('wtSearchOut').innerHTML='<b>'+r.matches+'</b> match(es) in '+r.files.length+'+ files: '+r.files.map(function(f){return '<span class="mono xs">'+f.path.split('/').slice(-2).join('/')+' ('+f.n+')</span>';}).join(', '); }); }
function wtReplace(){ var f=document.getElementById('wtFind').value; if(!f){toast('Enter text to find','e');return;} uiConfirm('Replace all occurrences of "'+f+'" in this site\'s files? Make a backup first if unsure.',function(){ api('wt_replace',{docroot:wtDoc(),find:f,replace:document.getElementById('wtRep').value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }); }
function wtCloneGo(){ var n=document.getElementById('wtClone').value.trim(); if(!n){toast('Enter a name','e');return;} api('wt_clone',{docroot:wtDoc(),name:n}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
wtRefresh();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'webtools','name'=>'Website Tools','desc'=>'Maintenance mode, permission repair, search & replace, OPcache, Redis/Memcached and site cloning.','feature'=>'enableWebTools'],
        'pages' => ['webtools'=>['title'=>'Website Tools','section'=>'FILES','feature'=>'enableWebTools','render'=>'webToolsPage']],
        'api'   => ['wt_sites'=>'wtApiSites','wt_maint'=>'wtApiMaint','wt_perms'=>'wtApiPerms','wt_opcache'=>'wtApiOpcache','wt_cache'=>'wtApiCache','wt_cache_install'=>'wtApiCacheInstall','wt_search'=>'wtApiSearch','wt_replace'=>'wtApiReplace','wt_clone'=>'wtApiClone'],
    ]);
}
