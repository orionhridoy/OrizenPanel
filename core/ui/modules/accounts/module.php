<?php
/*
 * Orizen module: Accounts (hosting accounts, resellers, packages)
 * The WHM-style layer: define hosting packages (disk / domain / database / email
 * limits), create admin -> reseller -> user accounts, assign websites & databases to
 * them, see live usage vs limits, suspend/unsuspend, and transfer ownership.
 * Optional dedicated Linux user per account (via the validated root helper).
 *
 * NOTE: this manages accounts and ownership. Giving each account its own panel
 * login (so resellers/users sign in themselves) is the planned next step and needs
 * the multi-user auth rework.
 */
function accFile(): string { return DATA_DIR.'/accounts.json'; }
function accStore(): array {
    $d = loadJson(accFile(), []); if (!is_array($d)) $d = [];
    if (empty($d['packages'])) $d['packages'] = [
        ['id'=>'starter','name'=>'Starter','disk_mb'=>2000,'bw_gb'=>20,'max_domains'=>1,'max_db'=>2,'max_email'=>5],
        ['id'=>'business','name'=>'Business','disk_mb'=>10000,'bw_gb'=>100,'max_domains'=>10,'max_db'=>20,'max_email'=>50],
    ];
    if (!isset($d['accounts']) || !is_array($d['accounts'])) $d['accounts'] = [];
    return $d;
}
function accSave(array $d): bool { return saveJson(accFile(), $d); }
function accSites(): array { $s = loadJson(SITES_FILE, []); return is_array($s) ? $s : []; }
function accDbList(): array { $pdo = db(); $o = []; if ($pdo) { try { foreach ($pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN) as $x) if (!in_array($x,['mysql','information_schema','performance_schema','sys'],true)) $o[] = $x; } catch (Exception $e) {} } return $o; }
function accMailboxes(): array { $m = loadJson(MAIL_FILE, ['mailboxes'=>[]]); return is_array($m['mailboxes'] ?? null) ? $m['mailboxes'] : []; }
function accPkg(array $d, string $id): ?array { foreach ($d['packages'] as $p) if (($p['id']??'')===$id) return $p; return null; }
function accFind(array $d, string $id): ?array { foreach ($d['accounts'] as $a) if (($a['id']??'')===$id) return $a; return null; }

function accUsage(array $acct): array {
    $u = $acct['username']; $domains = []; $disk = 0;
    foreach (accSites() as $s) if (($s['owner']??'') === $u) { $domains[] = $s['domain'] ?? ''; $disk += dirSize($s['docroot'] ?? '', 60000); }
    $emails = 0;
    foreach (accMailboxes() as $mb) { $addr = $mb['address'] ?? ($mb['email'] ?? ($mb['user'] ?? '')); $dom = (strpos($addr,'@')!==false) ? substr($addr, strpos($addr,'@')+1) : ($mb['domain'] ?? ''); if ($dom !== '' && in_array($dom, $domains, true)) $emails++; }
    return ['domains'=>count(array_filter($domains)), 'disk_mb'=>(int)round($disk/1048576), 'dbs'=>count($acct['databases'] ?? []), 'emails'=>$emails];
}

// -- suspension (panel-side; the web user owns the docroots) --
function accSuspendHtml(): string {
    return '<!doctype html><meta charset="utf-8"><title>Account suspended</title>'
        .'<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0f1220;color:#e8e0f0;display:grid;place-items:center;height:100vh;margin:0;text-align:center}</style>'
        .'<div><h1>Account suspended</h1><p>This website is temporarily unavailable. Please contact the administrator.</p></div>';
}
function accSuspendSite(string $doc): void {
    if (!is_dir($doc)) return; $doc = rtrim($doc,'/');
    @file_put_contents($doc.'/suspended.html', accSuspendHtml());
    $ht = $doc.'/.htaccess'; $cur = is_file($ht) ? file_get_contents($ht) : '';
    $cur = preg_replace('/# ORIZEN-SUSPEND-START.*?# ORIZEN-SUSPEND-END\n?/s', '', $cur);
    $block = "# ORIZEN-SUSPEND-START\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_URI} !=/suspended.html\nRewriteRule ^ /suspended.html [R=503,L]\n</IfModule>\nErrorDocument 503 /suspended.html\n# ORIZEN-SUSPEND-END\n";
    @file_put_contents($ht, $block.$cur);
}
function accUnsuspendSite(string $doc): void {
    $doc = rtrim($doc,'/'); $ht = $doc.'/.htaccess';
    if (is_file($ht)) { $c = file_get_contents($ht); $c = preg_replace('/# ORIZEN-SUSPEND-START.*?# ORIZEN-SUSPEND-END\n?/s', '', $c); @file_put_contents($ht, $c); }
    @unlink($doc.'/suspended.html');
}

// -- APIs --
function accCanManage(array $acct): bool { return isAdmin() || ($acct['owner'] ?? '') === curUser(); }
function accApiData(): array {
    $d = accStore(); $admin = isAdmin(); $me = curUser(); $adminU = cfgGet('admin_user','admin'); $accts = [];
    foreach ($d['accounts'] as $a) {
        if (!$admin && ($a['owner'] ?? '') !== $me) continue;                 // resellers see only their own sub-accounts
        $a['usage'] = accUsage($a); $a['limits'] = accPkg($d, $a['package'] ?? '') ?? []; $a['has_pass'] = !empty($a['pass_hash']);
        unset($a['pass_hash']);                                              // never leak password hashes
        $accts[] = $a;
    }
    $sites = accSites(); if (!$admin) $sites = array_filter($sites, fn($s)=>($s['owner']??'')===$me);
    $sites = array_values(array_map(fn($s)=>['domain'=>$s['domain']??'','docroot'=>$s['docroot']??'','owner'=>$s['owner']??''], $sites));
    $owners = $admin ? array_merge([$adminU], array_column($d['accounts'], 'username')) : array_merge([$me], array_column($accts, 'username'));
    return ['ok'=>true, 'packages'=>$admin?$d['packages']:[], 'accounts'=>$accts, 'sites'=>$sites, 'dbs'=>$admin?accDbList():[],
        'admin'=>$adminU, 'isAdmin'=>$admin, 'owners'=>array_values(array_unique($owners))];
}
function accApiSetPass(): array {
    $d = accStore(); $acct = accFind($d, (string)($_POST['id'] ?? ''));
    if (!$acct) return ['ok'=>false,'error'=>'Account not found.'];
    if (!accCanManage($acct)) return ['ok'=>false,'error'=>'Not permitted.'];
    $pass = (string)($_POST['password'] ?? ''); if (strlen($pass) < 6) return ['ok'=>false,'error'=>'Password too short (min 6).'];
    foreach ($d['accounts'] as &$a) if (($a['id']??'')===$acct['id']) $a['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT); unset($a);
    accSave($d); auditLog('acct_setpass', $acct['username']);
    return ['ok'=>true,'msg'=>'Login password set for '.$acct['username'].' - they can now sign in to the panel.'];
}
function accApiPkgSave(): array {
    $d = accStore();
    $name = trim((string)($_POST['name'] ?? '')); if ($name==='') return ['ok'=>false,'error'=>'Package name required.'];
    $id = trim((string)($_POST['id'] ?? '')) ?: strtolower(preg_replace('/[^a-z0-9]+/i','-', $name));
    $pkg = ['id'=>$id, 'name'=>$name,
        'disk_mb'=>max(0,(int)($_POST['disk_mb'] ?? 0)), 'bw_gb'=>max(0,(int)($_POST['bw_gb'] ?? 0)),
        'max_domains'=>max(0,(int)($_POST['max_domains'] ?? 0)), 'max_db'=>max(0,(int)($_POST['max_db'] ?? 0)), 'max_email'=>max(0,(int)($_POST['max_email'] ?? 0))];
    $found = false; foreach ($d['packages'] as &$p) if (($p['id']??'')===$id) { $p = $pkg; $found = true; } unset($p);
    if (!$found) $d['packages'][] = $pkg;
    return accSave($d) ? ['ok'=>true,'msg'=>'Package saved.'] : ['ok'=>false,'error'=>'Could not save.'];
}
function accApiPkgDel(): array {
    $d = accStore(); $id = (string)($_POST['id'] ?? '');
    foreach ($d['accounts'] as $a) if (($a['package']??'')===$id) return ['ok'=>false,'error'=>'That package is in use by an account.'];
    $d['packages'] = array_values(array_filter($d['packages'], fn($p)=>($p['id']??'')!==$id));
    return accSave($d) ? ['ok'=>true,'msg'=>'Package deleted.'] : ['ok'=>false,'error'=>'Could not save.'];
}
function accApiCreate(): array {
    $d = accStore();
    $u = strtolower(trim((string)($_POST['username'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_-]{1,30}$/', $u)) return ['ok'=>false,'error'=>'Username: start with a letter; letters, numbers, - or _ (2-31 chars).'];
    foreach ($d['accounts'] as $a) if (($a['username']??'')===$u) return ['ok'=>false,'error'=>'That username is already used.'];
    $role = in_array($_POST['role'] ?? '', ['user','reseller'], true) ? $_POST['role'] : 'user';
    $pkg = (string)($_POST['package'] ?? ''); if ($pkg !== '' && !accPkg($d,$pkg)) return ['ok'=>false,'error'=>'Unknown package.'];
    $owner = trim((string)($_POST['owner'] ?? '')) ?: cfgGet('admin_user','admin');
    if (!isAdmin()) { $owner = curUser(); $role = 'user'; }   // resellers can only create end-users under themselves
    $acct = ['id'=>bin2hex(random_bytes(5)), 'username'=>$u, 'role'=>$role, 'owner'=>$owner, 'package'=>$pkg,
        'email'=>trim((string)($_POST['email'] ?? '')), 'suspended'=>false, 'linux_user'=>'', 'databases'=>[], 'created'=>date('c')];
    if (($_POST['linux'] ?? '') === '1' && is_file(HELPER)) { $r = helper('acct-user-create', [$u]); if (($r['code']??1)===0) $acct['linux_user'] = $u; }
    $d['accounts'][] = $acct; accSave($d); auditLog('acct_create', $u.' ('.$role.')');
    return ['ok'=>true,'msg'=>'Account "'.$u.'" created.'.($acct['linux_user']?' Linux user added.':'')];
}
function accApiUpdate(): array {
    $d = accStore(); $id = (string)($_POST['id'] ?? ''); $ok = false;
    $acctU = accFind($d, $id); if ($acctU && !accCanManage($acctU)) return ['ok'=>false,'error'=>'Not permitted.'];
    foreach ($d['accounts'] as &$a) if (($a['id']??'')===$id) {
        if (isset($_POST['package'])) { $p=(string)$_POST['package']; if ($p==='' || accPkg($d,$p)) $a['package']=$p; }
        if (isset($_POST['email'])) $a['email'] = trim((string)$_POST['email']);
        if (isset($_POST['owner']) && trim((string)$_POST['owner'])!=='') $a['owner'] = trim((string)$_POST['owner']);
        $ok = true;
    } unset($a);
    return $ok && accSave($d) ? ['ok'=>true,'msg'=>'Account updated.'] : ['ok'=>false,'error'=>'Account not found.'];
}
function accApiSuspend(): array {
    $d = accStore(); $id = (string)($_POST['id'] ?? ''); $on = ($_POST['on'] ?? '1') !== '0'; $acct = accFind($d,$id);
    if (!$acct) return ['ok'=>false,'error'=>'Account not found.'];
    if (!accCanManage($acct)) return ['ok'=>false,'error'=>'Not permitted.'];
    foreach ($d['accounts'] as &$a) if (($a['id']??'')===$id) $a['suspended'] = $on; unset($a);
    foreach (accSites() as $s) if (($s['owner']??'')===$acct['username']) { $on ? accSuspendSite($s['docroot']??'') : accUnsuspendSite($s['docroot']??''); }
    if (!empty($acct['linux_user']) && is_file(HELPER)) helper($on ? 'acct-user-lock' : 'acct-user-unlock', [$acct['linux_user']]);
    accSave($d); auditLog($on?'acct_suspend':'acct_unsuspend', $acct['username']);
    return ['ok'=>true,'msg'=>$acct['username'].($on?' suspended (its sites now show a suspended page).':' unsuspended.')];
}
function accApiAssignSite(): array {
    $dom = (string)($_POST['domain'] ?? ''); $owner = trim((string)($_POST['owner'] ?? ''));
    if (!isAdmin()) {   // a reseller may only assign their own websites to themselves or their sub-accounts
        $ownsSite = false; foreach (accSites() as $s2) if (($s2['domain']??'')===$dom && ($s2['owner']??'')===curUser()) $ownsSite = true;
        $d0 = accStore(); $targetOk = ($owner===curUser()); foreach ($d0['accounts'] as $a2) if (($a2['username']??'')===$owner && ($a2['owner']??'')===curUser()) $targetOk = true;
        if (!$ownsSite || !$targetOk) return ['ok'=>false,'error'=>'You can only assign your own websites to your own accounts.'];
    }
    $sites = accSites(); $ok = false;
    foreach ($sites as &$s) if (($s['domain']??'')===$dom) { if ($owner==='' || $owner==='__none__') unset($s['owner']); else $s['owner'] = $owner; $ok = true; } unset($s);
    if (!$ok) return ['ok'=>false,'error'=>'Website not found.'];
    saveJson(SITES_FILE, $sites);
    return ['ok'=>true,'msg'=>'Ownership updated for '.$dom.'.'];
}
function accApiAssignDb(): array {
    $d = accStore(); $id = (string)($_POST['id'] ?? ''); $dbn = (string)($_POST['db'] ?? ''); $add = ($_POST['add'] ?? '1') !== '0';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbn)) return ['ok'=>false,'error'=>'Bad database name.'];
    $ok = false;
    foreach ($d['accounts'] as &$a) if (($a['id']??'')===$id) {
        $a['databases'] = $a['databases'] ?? [];
        if ($add) { if (!in_array($dbn,$a['databases'],true)) $a['databases'][] = $dbn; }
        else $a['databases'] = array_values(array_filter($a['databases'], fn($x)=>$x!==$dbn));
        $ok = true;
    } unset($a);
    return $ok && accSave($d) ? ['ok'=>true,'msg'=>'Database assignment updated.'] : ['ok'=>false,'error'=>'Account not found.'];
}
function accApiTransfer(): array {
    $d = accStore(); $from = accFind($d,(string)($_POST['id'] ?? '')); $toUser = trim((string)($_POST['to'] ?? ''));
    if (!$from) return ['ok'=>false,'error'=>'Account not found.'];
    $toExists = ($toUser===cfgGet('admin_user','admin')); foreach ($d['accounts'] as $a) if (($a['username']??'')===$toUser) $toExists = true;
    if (!$toExists) return ['ok'=>false,'error'=>'Pick a valid destination account.'];
    $sites = accSites(); $moved = 0;
    foreach ($sites as &$s) if (($s['owner']??'')===$from['username']) { $s['owner'] = $toUser; $moved++; } unset($s);
    saveJson(SITES_FILE, $sites);
    // move assigned databases too
    foreach ($d['accounts'] as &$a) {
        if (($a['id']??'')===$from['id']) $fromDbs = $a['databases'] ?? [];
    } unset($a);
    if (!empty($fromDbs)) foreach ($d['accounts'] as &$a) {
        if (($a['username']??'')===$toUser) { $a['databases'] = array_values(array_unique(array_merge($a['databases'] ?? [], $fromDbs))); }
        if (($a['id']??'')===$from['id']) $a['databases'] = [];
    } unset($a);
    accSave($d); auditLog('acct_transfer', $from['username'].' -> '.$toUser);
    return ['ok'=>true,'msg'=>"Transferred {$moved} website(s) from {$from['username']} to {$toUser}."];
}
function accApiDelete(): array {
    $d = accStore(); $acct = accFind($d,(string)($_POST['id'] ?? ''));
    if (!$acct) return ['ok'=>false,'error'=>'Account not found.'];
    if (!accCanManage($acct)) return ['ok'=>false,'error'=>'Not permitted.'];
    // unassign its websites (files are kept)
    $sites = accSites(); foreach ($sites as &$s) if (($s['owner']??'')===$acct['username']) unset($s['owner']); unset($s);
    saveJson(SITES_FILE, $sites);
    if (($_POST['del_linux'] ?? '')==='1' && !empty($acct['linux_user']) && is_file(HELPER)) helper('acct-user-delete', [$acct['linux_user']]);
    $d['accounts'] = array_values(array_filter($d['accounts'], fn($a)=>($a['id']??'')!==$acct['id']));
    accSave($d); auditLog('acct_delete', $acct['username']);
    return ['ok'=>true,'msg'=>'Account "'.$acct['username'].'" removed (its websites/databases are kept, just unassigned).'];
}

// -- page --
function accountsPage(): void { ?>
<?=helpBox('Hosting accounts &amp; packages', 'Hand out hosting to other people without sharing your admin login. Create a <b>package</b> (the limits), create an <b>account</b> (an end user, or a reseller who manages their own customers), then give it a <b>login password</b> (Manage -> Login password) so they can sign in at this same address and see only their own websites. Assign websites &amp; databases to them, watch <b>usage vs limits</b>, and <b>suspend</b> or <b>transfer</b> in one click. New here? Click <b>How to use this page</b> at the top-right for the full walkthrough.')?>
<div class="card" style="padding:0" id="accPkgCard">
  <div class="flex" style="padding:16px 18px 0"><h3 style="margin:0">Packages</h3><button class="btn btn-g btn-xs" style="margin-left:auto" onclick="accPkgForm()">New package</button></div>
  <table><thead><tr><th>Name</th><th>Disk</th><th>Bandwidth</th><th>Domains</th><th>Databases</th><th>Email</th><th></th></tr></thead><tbody id="accPkgs"><tr><td colspan="7" class="empty">loading</td></tr></tbody></table>
</div>
<div class="card">
  <h3>Create an account</h3>
  <div class="row">
    <div><label>Username</label><input id="acU" placeholder="john"></div>
    <div style="max-width:150px"><label>Type</label><select id="acRole"><option value="user">End user</option><option value="reseller">Reseller</option></select></div>
    <div><label>Owner</label><select id="acOwner"></select></div>
    <div><label>Package</label><select id="acPkg"></select></div>
    <div style="flex:2"><label>Contact email (optional)</label><input id="acEmail" placeholder="john@example.com"></div>
  </div>
  <div class="flex mt"><label class="ck"><input type="checkbox" id="acLinux"> Create a dedicated Linux user</label><button class="btn btn-p" style="margin-left:auto" onclick="accCreate()">Create account</button></div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Accounts</h3></div>
  <div id="accList"><div class="empty">loading</div></div>
</div>
<style>
.ck{display:flex;align-items:center;gap:8px}.ck input{width:16px;height:16px}
.qbar{height:6px;border-radius:4px;background:var(--surface2);overflow:hidden;margin-top:3px}
.qbar span{display:block;height:100%;background:var(--accent)}
.qbar.warn span{background:var(--red)}
.qcell{min-width:96px}.qcell .xs{white-space:nowrap}
.accrow-x{background:var(--surface2)}
</style>
<script>
var ACC={};
function accEsc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function fmtMB(m){ m=+m||0; return m>=1024?(m/1024).toFixed(1)+' GB':m+' MB'; }
function qbar(used,limit){ if(!limit) return '<div class="xs muted">'+used+' / ∞</div>'; var pct=Math.min(100,Math.round(used/limit*100)); return '<div class="xs '+(used>limit?'':'muted')+'">'+used+' / '+limit+'</div><div class="qbar'+(used>limit?' warn':'')+'"><span style="width:'+pct+'%"></span></div>'; }
function accLoad(){ api('acc_data',{}).then(function(r){ if(!r.ok)return; ACC=r;
  // packages
  document.getElementById('accPkgs').innerHTML=r.packages.length?r.packages.map(function(p){return '<tr><td><b>'+accEsc(p.name)+'</b></td><td>'+fmtMB(p.disk_mb)+'</td><td>'+(p.bw_gb||0)+' GB</td><td>'+(p.max_domains||'∞')+'</td><td>'+(p.max_db||'∞')+'</td><td>'+(p.max_email||'∞')+'</td><td class="flex"><button class="btn btn-g btn-xs" onclick=\'accPkgForm('+JSON.stringify(p)+')\'>edit</button><button class="btn btn-d btn-xs" onclick="accPkgDel(\''+p.id+'\')">del</button></td></tr>';}).join(''):'<tr><td colspan="7" class="empty">no packages</td></tr>';
  // owner + package selects
  document.getElementById('acOwner').innerHTML=r.owners.map(function(o){return '<option value="'+accEsc(o)+'">'+accEsc(o)+(o===r.admin?' (admin)':'')+'</option>';}).join('');
  document.getElementById('acPkg').innerHTML='<option value="">- no package -</option>'+r.packages.map(function(p){return '<option value="'+p.id+'">'+accEsc(p.name)+'</option>';}).join('');
  // accounts
  document.getElementById('accList').innerHTML=r.accounts.length?('<table><thead><tr><th>Account</th><th>Type</th><th>Owner</th><th>Package</th><th>Websites</th><th>Disk</th><th>DBs</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead><tbody>'+r.accounts.map(accRow).join('')+'</tbody></table>'):'<div class="empty">No accounts yet - create one above.</div>';
}); }
function accRow(a){
  var l=a.limits||{}, u=a.usage||{};
  return '<tr><td><b>'+accEsc(a.username)+'</b>'+(a.has_pass?' <span class="badge bg-green" title="can sign in to the panel">login</span>':'')+(a.linux_user?' <span class="badge bg-blue" title="dedicated Linux user">linux</span>':'')+(a.email?'<br><span class="xs muted">'+accEsc(a.email)+'</span>':'')+'</td>'+
   '<td>'+(a.role==='reseller'?'<span class="badge bg-blue">reseller</span>':'user')+'</td>'+
   '<td class="xs muted">'+accEsc(a.owner||'')+'</td>'+
   '<td class="xs">'+accEsc((l.name)||'-')+'</td>'+
   '<td class="qcell">'+qbar(u.domains,l.max_domains)+'</td>'+
   '<td class="qcell">'+qbar(u.disk_mb,l.disk_mb).replace(/(\d+) \/ (\d+|&#8734;)/,function(m,x,y){return fmtMB(x)+' / '+(l.disk_mb?fmtMB(l.disk_mb):'∞');})+'</td>'+
   '<td class="qcell">'+qbar(u.dbs,l.max_db)+'</td>'+
   '<td class="qcell">'+qbar(u.emails,l.max_email)+'</td>'+
   '<td>'+(a.suspended?'<span class="badge bg-red">suspended</span>':'<span class="badge bg-green">active</span>')+'</td>'+
   '<td class="flex">'+(a.suspended?'<button class="btn btn-p btn-xs" onclick="accSuspend(\''+a.id+'\',0)">Unsuspend</button>':'<button class="btn btn-g btn-xs" onclick="accSuspend(\''+a.id+'\',1)">Suspend</button>')+
   '<button class="btn btn-s btn-xs" onclick="accManage(\''+a.id+'\')">Manage</button>'+
   '<button class="btn btn-d btn-xs" onclick="accDelete(\''+a.id+'\')">Delete</button></td></tr>';
}
function accCreate(){ api('acc_create',{username:document.getElementById('acU').value.trim(),role:document.getElementById('acRole').value,owner:document.getElementById('acOwner').value,package:document.getElementById('acPkg').value,email:document.getElementById('acEmail').value.trim(),linux:document.getElementById('acLinux').checked?'1':'0'}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){document.getElementById('acU').value=document.getElementById('acEmail').value='';document.getElementById('acLinux').checked=false;accLoad();} }); }
function accSuspend(id,on){ api('acc_suspend',{id:id,on:on?'1':'0'}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); accLoad(); }); }
function accDelete(id){ uiConfirm('Delete this account? Its websites and databases are kept (just unassigned).',function(){ api('acc_delete',{id:id,del_linux:'1'}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); accLoad(); }); }); }
function accPkgForm(p){ p=p||{}; uiHtml('<h3 style="margin-top:0">'+(p.id?'Edit':'New')+' package</h3>'+
  '<input type="hidden" id="pkId" value="'+accEsc(p.id||'')+'">'+
  '<div class="mb"><label>Name</label><input id="pkName" value="'+accEsc(p.name||'')+'"></div>'+
  '<div class="row"><div><label>Disk (MB)</label><input id="pkDisk" type="number" value="'+(p.disk_mb||0)+'"></div><div><label>Bandwidth (GB)</label><input id="pkBw" type="number" value="'+(p.bw_gb||0)+'"></div></div>'+
  '<div class="row"><div><label>Max domains</label><input id="pkDom" type="number" value="'+(p.max_domains||0)+'"></div><div><label>Max databases</label><input id="pkDb" type="number" value="'+(p.max_db||0)+'"></div><div><label>Max email</label><input id="pkEm" type="number" value="'+(p.max_email||0)+'"></div></div>'+
  '<div class="xs muted mt">0 = unlimited.</div>'+
  '<div class="flex mt"><button class="btn btn-p" onclick="accPkgSave()">Save package</button></div>'); }
function accPkgSave(){ api('acc_pkg_save',{id:document.getElementById('pkId').value,name:document.getElementById('pkName').value.trim(),disk_mb:document.getElementById('pkDisk').value,bw_gb:document.getElementById('pkBw').value,max_domains:document.getElementById('pkDom').value,max_db:document.getElementById('pkDb').value,max_email:document.getElementById('pkEm').value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){uiClose();accLoad();} }); }
function accPkgDel(id){ uiConfirm('Delete this package?',function(){ api('acc_pkg_del',{id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); accLoad(); }); }); }
function accManage(id){
  var a=ACC.accounts.filter(function(x){return x.id===id;})[0]; if(!a)return;
  var mySites=ACC.sites.filter(function(s){return s.owner===a.username;});
  var freeSites=ACC.sites;
  var owners=ACC.owners;
  var body='<h3 style="margin-top:0">Manage '+accEsc(a.username)+'</h3>'+
   '<div class="row"><div><label>Package</label><select id="mPkg">'+'<option value="">- none -</option>'+ACC.packages.map(function(p){return '<option value="'+p.id+'"'+(a.package===p.id?' selected':'')+'>'+accEsc(p.name)+'</option>';}).join('')+'</select></div>'+
   '<div style="flex:2"><label>Contact email</label><input id="mEmail" value="'+accEsc(a.email||'')+'"></div>'+
   '<button class="btn btn-p" style="align-self:flex-end" onclick="accUpd(\''+id+'\')">Save</button></div>'+
   '<hr class="sep"><b class="sm">Login password</b> <span class="xs muted">'+(a.has_pass?'(this account can sign in)':'(no login set yet)')+'</span>'+
   '<div class="row"><input id="mPass" type="password" placeholder="set a login password (min 6)"><button class="btn btn-p" onclick="accSetPass(\''+id+'\')">Set login password</button></div>'+
   '<hr class="sep"><b class="sm">Assign a website to this account</b>'+
   '<div class="row"><select id="mSite">'+freeSites.map(function(s){return '<option value="'+accEsc(s.domain)+'">'+accEsc(s.domain)+(s.owner?' (owner: '+accEsc(s.owner)+')':' (unassigned)')+'</option>';}).join('')+'</select><button class="btn btn-g" onclick="accAssignSite(\''+accEsc(a.username)+'\')">Assign</button></div>'+
   '<div class="xs muted">Owned: '+(mySites.length?mySites.map(function(s){return accEsc(s.domain);}).join(', '):'none')+'</div>'+
   '<hr class="sep"><b class="sm">Assign a database</b>'+
   '<div class="row"><select id="mDb">'+ACC.dbs.map(function(x){return '<option value="'+accEsc(x)+'"'+((a.databases||[]).indexOf(x)>=0?' selected':'')+'>'+accEsc(x)+'</option>';}).join('')+'</select><button class="btn btn-g" onclick="accAssignDb(\''+id+'\',1)">Add</button><button class="btn btn-g" onclick="accAssignDb(\''+id+'\',0)">Remove</button></div>'+
   '<div class="xs muted">Assigned DBs: '+((a.databases||[]).length?(a.databases||[]).map(accEsc).join(', '):'none')+'</div>'+
   '<hr class="sep"><b class="sm">Transfer everything to another account</b>'+
   '<div class="row"><select id="mTo">'+owners.filter(function(o){return o!==a.username;}).map(function(o){return '<option value="'+accEsc(o)+'">'+accEsc(o)+'</option>';}).join('')+'</select><button class="btn btn-d" onclick="accTransfer(\''+id+'\')">Transfer</button></div>';
  uiHtml(body, true);
}
function accUpd(id){ api('acc_update',{id:id,package:document.getElementById('mPkg').value,email:document.getElementById('mEmail').value.trim()}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok)accLoad(); }); }
function accSetPass(id){ var p=document.getElementById('mPass').value; if(!p){toast('Enter a password','e');return;} api('acc_setpass',{id:id,password:p}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){uiClose();accLoad();} }); }
function accAssignSite(user){ api('acc_assign_site',{domain:document.getElementById('mSite').value,owner:user}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){uiClose();accLoad();} }); }
function accAssignDb(id,add){ api('acc_assign_db',{id:id,db:document.getElementById('mDb').value,add:add?'1':'0'}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){uiClose();accLoad();} }); }
function accTransfer(id){ var to=document.getElementById('mTo').value; uiConfirm('Transfer all websites &amp; databases to '+to+'?',function(){ api('acc_transfer',{id:id,to:to}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){uiClose();accLoad();} }); }); }
accLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'accounts','name'=>'Hosting Accounts','desc'=>'WHM-style accounts & resellers: packages, quotas, suspend, ownership transfer.','feature'=>'enableAccounts'],
        'pages' => ['accounts'=>['title'=>'Accounts','section'=>'RESELLER','feature'=>'enableAccounts','render'=>'accountsPage']],
        'api'   => ['acc_data'=>'accApiData','acc_pkg_save'=>'accApiPkgSave','acc_pkg_del'=>'accApiPkgDel','acc_create'=>'accApiCreate','acc_update'=>'accApiUpdate','acc_setpass'=>'accApiSetPass','acc_suspend'=>'accApiSuspend','acc_assign_site'=>'accApiAssignSite','acc_assign_db'=>'accApiAssignDb','acc_transfer'=>'accApiTransfer','acc_delete'=>'accApiDelete'],
    ]);
}
