<?php
/*
 * Orizen module: Security+
 * Two-factor auth (TOTP), login audit + brute-force lockouts (enforced in core),
 * Fail2Ban management and ClamAV on-demand scanning. Free tools only.
 */

function spB32encode(string $data): string {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
    for ($i=0;$i<strlen($data);$i++) $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    $out = ''; for ($i=0;$i<strlen($bits);$i+=5) { $c = substr($bits,$i,5); if (strlen($c)<5) $c = str_pad($c,5,'0'); $out .= $map[bindec($c)]; }
    return $out;
}

function spApiStatus(): array {
    $f2b = ['installed'=>false,'active'=>false,'jails'=>[],'banned'=>[]];
    if (is_file(HELPER)) {
        $r = helper('fail2ban-status', []); $out = trim((string)$r['out']);
        if ($out !== 'NOT_INSTALLED' && $out !== '') {
            $f2b['installed'] = true; $lines = explode("\n", $out);
            $f2b['active'] = (trim($lines[0] ?? '') === 'active');
            foreach ($lines as $ln) if (strpos($ln,'JAIL ')===0) { $p = explode('|', substr($ln,5)); $jail=trim($p[0]??''); $ips=trim($p[1]??''); $f2b['jails'][]=$jail; if($ips!=='') foreach(preg_split('/\s+/',$ips) as $ip) if($ip!=='') $f2b['banned'][]=['jail'=>$jail,'ip'=>$ip]; }
        }
    }
    $audit = []; $af = DATA_DIR.'/audit.log';
    if (is_file($af)) $audit = array_reverse(array_slice(array_filter(explode("\n",(string)@file_get_contents($af))), -25));
    $locks = []; $now = time();
    foreach (loadJson(DATA_DIR.'/loginfails.json', []) as $ip=>$e) if (($e['until'] ?? 0) > $now) $locks[] = ['ip'=>$ip,'mins'=>(int)ceil(($e['until']-$now)/60),'count'=>(int)($e['count']??0)];
    return ['ok'=>true, 'fail2ban'=>$f2b, 'clamav'=>is_file('/usr/bin/clamscan'),
        'twofa'=>(bool)cfgGet('totp_enabled') && cfgGet('totp_secret')!=='',
        'tg2fa'=>['enabled'=>(bool)cfgGet('tg2fa_enabled'), 'configured'=>cfgGet('tg2fa_token','')!=='' && cfgGet('tg2fa_chat','')!=='', 'chat'=>(string)cfgGet('tg2fa_chat','')],
        'audit'=>$audit, 'locks'=>$locks];
}
function spApiInstall(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    $what = (string)($_POST['what'] ?? '');
    if ($what === 'fail2ban') { helper('pkg-ensure', ['fail2ban']); helper('fail2ban-setup', []); return ['ok'=>true,'msg'=>'Fail2Ban installed and enabled (bans repeated SSH brute-force attempts).']; }
    if ($what === 'clamav')   { helper('pkg-ensure', ['clamav']); helper('pkg-ensure', ['clamav-freshclam']); return ['ok'=>true,'msg'=>'ClamAV installed. Virus definitions download in the background (first update can take a few minutes).']; }
    return ['ok'=>false,'error'=>'unknown'];
}
function spApiUnban(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'helper unavailable'];
    $r = helper('fail2ban-unban', [(string)($_POST['jail'] ?? ''), (string)($_POST['ip'] ?? '')]);
    return ($r['code']??1)===0 ? ['ok'=>true,'msg'=>'Unbanned.'] : ['ok'=>false,'error'=>trim((string)$r['out'])];
}
function spApiScan(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'helper unavailable'];
    $doc = (string)($_POST['path'] ?? '');
    $sites = loadJson(SITES_FILE, []); $known = false; foreach ($sites as $s) if (($s['docroot']??'')===$doc) $known=true;
    if (!$known) return ['ok'=>false,'error'=>'Pick a known site to scan.'];
    $r = helper('clamav-scan', [$doc]);
    $out = trim((string)$r['out']);
    $infected = array_values(array_filter(explode("\n",$out), fn($l)=>strpos($l,'FOUND')!==false));
    return ['ok'=>true,'infected'=>$infected,'clean'=>empty($infected),'raw'=>substr($out,-1500)];
}
function spApi2faBegin(): array {
    $secret = spB32encode(random_bytes(20));
    $_SESSION['totp_pending'] = $secret;
    $label = rawurlencode('Orizen:'.cfgGet('admin_user','admin'));
    $uri = 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer=Orizen';
    return ['ok'=>true,'secret'=>$secret,'uri'=>$uri];
}
function spApi2faEnable(): array {
    $pending = (string)($_SESSION['totp_pending'] ?? ''); if ($pending === '') return ['ok'=>false,'error'=>'Start setup again.'];
    if (!totpVerify($pending, (string)($_POST['code'] ?? ''))) return ['ok'=>false,'error'=>'That code is not valid - check your authenticator app and try again.'];
    $c = cfg(); $c['totp_secret'] = $pending; $c['totp_enabled'] = true; saveCfg($c);
    unset($_SESSION['totp_pending']); auditLog('2fa_enabled', cfgGet('admin_user',''));
    return ['ok'=>true,'msg'=>'Two-factor authentication is now ON. You will need a code at next login.'];
}
function spApi2faDisable(): array {
    $c = cfg(); $c['totp_enabled'] = false; $c['totp_secret'] = ''; saveCfg($c); auditLog('2fa_disabled', cfgGet('admin_user',''));
    return ['ok'=>true,'msg'=>'Two-factor authentication turned off.'];
}

// -- Telegram 2FA (second factor delivered as a login code over a Telegram bot) --
function spApiTgSend(): array {
    $token = trim((string)($_POST['token'] ?? '')); $chat = trim((string)($_POST['chat'] ?? ''));
    if ($token === '' || $chat === '') return ['ok'=>false,'error'=>'Enter both the bot token and your chat ID.'];
    if (!preg_match('~^\d{6,}:[A-Za-z0-9_-]{30,}$~', $token)) return ['ok'=>false,'error'=>'That does not look like a valid Telegram bot token (should look like 123456789:ABC...).'];
    $c = cfg(); $c['tg2fa_token'] = $token; $c['tg2fa_chat'] = $chat; saveCfg($c);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['tg2fa_test'] = $code; $_SESSION['tg2fa_test_t'] = time();
    if (!tgSendMessage($token, $chat, "Orizen Panel test code: $code\nEnter this in Security+ to switch on Telegram 2FA.")) {
        return ['ok'=>false,'error'=>'Saved, but Telegram would not deliver the message. Check the token, and make sure you have opened a chat and pressed Start with your bot. Then send again.'];
    }
    return ['ok'=>true,'msg'=>'Test code sent to your Telegram - enter it below to enable.'];
}
function spApiTgEnable(): array {
    $want = (string)($_SESSION['tg2fa_test'] ?? ''); $t = (int)($_SESSION['tg2fa_test_t'] ?? 0);
    $code = preg_replace('/\D/','', (string)($_POST['code'] ?? ''));
    if ($want === '' || (time() - $t) > 600) return ['ok'=>false,'error'=>'Send a fresh test code first.'];
    if (strlen($code) !== 6 || !hash_equals($want, $code)) return ['ok'=>false,'error'=>'That code does not match the one we sent.'];
    $c = cfg(); $c['tg2fa_enabled'] = true; saveCfg($c); unset($_SESSION['tg2fa_test'], $_SESSION['tg2fa_test_t']);
    auditLog('tg2fa_enabled', cfgGet('admin_user',''));
    return ['ok'=>true,'msg'=>'Telegram 2FA is on. A login code will be sent to your Telegram each time you sign in.'];
}
function spApiTgDisable(): array {
    $c = cfg(); $c['tg2fa_enabled'] = false; saveCfg($c); auditLog('tg2fa_disabled', cfgGet('admin_user',''));
    return ['ok'=>true,'msg'=>'Telegram 2FA turned off.'];
}

function secPlusPage(): void {
    $sites = loadJson(SITES_FILE, []); ?>
<?=helpBox('Security+', 'Extra protection for your panel and server, using only free tools. Turn on <b>two-factor authentication</b> for logins, watch <b>login activity</b> (failed logins are rate-limited automatically), install <b>Fail2Ban</b> to auto-ban brute-force IPs, and run an on-demand <b>ClamAV</b> virus scan on a website.')?>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card"><h3>Two-factor authentication (2FA)</h3><div class="sm muted">Login 2FA (authenticator app + Telegram) now lives in <a href="?page=settings"><b>Settings &rarr; Two-factor authentication</b></a>.</div></div>
  <div class="card"><h3>Login activity</h3>
    <div id="spLocks" class="sm mb"></div>
    <b class="xs muted">Recent events</b>
    <pre class="code" id="spAudit" style="max-height:180px;overflow:auto;margin-top:6px">-</pre>
  </div>
  <div class="card"><h3>Fail2Ban (brute-force protection)</h3><div id="spF2b" class="sm">...</div></div>
  <div class="card"><h3>Antivirus scan (ClamAV)</h3>
    <div id="spClam" class="sm mb">...</div>
    <div class="row"><div><label>Scan a website</label><select id="spScanSite"><?php foreach($sites as $s):?><option value="<?=h($s['docroot'])?>"><?=h($s['domain'])?></option><?php endforeach;?></select></div>
      <button class="btn btn-p" onclick="spScan()">Scan now</button></div>
    <div id="spScanOut" class="sm mt"></div>
  </div>
</div>
<script>
function spEsc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
function spLoad(){ api('sp_status',{}).then(function(r){ if(!r.ok)return;
  var f=r.fail2ban, e=document.getElementById('spF2b');
  if(!f.installed){ e.innerHTML='<div class="muted mb">Not installed. Fail2Ban watches your logs and bans IPs that hammer SSH or the panel.</div><button class="btn btn-p" onclick="spInstall(\'fail2ban\')">Install Fail2Ban</button>'; }
  else { e.innerHTML='<span class="badge '+(f.active?'bg-green':'bg-red')+'">'+(f.active?'running':'stopped')+'</span> jails: '+(f.jails.join(', ')||'-')+'<div class="mt">'+(f.banned.length?f.banned.map(function(b){return '<div>'+spEsc(b.jail)+': <span class="mono">'+spEsc(b.ip)+'</span> <button class="btn btn-g btn-xs" onclick="spUnban(\''+b.jail+'\',\''+b.ip+'\')">unban</button></div>';}).join(''):'<span class="muted">no banned IPs</span>')+'</div>'; }
  document.getElementById('spClam').innerHTML = r.clamav?'<span class="badge bg-green">installed</span>':'<span class="badge bg-red">not installed</span> <button class="btn btn-g btn-xs" onclick="spInstall(\'clamav\')">Install ClamAV</button>';
  document.getElementById('spLocks').innerHTML = r.locks.length?('<b>Currently locked out:</b> '+r.locks.map(function(l){return '<span class="mono">'+spEsc(l.ip)+'</span> ('+l.mins+'m)';}).join(', ')):'<span class="muted">No IPs are locked out. Failed logins lock an IP for 15 min after 6 tries.</span>';
  document.getElementById('spAudit').textContent = (r.audit&&r.audit.length)?r.audit.join('\n'):'(no login events yet)';
}); }
function spInstall(w){ toast('Installing '+w+'... this can take a moment'); api('sp_install',{what:w}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); spLoad(); }); }
function spUnban(j,ip){ api('sp_unban',{jail:j,ip:ip}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); spLoad(); }); }
function sp2faBegin(){ api('sp_2fa_begin',{}).then(function(r){ if(!r.ok)return; document.getElementById('sp2faSetup').innerHTML='<hr class="sep"><div class="sm">1. In your authenticator app, add an account by <b>secret key</b>:</div><div class="mono mb" style="font-size:15px;letter-spacing:2px">'+spEsc(r.secret)+'</div><div class="xs muted mb">(or use this setup URL: <span class="mono">'+spEsc(r.uri)+'</span>)</div><div class="sm">2. Enter the 6-digit code it shows:</div><div class="row"><input id="sp2faCode" inputmode="numeric" placeholder="123456" style="max-width:140px"><button class="btn btn-p" onclick="sp2faEnable()">Verify &amp; enable</button></div>'; }); }
function sp2faEnable(){ api('sp_2fa_enable',{code:document.getElementById('sp2faCode').value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok) spLoad(); }); }
function sp2faOff(){ uiConfirm('Turn off two-factor authentication?',function(){ api('sp_2fa_disable',{}).then(function(r){ toast(r.msg); spLoad(); }); }); }
function spTgSend(){ var tok=document.getElementById('spTgTok').value.trim(), chat=document.getElementById('spTgChat').value.trim(); if(!tok||!chat){toast('Enter the bot token and chat ID','e');return;} toast('Sending test code to Telegram...'); api('sp_tg_send',{token:tok,chat:chat}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){ document.getElementById('spTgVerify').innerHTML='<hr class="sep"><label>Enter the code we sent you</label><div class="row"><input id="spTgCode" inputmode="numeric" placeholder="123456" style="max-width:140px"><button class="btn btn-p" onclick="spTgEnable()">Verify &amp; enable</button></div>'; } }); }
function spTgEnable(){ api('sp_tg_enable',{code:document.getElementById('spTgCode').value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok) spLoad(); }); }
function spTgOff(){ uiConfirm('Turn off Telegram 2FA?',function(){ api('sp_tg_disable',{}).then(function(r){ toast(r.msg); spLoad(); }); }); }
function spScan(){ document.getElementById('spScanOut').textContent='Scanning (this can take a while)...'; api('sp_scan',{path:document.getElementById('spScanSite').value}).then(function(r){ if(!r.ok){document.getElementById('spScanOut').textContent=r.error;return;} document.getElementById('spScanOut').innerHTML=r.clean?'<span style="color:var(--green)">No threats found.</span>':('<span style="color:var(--red)">Found:</span><pre class="code">'+spEsc(r.infected.join('\n'))+'</pre>'); }); }
spLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'secplus','name'=>'Security+','desc'=>'Two-factor login, brute-force lockouts, Fail2Ban and ClamAV scanning.','feature'=>'enableSecurityPlus'],
        'pages' => ['secplus'=>['title'=>'Security+','section'=>'SYSTEM','feature'=>'enableSecurityPlus','render'=>'secPlusPage']],
        'api'   => ['sp_status'=>'spApiStatus','sp_install'=>'spApiInstall','sp_unban'=>'spApiUnban','sp_scan'=>'spApiScan','sp_2fa_begin'=>'spApi2faBegin','sp_2fa_enable'=>'spApi2faEnable','sp_2fa_disable'=>'spApi2faDisable','sp_tg_send'=>'spApiTgSend','sp_tg_enable'=>'spApiTgEnable','sp_tg_disable'=>'spApiTgDisable'],
    ]);
}
