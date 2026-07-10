<?php
/*
 * Orizen module: Mail Improvements
 * Postfix mail-queue viewer (flush / delete), DKIM record verification, and
 * DNS blacklist (DNSBL) checks for your server IP. Read-only DNS checks need
 * no mail server; the queue tools need Postfix.
 */
function mpApiQueue(): array {
    if (!is_file(HELPER)) return ['ok'=>true,'postfix'=>false,'items'=>[]];
    $r = helper('mailq-list', []); $out = trim((string)$r['out']);
    if ($out === 'NO_POSTFIX') return ['ok'=>true,'postfix'=>false,'items'=>[]];
    if ($out === '' || stripos($out,'is empty') !== false) return ['ok'=>true,'postfix'=>true,'items'=>[]];
    $items = [];
    foreach (preg_split('/\n\s*\n/', $out) as $blk) {
        $blk = trim($blk); if ($blk === '' || stripos($blk,'-Queue ID-') !== false) continue;
        $lines = preg_split('/\n/', $blk); $first = preg_split('/\s+/', trim($lines[0]));
        $id = $first[0] ?? ''; if (!preg_match('/^[A-F0-9]+\*?$/i',$id)) continue;
        $to = trim($lines[count($lines)-1] ?? '');
        $items[] = ['id'=>rtrim($id,'*'), 'size'=>$first[1] ?? '', 'from'=>$first[count($first)-1] ?? '', 'to'=>$to];
    }
    return ['ok'=>true,'postfix'=>true,'items'=>$items];
}
function mpApiFlush(): array { if (!is_file(HELPER)) return ['ok'=>false,'error'=>'helper unavailable']; helper('mailq-flush', []); return ['ok'=>true,'msg'=>'Queue flush requested.']; }
function mpApiDelete(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'helper unavailable'];
    $id = (string)($_POST['id'] ?? ''); if ($id !== 'ALL' && !preg_match('/^[A-Za-z0-9]+$/',$id)) return ['ok'=>false,'error'=>'bad id'];
    $r = helper('mailq-delete', [$id]); return ['ok'=>($r['code']??1)===0,'msg'=>'Deleted.'];
}
function mpApiDkim(): array {
    $domain = strtolower(trim((string)($_POST['domain'] ?? ''))); $sel = preg_replace('/[^A-Za-z0-9_.-]/','', (string)($_POST['selector'] ?? 'default')) ?: 'default';
    if (!preg_match('/^[a-z0-9.-]+$/',$domain)) return ['ok'=>false,'error'=>'Enter a domain.'];
    $host = $sel.'._domainkey.'.$domain;
    $rec = @dns_get_record($host, DNS_TXT); $txt = '';
    if ($rec) foreach ($rec as $r) $txt .= ($r['txt'] ?? ($r['entries'][0] ?? ''));
    $ok = stripos($txt,'p=') !== false && stripos($txt,'v=DKIM1') !== false;
    return ['ok'=>true,'found'=>($txt!==''),'valid'=>$ok,'record'=>$txt,'host'=>$host];
}
function mpApiBlacklist(): array {
    $ip = trim((string)($_POST['ip'] ?? cfgGet('server_ip','')));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return ['ok'=>false,'error'=>'Enter a valid IPv4 address.'];
    $rev = implode('.', array_reverse(explode('.', $ip)));
    $bls = ['zen.spamhaus.org','bl.spamcop.net','b.barracudacentral.org','dnsbl.sorbs.net'];
    $res = [];
    foreach ($bls as $bl) { $listed = checkdnsrr($rev.'.'.$bl.'.', 'A'); $res[] = ['bl'=>$bl,'listed'=>$listed]; }
    $bad = count(array_filter($res, fn($r)=>$r['listed']));
    return ['ok'=>true,'ip'=>$ip,'results'=>$res,'listed'=>$bad];
}

function mailPlusPage(): void { ?>
<?=helpBox('Mail improvements', 'Tools for a mail server: view and manage the <b>outgoing mail queue</b>, verify your <b>DKIM</b> DNS record is published correctly, and check whether your server IP is on a <b>spam blacklist</b> (DNSBL). The DNS checks work even without a mail server installed.')?>
<div class="card" style="padding:0">
  <div class="flex" style="padding:16px 18px 0"><h3 style="margin:0">Mail queue</h3><span style="margin-left:auto" class="flex"><button class="btn btn-g btn-xs" onclick="mpQueue()">Refresh</button><button class="btn btn-g btn-xs" onclick="mpFlush()">Flush</button><button class="btn btn-d btn-xs" onclick="mpDel('ALL')">Delete all</button></span></div>
  <table><thead><tr><th>ID</th><th>Size</th><th>From</th><th>To</th><th></th></tr></thead><tbody id="mpQ"><tr><td colspan="5" class="empty">loading</td></tr></tbody></table>
</div>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card"><h3>DKIM check</h3>
    <div class="row"><div style="flex:2"><label>Domain</label><input id="mpDom" placeholder="example.com"></div><div><label>Selector</label><input id="mpSel" value="default" style="max-width:120px"></div></div>
    <button class="btn btn-p" onclick="mpDkim()">Check DKIM</button><div id="mpDkimOut" class="sm mt"></div>
  </div>
  <div class="card"><h3>Blacklist check</h3>
    <div class="row"><div><label>Server IP</label><input id="mpIp" value="<?=h(cfgGet('server_ip'))?>"></div><button class="btn btn-p" onclick="mpBl()">Check blacklists</button></div>
    <div id="mpBlOut" class="sm mt"></div>
  </div>
</div>
<script>
function mpEsc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
function mpQueue(){ api('mp_queue',{}).then(function(r){ var t=document.getElementById('mpQ'); if(!r.postfix){t.innerHTML='<tr><td colspan="5" class="empty">Postfix is not installed on this server.</td></tr>';return;} t.innerHTML=r.items.length?r.items.map(function(m){return '<tr><td class="mono xs">'+mpEsc(m.id)+'</td><td class="xs">'+mpEsc(m.size)+'</td><td class="xs mono">'+mpEsc(m.from)+'</td><td class="xs mono">'+mpEsc(m.to)+'</td><td><button class="btn btn-d btn-xs" onclick="mpDel(\''+mpEsc(m.id)+'\')">del</button></td></tr>';}).join(''):'<tr><td colspan="5" class="empty">Queue is empty.</td></tr>'; }); }
function mpFlush(){ api('mp_flush',{}).then(function(r){ toast(r.msg||r.error); mpQueue(); }); }
function mpDel(id){ api('mp_delete',{id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); mpQueue(); }); }
function mpDkim(){ api('mp_dkim',{domain:document.getElementById('mpDom').value.trim(),selector:document.getElementById('mpSel').value.trim()}).then(function(r){ if(!r.ok){document.getElementById('mpDkimOut').textContent=r.error;return;} document.getElementById('mpDkimOut').innerHTML=r.valid?'<span style="color:var(--green)">Valid DKIM record found</span> at <span class="mono xs">'+mpEsc(r.host)+'</span>':(r.found?'<span style="color:var(--red)">Record found but not a valid DKIM key</span>':'<span style="color:var(--red)">No record at '+mpEsc(r.host)+'</span>'); }); }
function mpBl(){ document.getElementById('mpBlOut').textContent='Checking...'; api('mp_blacklist',{ip:document.getElementById('mpIp').value.trim()}).then(function(r){ if(!r.ok){document.getElementById('mpBlOut').textContent=r.error;return;} document.getElementById('mpBlOut').innerHTML=(r.listed?'<b style="color:var(--red)">Listed on '+r.listed+'</b>':'<b style="color:var(--green)">Not listed</b>')+'<div class="mt">'+r.results.map(function(x){return '<div>'+(x.listed?'<span class="badge bg-red">listed</span>':'<span class="badge bg-green">clean</span>')+' '+mpEsc(x.bl)+'</div>';}).join('')+'</div>'; }); }
mpQueue();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'mailplus','name'=>'Mail Improvements','desc'=>'Mail-queue viewer, DKIM verification and blacklist (DNSBL) checks.','feature'=>'enableMailPlus'],
        'pages' => ['mailplus'=>['title'=>'Mail Tools','section'=>'MAIL','feature'=>'enableMailPlus','render'=>'mailPlusPage']],
        'api'   => ['mp_queue'=>'mpApiQueue','mp_flush'=>'mpApiFlush','mp_delete'=>'mpApiDelete','mp_dkim'=>'mpApiDkim','mp_blacklist'=>'mpApiBlacklist'],
    ]);
}
