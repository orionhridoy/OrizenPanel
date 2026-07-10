<?php
/*
 * Orizen module: REST API & tokens
 * Create API tokens for automation. Any panel action can then be called over
 * HTTPS with an Authorization: Bearer <token> header (no browser session/CSRF).
 * Ships with an `orizen` CLI on the server. Includes a full command reference.
 * Admin-only.
 */
function atList(): array { $t = cfgGet('api_tokens', []); return is_array($t) ? $t : []; }
function atApiList(): array {
    $out = [];
    foreach (atList() as $t) $out[] = ['id'=>$t['id']??'', 'name'=>$t['name']??'', 'prefix'=>$t['prefix']??'', 'created'=>$t['created']??''];
    return ['ok'=>true, 'tokens'=>$out, 'base'=>'https://'.cfgGet('server_ip').':'.cfgGet('panel_port').'/'];
}
function atApiCreate(): array {
    $name = trim((string)($_POST['name'] ?? '')); if ($name==='' ) $name = 'token';
    $secret = 'ozk_'.bin2hex(random_bytes(24));
    $c = cfg(); $c['api_tokens'] = is_array($c['api_tokens'] ?? null) ? $c['api_tokens'] : [];
    $c['api_tokens'][] = ['id'=>bin2hex(random_bytes(4)), 'name'=>substr($name,0,40), 'prefix'=>substr($secret,0,12), 'hash'=>hash('sha256',$secret), 'created'=>date('c')];
    if (!saveCfg($c)) return ['ok'=>false,'error'=>'Could not save.'];
    auditLog('api_token_create', $name);
    return ['ok'=>true, 'token'=>$secret, 'msg'=>'Token created. Copy it now - it is shown only once.'];
}
function atApiRevoke(): array {
    $id = (string)($_POST['id'] ?? ''); $c = cfg();
    $c['api_tokens'] = array_values(array_filter(is_array($c['api_tokens'] ?? null) ? $c['api_tokens'] : [], fn($t)=>($t['id']??'')!==$id));
    saveCfg($c); auditLog('api_token_revoke', $id);
    return ['ok'=>true, 'msg'=>'Token revoked.'];
}

/**
 * The full, human-written catalog of API actions grouped by area. Every entry is a
 * real panel action: a = action name, p = parameters, d = what it does.
 * Params use "name" for required, "name=a|b" to show choices, "(optional)" where noted.
 */
function apiCatalog(): array {
    $mk = fn($a,$p,$d)=>['a'=>$a,'p'=>$p,'d'=>$d];
    return [
      ['cat'=>'Server &amp; dashboard', 'items'=>[
        $mk('dash_stats','(none)','Full server metrics: disk, memory, CPU, uptime, database count, services.'),
        $mk('dash_cpu','(none)','Live CPU %, memory and uptime (the values the dashboard polls).'),
        $mk('disk_usage','dir (optional)','Size breakdown of a folder, biggest first.'),
        $mk('net_run','tool=ping|dns|trace|port|ipconfig, host, port (for port)','Network tools: reachability, DNS lookup, traceroute, port check.'),
      ]],
      ['cat'=>'Websites &amp; domains', 'items'=>[
        $mk('site_add','domain, mode=new|alias, share (site to share when alias)','Create a website (its own folder) or point another domain at an existing site.'),
        $mk('site_del','domain','Remove a website\'s web config.'),
        $mk('sub_add','sub, parent','Create a subdomain such as blog.example.com.'),
        $mk('redirect_add','domain, target','Create a 301 redirect from a domain you own to any URL.'),
        $mk('redirect_del','domain','Delete a redirect.'),
        $mk('dns_check','domain','Check whether a domain now points at this server.'),
      ]],
      ['cat'=>'SSL / HTTPS', 'items'=>[
        $mk('ssl_issue','domain','Issue and install a free Let\'s Encrypt certificate (domain must point here first).'),
      ]],
      ['cat'=>'DNS (Cloudflare)', 'items'=>[
        $mk('set_cf_token','token','Save and validate your Cloudflare API token.'),
        $mk('zone_list','domain','List the Cloudflare DNS records for a domain.'),
        $mk('zone_save','domain, id (blank=new), type, name, content','Add or update a DNS record.'),
        $mk('zone_del','domain, id','Delete a DNS record.'),
      ]],
      ['cat'=>'Databases', 'items'=>[
        $mk('db_create','name','Create a database.'),
        $mk('db_drop','name','Delete a database.'),
        $mk('dbuser_create','user, pass, grant_db (optional)','Create a database user and optionally grant it a database.'),
        $mk('dbuser_drop','user','Delete a database user.'),
        $mk('sql_query','db, query','Run a SQL statement and get the result.'),
        $mk('sql_tables','db','List the tables in a database.'),
        $mk('sql_export','db, table (blank + all=1 for whole database)','Get a .sql dump of a table or a whole database.'),
      ]],
      ['cat'=>'Backups', 'items'=>[
        $mk('backup_create','domain, dbs (comma-separated database names)','Back up a website\'s files plus the chosen databases into one archive.'),
        $mk('backup_list','(none)','List existing backup files.'),
        $mk('backup_restore','name','Restore a backup (overwrites files and re-imports databases).'),
        $mk('backup_delete','name','Delete a backup file.'),
        $mk('backupx_get','(none)','Get the scheduled-backup config, history and log.'),
        $mk('backupx_save','enabled=1|0, freq=hourly|daily|weekly, retention, dtype, dhost, duser, dpass, dpath','Save the scheduled/off-server backup settings.'),
        $mk('backupx_run','(none)','Run a scheduled backup right now.'),
      ]],
      ['cat'=>'Email', 'items'=>[
        $mk('mail_domain_add','domain','Set up a mail domain.'),
        $mk('mailbox_add','email, pass','Create a mailbox (its mail domain is created automatically if new).'),
        $mk('mailbox_passwd','email, pass','Change a mailbox password.'),
        $mk('mailbox_del','email','Delete a mailbox.'),
      ]],
      ['cat'=>'Files', 'items'=>[
        $mk('file_load','file','Read a file\'s contents.'),
        $mk('file_save','file, content','Write contents to a file.'),
        $mk('file_new','dir, name, isdir=1 (for a folder)','Create a new file or folder.'),
        $mk('file_del','paths (array) or path','Delete one or more files/folders.'),
        $mk('file_rename','old, new','Rename a file or folder.'),
        $mk('file_copy','files (array), dest','Copy items into a folder.'),
        $mk('file_move','files (array), dest','Move items into a folder.'),
        $mk('file_chmod','path, mode','Change a file\'s permissions.'),
        $mk('file_zip','files (array), dest','Zip items into an archive.'),
        $mk('file_unzip','file','Extract an archive.'),
      ]],
      ['cat'=>'System', 'items'=>[
        $mk('service','svc, act=start|stop|restart','Control a system service (web server, database, mail...).'),
        $mk('fw','op=allow|deny|delete-allow, port','Open or close a firewall port.'),
        $mk('console','cmd','Run a shell command as the limited web user.'),
        $mk('log_list','(none)','List available log files.'),
        $mk('log_view','path, lines (optional), filter (optional)','Read a log file, optionally filtered.'),
        $mk('proc_list','(none)','List running processes.'),
        $mk('proc_kill','pid','Stop a process by PID.'),
      ]],
      ['cat'=>'Settings &amp; security', 'items'=>[
        $mk('set_password','current, new','Change the admin login password.'),
        $mk('set_features','enable<Module>=1|0','Turn optional modules on or off (e.g. enableDocker=1).'),
        $mk('sec_protect','dir, user, pass','Password-protect a folder.'),
        $mk('sec_block_ip','dir, ip','Block an IP address from a site.'),
      ]],
      ['cat'=>'Hosting accounts &amp; resellers', 'items'=>[
        $mk('acc_data','(none)','List packages, accounts, owned sites and live usage.'),
        $mk('acc_pkg_save','name, disk_mb, bw_gb, max_domains, max_db, max_email, id (blank=new)','Create or edit a hosting package (0 = unlimited).'),
        $mk('acc_pkg_del','id','Delete a package (must be unused).'),
        $mk('acc_create','username, role=user|reseller, owner, package, email, linux=1 (optional)','Create a hosting account.'),
        $mk('acc_update','id, package, email, owner','Update an account.'),
        $mk('acc_setpass','id, password','Set an account\'s panel login password (lets them sign in).'),
        $mk('acc_suspend','id, on=1|0','Suspend or unsuspend an account (its sites show a suspended page).'),
        $mk('acc_assign_site','domain, owner','Assign a website to an account.'),
        $mk('acc_assign_db','id, db, add=1|0','Assign or remove a database from an account.'),
        $mk('acc_transfer','id, to','Move all of an account\'s sites and databases to another account.'),
        $mk('acc_delete','id, del_linux=1 (optional)','Delete an account (sites/databases are kept, just unassigned).'),
      ]],
      ['cat'=>'Monitoring &amp; notifications', 'items'=>[
        $mk('mon_live','(none)','One live sample of CPU/memory/disk/network.'),
        $mk('mon_history','(none)','Recorded history for the graphs.'),
        $mk('notify_get','(none)','Get notification settings and recent alert log.'),
        $mk('notify_save','email, telegram_token, telegram_chat, discord_webhook, thresholds','Save where alerts are sent.'),
        $mk('notify_test','(none)','Send a test alert to confirm delivery.'),
      ]],
      ['cat'=>'Git deploy', 'items'=>[
        $mk('git_list','(none)','List configured deployments.'),
        $mk('git_add','name, repo, branch, path, build (optional)','Add a deployment from an HTTPS Git repo.'),
        $mk('git_deploy','id','Pull the latest code for a deployment now.'),
        $mk('git_rollback','id','Roll a deployment back to the previous commit.'),
        $mk('git_delete','id','Remove a deployment.'),
      ]],
      ['cat'=>'Website tools', 'items'=>[
        $mk('wt_maint','domain, on=1|0','Turn maintenance mode on or off for a site.'),
        $mk('wt_perms','domain','Reset a site\'s file permissions and ownership.'),
        $mk('wt_search','domain, find','Search a site\'s files for text.'),
        $mk('wt_replace','domain, find, replace','Find and replace text across a site\'s files.'),
        $mk('wt_clone','domain, dest','Clone a site to another folder.'),
        $mk('wt_opcache','op=status|reset','Read or clear the PHP OPcache.'),
      ]],
      ['cat'=>'Staging &amp; apps', 'items'=>[
        $mk('stg_data','(none)','List sites and their staging copies.'),
        $mk('stg_create','domain','Create a staging copy of a live site.'),
        $mk('stg_push','id','Push a staging copy back to production (backs up prod first).'),
        $mk('stg_delete','id','Delete a staging copy.'),
        $mk('oc_list','(none)','List one-click apps available to install.'),
        $mk('oc_install','app, domain','Install an app (e.g. WordPress) onto a website.'),
      ]],
      ['cat'=>'Advanced (opt-in modules)', 'items'=>[
        $mk('rt_status','(none)','Installed PHP versions and each site\'s version.'),
        $mk('rt_install','version','Install a PHP version (e.g. 8.3).'),
        $mk('rt_set','domain, version','Set which PHP version a site uses.'),
        $mk('iso_status','(none)','Which sites are isolated.'),
        $mk('iso_on','domain','Isolate a site (own Linux user + PHP pool).'),
        $mk('iso_off','domain','Revert isolation.'),
        $mk('dk_ps','(none)','List Docker containers.'),
        $mk('dk_ctl','id, act=start|stop|restart|rm','Control a Docker container.'),
        $mk('mp_queue','(none)','View the mail queue.'),
        $mk('mp_blacklist','(none)','Check if the server IP is on a spam blacklist.'),
        $mk('mg_import','(after mg_scan)','Import an uploaded migration archive.'),
        $mk('sp_status','(none)','Security status: 2FA, Fail2Ban, bans, audit tail.'),
        $mk('sp_unban','ip','Unban an IP address.'),
        $mk('ms_get','(none)','List monitored servers (multi-server).'),
      ]],
      ['cat'=>'API tokens', 'items'=>[
        $mk('at_list','(none)','List your API tokens (no secrets).'),
        $mk('at_create','name','Create a new token (returned once).'),
        $mk('at_revoke','id','Revoke a token.'),
        $mk('at_actions','(none)','This command reference, as JSON.'),
      ]],
    ];
}
/** Return the catalog plus any registered module actions not already documented, so nothing is hidden. */
function atApiActions(): array {
    $cat = apiCatalog();
    $documented = [];
    foreach ($cat as $g) foreach ($g['items'] as $it) $documented[$it['a']] = true;
    $extra = [];
    foreach (array_keys($GLOBALS['ORIZEN_MOD']['api'] ?? []) as $a) if (!isset($documented[$a])) $extra[] = $a;
    sort($extra);
    return ['ok'=>true, 'groups'=>$cat, 'extra'=>$extra, 'base'=>'https://'.cfgGet('server_ip').':'.cfgGet('panel_port').'/'];
}

function apiTokensPage(): void {
    $base = 'https://'.cfgGet('server_ip').':'.cfgGet('panel_port').'/'; ?>
<?=helpBox('REST API &amp; automation', 'Create a token, then drive Orizen from scripts, CI/CD or your own tools. Send any panel action as an HTTP <b>POST</b> with an <span class="mono">Authorization: Bearer &lt;token&gt;</span> header - no browser login needed. An <span class="mono">orizen</span> command-line tool is installed on the server too. The full <b>command reference</b> is at the bottom of this page. Tokens have full admin rights, so keep them secret and revoke ones you no longer use.')?>
<div class="card">
  <h3>1. Create a token</h3>
  <div class="row"><div style="flex:2"><label>Name (what it's for)</label><input id="atName" placeholder="ci-deploy"></div><button class="btn btn-p" onclick="atCreate()">Create token</button></div>
  <div id="atNew"></div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Your tokens</h3></div>
  <table><thead><tr><th>Name</th><th>Token</th><th>Created</th><th></th></tr></thead><tbody id="atList"><tr><td colspan="4" class="empty">none</td></tr></tbody></table>
</div>
<div class="card">
  <h3>2. Call the API</h3>
  <div class="sm muted mb">Every menu action works over the API. Two ways to call it:</div>
  <pre class="code" style="white-space:pre-wrap"># From anywhere, with curl:
curl -k -X POST <?=h($base)?> \
  -H "Authorization: Bearer &lt;YOUR_TOKEN&gt;" \
  --data-urlencode "action=site_add" \
  --data-urlencode "domain=example.com" --data-urlencode "mode=new"

# From the server, using the built-in CLI:
export ORIZEN_TOKEN=&lt;YOUR_TOKEN&gt;
orizen site_add domain=example.com mode=new
orizen backup_list</pre>
  <div class="xs muted mt">Responses are JSON with an <span class="mono">ok</span> field (true/false). Parameter names below are exactly the fields the panel forms use.</div>
</div>
<div class="card">
  <div class="flex" style="align-items:center;gap:12px;flex-wrap:wrap">
    <h3 style="margin:0">3. Command reference</h3>
    <span class="xs muted" id="atCount"></span>
    <input id="atSearch" placeholder="Search actions (e.g. backup, site, dns)..." style="margin-left:auto;min-width:240px;flex:1" oninput="atFilter()">
  </div>
  <div class="xs muted mt mb">Click <b>Copy</b> on any row to copy a ready-to-edit <span class="mono">orizen</span> command. Swap in your values.</div>
  <div id="atRef"><div class="empty">loading...</div></div>
</div>
<style>
.aref-g{margin-top:14px}
.aref-h{font-size:11px;letter-spacing:.6px;text-transform:uppercase;font-weight:700;color:var(--accent);margin:0 0 6px}
.aref-t{width:100%;border-collapse:collapse}
.aref-t td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:top;font-size:12.5px}
.aref-t tr:hover{background:var(--surface2)}
.aref-a{font-family:'JetBrains Mono',monospace;font-weight:700;color:var(--text);white-space:nowrap}
.aref-p{font-family:'JetBrains Mono',monospace;color:var(--text2);font-size:11.5px;word-break:break-word}
.aref-d{color:var(--text2)}
.aref-c{cursor:pointer;background:var(--surface2);border:1px solid var(--border2);border-radius:6px;padding:3px 9px;font-size:10px;color:var(--accent);white-space:nowrap}
.aref-x{display:flex;flex-wrap:wrap;gap:7px}
.aref-x span{font-family:'JetBrains Mono',monospace;font-size:11px;background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:2px 7px;color:var(--text2)}
</style>
<script>
function atLoad(){ api('at_list',{}).then(function(r){ if(!r.ok)return; document.getElementById('atList').innerHTML=r.tokens.length?r.tokens.map(function(t){return '<tr><td><b>'+(t.name||'-')+'</b></td><td class="mono xs">'+t.prefix+'...</td><td class="xs muted">'+(t.created||'').replace('T',' ').slice(0,16)+'</td><td><button class="btn btn-d btn-xs" onclick="atRevoke(\''+t.id+'\')">revoke</button></td></tr>';}).join(''):'<tr><td colspan="4" class="empty">no tokens yet</td></tr>'; }); }
function atCreate(){ api('at_create',{name:document.getElementById('atName').value.trim()}).then(function(r){ if(!r.ok){toast(r.error||'Failed','e');return;} document.getElementById('atNew').innerHTML='<div class="alert alert-ok mt">New token (copy it now, shown once):<br><span class="mono" style="word-break:break-all">'+r.token+'</span></div>'; document.getElementById('atName').value=''; atLoad(); }); }
function atRevoke(id){ uiConfirm('Revoke this token? Anything using it stops working.',function(){ api('at_revoke',{id:id}).then(function(r){ toast(r.msg||r.error); atLoad(); }); }); }

var ATREF=null;
function atEsc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
// Turn a params string into a ready "orizen <action> key=value" example.
function atExample(a,p){ var kv=''; if(p && p.indexOf('(none)')<0){ p.split(',').forEach(function(tok){ tok=tok.trim(); if(!tok)return; tok=tok.replace(/\s*\(.*?\)\s*/g,''); var key=tok, val=''; if(tok.indexOf('=')>=0){ key=tok.split('=')[0].trim(); val=tok.split('=')[1].split('|')[0].trim(); } key=key.split(' ')[0]; if(!key)return; kv+=' '+key+'='+val; }); } return 'orizen '+a+kv; }
function atRenderGroup(g){ var rows=g.items.map(function(it){
    var ex=atExample(it.a,it.p).replace(/'/g,"\\'");
    return '<tr data-k="'+atEsc((it.a+' '+it.d+' '+it.p).toLowerCase())+'">'+
      '<td class="aref-a">'+atEsc(it.a)+'</td>'+
      '<td class="aref-p">'+atEsc(it.p)+'</td>'+
      '<td class="aref-d">'+it.d+'</td>'+
      '<td><button class="aref-c" onclick="copyText(\''+ex+'\')">Copy</button></td></tr>';
  }).join('');
  return '<div class="aref-g" data-cat="'+atEsc(g.cat)+'"><div class="aref-h">'+g.cat+'</div><table class="aref-t"><tbody>'+rows+'</tbody></table></div>';
}
function atRenderRef(){ var el=document.getElementById('atRef'); var html=ATREF.groups.map(atRenderGroup).join('');
  if(ATREF.extra&&ATREF.extra.length){ html+='<div class="aref-g" data-cat="other"><div class="aref-h">Other installed actions</div><div class="aref-x" style="margin-top:4px">'+ATREF.extra.map(function(a){return '<span data-k="'+atEsc(a)+'">'+atEsc(a)+'</span>';}).join('')+'</div></div>'; }
  el.innerHTML=html;
  var n=0; ATREF.groups.forEach(function(g){n+=g.items.length;}); document.getElementById('atCount').textContent=n+' actions'+((ATREF.extra&&ATREF.extra.length)?' + '+ATREF.extra.length+' more':'');
}
function atFilter(){ var q=(document.getElementById('atSearch').value||'').toLowerCase().trim();
  document.querySelectorAll('#atRef tr[data-k]').forEach(function(tr){ tr.style.display=(!q||tr.dataset.k.indexOf(q)>=0)?'':'none'; });
  document.querySelectorAll('#atRef .aref-x span[data-k]').forEach(function(s){ s.style.display=(!q||s.dataset.k.indexOf(q)>=0)?'':'none'; });
  document.querySelectorAll('#atRef .aref-g').forEach(function(g){ var vis=g.querySelectorAll('tr[data-k]:not([style*="none"]), .aref-x span:not([style*="none"])'); g.style.display=vis.length?'':'none'; });
}
api('at_actions',{}).then(function(r){ if(r.ok){ ATREF=r; atRenderRef(); } else document.getElementById('atRef').innerHTML='<div class="empty">could not load reference</div>'; });
atLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'apitokens','name'=>'REST API','desc'=>'API tokens + a command-line tool to automate every panel action, with a full command reference.','feature'=>'enableApi'],
        'pages' => ['apitokens'=>['title'=>'API & Tokens','section'=>'SYSTEM','feature'=>'enableApi','render'=>'apiTokensPage']],
        'api'   => ['at_list'=>'atApiList','at_create'=>'atApiCreate','at_revoke'=>'atApiRevoke','at_actions'=>'atApiActions'],
    ]);
}
