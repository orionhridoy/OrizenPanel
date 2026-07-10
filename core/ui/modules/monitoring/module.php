<?php
/*
 * Orizen module: Monitoring
 * Live CPU / memory / disk / network + history graphs. 100% built-in:
 * reads /proc directly, stores a rolling history as JSON, no external services.
 * A once-a-minute cron collector keeps history growing 24/7 (installed via the
 * root helper the first time the page is opened).
 */

// ---- shared collection helpers (defined always so collector.php can reuse them) ----
function monDir(): string { return DATA_DIR . '/monitor'; }
function monHistFile(): string { return monDir() . '/history.json'; }

/** Take one point-in-time sample from /proc. */
function monSample(): array {
    // CPU busy% from two /proc/stat snapshots
    $cpu = 0.0; $s1 = @file_get_contents('/proc/stat');
    if ($s1 && preg_match('/^cpu\s+(.+)$/m', $s1, $m)) {
        $p = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $idle1 = ($p[3] ?? 0) + ($p[4] ?? 0); $tot1 = array_sum($p);
        usleep(200000);
        $s2 = @file_get_contents('/proc/stat');
        if ($s2 && preg_match('/^cpu\s+(.+)$/m', $s2, $m2)) {
            $q = array_map('intval', preg_split('/\s+/', trim($m2[1])));
            $idle2 = ($q[3] ?? 0) + ($q[4] ?? 0); $tot2 = array_sum($q);
            $dt = $tot2 - $tot1; $di = $idle2 - $idle1;
            if ($dt > 0) $cpu = max(0, min(100, round((1 - $di / $dt) * 100, 1)));
        }
    }
    // memory
    $memTotal = 0; $memAvail = 0; $mi = @file_get_contents('/proc/meminfo');
    if ($mi) {
        if (preg_match('/MemTotal:\s+(\d+)/', $mi, $x)) $memTotal = (int)$x[1] * 1024;
        if (preg_match('/MemAvailable:\s+(\d+)/', $mi, $x)) $memAvail = (int)$x[1] * 1024;
        elseif (preg_match('/MemFree:\s+(\d+)/', $mi, $x)) $memAvail = (int)$x[1] * 1024;
    }
    $memUsed = $memTotal > 0 ? max(0, $memTotal - $memAvail) : 0;
    // disk (root)
    $dTot = @disk_total_space('/') ?: 0; $dFree = @disk_free_space('/') ?: 0;
    // network cumulative bytes (all non-loopback interfaces)
    $rx = 0; $tx = 0; $nd = @file('/proc/net/dev');
    if ($nd) foreach ($nd as $line) {
        if (strpos($line, ':') === false) continue;
        [$if, $rest] = explode(':', $line, 2); $if = trim($if);
        if ($if === 'lo') continue;
        $c = preg_split('/\s+/', trim($rest));
        $rx += (int)($c[0] ?? 0); $tx += (int)($c[8] ?? 0);
    }
    $la = function_exists('sys_getloadavg') ? (@sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
    return ['t' => time(), 'cpu' => $cpu,
        'memUsed' => $memUsed, 'memTotal' => $memTotal,
        'diskUsed' => max(0, $dTot - $dFree), 'diskTotal' => $dTot,
        'rx' => $rx, 'tx' => $tx, 'load1' => round((float)($la[0] ?? 0), 2)];
}

function monLoadHistory(): array {
    $f = monHistFile(); if (!is_file($f)) return [];
    $j = json_decode(@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
/** Append a sample to the rolling history (keeps the most recent $keep points). */
function monAppend(array $sample, int $keep = 1500): bool {
    $dir = monDir(); if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $h = monLoadHistory(); $h[] = $sample;
    if (count($h) > $keep) $h = array_slice($h, -$keep);
    return @file_put_contents(monHistFile(), json_encode($h), LOCK_EX) !== false;
}

// ---- API handlers ----
function monApiLive(): array {
    $s = monSample();
    // grow history as you watch, throttled to ~1/min so cadence matches the cron collector
    $h = monLoadHistory(); $last = $h ? end($h) : null;
    if (!$last || ($s['t'] - (int)($last['t'] ?? 0)) >= 45) @monAppend($s);
    return ['ok' => true, 'sample' => $s];
}
function monApiHistory(): array { return ['ok' => true, 'history' => monLoadHistory()]; }
/** Install the once-a-minute collector cron (idempotent, fixed command) via the root helper. */
function monApiEnsure(): array {
    if (!is_file(HELPER)) return ['ok' => true, 'cron' => false];   // dev/local: page still works via live sampling
    $r = helper('monitor-setup', [cfgGet('web_user', 'www-data')]);
    return ['ok' => (($r['code'] ?? 1) === 0), 'cron' => (($r['code'] ?? 1) === 0), 'out' => trim((string)($r['out'] ?? ''))];
}

// ---- page ----
function monitoringPage(): void { ?>
<?=helpBox('Server monitoring', 'A live view of your server plus history graphs - CPU, memory, disk and network. It reads Linux metrics directly (no agents, no external service) and keeps a rolling history that fills in over time. Leave this page to run in the background; a once-a-minute collector keeps the history going even when the page is closed.')?>
<div class="grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat"><div class="l">CPU</div><div class="v" id="gCpu">...</div></div>
  <div class="stat"><div class="l">Memory</div><div class="v" id="gMem" style="font-size:15px">...</div></div>
  <div class="stat"><div class="l">Disk</div><div class="v" id="gDisk" style="font-size:15px">...</div></div>
  <div class="stat"><div class="l" title="Average number of processes competing for the CPU over the last minute. Below your core count is healthy.">Load (1m)</div><div class="v" id="gLoad">...</div></div>
</div>
<div class="flex mb" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">
  <span class="xs muted"><span id="monDot" class="mon-dot"></span> <b id="monState">Live</b> - updates every 3 seconds. <span class="muted">A near-<b>0% CPU</b> and <b>0.00 load</b> just mean the server is idle right now; the graphs move as soon as there is activity.</span></span>
  <span class="xs muted" id="monUpd"></span>
</div>
<style>.mon-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;vertical-align:middle;margin-right:3px;box-shadow:0 0 0 0 rgba(22,163,74,.5);animation:monPulse 1.8s infinite}
@keyframes monPulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.5)}70%{box-shadow:0 0 0 6px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}
.mon-dot.stale{background:var(--red);animation:none}</style>
<div class="card">
  <div class="flex" style="justify-content:space-between"><h3 style="margin:0">CPU usage</h3><span class="xs muted" id="cpuNow"></span></div>
  <div id="chCpu" style="margin-top:8px"></div>
</div>
<div class="card">
  <div class="flex" style="justify-content:space-between"><h3 style="margin:0">Memory usage</h3><span class="xs muted" id="memNow"></span></div>
  <div id="chMem" style="margin-top:8px"></div>
</div>
<div class="card">
  <div class="flex" style="justify-content:space-between"><h3 style="margin:0">Network</h3><span class="xs muted" id="netNow"></span></div>
  <div id="chNet" style="margin-top:8px"></div>
</div>
<script>
(function(){
  var LIVE=[], prevNet=null, MAXPTS=180;
  function fmtBytes(b){ b=+b||0; var u=['B','KB','MB','GB','TB'],i=0; while(b>=1024&&i<u.length-1){b/=1024;i++;} return (i?b.toFixed(1):Math.round(b))+' '+u[i]; }
  function pct(u,t){ return t>0?Math.round(u/t*100):0; }
  // build a themed area+line chart from an array of numbers (0..max)
  function chart(el, pts, opt){
    opt=opt||{}; var w=900,h=140,pad=6, max=opt.max||Math.max(1,Math.max.apply(null,pts.length?pts:[1]))*1.15;
    var n=pts.length; if(n<2){ el.innerHTML='<div class="xs muted" style="padding:18px 4px">Collecting data - the graph fills in as samples arrive (about one a minute).</div>'; return; }
    var stepX=(w-pad*2)/(n-1);
    function x(i){return pad+i*stepX;} function y(v){return h-pad-(Math.max(0,Math.min(max,v))/max)*(h-pad*2);}
    var line='',area='M'+x(0)+' '+y(pts[0]);
    for(var i=0;i<n;i++){ var px=x(i),py=y(pts[i]); line+=(i?'L':'M')+px.toFixed(1)+' '+py.toFixed(1)+' '; area+=' L'+px.toFixed(1)+' '+py.toFixed(1); }
    area+=' L'+x(n-1)+' '+(h-pad)+' L'+x(0)+' '+(h-pad)+' Z';
    var col=opt.color||'var(--accent)';
    var grid=''; for(var g=0;g<=4;g++){ var gy=pad+g*((h-pad*2)/4); grid+='<line x1="'+pad+'" y1="'+gy.toFixed(1)+'" x2="'+(w-pad)+'" y2="'+gy.toFixed(1)+'" stroke="var(--border)" stroke-width="1"/>'; }
    el.innerHTML='<svg viewBox="0 0 '+w+' '+h+'" preserveAspectRatio="none" style="width:100%;height:150px;display:block">'
      +grid+'<path d="'+area+'" fill="'+col+'" opacity="0.14"/>'
      +'<path d="'+line+'" fill="none" stroke="'+col+'" stroke-width="2" stroke-linejoin="round"/></svg>';
  }
  function redraw(){
    var cpu=LIVE.map(function(s){return s.cpu;});
    var mem=LIVE.map(function(s){return pct(s.memUsed,s.memTotal);});
    var net=[]; for(var i=1;i<LIVE.length;i++){ var a=LIVE[i-1],b=LIVE[i],dt=(b.t-a.t)||1; net.push(((b.rx-a.rx)+(b.tx-a.tx))/dt); }
    chart(document.getElementById('chCpu'), cpu, {max:100, color:'var(--accent)'});
    chart(document.getElementById('chMem'), mem, {max:100, color:'#16a34a'});
    chart(document.getElementById('chNet'), net, {color:'#9333ea'});
  }
  function applyLive(s){
    document.getElementById('gCpu').textContent=s.cpu+'%';
    document.getElementById('gMem').textContent=fmtBytes(s.memUsed)+' / '+fmtBytes(s.memTotal)+' ('+pct(s.memUsed,s.memTotal)+'%)';
    document.getElementById('gDisk').textContent=fmtBytes(s.diskUsed)+' / '+fmtBytes(s.diskTotal)+' ('+pct(s.diskUsed,s.diskTotal)+'%)';
    document.getElementById('gLoad').textContent=(+s.load1).toFixed(2);
    var _d=new Date(); document.getElementById('monUpd').textContent='updated '+_d.toLocaleTimeString();
    var _dot=document.getElementById('monDot'), _st=document.getElementById('monState'); if(_dot)_dot.classList.remove('stale'); if(_st)_st.textContent='Live';
    document.getElementById('cpuNow').textContent='now '+s.cpu+'%';
    document.getElementById('memNow').textContent='now '+pct(s.memUsed,s.memTotal)+'%';
    if(prevNet){ var dt=(s.t-prevNet.t)||1; document.getElementById('netNow').textContent='in '+fmtBytes((s.rx-prevNet.rx)/dt)+'/s . out '+fmtBytes((s.tx-prevNet.tx)/dt)+'/s'; }
    prevNet=s;
    LIVE.push(s); if(LIVE.length>MAXPTS) LIVE=LIVE.slice(-MAXPTS);
    redraw();
  }
  function monStale(){ var d=document.getElementById('monDot'),s=document.getElementById('monState'); if(d)d.classList.add('stale'); if(s)s.textContent='Reconnecting'; }
  function poll(){ api('mon_live',{}).then(function(r){ if(r&&r.ok&&r.sample) applyLive(r.sample); else monStale(); }).catch(monStale); }
  // init: install collector cron, backfill from stored history, then poll live
  api('mon_ensure',{});
  api('mon_history',{}).then(function(r){ if(r&&r.ok&&r.history&&r.history.length){ LIVE=r.history.slice(-MAXPTS); prevNet=LIVE[LIVE.length-1]; redraw(); } poll(); });
  setInterval(poll, 3000);
})();
</script>
<?php }

// ---- registration (skipped when this file is required by the CLI collector) ----
if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key' => 'monitoring', 'name' => 'Monitoring',
                    'desc' => 'Live CPU, memory, disk and network with history graphs (built-in, no agents).',
                    'feature' => 'enableMonitoring'],
        'pages' => ['monitoring' => ['title' => 'Monitoring', 'section' => 'MONITOR',
                    'feature' => 'enableMonitoring', 'render' => 'monitoringPage']],
        'api'   => ['mon_live' => 'monApiLive', 'mon_history' => 'monApiHistory', 'mon_ensure' => 'monApiEnsure'],
    ]);
}
