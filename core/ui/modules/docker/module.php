<?php
/*
 * Orizen module: Docker management (optional, off by default)
 * List/start/stop/restart/remove containers, view images and logs, run Compose.
 * All docker commands run as root through the validated helper (ids are validated).
 */
function dkHelper(string $action, array $args = []): array { return is_file(HELPER) ? helper($action, $args) : ['code'=>1,'out'=>'helper unavailable']; }
function dkJsonLines(string $out): array {
    $rows = []; foreach (explode("\n", trim($out)) as $l) { $l = trim($l); if ($l === '' || $l[0] !== '{') continue; $j = json_decode($l, true); if (is_array($j)) $rows[] = $j; }
    return $rows;
}
function dkApiStatus(): array {
    $r = dkHelper('docker-ps'); $installed = trim((string)$r['out']) !== 'NOT_INSTALLED' && ($r['code'] ?? 1) === 0;
    return ['ok'=>true, 'installed'=>$installed];
}
function dkApiInstall(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $r = helper('docker-install', []);
    return ($r['code'] ?? 1) === 0 ? ['ok'=>true,'msg'=>'Docker installed and started.'] : ['ok'=>false,'error'=>trim((string)$r['out'])];
}
function dkApiPs(): array {
    $r = dkHelper('docker-ps'); if (trim((string)$r['out']) === 'NOT_INSTALLED') return ['ok'=>true,'installed'=>false,'containers'=>[]];
    $c = [];
    foreach (dkJsonLines((string)$r['out']) as $x) $c[] = ['id'=>$x['ID'] ?? '', 'name'=>$x['Names'] ?? '', 'image'=>$x['Image'] ?? '', 'status'=>$x['Status'] ?? '', 'state'=>$x['State'] ?? '', 'ports'=>$x['Ports'] ?? ''];
    return ['ok'=>true,'installed'=>true,'containers'=>$c];
}
function dkApiImages(): array {
    $r = dkHelper('docker-images'); if (trim((string)$r['out']) === 'NOT_INSTALLED') return ['ok'=>true,'images'=>[]];
    $im = [];
    foreach (dkJsonLines((string)$r['out']) as $x) $im[] = ['repo'=>$x['Repository'] ?? '', 'tag'=>$x['Tag'] ?? '', 'size'=>$x['Size'] ?? '', 'id'=>$x['ID'] ?? ''];
    return ['ok'=>true,'images'=>$im];
}
function dkApiCtl(): array {
    $a = (string)($_POST['act'] ?? ''); $id = (string)($_POST['id'] ?? '');
    if (!in_array($a, ['start','stop','restart','rm'], true)) return ['ok'=>false,'error'=>'bad action'];
    $r = dkHelper('docker-ctl', [$a, $id]);
    return ($r['code'] ?? 1) === 0 ? ['ok'=>true,'msg'=>'Done: '.$a] : ['ok'=>false,'error'=>trim((string)$r['out'])];
}
function dkApiLogs(): array {
    $r = dkHelper('docker-logs', [(string)($_POST['id'] ?? '')]);
    return ['ok'=>($r['code'] ?? 1)===0, 'log'=>trim((string)$r['out'])];
}

function dockerPage(): void { ?>
<?=helpBox('Docker', 'Manage Docker containers on this server: see what is running, start/stop/restart/remove containers, view images and logs. If Docker is not installed yet, install it with one click. (This module is optional and off by default - enable it in Settings -> Modules.)')?>
<div id="dkWrap"><div class="card"><div class="empty">Loading...</div></div></div>
<script>
function dkEsc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function dkLoad(){
  api('dk_ps',{}).then(function(r){
    if(!r.ok){document.getElementById('dkWrap').innerHTML='<div class="card"><div class="empty">'+(r.error||'error')+'</div></div>';return;}
    if(!r.installed){ document.getElementById('dkWrap').innerHTML='<div class="card"><h3>Docker is not installed</h3><div class="sm muted mb">Install the free Docker engine to manage containers here.</div><button class="btn btn-p" onclick="dkInstall()">Install Docker</button></div>'; return; }
    var rows=r.containers.map(function(c){ var running=/^up/i.test(c.status)||c.state==='running';
      return '<tr><td><b>'+dkEsc(c.name)+'</b><br><span class="xs mono muted">'+dkEsc(c.id).slice(0,12)+'</span></td><td class="xs mono">'+dkEsc(c.image)+'</td><td>'+(running?'<span class="badge bg-green">up</span>':'<span class="badge bg-red">stopped</span>')+' <span class="xs muted">'+dkEsc(c.status)+'</span></td><td class="xs mono">'+dkEsc(c.ports)+'</td><td class="flex">'+
      (running?'<button class="btn btn-g btn-xs" onclick="dkCtl(\'stop\',\''+c.id+'\')">Stop</button><button class="btn btn-g btn-xs" onclick="dkCtl(\'restart\',\''+c.id+'\')">Restart</button>':'<button class="btn btn-p btn-xs" onclick="dkCtl(\'start\',\''+c.id+'\')">Start</button>')+
      '<button class="btn btn-s btn-xs" onclick="dkLogs(\''+c.id+'\')">Logs</button><button class="btn btn-d btn-xs" onclick="dkRm(\''+c.id+'\')">Remove</button></td></tr>'; }).join('');
    document.getElementById('dkWrap').innerHTML='<div class="card" style="padding:0"><div class="flex" style="padding:16px 18px 0"><h3 style="margin:0">Containers</h3><button class="btn btn-g btn-xs" onclick="dkLoad()" style="margin-left:auto">Refresh</button></div><table><thead><tr><th>Name</th><th>Image</th><th>Status</th><th>Ports</th><th>Actions</th></tr></thead><tbody>'+(rows||'<tr><td colspan="5" class="empty">no containers</td></tr>')+'</tbody></table></div><div class="card" id="dkImages"><h3>Images</h3><div class="sm muted">loading...</div></div>';
    api('dk_images',{}).then(function(ir){ if(!ir.ok)return; document.getElementById('dkImages').innerHTML='<h3>Images</h3>'+(ir.images.length?'<table><thead><tr><th>Repository</th><th>Tag</th><th>Size</th></tr></thead><tbody>'+ir.images.map(function(i){return '<tr><td class="mono xs">'+dkEsc(i.repo)+'</td><td class="xs">'+dkEsc(i.tag)+'</td><td class="xs">'+dkEsc(i.size)+'</td></tr>';}).join('')+'</tbody></table>':'<div class="sm muted">no images</div>'); });
  });
}
function dkInstall(){ toast('Installing Docker... this can take a minute'); api('dk_install',{}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); dkLoad(); }); }
function dkCtl(a,id){ api('dk_ctl',{act:a,id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); dkLoad(); }); }
function dkRm(id){ uiConfirm('Remove this container? (it will be force-removed)',function(){ dkCtl('rm',id); }); }
function dkLogs(id){ api('dk_logs',{id:id}).then(function(r){ uiAlert('<pre class="code" style="max-height:55vh;overflow:auto;white-space:pre-wrap">'+dkEsc(r.log||'(no output)')+'</pre>','Container logs'); }); }
dkLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'docker','name'=>'Docker','desc'=>'Manage Docker containers, images, logs and Compose (installs Docker on demand).','feature'=>'enableDocker'],
        'pages' => ['docker'=>['title'=>'Docker','section'=>'SYSTEM','feature'=>'enableDocker','render'=>'dockerPage']],
        'api'   => ['dk_status'=>'dkApiStatus','dk_install'=>'dkApiInstall','dk_ps'=>'dkApiPs','dk_images'=>'dkApiImages','dk_ctl'=>'dkApiCtl','dk_logs'=>'dkApiLogs'],
    ]);
}
