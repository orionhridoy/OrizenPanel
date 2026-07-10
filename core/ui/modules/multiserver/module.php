<?php
/*
 * Orizen module: Multi-Server Management (off by default)
 * A central dashboard that watches several Orizen servers. Each server exposes a
 * secure, read-only "agent" endpoint (a token-protected webhook) reporting health;
 * the central panel polls them. No agent runs unless you enable it.
 */
function msNodeStats(): array {
    $mi = @file_get_contents('/proc/meminfo'); $mt=0;$ma=0;
    if ($mi) { if(preg_match('/MemTotal:\s+(\d+)/',$mi,$x))$mt=(int)$x[1]; if(preg_match('/MemAvailable:\s+(\d+)/',$mi,$x))$ma=(int)$x[1]; }
    $dt=@disk_total_space('/')?:1; $df=@disk_free_space('/')?:0;
    $la=function_exists('sys_getloadavg')?(@sys_getloadavg()?:[0]):[0];
    return ['host'=>gethostname(),'ram_pct'=>$mt?(int)round(100-$ma/$mt*100):0,'disk_pct'=>(int)round(100-$df/$dt*100),
        'load1'=>round((float)($la[0]??0),2),'apache'=>svcActive(webSvc(),webProc()),'mariadb'=>svcActive(mariaSvc(),'mysqld'),'ver'=>appVersion()];
}
/* PUBLIC agent webhook: ?hook=agent&key=<token> */
function msHook(): array {
    $key = (string)($_GET['key'] ?? '');
    if (!cfgGet('agent_enabled') || cfgGet('agent_token','')==='' || !hash_equals((string)cfgGet('agent_token'), $key)) { http_response_code(403); return ['ok'=>false,'error'=>'forbidden']; }
    return ['ok'=>true,'stats'=>msNodeStats()];
}
function msApiGet(): array {
    return ['ok'=>true,'agent'=>['enabled'=>(bool)cfgGet('agent_enabled'),'token'=>(string)cfgGet('agent_token','')],
        'nodes'=>loadJson(DATA_DIR.'/ms_nodes.json', []),
        'self'=>'https://'.cfgGet('server_ip').':'.cfgGet('panel_port')];
}
function msApiSave(): array {
    $c = cfg(); $c['agent_enabled'] = ($_POST['agent_enabled'] ?? '')==='1';
    if (($_POST['gen_token'] ?? '')==='1' || cfgGet('agent_token','')==='') $c['agent_token'] = bin2hex(random_bytes(16));
    saveCfg($c);
    $nodes = json_decode((string)($_POST['nodes'] ?? '[]'), true); if (!is_array($nodes)) $nodes = [];
    $clean = [];
    foreach ($nodes as $n) { $u = trim((string)($n['url'] ?? '')); if (!preg_match('~^https?://~',$u)) continue; $clean[] = ['name'=>substr(trim((string)($n['name'] ?? '')),0,40),'url'=>$u,'token'=>trim((string)($n['token'] ?? ''))]; }
    saveJson(DATA_DIR.'/ms_nodes.json', $clean);
    return ['ok'=>true,'msg'=>'Saved.','token'=>(string)($c['agent_token'] ?? '')];   // from $c (cfg() is memoized this request)
}
function msApiPoll(): array {
    $out = [];
    foreach (loadJson(DATA_DIR.'/ms_nodes.json', []) as $n) {
        $url = rtrim($n['url'],'/').'/?hook=agent&key='.urlencode($n['token'] ?? '');
        $raw = httpGet($url, 6, true); $j = $raw ? json_decode($raw, true) : null;
        $out[] = ['name'=>$n['name'] ?: $n['url'], 'url'=>$n['url'], 'up'=>($j && !empty($j['ok'])), 'stats'=>($j['stats'] ?? null)];
    }
    return ['ok'=>true,'nodes'=>$out];
}

function multiServerPage(): void { ?>
<?=helpBox('Multi-server dashboard', 'Watch several Orizen servers from one place. On each server you want to manage, open this page and <b>enable the agent</b> - it publishes a read-only, token-protected health endpoint. Then add those servers here (URL + token) and this dashboard polls them for CPU/RAM/disk and service status. Off by default; the agent only runs when enabled.')?>
<div class="card"><h3>This server\'s agent</h3>
  <label class="ck"><input type="checkbox" id="msAgent"> <b>Publish a read-only health agent from this server</b></label>
  <div class="mt"><label>Agent token</label><input id="msToken" readonly onclick="this.select()" class="mono"></div>
  <div class="xs muted mt">Agent URL for this server: <span class="mono" id="msSelf"></span><span class="mono">/?hook=agent&key=&lt;token&gt;</span></div>
  <div class="flex mt"><button class="btn btn-p" onclick="msSave()">Save</button><button class="btn btn-g" onclick="msSave(true)">Regenerate token</button></div>
</div>
<div class="card">
  <h3>Managed servers</h3>
  <div id="msNodes"></div>
  <div class="row mt"><div><label>Name</label><input id="msNName" placeholder="db-server"></div><div style="flex:2"><label>URL</label><input id="msNUrl" placeholder="https://1.2.3.4:1337"></div><div style="flex:2"><label>Token</label><input id="msNTok" placeholder="agent token from that server"></div><button class="btn btn-g" onclick="msAddNode()">Add</button></div>
  <div class="flex mt"><button class="btn btn-p" onclick="msPoll()">Refresh health</button></div>
  <div id="msHealth" class="mt"></div>
</div>
<style>.ck{display:flex;align-items:center;gap:8px}.ck input{width:16px;height:16px}</style>
<script>
var MSNODES=[];
function msEsc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
function msRenderNodes(){ document.getElementById('msNodes').innerHTML=MSNODES.length?MSNODES.map(function(n,i){return '<div class="flex" style="justify-content:space-between;border-bottom:1px solid var(--border);padding:6px 0"><span><b>'+msEsc(n.name||n.url)+'</b> <span class="xs mono muted">'+msEsc(n.url)+'</span></span><button class="btn btn-d btn-xs" onclick="msDel('+i+')">remove</button></div>';}).join(''):'<div class="sm muted">No servers added yet.</div>'; }
function msDel(i){ MSNODES.splice(i,1); msRenderNodes(); msSave(); }
function msAddNode(){ var u=document.getElementById('msNUrl').value.trim(); if(!/^https?:\/\//.test(u)){toast('Enter a full URL (https://...)','e');return;} MSNODES.push({name:document.getElementById('msNName').value.trim(),url:u,token:document.getElementById('msNTok').value.trim()}); document.getElementById('msNName').value=document.getElementById('msNUrl').value=document.getElementById('msNTok').value=''; msRenderNodes(); msSave(); }
function msSave(regen){ api('ms_save',{agent_enabled:document.getElementById('msAgent').checked?'1':'0',gen_token:regen?'1':'0',nodes:JSON.stringify(MSNODES)}).then(function(r){ if(r.token)document.getElementById('msToken').value=r.token; toast(r.ok?'Saved':(r.error||'Failed'), r.ok?'':'e'); }); }
function msPoll(){ document.getElementById('msHealth').innerHTML='<div class="sm muted">Polling...</div>'; api('ms_poll',{}).then(function(r){ if(!r.ok)return; document.getElementById('msHealth').innerHTML='<table><thead><tr><th>Server</th><th>Status</th><th>CPU load</th><th>RAM</th><th>Disk</th><th>Services</th></tr></thead><tbody>'+(r.nodes.length?r.nodes.map(function(n){ var s=n.stats||{}; return '<tr><td><b>'+msEsc(n.name)+'</b></td><td>'+(n.up?'<span class="badge bg-green">up</span>':'<span class="badge bg-red">down</span>')+'</td><td>'+(n.up?s.load1:'-')+'</td><td>'+(n.up?s.ram_pct+'%':'-')+'</td><td>'+(n.up?s.disk_pct+'%':'-')+'</td><td class="xs">'+(n.up?('Apache '+(s.apache?'ok':'down')+', MariaDB '+(s.mariadb?'ok':'down')):'-')+'</td></tr>';}).join(''):'<tr><td colspan="6" class="empty">no servers</td></tr>')+'</tbody></table>'; }); }
api('ms_get',{}).then(function(r){ if(!r.ok)return; document.getElementById('msAgent').checked=r.agent.enabled; document.getElementById('msToken').value=r.agent.token||''; document.getElementById('msSelf').textContent=r.self; MSNODES=r.nodes||[]; msRenderNodes(); });
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'multiserver','name'=>'Multi-Server','desc'=>'Central dashboard watching several Orizen servers via a secure agent (off by default).','feature'=>'enableMultiServer'],
        'pages' => ['multiserver'=>['title'=>'Multi-Server','section'=>'SYSTEM','feature'=>'enableMultiServer','render'=>'multiServerPage']],
        'api'   => ['ms_get'=>'msApiGet','ms_save'=>'msApiSave','ms_poll'=>'msApiPoll'],
        'hooks' => ['agent'=>'msHook'],
    ]);
}
