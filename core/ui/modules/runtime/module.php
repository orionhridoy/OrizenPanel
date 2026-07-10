<?php
/*
 * Orizen module: Runtime Manager (multiple PHP versions)
 * Install PHP 8.1 / 8.2 / 8.3 side by side and choose the version per website.
 * Existing sites keep the server default until you switch them (opt-in, per site).
 */
function rtStore(): string { return DATA_DIR.'/runtime.json'; }
function rtMap(): array { $j = loadJson(rtStore(), []); return is_array($j) ? $j : []; }
function rtApiStatus(): array {
    $installed = []; if (is_file(HELPER)) { $r = helper('php-list', []); foreach (explode("\n", (string)$r['out']) as $l) { $l = trim($l); if (preg_match('/^have (\d\.\d)$/',$l,$m)) $installed[] = $m[1]; } }
    $installed = array_values(array_unique($installed));
    $sites = []; $map = rtMap();
    foreach (loadJson(SITES_FILE, []) as $s) { $d = $s['domain'] ?? ''; $sites[] = ['domain'=>$d,'docroot'=>$s['docroot'] ?? '','ver'=>$map[$d] ?? 'default']; }
    return ['ok'=>true, 'installed'=>$installed, 'candidates'=>['8.1','8.2','8.3'], 'sites'=>$sites, 'default'=>cfgGet('php_ver','')];
}
function rtApiInstall(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $v = (string)($_POST['ver'] ?? ''); if (!preg_match('/^\d\.\d$/',$v)) return ['ok'=>false,'error'=>'Bad version.'];
    $r = helper('php-install', [$v]);
    return ($r['code'] ?? 1) === 0 ? ['ok'=>true,'msg'=>'PHP '.$v.' installed.'] : ['ok'=>false,'error'=>trim((string)$r['out'])];
}
function rtApiSet(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $d = (string)($_POST['domain'] ?? ''); $ver = (string)($_POST['ver'] ?? 'default');
    $site = null; foreach (loadJson(SITES_FILE, []) as $s) if (($s['domain']??'')===$d) $site = $s;
    if (!$site) return ['ok'=>false,'error'=>'Unknown site.'];
    if ($ver !== 'default' && !preg_match('/^\d\.\d$/',$ver)) return ['ok'=>false,'error'=>'Bad version.'];
    $r = helper('site-runtime', [$d, $site['docroot'], $ver]);
    if (($r['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>trim((string)$r['out'])];
    $map = rtMap(); if ($ver === 'default') unset($map[$d]); else $map[$d] = $ver; saveJson(rtStore(), $map);
    return ['ok'=>true,'msg'=>$d.' now uses PHP '.$ver.'.'];
}

function runtimePage(): void { ?>
<?=helpBox('PHP versions (Runtime Manager)', 'Run different PHP versions on the same server and pick one <b>per website</b>. Install a version once, then choose it for a site - other sites are untouched and keep the default until you change them. Switching a site uses a dedicated PHP-FPM pool for that version.')?>
<div class="card"><h3>Installed PHP versions</h3><div id="rtVers" class="sm">...</div></div>
<div class="card" style="padding:0"><div style="padding:16px 18px 0"><h3>Per-site PHP version</h3></div>
  <table><thead><tr><th>Website</th><th>PHP version</th><th></th></tr></thead><tbody id="rtSites"><tr><td colspan="3" class="empty">loading</td></tr></tbody></table></div>
<script>
var RTINST=[];
function rtLoad(){ api('rt_status',{}).then(function(r){ if(!r.ok)return; RTINST=r.installed;
  document.getElementById('rtVers').innerHTML='<div class="mb">Installed: '+(r.installed.length?r.installed.map(function(v){return '<span class="badge bg-green">PHP '+v+'</span>';}).join(' '):'<span class="muted">only the server default ('+(r.default||'?')+')</span>')+'</div>'+r.candidates.filter(function(v){return r.installed.indexOf(v)<0;}).map(function(v){return '<button class="btn btn-g btn-xs" onclick="rtInstall(\''+v+'\')">Install PHP '+v+'</button>';}).join(' ');
  document.getElementById('rtSites').innerHTML=r.sites.length?r.sites.map(function(s){ var opts='<option value="default"'+(s.ver==='default'?' selected':'')+'>Server default</option>'+r.installed.map(function(v){return '<option value="'+v+'"'+(s.ver===v?' selected':'')+'>PHP '+v+'</option>';}).join(''); return '<tr><td><b>'+s.domain+'</b></td><td><select id="rt_'+s.domain+'">'+opts+'</select></td><td><button class="btn btn-p btn-xs" onclick="rtSet(\''+s.domain+'\')">Apply</button></td></tr>'; }).join(''):'<tr><td colspan="3" class="empty">no sites</td></tr>';
}); }
function rtInstall(v){ toast('Installing PHP '+v+' (a minute or two)...'); api('rt_install',{ver:v}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); rtLoad(); }); }
function rtSet(d){ var v=document.getElementById('rt_'+d).value; api('rt_set',{domain:d,ver:v}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
rtLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'runtime','name'=>'Runtime Manager','desc'=>'Install multiple PHP versions and choose one per website (PHP-FPM).','feature'=>'enableRuntime'],
        'pages' => ['runtime'=>['title'=>'PHP Versions','section'=>'SYSTEM','feature'=>'enableRuntime','render'=>'runtimePage']],
        'api'   => ['rt_status'=>'rtApiStatus','rt_install'=>'rtApiInstall','rt_set'=>'rtApiSet'],
    ]);
}
