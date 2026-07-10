<?php
/* Standalone notification library - shared by the panel module and the checker cron. No core deps beyond DATA_DIR. */
if (!defined('DATA_DIR')) define('DATA_DIR', '/opt/orizen/data');

function ntCfg(): array {
    $c = json_decode(@file_get_contents(DATA_DIR.'/config.json') ?: '{}', true) ?: [];
    $n = $c['notify'] ?? [];
    // Reuse the 2FA Telegram bot/chat for notifications when no separate notify token is set,
    // so the operator configures Telegram once (in Settings -> 2FA) and gets alerts too.
    $tgTok = (string)($n['telegram']['token'] ?? ''); $tgChat = (string)($n['telegram']['chat'] ?? '');
    if ($tgTok === '' && !empty($c['tg2fa_enabled']) && !empty($c['tg2fa_token']) && !empty($c['tg2fa_chat'])) {
        $tgTok = (string)$c['tg2fa_token']; $tgChat = (string)$c['tg2fa_chat'];
    }
    return [
        'enabled'  => (bool)($n['enabled'] ?? false),
        'email'    => (bool)($n['email'] ?? false),
        'email_to' => (string)($n['email_to'] ?? ($c['admin_email'] ?? '')),
        'telegram' => ['token'=>$tgTok, 'chat'=>$tgChat],
        'discord'  => ['webhook'=>(string)($n['discord']['webhook'] ?? '')],
        'events'   => array_merge(['service'=>true,'disk'=>true,'cpu'=>true,'ram'=>true], (array)($n['events'] ?? [])),
        'disk_pct' => (int)($n['disk_pct'] ?? 90), 'cpu_pct'=>(int)($n['cpu_pct'] ?? 90), 'ram_pct'=>(int)($n['ram_pct'] ?? 90),
    ];
}
function ntCurlPost(string $url, $body, bool $json = false): bool {
    if (!preg_match('~^https://~i', $url) || !function_exists('curl_init')) return false;
    $ch = curl_init($url); $h = [];
    if ($json) { $body = json_encode($body); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$h, CURLOPT_TIMEOUT=>10]);
    $r = curl_exec($ch); $ok = ($r !== false && curl_errno($ch) === 0); curl_close($ch); return $ok;
}
function ntLogEvent(string $msg): void { $d = DATA_DIR.'/notify'; if (!is_dir($d)) @mkdir($d, 0775, true); @file_put_contents($d.'/events.log', date('c').' '.$msg."\n", FILE_APPEND|LOCK_EX); }
function ntState(): array { $j = json_decode(@file_get_contents(DATA_DIR.'/notify/state.json') ?: '{}', true); return is_array($j) ? $j : []; }
function ntSaveState(array $s): void { $d = DATA_DIR.'/notify'; if (!is_dir($d)) @mkdir($d, 0775, true); @file_put_contents($d.'/state.json', json_encode($s)); }

/** Send to every configured + enabled channel. $only limits to one channel (for the Test button). */
function ntSend(string $subject, string $body, string $only = ''): array {
    $c = ntCfg(); $done = [];
    if (($c['email'] && ($only===''||$only==='email')) && $c['email_to'] !== '') {
        if (@mail($c['email_to'], '[Orizen] '.$subject, $body, 'From: orizen@'.gethostname())) $done[] = 'email';
    }
    if (($only===''||$only==='telegram') && $c['telegram']['token'] !== '' && $c['telegram']['chat'] !== '') {
        if (ntCurlPost('https://api.telegram.org/bot'.$c['telegram']['token'].'/sendMessage', ['chat_id'=>$c['telegram']['chat'], 'text'=>$subject."\n".$body])) $done[] = 'telegram';
    }
    if (($only===''||$only==='discord') && $c['discord']['webhook'] !== '') {
        if (ntCurlPost($c['discord']['webhook'], ['content'=>'**'.$subject.'**'."\n".$body], true)) $done[] = 'discord';
    }
    if ($done) ntLogEvent($subject.' -> '.implode(',', $done));
    return ['sent'=>$done];
}
