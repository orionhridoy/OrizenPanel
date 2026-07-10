<?php
/*
 * Orizen module: Git Deployment
 * Deploy a website from a GitHub/GitLab (any https) repo: clone/pull a branch,
 * run an optional build command, keep deploy history, roll back, and auto-deploy
 * on push via a per-deployment webhook. All git runs as the unprivileged web user
 * through the validated root helper - no arbitrary root, https URLs only.
 */

function gdFile(): string { return DATA_DIR . '/gitdeploy.json'; }
function gdLoad(): array { $j = loadJson(gdFile(), []); return is_array($j) ? $j : []; }
function gdSave(array $d): bool { return saveJson(gdFile(), array_values($d)); }
function gdFind(string $id): ?array { foreach (gdLoad() as $d) if (($d['id'] ?? '') === $id) return $d; return null; }
function gdWebuser(): string { return cfgGet('web_user', 'www-data'); }
function gdSlug(string $s): string { $s = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', $s)); return trim($s, '-') ?: 'app'; }

/** Run a deployment (clone/pull + optional build); append a history entry. */
function gdDeploy(array &$d, string $trigger): array {
    $wu = gdWebuser();
    $r = helper('git-deploy', [$d['repo'], $d['branch'], $d['path'], $wu]);
    $log = trim((string)$r['out']); $ok = (($r['code'] ?? 1) === 0);
    $ref = ''; if (preg_match('/deployed\s+(\S+)/', $log, $m)) $ref = $m[1];
    if ($ok && !empty($d['build'])) {
        $rb = helper('git-run', [$d['path'], $wu, $d['build']]);
        $log .= "\n\n[build]\n" . trim((string)$rb['out']);
        if (($rb['code'] ?? 1) !== 0) $ok = false;
    }
    $entry = ['at' => date('c'), 'ref' => $ref, 'ok' => $ok, 'trigger' => $trigger, 'msg' => substr($log, -600)];
    // persist history against the stored copy
    $all = gdLoad();
    foreach ($all as &$x) if (($x['id'] ?? '') === $d['id']) {
        $x['history'][] = $entry; if (count($x['history']) > 30) $x['history'] = array_slice($x['history'], -30);
        $x['last'] = $entry; $d = $x;
    }
    unset($x); gdSave($all);
    return ['ok' => $ok, 'ref' => $ref, 'log' => $log, 'error' => $ok ? '' : 'Deploy failed - see log.'];
}

// ---- authed APIs ----
function gitApiList(): array { return ['ok' => true, 'items' => gdLoad(),
    'webhook_base' => 'https://' . cfgGet('server_ip') . ':' . cfgGet('panel_port') . '/?hook=git']; }

function gitApiAdd(): array {
    $name = trim((string)($_POST['name'] ?? ''));
    $repo = trim((string)($_POST['repo'] ?? ''));
    $branch = trim((string)($_POST['branch'] ?? '')) ?: 'main';
    $path = trim((string)($_POST['path'] ?? ''));
    $build = trim((string)($_POST['build'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9 ._-]{1,60}$/', $name)) return ['ok'=>false,'error'=>'Enter a short name (letters, numbers, spaces).'];
    if (!preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/~-]+$#', $repo)) return ['ok'=>false,'error'=>'Repo must be an https git URL (GitHub/GitLab/etc.).'];
    if (!preg_match('~^[A-Za-z0-9._/-]{1,80}$~', $branch)) return ['ok'=>false,'error'=>'Invalid branch name.'];
    if ($path === '') $path = '/var/www/' . gdSlug($name);
    if (!preg_match('~^(/var/www/|/opt/orizen/deploys/)[A-Za-z0-9._/-]+$~', $path) || strpos($path, '..') !== false)
        return ['ok'=>false,'error'=>'Target must be a path under /var/www or /opt/orizen/deploys.'];
    $all = gdLoad();
    foreach ($all as $d) if (($d['path'] ?? '') === $path) return ['ok'=>false,'error'=>'That target path is already used by another deployment.'];
    $rec = ['id'=>bin2hex(random_bytes(5)), 'name'=>$name, 'repo'=>$repo, 'branch'=>$branch, 'path'=>$path,
            'build'=>$build, 'secret'=>bin2hex(random_bytes(16)), 'created'=>date('c'), 'history'=>[], 'last'=>null];
    $all[] = $rec; gdSave($all);
    return ['ok'=>true, 'id'=>$rec['id'], 'msg'=>'Deployment added. Click Deploy to pull the code.'];
}

function gitApiDeploy(): array {
    $d = gdFind((string)($_POST['id'] ?? '')); if (!$d) return ['ok'=>false,'error'=>'Deployment not found.'];
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Git deploy runs on the server through the Orizen helper (not in this environment).'];
    $r = gdDeploy($d, 'manual');
    return $r['ok'] ? ['ok'=>true,'msg'=>'Deployed '.$r['ref'],'log'=>$r['log']] : ['ok'=>false,'error'=>$r['error'],'log'=>$r['log']];
}

function gitApiRollback(): array {
    $d = gdFind((string)($_POST['id'] ?? '')); if (!$d) return ['ok'=>false,'error'=>'Deployment not found.'];
    $ref = trim((string)($_POST['ref'] ?? ''));
    if (!preg_match('~^[A-Za-z0-9._/-]{1,60}$~', $ref)) return ['ok'=>false,'error'=>'Pick a valid revision to roll back to.'];
    $r = helper('git-checkout', [$d['path'], gdWebuser(), $ref]);
    $ok = (($r['code'] ?? 1) === 0); $log = trim((string)$r['out']);
    $all = gdLoad();
    foreach ($all as &$x) if (($x['id'] ?? '') === $d['id']) { $e=['at'=>date('c'),'ref'=>$ref,'ok'=>$ok,'trigger'=>'rollback','msg'=>substr($log,-400)]; $x['history'][]=$e; $x['last']=$e; }
    unset($x); gdSave($all);
    return $ok ? ['ok'=>true,'msg'=>'Rolled back to '.$ref] : ['ok'=>false,'error'=>'Rollback failed.','log'=>$log];
}

function gitApiDelete(): array {
    $id = (string)($_POST['id'] ?? '');
    $all = array_filter(gdLoad(), fn($d) => ($d['id'] ?? '') !== $id);
    gdSave($all);
    return ['ok'=>true,'msg'=>'Deployment removed (files were left in place).'];
}

// ---- PUBLIC webhook: ?hook=git&id=<id>&key=<secret> (POST) - auto-deploy on push ----
function gitHook(): array {
    $id = (string)($_GET['id'] ?? '');
    $key = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_ORIZEN_KEY'] ?? ''));
    $d = gdFind($id);
    if (!$d || empty($d['secret']) || !hash_equals($d['secret'], $key)) { http_response_code(403); return ['ok'=>false,'error'=>'forbidden']; }
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'helper unavailable'];
    $r = gdDeploy($d, 'webhook');
    return ['ok'=>$r['ok'], 'ref'=>$r['ref']];
}

// ---- page ----
function gitDeployPage(): void {
    $ip = cfgGet('server_ip'); $port = cfgGet('panel_port'); ?>
<?=helpBox('Deploy from Git', 'Connect a GitHub or GitLab repository and deploy it to a folder on your server. <b>Deploy</b> pulls the chosen branch (and runs your build command if you set one); <b>history</b> lets you roll back to an earlier version. For automatic deploys on every push, copy the <b>webhook URL</b> into your repo settings. Everything runs as the unprivileged web user - only https repo URLs are allowed.')?>
<div class="card">
  <h3>Add a deployment</h3>
  <div class="row">
    <div><label>Name</label><input id="gName" placeholder="my-site"></div>
    <div style="flex:2"><label>Repository (https)</label><input id="gRepo" placeholder="https://github.com/user/repo.git"></div>
    <div style="max-width:150px"><label>Branch</label><input id="gBranch" placeholder="main" value="main"></div>
  </div>
  <div class="row">
    <div style="flex:2"><label>Target folder</label><input id="gPath" placeholder="/var/www/my-site (leave blank = auto)"></div>
    <div style="flex:2"><label>Build command (optional)</label><input id="gBuild" placeholder="e.g. npm ci && npm run build"></div>
    <button class="btn btn-p" onclick="gdAdd()">Add</button>
  </div>
  <div class="xs muted mt">Tip: point a website's document root at the target folder (or its <span class="mono">/public</span> sub-folder) to serve it. The folder must be empty on first deploy.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Deployments</h3></div>
  <div id="gdList"><div class="empty">Loading...</div></div>
</div>
<script>
var GDWH='';
function gdEsc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function gdAdd(){
  var b={name:gName.value.trim(),repo:gRepo.value.trim(),branch:gBranch.value.trim()||'main',path:gPath.value.trim(),build:gBuild.value.trim()};
  if(!b.name||!b.repo){toast('Enter a name and repo URL','e');return;}
  api('git_add',b).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){gName.value=gRepo.value=gPath.value=gBuild.value='';gBranch.value='main';gdLoad();} });
}
function gdDeploy(id){ toast('Deploying...'); api('git_deploy',{id:id}).then(function(r){ if(r.ok)toast(r.msg); else toast(r.error||'Failed','e'); if(r.log) uiAlert('<pre class="code" style="max-height:50vh;overflow:auto;white-space:pre-wrap">'+gdEsc(r.log)+'</pre>','Deploy log'); gdLoad(); }); }
function gdRollback(id,ref){ uiConfirm('Roll back this deployment to '+ref+'?',function(){ api('git_rollback',{id:id,ref:ref}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); gdLoad(); }); }); }
function gdDelete(id){ uiConfirm('Remove this deployment? (the files on disk are kept)',function(){ api('git_delete',{id:id}).then(function(r){ toast(r.msg||'Removed'); gdLoad(); }); }); }
function gdHook(id,secret){ var u=GDWH+'&id='+id+'&key='+secret; uiAlert('<div class="sm">Add this <b>Push</b> webhook URL in your repo (GitHub: Settings -> Webhooks; content type JSON):</div><input class="mono" style="width:100%;margin-top:8px" readonly onclick="this.select()" value="'+gdEsc(u)+'">','Webhook URL'); }
function gdLoad(){
  api('git_list',{}).then(function(r){
    if(!r.ok){document.getElementById('gdList').innerHTML='<div class="empty">'+(r.error||'error')+'</div>';return;}
    GDWH=r.webhook_base;
    if(!r.items.length){document.getElementById('gdList').innerHTML='<div class="empty">No deployments yet - add one above.</div>';return;}
    document.getElementById('gdList').innerHTML='<table><thead><tr><th>Name</th><th>Repo / branch</th><th>Target</th><th>Last deploy</th><th>Actions</th></tr></thead><tbody>'+
      r.items.map(function(d){
        var last=d.last?('<span class="badge '+(d.last.ok?'bg-green':'bg-red')+'">'+(d.last.ref||(d.last.ok?'ok':'failed'))+'</span> <span class="xs muted">'+gdEsc((d.last.at||'').replace('T',' ').slice(0,16))+' . '+gdEsc(d.last.trigger||'')+'</span>'):'<span class="xs muted">never</span>';
        var hist=(d.history||[]).slice().reverse().slice(0,6).map(function(h){return h.ref?('<button class="btn btn-g btn-xs" title="roll back to '+h.ref+'" onclick="gdRollback(\''+d.id+'\',\''+h.ref+'\')">'+h.ref+'</button>'):'';}).join(' ');
        return '<tr><td><b>'+gdEsc(d.name)+'</b></td>'+
          '<td class="xs mono">'+gdEsc(d.repo)+'<br><span class="muted">'+gdEsc(d.branch)+'</span></td>'+
          '<td class="xs mono muted">'+gdEsc(d.path)+'</td>'+
          '<td>'+last+(hist?'<div class="xs muted mt" style="margin-top:6px">roll back: '+hist+'</div>':'')+'</td>'+
          '<td class="flex"><button class="btn btn-p btn-xs" onclick="gdDeploy(\''+d.id+'\')">Deploy</button>'+
          '<button class="btn btn-s btn-xs" onclick="gdHook(\''+d.id+'\',\''+gdEsc(d.secret)+'\')">Webhook</button>'+
          '<button class="btn btn-d btn-xs" onclick="gdDelete(\''+d.id+'\')">Remove</button></td></tr>';
      }).join('')+'</tbody></table>';
  });
}
gdLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'gitdeploy', 'name'=>'Git Deploy',
                    'desc'=>'Deploy websites from a GitHub/GitLab repo, with branches, build commands, history, rollback and push webhooks.',
                    'feature'=>'enableGitDeploy'],
        'pages' => ['gitdeploy' => ['title'=>'Git Deploy', 'section'=>'DOMAINS', 'feature'=>'enableGitDeploy', 'render'=>'gitDeployPage']],
        'api'   => ['git_list'=>'gitApiList', 'git_add'=>'gitApiAdd', 'git_deploy'=>'gitApiDeploy', 'git_rollback'=>'gitApiRollback', 'git_delete'=>'gitApiDelete'],
        'hooks' => ['git'=>'gitHook'],
    ]);
}
