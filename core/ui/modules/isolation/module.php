<?php
/*
 * Orizen module: Site Isolation
 * Give a website its own Linux user + dedicated PHP-FPM pool (with a process
 * limit and open_basedir sandbox). Optional and per-site - other sites are
 * untouched and keep running as the shared web user until you isolate them.
 */
function isoStore(): string { return DATA_DIR.'/isolation.json'; }
function isoMap(): array { $j = loadJson(isoStore(), []); return is_array($j) ? $j : []; }
function isoInstalledPhp(): array {
    $v = []; if (is_file(HELPER)) { $r = helper('php-list', []); foreach (explode("\n",(string)$r['out']) as $l) if (preg_match('/^have (\d\.\d)$/',trim($l),$m)) $v[] = $m[1]; }
    return array_values(array_unique($v));
}
function isoApiStatus(): array {
    $map = isoMap(); $sites = [];
    foreach (loadJson(SITES_FILE, []) as $s) { $d = $s['domain'] ?? ''; $sites[] = ['domain'=>$d,'docroot'=>$s['docroot'] ?? '','iso'=>isset($map[$d]),'user'=>$map[$d]['user'] ?? '','ver'=>$map[$d]['ver'] ?? '','max'=>$map[$d]['max'] ?? '']; }
    return ['ok'=>true,'sites'=>$sites,'php'=>isoInstalledPhp()];
}
function isoApiOn(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $d = (string)($_POST['domain'] ?? ''); $ver = (string)($_POST['ver'] ?? ''); $max = max(1, min(50, (int)($_POST['max'] ?? 5)));
    $site = null; foreach (loadJson(SITES_FILE, []) as $s) if (($s['domain']??'')===$d) $site = $s;
    if (!$site) return ['ok'=>false,'error'=>'Unknown site.'];
    if (!preg_match('/^\d\.\d$/',$ver)) return ['ok'=>false,'error'=>'Install a PHP version first (Runtime Manager).'];
    $r = helper('isolate-site', [$d, $site['docroot'], $ver, (string)$max]);
    if (($r['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>trim((string)$r['out'])];
    $user = ''; if (preg_match('/as user (\S+)/', (string)$r['out'], $m)) $user = $m[1];
    $map = isoMap(); $map[$d] = ['user'=>$user,'ver'=>$ver,'max'=>$max]; saveJson(isoStore(), $map);
    auditLog('isolate_on', $d);
    return ['ok'=>true,'msg'=>$d.' isolated as '.$user.' (PHP '.$ver.', max '.$max.' processes).'];
}
function isoApiOff(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $d = (string)($_POST['domain'] ?? ''); $map = isoMap(); $ver = $map[$d]['ver'] ?? '';
    $site = null; foreach (loadJson(SITES_FILE, []) as $s) if (($s['domain']??'')===$d) $site = $s;
    if (!$site || $ver === '') return ['ok'=>false,'error'=>'Not isolated.'];
    $r = helper('unisolate-site', [$d, $site['docroot'], $ver]);
    if (($r['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>trim((string)$r['out'])];
    unset($map[$d]); saveJson(isoStore(), $map); auditLog('isolate_off', $d);
    return ['ok'=>true,'msg'=>'Isolation removed for '.$d.'.'];
}

function isolationPage(): void { ?>
<?=helpBox('Site isolation', 'Run a website under its <b>own Linux user</b> and a <b>dedicated PHP-FPM pool</b>, so one site cannot read another site\'s files and a runaway site cannot use all the PHP workers. You set a <b>process limit</b> per site and files are sandboxed with open_basedir. This is optional per site - needs a PHP version from the <a href="?page=runtime">Runtime Manager</a>.')?>
<div class="card" style="padding:0"><div style="padding:16px 18px 0"><h3>Websites</h3></div>
  <table><thead><tr><th>Website</th><th>Isolation</th><th>Settings</th><th></th></tr></thead><tbody id="isoList"><tr><td colspan="4" class="empty">loading</td></tr></tbody></table></div>
<script>
var ISOPHP=[];
function isoLoad(){ api('iso_status',{}).then(function(r){ if(!r.ok)return; ISOPHP=r.php;
  document.getElementById('isoList').innerHTML=r.sites.length?r.sites.map(function(s){
    if(s.iso) return '<tr><td><b>'+s.domain+'</b></td><td><span class="badge bg-green">isolated</span> <span class="xs mono muted">'+s.user+'</span></td><td class="xs">PHP '+s.ver+', max '+s.max+' procs</td><td><button class="btn btn-d btn-xs" onclick="isoOff(\''+s.domain+'\')">Remove</button></td></tr>';
    var vopts=ISOPHP.map(function(v){return '<option value="'+v+'">PHP '+v+'</option>';}).join('')||'<option value="">install PHP first</option>';
    return '<tr><td><b>'+s.domain+'</b></td><td><span class="badge bg-red">shared</span></td><td class="flex"><select id="iv_'+s.domain+'" style="max-width:110px">'+vopts+'</select><input id="im_'+s.domain+'" type="number" min="1" max="50" value="5" style="width:70px" title="max processes"></td><td><button class="btn btn-p btn-xs" onclick="isoOn(\''+s.domain+'\')">Isolate</button></td></tr>';
  }).join(''):'<tr><td colspan="4" class="empty">no sites</td></tr>';
}); }
function isoOn(d){ var v=document.getElementById('iv_'+d).value; if(!v){toast('Install a PHP version first (Runtime Manager)','e');return;} toast('Isolating...'); api('iso_on',{domain:d,ver:v,max:document.getElementById('im_'+d).value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); isoLoad(); }); }
function isoOff(d){ uiConfirm('Remove isolation for '+d+'? It returns to the shared web user.',function(){ api('iso_off',{domain:d}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); isoLoad(); }); }); }
isoLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'isolation','name'=>'Site Isolation','desc'=>'Dedicated Linux user + PHP-FPM pool + process limit per website.','feature'=>'enableSiteIsolation'],
        'pages' => ['isolation'=>['title'=>'Site Isolation','section'=>'SYSTEM','feature'=>'enableSiteIsolation','render'=>'isolationPage']],
        'api'   => ['iso_status'=>'isoApiStatus','iso_on'=>'isoApiOn','iso_off'=>'isoApiOff'],
    ]);
}
