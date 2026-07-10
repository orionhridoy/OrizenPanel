<?php
/*
 * Orizen module: Notifications
 * Alerts to Email / Telegram / Discord on server events (service down, disk full,
 * high CPU/RAM). A 5-minute checker cron evaluates events and sends once per change.
 */
require_once __DIR__ . '/notifylib.php';

function ntSaveConfig(array $notify): bool { $c = cfg(); $c['notify'] = $notify; return saveCfg($c); }

function ntApiGet(): array {
    $c = cfg(); $n = $c['notify'] ?? [];
    // never echo secrets back verbatim beyond what's needed to edit
    return ['ok'=>true, 'notify'=>[
        'enabled'=>(bool)($n['enabled'] ?? false), 'email'=>(bool)($n['email'] ?? false),
        'email_to'=>(string)($n['email_to'] ?? ($c['admin_email'] ?? '')),
        'telegram'=>['token'=>(string)($n['telegram']['token'] ?? ''), 'chat'=>(string)($n['telegram']['chat'] ?? '')],
        'discord'=>['webhook'=>(string)($n['discord']['webhook'] ?? '')],
        'events'=>array_merge(['service'=>true,'disk'=>true,'cpu'=>true,'ram'=>true], (array)($n['events'] ?? [])),
        'disk_pct'=>(int)($n['disk_pct'] ?? 90), 'cpu_pct'=>(int)($n['cpu_pct'] ?? 90), 'ram_pct'=>(int)($n['ram_pct'] ?? 90),
    ]];
}
function ntApiSave(): array {
    $ev = json_decode((string)($_POST['events'] ?? '{}'), true); if (!is_array($ev)) $ev = [];
    $n = [
        'enabled'=>($_POST['enabled'] ?? '')==='1', 'email'=>($_POST['email'] ?? '')==='1',
        'email_to'=>trim((string)($_POST['email_to'] ?? '')),
        'telegram'=>['token'=>trim((string)($_POST['tg_token'] ?? '')), 'chat'=>trim((string)($_POST['tg_chat'] ?? ''))],
        'discord'=>['webhook'=>trim((string)($_POST['discord'] ?? ''))],
        'events'=>['service'=>!empty($ev['service']),'disk'=>!empty($ev['disk']),'cpu'=>!empty($ev['cpu']),'ram'=>!empty($ev['ram'])],
        'disk_pct'=>max(50,min(99,(int)($_POST['disk_pct'] ?? 90))), 'cpu_pct'=>max(50,min(100,(int)($_POST['cpu_pct'] ?? 90))), 'ram_pct'=>max(50,min(99,(int)($_POST['ram_pct'] ?? 90))),
    ];
    if ($n['discord']['webhook'] !== '' && !preg_match('~^https://~i', $n['discord']['webhook'])) return ['ok'=>false,'error'=>'Discord webhook must be an https URL.'];
    if (!ntSaveConfig($n)) return ['ok'=>false,'error'=>'Could not write config.'];
    if (is_file(HELPER)) { $n['enabled'] ? helper('notify-setup', [cfgGet('web_user','www-data')]) : helper('notify-teardown', []); }
    return ['ok'=>true,'msg'=>'Notification settings saved.'];
}
function ntApiTest(): array {
    $only = (string)($_POST['channel'] ?? '');
    $r = ntSend('Test alert', 'This is a test notification from Orizen Panel on '.gethostname().'.', $only);
    return empty($r['sent']) ? ['ok'=>false,'error'=>'Nothing sent - check the channel is filled in and saved.'] : ['ok'=>true,'msg'=>'Sent via: '.implode(', ', $r['sent'])];
}
function ntApiLog(): array {
    $f = DATA_DIR.'/notify/events.log'; $lines = is_file($f) ? array_slice(array_filter(explode("\n", (string)@file_get_contents($f))), -30) : [];
    return ['ok'=>true,'log'=>array_reverse($lines)];
}

function notifyPage(): void { ?>
<?=helpBox('Notifications', 'Get alerted when something needs attention - a service goes down, the disk fills up, or CPU/memory spikes. Choose one or more channels (Email, Telegram, Discord), pick which events matter, and Orizen checks every 5 minutes and messages you once per event (and again when it recovers). All free: your own email server, a Telegram bot, or a Discord webhook.')?>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card">
    <h3>Channels</h3>
    <label class="ck"><input type="checkbox" id="nEnabled"> <b>Enable notifications</b></label>
    <hr class="sep">
    <label class="ck"><input type="checkbox" id="nEmail"> Email</label>
    <input id="nEmailTo" placeholder="you@example.com" class="mb">
    <button class="btn btn-g btn-xs" onclick="nTest('email')">Send test email</button>
    <hr class="sep">
    <b class="sm">Telegram</b> <span class="xs muted">(create a bot with @BotFather, then get your chat id)</span>
    <div class="xs muted" style="margin:2px 0 6px">If you've set up <b>Telegram 2FA</b> in <a href="?page=settings">Settings</a>, alerts use that bot/chat automatically - leave these blank. Fill them only to use a <b>different</b> bot for alerts.</div>
    <input id="nTgToken" placeholder="bot token (optional - blank = use 2FA bot)" class="mb"><input id="nTgChat" placeholder="chat id" class="mb">
    <button class="btn btn-g btn-xs" onclick="nTest('telegram')">Send test to Telegram</button>
    <hr class="sep">
    <b class="sm">Discord</b> <span class="xs muted">(Server Settings -> Integrations -> Webhooks)</span>
    <input id="nDiscord" placeholder="https://discord.com/api/webhooks/..." class="mb">
    <button class="btn btn-g btn-xs" onclick="nTest('discord')">Send test to Discord</button>
  </div>
  <div class="card">
    <h3>Events</h3>
    <label class="ck"><input type="checkbox" id="evService"> Service down (Apache / MariaDB)</label>
    <label class="ck"><input type="checkbox" id="evDisk"> Disk almost full &nbsp;<input id="nDisk" type="number" min="50" max="99" style="width:64px">%</label>
    <label class="ck"><input type="checkbox" id="evCpu"> High CPU &nbsp;<input id="nCpu" type="number" min="50" max="100" style="width:64px">%</label>
    <label class="ck"><input type="checkbox" id="evRam"> High memory &nbsp;<input id="nRam" type="number" min="50" max="99" style="width:64px">%</label>
    <div class="mt"><button class="btn btn-p" onclick="nSave()">Save settings</button></div>
    <hr class="sep">
    <b class="sm">Recent alerts</b>
    <pre class="code" id="nLog" style="max-height:180px;overflow:auto;margin-top:8px">-</pre>
  </div>
</div>
<style>.ck{display:flex;align-items:center;gap:8px;padding:5px 0}.ck input[type=checkbox]{width:16px;height:16px}.sep{border:0;border-top:1px solid var(--border);margin:12px 0}</style>
<script>
function nFill(n){ nEnabled.checked=n.enabled; nEmail.checked=n.email; nEmailTo.value=n.email_to||''; nTgToken.value=n.telegram.token||''; nTgChat.value=n.telegram.chat||''; nDiscord.value=n.discord.webhook||''; evService.checked=n.events.service; evDisk.checked=n.events.disk; evCpu.checked=n.events.cpu; evRam.checked=n.events.ram; nDisk.value=n.disk_pct; nCpu.value=n.cpu_pct; nRam.value=n.ram_pct; }
function nSave(){ var ev={service:evService.checked?1:0,disk:evDisk.checked?1:0,cpu:evCpu.checked?1:0,ram:evRam.checked?1:0};
  api('notify_save',{enabled:nEnabled.checked?'1':'0',email:nEmail.checked?'1':'0',email_to:nEmailTo.value.trim(),tg_token:nTgToken.value.trim(),tg_chat:nTgChat.value.trim(),discord:nDiscord.value.trim(),events:JSON.stringify(ev),disk_pct:nDisk.value,cpu_pct:nCpu.value,ram_pct:nRam.value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); nLoadLog(); }); }
function nTest(ch){ api('notify_test',{channel:ch}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
function nLoadLog(){ api('notify_log',{}).then(function(r){ document.getElementById('nLog').textContent=(r.log&&r.log.length)?r.log.join('\n'):'(no alerts yet)'; }); }
api('notify_get',{}).then(function(r){ if(r.ok) nFill(r.notify); }); nLoadLog();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'notify','name'=>'Notifications','desc'=>'Email / Telegram / Discord alerts for service down, disk full, high CPU/RAM.','feature'=>'enableNotifications'],
        'pages' => ['notify'=>['title'=>'Notifications','section'=>'MONITOR','feature'=>'enableNotifications','render'=>'notifyPage']],
        'api'   => ['notify_get'=>'ntApiGet','notify_save'=>'ntApiSave','notify_test'=>'ntApiTest','notify_log'=>'ntApiLog'],
    ]);
}
