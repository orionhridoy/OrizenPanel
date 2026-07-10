<?php
/*
 * Orizen module: Backup Improvements
 * Scheduled full-server backups (files + all databases) with retention and an
 * optional off-server destination (FTP / FTPS / SFTP / WebDAV). Builds on the
 * built-in Backups; runs from a cron via the validated helper.
 */
function bxCfg(): array {
    $b = cfg()['backupx'] ?? [];
    return ['enabled'=>(bool)($b['enabled'] ?? false), 'freq'=>$b['freq'] ?? 'daily', 'retention'=>(int)($b['retention'] ?? 7),
        'dest'=>['type'=>$b['dest']['type'] ?? '', 'host'=>$b['dest']['host'] ?? '', 'user'=>$b['dest']['user'] ?? '', 'pass'=>$b['dest']['pass'] ?? '', 'path'=>$b['dest']['path'] ?? '/']];
}
function bxList(): array {
    $dir = DATA_DIR.'/backups'; $out = [];
    foreach (glob($dir.'/scheduled-*.tar.gz') ?: [] as $f) $out[] = ['name'=>basename($f), 'size'=>fmtSize(@filesize($f)), 'at'=>date('Y-m-d H:i', @filemtime($f))];
    usort($out, fn($a,$b)=>strcmp($b['name'],$a['name']));
    $log = is_file($dir.'/backupx.log') ? array_reverse(array_slice(array_filter(explode("\n",(string)@file_get_contents($dir.'/backupx.log'))),-10)) : [];
    return ['backups'=>$out, 'log'=>$log];
}
function bxApiGet(): array { return ['ok'=>true, 'cfg'=>bxCfg()] + bxList(); }
function bxApiSave(): array {
    $type = (string)($_POST['dtype'] ?? '');
    if ($type !== '' && !in_array($type, ['ftp','ftps','sftp','webdav'], true)) return ['ok'=>false,'error'=>'Invalid destination type.'];
    $c = cfg();
    $c['backupx'] = [
        'enabled'=>($_POST['enabled'] ?? '')==='1',
        'freq'=>in_array($_POST['freq'] ?? '', ['hourly','daily','weekly'], true) ? $_POST['freq'] : 'daily',
        'retention'=>max(1, min(60, (int)($_POST['retention'] ?? 7))),
        'dest'=>['type'=>$type, 'host'=>trim((string)($_POST['dhost'] ?? '')), 'user'=>trim((string)($_POST['duser'] ?? '')), 'pass'=>(string)($_POST['dpass'] ?? ''), 'path'=>trim((string)($_POST['dpath'] ?? '/')) ?: '/'],
    ];
    if (!saveCfg($c)) return ['ok'=>false,'error'=>'Could not write config.'];
    if (is_file(HELPER)) { $c['backupx']['enabled'] ? helper('backupx-setup', [cfgGet('web_user','www-data')]) : helper('backupx-teardown', []); }
    return ['ok'=>true,'msg'=>'Backup schedule saved.'];
}
function bxApiRun(): array {
    $runner = __DIR__.'/runner.php';
    if (!is_file($runner)) return ['ok'=>false,'error'=>'runner missing'];
    @exec('php '.escapeshellarg($runner).' now 2>&1', $o, $rc);
    $l = bxList();
    return ['ok'=>true,'msg'=>'Backup run complete.','log'=>$l['log'],'backups'=>$l['backups']];
}

function backupxPage(): void { $c = bxCfg(); ?>
<?=helpBox('Scheduled &amp; off-server backups', 'Automatically back up <b>all your websites and databases</b> on a schedule, keep a chosen number of copies (older ones are pruned), and optionally copy each backup to another server (FTP, FTPS, SFTP or WebDAV) so a copy lives off this machine. This adds to the manual <a href="?page=backups">Backups</a> page.')?>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card">
    <h3>Schedule</h3>
    <label class="ck"><input type="checkbox" id="bxEnabled" <?=$c['enabled']?'checked':''?>> <b>Enable scheduled backups</b></label>
    <div class="row mt"><div><label>Frequency</label><select id="bxFreq">
      <?php foreach(['daily'=>'Every day','hourly'=>'Every hour','weekly'=>'Every week'] as $k=>$v):?><option value="<?=$k?>" <?=$c['freq']===$k?'selected':''?>><?=$v?></option><?php endforeach;?>
    </select></div>
    <div><label>Keep last</label><input id="bxRet" type="number" min="1" max="60" value="<?=$c['retention']?>" style="width:80px"> copies</div></div>
    <div class="flex mt"><button class="btn btn-p" onclick="bxSave()">Save schedule</button><button class="btn btn-g" onclick="bxRun()">Back up now</button></div>
  </div>
  <div class="card">
    <h3>Off-server destination (optional)</h3>
    <div class="sm muted mb">Leave type blank to keep backups local only.</div>
    <div class="row"><div><label>Type</label><select id="bxType">
      <option value="">- local only -</option>
      <?php foreach(['ftp'=>'FTP','ftps'=>'FTPS','sftp'=>'SFTP','webdav'=>'WebDAV'] as $k=>$v):?><option value="<?=$k?>" <?=$c['dest']['type']===$k?'selected':''?>><?=$v?></option><?php endforeach;?>
    </select></div>
    <div style="flex:2"><label>Host</label><input id="bxHost" value="<?=h($c['dest']['host'])?>" placeholder="backup.example.com"></div></div>
    <div class="row"><div><label>Username</label><input id="bxUser" value="<?=h($c['dest']['user'])?>"></div>
    <div><label>Password</label><input id="bxPass" type="password" placeholder="<?=$c['dest']['pass']?'(unchanged)':''?>"></div>
    <div><label>Remote path</label><input id="bxPath" value="<?=h($c['dest']['path'])?>" placeholder="/backups"></div></div>
  </div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Scheduled backups</h3></div>
  <table><thead><tr><th>File</th><th>Size</th><th>When</th></tr></thead><tbody id="bxList"><tr><td colspan="3" class="empty">none yet</td></tr></tbody></table>
  <div style="padding:0 18px 16px"><b class="xs muted">Log</b><pre class="code" id="bxLog" style="max-height:140px;overflow:auto;margin-top:6px">-</pre></div>
</div>
<style>.ck{display:flex;align-items:center;gap:8px}.ck input{width:16px;height:16px}</style>
<script>
function bxRender(d){ document.getElementById('bxList').innerHTML=(d.backups&&d.backups.length)?d.backups.map(function(b){return '<tr><td class="mono xs">'+b.name+'</td><td>'+b.size+'</td><td class="xs muted">'+b.at+'</td></tr>';}).join(''):'<tr><td colspan="3" class="empty">none yet</td></tr>'; document.getElementById('bxLog').textContent=(d.log&&d.log.length)?d.log.join('\n'):'(no runs yet)'; }
function bxSave(){ var pass=document.getElementById('bxPass').value; api('backupx_save',{enabled:bxEnabled.checked?'1':'0',freq:bxFreq.value,retention:bxRet.value,dtype:bxType.value,dhost:bxHost.value.trim(),duser:bxUser.value.trim(),dpass:pass,dpath:bxPath.value.trim()}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
function bxRun(){ toast('Running backup...'); api('backupx_run',{}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); bxRender(r); }); }
api('backupx_get',{}).then(function(r){ if(r.ok) bxRender(r); });
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'backupx','name'=>'Backup Improvements','desc'=>'Scheduled full backups, retention, and off-server destinations (FTP/FTPS/SFTP/WebDAV).','feature'=>'enableBackupPlus'],
        'pages' => ['backupx'=>['title'=>'Scheduled Backups','section'=>'FILES','feature'=>'enableBackupPlus','render'=>'backupxPage']],
        'api'   => ['backupx_get'=>'bxApiGet','backupx_save'=>'bxApiSave','backupx_run'=>'bxApiRun'],
    ]);
}
