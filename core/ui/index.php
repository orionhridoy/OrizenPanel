<?php
/*
 * Orizen Panel - self-hosted web hosting control panel.
 * Deployed by install.sh to /opt/orizen/panel and served on a port over HTTPS.
 * Runs as the web user (www-data); privileged actions go through the root helper via sudo.
 */
@ini_set('display_errors', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// Mark the session cookie Secure when the request arrived over HTTPS (the panel is
// served over TLS). Conditional so a plain-HTTP dev/test setup still works.
if ((($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    ini_set('session.cookie_secure', '1');
}
session_name('orizen');
session_start();
date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

// -- Paths --------------------------------------------------
const DATA_DIR    = '/opt/orizen/data';
const HELPER      = '/usr/local/bin/orizen-helper';
const CONFIG_FILE = DATA_DIR . '/config.json';
const SITES_FILE  = DATA_DIR . '/sites.json';
const MAIL_FILE   = DATA_DIR . '/mail.json';
const REDIRECTS_FILE = DATA_DIR . '/redirects.json';
const SCRIPTS_FILE = DATA_DIR . '/scripts.json';
const BACKUP_DIR  = DATA_DIR . '/backups';
const SESSION_TTL = 7200;
// Build attribution - stored encoded and re-asserted on every render via an
// integrity check, so editing or deleting a visible copy does not change or remove
// the credit. Referenced app-wide through ozAttr(); there is no plain-text copy.
const OZ_A = 'T3Jpb24gSHJpZG95';
const OZ_U = 'aHR0cHM6Ly9mYWNlYm9vay5jb20vb3Jpb24uaHJpZG95';
const OZ_K = 'f2b404ba0fc1ca12';
function ozAttr(): array {
    static $c = null;
    if ($c !== null) return $c;
    $n = base64_decode(OZ_A); $u = base64_decode(OZ_U);
    if (substr(hash('sha256', (string)$n), 0, 16) !== OZ_K) {   // tampered -> re-derive
        $n = base64_decode('T3Jpb24gSHJpZG95'); $u = base64_decode('aHR0cHM6Ly9mYWNlYm9vay5jb20vb3Jpb24uaHJpZG95');
    }
    return $c = ['name' => (string)$n, 'url' => (string)$u];
}

// -- Small helpers ------------------------------------------
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function stripBom(string $s): string { return (substr($s, 0, 3) === "\xEF\xBB\xBF") ? substr($s, 3) : $s; }
function cfg(): array { static $c = null; if ($c === null) { $c = json_decode(stripBom(@file_get_contents(CONFIG_FILE) ?: '{}'), true) ?: []; } return $c; }
function cfgGet(string $k, $d = '') { $c = cfg(); if (array_key_exists($k, $c) && $c[$k] !== null) return $c[$k]; $def = cfgDefaults(); return array_key_exists($k, $def) ? $def[$k] : $d; }
function saveCfg(array $c): bool { return @file_put_contents(CONFIG_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false; }
function loadJson(string $f, $d) { $x = json_decode(stripBom(@file_get_contents($f) ?: 'null'), true); return $x === null ? $d : $x; }
function saveJson(string $f, $d): bool { return @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false; }
function fmtSize($b): string { $b=(float)$b; foreach(['B','KB','MB','GB','TB'] as $u){ if($b<1024) return round($b,$u==='B'?0:2).' '.$u; $b/=1024; } return round($b,2).' PB'; }

// -- Version, feature flags, config self-healing, modules (all additive & backward-compatible) --
const APP_VERSION = '1.1.0';   // fallback if panel/version.json is missing

/** Default config values. Old installs auto-gain any missing keys (cfgMigrate) and cfgGet falls back here, so upgrades never need a reinstall. */
function cfgDefaults(): array {
    return [
        'schema_version' => 2,
        'features' => [
            'enableDocker'        => false,
            'enableMultiPHP'      => false,
            'enableMultiServer'   => false,
            'enableSiteIsolation' => false,
            'enableMonitoring'    => true,
            'enableGitDeploy'     => true,
            'enableRemoteBackup'  => true,
            'enableWebTools'      => true,
            'enableNotifications' => true,
            'enableSecurityPlus'  => true,
            'enableBackupPlus'    => true,
            'enableStaging'       => true,
            'enableOneClick'      => true,
            'enableRuntime'       => false,
            'enableWebServerModes'=> false,
            'enableMailPlus'      => false,
            'enableMigration'     => false,
            'enableAccounts'      => true,
            'enableApi'           => true,
        ],
        'api_tokens'       => [],
        'stats_enabled'    => true,
        'stats_url'        => 'https://worker-throbbing-morning-7bb5.dokalura.workers.dev/',
    ];
}
/** Merge missing defaults into the on-disk config. Best-effort: no-ops silently if the file isn't writable, so it can never block startup. */
function cfgMigrate(): void {
    if (!is_file(CONFIG_FILE) || !is_writable(CONFIG_FILE)) return;
    $c = cfg(); $def = cfgDefaults(); $changed = false;
    foreach ($def as $k => $v) {
        if (!array_key_exists($k, $c)) { $c[$k] = $v; $changed = true; }
        elseif (is_array($v) && is_array($c[$k])) {
            foreach ($v as $sk => $sv) if (!array_key_exists($sk, $c[$k])) { $c[$k][$sk] = $sv; $changed = true; }
        }
    }
    if ((int)($c['schema_version'] ?? 0) < (int)$def['schema_version']) { $c['schema_version'] = $def['schema_version']; $changed = true; }
    if ($changed) saveCfg($c);
}
/** Is an optional feature enabled? */
function feat(string $k): bool {
    $f = cfgGet('features', []); $d = cfgDefaults()['features'];
    return (bool)((is_array($f) && array_key_exists($k, $f)) ? $f[$k] : ($d[$k] ?? false));
}
/** Installed panel version metadata (from version.json shipped with the panel). */
function appVersionInfo(): array {
    static $j = null;
    if ($j === null) { $f = __DIR__.'/version.json'; $j = is_file($f) ? (json_decode(stripBom(@file_get_contents($f) ?: '{}'), true) ?: []) : []; }
    return $j;
}
function appVersion(): string { $i = appVersionInfo(); return !empty($i['version']) ? (string)$i['version'] : APP_VERSION; }
/** Small HTTP(S) GET with timeout; returns body or null. Never throws. $insecure skips TLS verify (for user-trusted agent nodes on self-signed certs). */
function httpGet(string $url, int $timeout = 6, bool $insecure = false): ?string {
    if (!preg_match('~^https?://~i', $url)) return null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>$timeout, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3, CURLOPT_USERAGENT=>'Orizen-Panel/'.appVersion()]);
        if ($insecure) curl_setopt_array($ch, [CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0]);
        $out = curl_exec($ch); $ok = ($out !== false && curl_errno($ch) === 0); curl_close($ch);
        return $ok ? $out : null;
    }
    $sslCtx = $insecure ? ['verify_peer'=>false,'verify_peer_name'=>false] : [];
    $ctx = stream_context_create(['http'=>['timeout'=>$timeout,'ignore_errors'=>true,'user_agent'=>'Orizen-Panel/'.appVersion()], 'ssl'=>$sslCtx]);
    $out = @file_get_contents($url, false, $ctx);
    return $out === false ? null : $out;
}

// -- Module system (additive): drop features in panel/modules/<name>/module.php or panel/plugins/<name>/plugin.php --
$GLOBALS['ORIZEN_MOD'] = ['pages'=>[], 'api'=>[], 'meta'=>[], 'hooks'=>[]];
/** A module registers pages and/or API actions here. See panel/modules/README.md for the contract. */
function moduleRegister(array $def): void {
    $g = &$GLOBALS['ORIZEN_MOD'];
    foreach (($def['pages'] ?? []) as $slug => $p) $g['pages'][$slug] = $p;   // ['title'=>, 'render'=>callable, 'section'=>'SYSTEM', 'feature'=>'enableX'(optional)]
    foreach (($def['api'] ?? []) as $act => $cb) $g['api'][$act] = $cb;        // callable(): array (authed + CSRF)
    foreach (($def['hooks'] ?? []) as $name => $cb) $g['hooks'][$name] = $cb;  // callable(): array (PUBLIC - must self-validate a secret)
    if (!empty($def['meta']['key'])) $g['meta'][$def['meta']['key']] = $def['meta']; // ['key'=>,'name'=>,'desc'=>,'feature'=>]
}
/** Metadata for every installed module (used by Settings to list real, toggleable modules). */
function moduleMeta(): array { return $GLOBALS['ORIZEN_MOD']['meta']; }
/** Dispatch a PUBLIC module webhook (no panel session). The handler MUST validate its own secret. */
function moduleHook(string $name): ?array {
    $cb = $GLOBALS['ORIZEN_MOD']['hooks'][$name] ?? null;
    if (!$cb) return null;
    try { $r = call_user_func($cb); return is_array($r) ? $r : ['ok'=>true]; }
    catch (Throwable $e) { return ['ok'=>false,'error'=>'hook error']; }
}
/** Load every module + plugin once. Failures are logged, never fatal - a broken module can't take down the panel. */
function moduleLoadAll(): void {
    static $done = false; if ($done) return; $done = true;
    foreach (['modules/*/module.php', 'plugins/*/plugin.php'] as $pat) {
        foreach (glob(__DIR__.'/'.$pat) ?: [] as $mf) {
            try { require $mf; } catch (Throwable $e) { @error_log('Orizen module load failed: '.$mf.' - '.$e->getMessage()); }
        }
    }
}
/** Registered module pages allowed by their feature flag. */
function modulePages(): array {
    $out = [];
    foreach ($GLOBALS['ORIZEN_MOD']['pages'] as $slug => $p) if (empty($p['feature']) || feat($p['feature'])) $out[$slug] = $p;
    return $out;
}
/** Dispatch a module API action; null if none registered for it. */
function moduleApi(string $action): ?array {
    $cb = $GLOBALS['ORIZEN_MOD']['api'][$action] ?? null;
    if (!$cb) return null;
    try { $r = call_user_func($cb); return is_array($r) ? $r : ['ok'=>true]; }
    catch (Throwable $e) { return internalFail($e, 'module:'.$action); }
}
function dirSize(string $path, int $max=80000): int {
    $size=0; $n=0;
    try { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) { if ($f->isFile()) $size += (int)$f->getSize(); if (++$n >= $max) break; } } catch (Throwable $e) {}
    return $size;
}
/** Parse the installer's platform.env (WEB_SVC/MARIA_SVC/WEB_USER/FW/...). */
function platformEnv(): array {
    static $e = null;
    if ($e === null) {
        $e = [];
        $f = DATA_DIR . '/platform.env';
        if (is_file($f)) foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
            [$k, $v] = explode('=', $ln, 2); $e[trim($k)] = trim($v);
        }
    }
    return $e;
}
function webSvc(): string { return platformEnv()['WEB_SVC'] ?? (cfgGet('web_svc') ?: 'apache2'); }
function webProc(): string { return webSvc()==='httpd' ? 'httpd' : 'apache2'; }
function mariaSvc(): string { return platformEnv()['MARIA_SVC'] ?? (cfgGet('maria_svc') ?: 'mariadb'); }
/** Is a service running? Authoritative on systemd Linux, with Windows + generic fallbacks. Process lists are memoized so checking several services costs one spawn. */
function svcActive(string $unit, string $proc): bool {
    static $hasSystemctl = null, $winTasks = null, $psComm = null;
    if (!isWin()) {
        if ($hasSystemctl === null) $hasSystemctl = trim(sh('command -v systemctl 2>/dev/null')) !== '';
        if ($hasSystemctl) {
            $st = trim(sh('systemctl is-active '.escapeshellarg($unit).' 2>/dev/null'));
            if ($st !== '') return $st === 'active';
        }
    }
    if (isWin()) {
        $cands = [$proc];
        if (in_array($proc, ['apache2','httpd','apache'], true)) $cands = ['httpd','apache','apache2'];
        if (in_array($proc, ['mysqld','mariadbd','mysql'], true)) $cands = ['mysqld','mariadbd'];
        if ($winTasks === null) $winTasks = sh('tasklist /NH /FO CSV 2>NUL');
        foreach ($cands as $c) if (stripos($winTasks, $c.'.exe') !== false) return true;
        return false;
    }
    if ($psComm === null) $psComm = sh('ps -A -o comm 2>/dev/null');
    return stripos($psComm, $proc) !== false;
}
function isWin(): bool { return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'; }
/** Symbolic permission string e.g. -rw-r--r-- */
function formatPerms(string $path): string {
    if (!file_exists($path)) return '----------';
    $p = fileperms($path);
    $i = is_dir($path) ? 'd' : (is_link($path) ? 'l' : '-');
    $i .= ($p & 0x0100) ? 'r' : '-'; $i .= ($p & 0x0080) ? 'w' : '-'; $i .= ($p & 0x0040) ? 'x' : '-';
    $i .= ($p & 0x0020) ? 'r' : '-'; $i .= ($p & 0x0010) ? 'w' : '-'; $i .= ($p & 0x0008) ? 'x' : '-';
    $i .= ($p & 0x0004) ? 'r' : '-'; $i .= ($p & 0x0002) ? 'w' : '-'; $i .= ($p & 0x0001) ? 'x' : '-';
    return $i;
}
function fileIcon(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    static $cat = null;
    if ($cat === null) $cat = [
        'image'=>['jpg','jpeg','png','gif','webp','svg','ico','bmp'],
        'archive'=>['zip','gz','tar','rar','7z','bz2'],
        'audio'=>['mp3','wav','ogg','flac','m4a'],
        'video'=>['mp4','webm','mkv','mov','avi'],
        'code'=>['php','phtml','js','mjs','ts','jsx','py','rb','go','rs','sh','bash','c','cpp','h','java','json','xml','yml','yaml','sql','css','scss','html','htm'],
        'doc'=>['pdf','doc','docx','xls','xlsx','md','txt','log','csv','ini','conf','env'],
    ];
    $c = 'file'; foreach ($cat as $k=>$exts) { if (in_array($ext,$exts,true)) { $c=$k; break; } }
    $col = ['image'=>'#34d399','archive'=>'#fbbf24','audio'=>'#22d3ee','video'=>'#f472b6','code'=>'#818cf8','doc'=>'#a78bfa','file'=>'#8a7aaa'][$c];
    static $paths = [
        'image'=>'<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5L5 20"/>',
        'archive'=>'<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
        'audio'=>'<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'video'=>'<rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>',
        'code'=>'<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>',
        'doc'=>'<path d="M14 3v5h5M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M9 13h6M9 17h4"/>',
        'file'=>'<path d="M14 3v5h5M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="'.$col.'" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px">'.$paths[$c].'</svg>';
}
/** Directories the file streamer / manager is allowed to touch (managed web content only).
 *  Confines file read/stream so a crafted path can never reach /etc, /root, config.json, etc. */
function fmRoots(): array {
    return array_values(array_filter(array_map('realpath', [
        cfgGet('webroot_base', '/var/www'), '/srv', DATA_DIR . '/scripts', BACKUP_DIR,
    ]), fn($p) => $p !== false && $p !== ''));
}
function fmConfined(string $rf): bool {
    foreach (fmRoots() as $root) if ($rf === $root || strpos($rf, $root . '/') === 0) return true;
    return false;
}
/** Stream a file to the browser (download or inline view). Admin callers only, confined to web roots. */
function fileDownload(string $f): void {
    $rf = realpath($f);
    if (!$rf || !is_file($rf) || !fmConfined($rf)) { http_response_code(404); echo 'Not found'; exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($rf) . '"');
    header('Content-Length: ' . filesize($rf));
    readfile($rf); exit;
}
function fileView(string $f): void {
    $rf = realpath($f);
    if (!$rf || !is_file($rf) || !fmConfined($rf)) { http_response_code(404); echo 'Not found'; exit; }
    $ext = strtolower(pathinfo($rf, PATHINFO_EXTENSION));
    $mimes = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','ico'=>'image/x-icon',
        'pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','txt'=>'text/plain'];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($rf));
    header('Content-Disposition: inline; filename="' . basename($rf) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($rf); exit;
}
/** Recursively copy a path (file or dir). */
function rcopy(string $src, string $dst): bool {
    if (is_file($src)) { @mkdir(dirname($dst), 0755, true); return @copy($src, $dst); }
    if (!is_dir($src)) return false;
    @mkdir($dst, 0755, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) { $t = $dst . '/' . $it->getSubPathName(); $f->isDir() ? @mkdir($t, 0755, true) : @copy($f->getPathname(), $t); }
    return true;
}
/** Recursively delete a path. */
function rdelete(string $p): bool {
    if (is_file($p) || is_link($p)) return @unlink($p);
    if (!is_dir($p)) return false;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    return @rmdir($p);
}

/** Run the privileged helper. Returns ['code'=>int,'out'=>string]. */
function helper(string $action, array $args = []): array {
    $cmd = 'sudo ' . escapeshellarg(HELPER) . ' ' . escapeshellarg($action);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    $cmd .= ' 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** Run a plain shell command as the web user. */
function sh(string $cmd): string { $o=[]; @exec($cmd . ' 2>&1', $o); return implode("\n", $o); }

/** Cloudflare API (optional full DNS automation). */
function cfApi(string $method, string $path, ?array $body = null): array {
    $token = cfPrimaryToken(); if (!$token || !function_exists('curl_init')) return ['ok'=>false,'error'=>'no token'];
    $ch = curl_init('https://api.cloudflare.com/client/v4' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token, 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($resp === false) return ['ok'=>false,'error'=>$err ?: 'request failed'];
    $j = json_decode($resp, true) ?: [];
    return ['ok'=>!empty($j['success']), 'data'=>$j['result'] ?? null, 'error'=>$j['errors'][0]['message'] ?? 'Cloudflare error'];
}
/** Create/update A records (@ and www) for a domain in Cloudflare as DNS-only (grey cloud).
 *  We do NOT enable the Cloudflare proxy / DDoS protection - the user can turn that on
 *  themselves in Cloudflare if they want it. HTTPS is handled by Let's Encrypt on the server. */
function cfSetA(string $domain, string $ip): array {
    $z = cfResolveZone($domain);
    if (!$z) return ['ok'=>false,'error'=>'domain not in your Cloudflare account - add it to Cloudflare first'];
    $tok = $z['token']; $zid = $z['zoneId'];
    $names = ($domain === $z['zoneName']) ? [$domain, 'www.' . $domain] : [$domain];
    foreach ($names as $name) {
        $ex = cfApiTok($tok, 'GET', "/zones/$zid/dns_records?type=A&name=" . urlencode($name));
        $rec = ['type'=>'A','name'=>$name,'content'=>$ip,'ttl'=>120,'proxied'=>false];
        if (!empty($ex['data'][0]['id'])) cfApiTok($tok, 'PUT', "/zones/$zid/dns_records/" . $ex['data'][0]['id'], $rec);
        else cfApiTok($tok, 'POST', "/zones/$zid/dns_records", $rec);
    }
    return ['ok'=>true];
}
/** Resolve a domain (or subdomain) to its Cloudflare zone id, or null. */
function cfZoneId(string $domain): ?string {
    $cands = [$domain];
    $parts = explode('.', $domain);
    if (count($parts) > 2) $cands[] = implode('.', array_slice($parts, -2));
    foreach (array_unique($cands) as $cand) {
        $z = cfApi('GET', '/zones?name=' . urlencode($cand));
        if ($z['ok'] && !empty($z['data'][0]['id'])) return $z['data'][0]['id'];
    }
    return null;
}

// -- Cloudflare: multiple API tokens (one per account/website) ----------------
/** Every configured Cloudflare token: the legacy single cf_token plus each entry
 *  in cf_accounts[] ({id,label,token}). Lets several sites on different Cloudflare
 *  accounts each be managed. */
function cfTokens(): array {
    $out = [];
    $legacy = (string)cfgGet('cf_token', '');
    if ($legacy !== '') $out[] = ['id' => 'default', 'label' => 'Default', 'token' => $legacy];
    foreach ((array)cfgGet('cf_accounts', []) as $a) {
        if (!empty($a['token'])) {
            $out[] = [
                'id'    => (string)($a['id'] ?? substr(md5((string)$a['token']), 0, 8)),
                'label' => (string)($a['label'] ?? 'Cloudflare'),
                'token' => (string)$a['token'],
            ];
        }
    }
    return $out;
}
/** First available Cloudflare token (legacy cf_token wins, else first account). */
function cfPrimaryToken(): string {
    $t = cfTokens();
    return $t ? (string)$t[0]['token'] : '';
}
/** True if at least one Cloudflare token is connected. */
function cfConnected(): bool { return cfPrimaryToken() !== ''; }
/** Cloudflare AUTOMATION (auto DNS / SSL / email records) is active only when a token
 *  is connected AND the operator has not chosen manual mode. Cloudflare is always an
 *  ENHANCEMENT, never mandatory: in manual mode every action still works - it just falls
 *  back to the traditional "here are the DNS records to set yourself" flow. */
function cfAutoEnabled(): bool { return cfConnected() && cfgGet('cf_mode', 'auto') !== 'manual'; }
/** Create or update a single DNS record idempotently (no duplicates). $match lets SPF
 *  target the existing spf TXT by content-prefix instead of clobbering other TXT records. */
function cfUpsertRecord(string $tok, string $zid, string $type, string $name, string $content, array $extra = [], string $match = ''): bool {
    $list = cfApiTok($tok, 'GET', "/zones/$zid/dns_records?type=$type&name=" . urlencode($name) . "&per_page=100");
    $existing = null;
    if (!empty($list['data'])) {
        foreach ($list['data'] as $r) {
            if ($match === '' || (isset($r['content']) && strpos($r['content'], $match) === 0)) { $existing = $r; break; }
        }
    }
    $body = array_merge(['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => 1], $extra);
    if ($existing) {
        // Already correct? Skip to avoid a needless write.
        if (($existing['content'] ?? '') === $content && (int)($existing['priority'] ?? ($extra['priority'] ?? 0)) === (int)($extra['priority'] ?? 0)) return true;
        $r = cfApiTok($tok, 'PUT', "/zones/$zid/dns_records/" . $existing['id'], $body);
        return !empty($r['ok']);
    }
    $r = cfApiTok($tok, 'POST', "/zones/$zid/dns_records", $body);
    return !empty($r['ok']);
}
/** Auto-configure the email DNS records (MX, SPF, DKIM, DMARC) for a Cloudflare-managed
 *  domain. Idempotent: creates what is missing, updates safely, never duplicates. */
function cfEnsureEmailDns(string $domain): array {
    $z = cfResolveZone($domain);
    if (!$z) return ['ok' => false, 'error' => 'not on Cloudflare'];
    $tok = $z['token']; $zid = $z['zoneId'];
    $ip = (string)cfgGet('server_ip');
    // Mail host must be a real FQDN under (or resolving to) this server. A blank/misconfigured
    // primary_domain used to collapse this to "mail." which produced an invalid "MX 10 mail.".
    // Fall back to a self-contained mail.<domain> so every mail domain works on its own.
    $mailHost = (string)cfgGet('mail_host', '');
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $mailHost)) {
        $mailHost = 'mail.' . $domain;
    }
    $done = [];
    // A record for the mail host itself -> this server, DNS-only (grey cloud). Without it the
    // MX target does not resolve, so inbound mail never reaches the server. Cloudflare never
    // proxies SMTP, so this record must not be orange-clouded.
    if ($ip && cfUpsertRecord($tok, $zid, 'A', $mailHost, $ip, ['proxied' => false])) $done[] = 'A';
    // MX -> mail host (priority 10)
    if (cfUpsertRecord($tok, $zid, 'MX', $domain, $mailHost, ['priority' => 10])) $done[] = 'MX';
    // SPF (match the existing v=spf1 TXT so other TXT records are untouched)
    if (cfUpsertRecord($tok, $zid, 'TXT', $domain, 'v=spf1 a mx' . ($ip ? ' ip4:' . $ip : '') . ' ~all', [], 'v=spf1')) $done[] = 'SPF';
    // DMARC
    $dmarc = 'v=DMARC1; p=quarantine; rua=mailto:' . cfgGet('admin_email', 'admin@' . $domain);
    if (cfUpsertRecord($tok, $zid, 'TXT', '_dmarc.' . $domain, $dmarc, [], 'v=DMARC1')) $done[] = 'DMARC';
    // DKIM (only if opendkim has generated the key; else it is set on a later run)
    $dk = helper('get-dkim', [$domain]); $dkim = trim((string)($dk['out'] ?? ''));
    if ($dkim && $dkim !== 'NO_DKIM_KEY' && preg_match('/p=([A-Za-z0-9+\/=\s"]+)/', $dkim, $mm)) {
        $p = preg_replace('/["\s]/', '', $mm[1]);
        if ($p !== '' && cfUpsertRecord($tok, $zid, 'TXT', 'mail._domainkey.' . $domain, 'v=DKIM1; k=rsa; p=' . $p, [], 'v=DKIM1')) $done[] = 'DKIM';
    }
    return ['ok' => true, 'zone' => $z['zoneName'], 'records' => $done, 'dkim_pending' => !in_array('DKIM', $done, true)];
}

/** Cloudflare API call with an explicit token. */
function cfApiTok(string $token, string $method, string $path, ?array $body = null): array {
    if (!$token || !function_exists('curl_init')) return ['ok' => false, 'error' => 'no token'];
    $ch = curl_init('https://api.cloudflare.com/client/v4' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => $err ?: 'request failed'];
    $j = json_decode($resp, true) ?: [];
    return ['ok' => !empty($j['success']), 'data' => $j['result'] ?? null, 'error' => $j['errors'][0]['message'] ?? 'Cloudflare error'];
}
/** Find which connected token owns the zone for $domain. Returns
 *  ['token'=>,'zoneId'=>,'zoneName'=>,'label'=>] or null. */
function cfResolveZone(string $domain): ?array {
    $cands = [$domain];
    $parts = explode('.', $domain);
    if (count($parts) > 2) $cands[] = implode('.', array_slice($parts, -2));
    $cands = array_unique($cands);
    foreach (cfTokens() as $acct) {
        foreach ($cands as $cand) {
            $z = cfApiTok($acct['token'], 'GET', '/zones?name=' . urlencode($cand));
            if ($z['ok'] && !empty($z['data'][0]['id'])) {
                return ['token' => $acct['token'], 'zoneId' => $z['data'][0]['id'],
                        'zoneName' => $z['data'][0]['name'], 'label' => $acct['label']];
            }
        }
    }
    return null;
}
/** One-click "full HTTPS via Cloudflare": point the (sub)domain at $ip as a
 *  PROXIED (orange-cloud) A record and force HTTPS at the edge. Cloudflare then
 *  serves a free, auto-renewing certificate for the life of the site. */
function cfOnboardSite(string $domain, string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['ok' => false, 'error' => 'server IP not set'];
    $z = cfResolveZone($domain);
    if (!$z) return ['ok' => false, 'error' => 'That domain is not in any connected Cloudflare account. Add the domain to Cloudflare (or connect the right API token) first.'];
    $tok = $z['token']; $zid = $z['zoneId'];
    $names = ($domain === $z['zoneName']) ? [$domain, 'www.' . $domain] : [$domain];
    foreach ($names as $name) {
        $rec = ['type' => 'A', 'name' => $name, 'content' => $ip, 'ttl' => 1, 'proxied' => true];
        $ex = cfApiTok($tok, 'GET', "/zones/$zid/dns_records?type=A&name=" . urlencode($name));
        if (!empty($ex['data'][0]['id'])) cfApiTok($tok, 'PUT', "/zones/$zid/dns_records/" . $ex['data'][0]['id'], $rec);
        else cfApiTok($tok, 'POST', "/zones/$zid/dns_records", $rec);
    }
    // Full: Cloudflare connects to the origin over HTTPS (self-signed accepted),
    // and always upgrades visitors to HTTPS.
    cfApiTok($tok, 'PATCH', "/zones/$zid/settings/ssl", ['value' => 'full']);
    cfApiTok($tok, 'PATCH', "/zones/$zid/settings/always_use_https", ['value' => 'on']);
    cfApiTok($tok, 'PATCH', "/zones/$zid/settings/automatic_https_rewrites", ['value' => 'on']);
    return ['ok' => true, 'zone' => $z['zoneName'], 'label' => $z['label'], 'proxied' => true];
}
/** Remove the DNS records (A/AAAA/CNAME, plus www at apex) a site/subdomain created,
 *  so deleting a domain also cleans up its Cloudflare DNS. Best-effort. */
function cfDnsDelete(string $domain): array {
    $z = cfResolveZone($domain);
    if (!$z) return ['ok' => false, 'error' => 'no zone', 'removed' => 0];
    $tok = $z['token']; $zid = $z['zoneId'];
    $names = ($domain === $z['zoneName']) ? [$domain, 'www.' . $domain] : [$domain];
    $removed = 0;
    foreach ($names as $name) {
        foreach (['A', 'AAAA', 'CNAME'] as $type) {
            $ex = cfApiTok($tok, 'GET', "/zones/$zid/dns_records?type=$type&name=" . urlencode($name));
            if (!empty($ex['data'])) foreach ($ex['data'] as $rec) {
                if (!empty($rec['id'])) { cfApiTok($tok, 'DELETE', "/zones/$zid/dns_records/" . $rec['id']); $removed++; }
            }
        }
    }
    return ['ok' => true, 'removed' => $removed];
}
/** Change a Cloudflare zone setting (ssl / always_use_https / automatic_https_rewrites). */
function cfSetSetting(string $domain, string $key, $value): array {
    $z = cfResolveZone($domain);
    if (!$z) return ['ok' => false, 'error' => 'That domain is not in any connected Cloudflare account.'];
    $r = cfApiTok($z['token'], 'PATCH', "/zones/{$z['zoneId']}/settings/$key", ['value' => $value]);
    if (!$r['ok']) return ['ok' => false, 'error' => ($r['error'] ?: 'Cloudflare rejected the change') . ' - your API token needs "Zone Settings: Edit" (and "SSL and Certificates: Edit") for this zone.'];
    return ['ok' => true];
}
/** Set the proxy (orange/grey cloud) on a domain's A records. */
function cfSetProxied(string $domain, bool $proxied): array {
    $z = cfResolveZone($domain);
    if (!$z) return ['ok' => false, 'error' => 'That domain is not in any connected Cloudflare account.'];
    $tok = $z['token']; $zid = $z['zoneId'];
    $names = ($domain === $z['zoneName']) ? [$domain, 'www.' . $domain] : [$domain];
    $touched = 0;
    foreach ($names as $name) {
        $ex = cfApiTok($tok, 'GET', "/zones/$zid/dns_records?type=A&name=" . urlencode($name));
        if (!empty($ex['data'][0]['id'])) {
            $rec = $ex['data'][0];
            cfApiTok($tok, 'PATCH', "/zones/$zid/dns_records/" . $rec['id'], ['proxied' => $proxied]);
            $touched++;
        }
    }
    return $touched ? ['ok' => true] : ['ok' => false, 'error' => 'No A record found for that domain in Cloudflare.'];
}

// -- Webmail (IMAP) helpers ---------------------------------
function wmAvailable(): bool { return function_exists('imap_open'); }
/** Open an IMAP connection for the signed-in webmail user, or null. */
function wmConn() {
    if (!wmAvailable() || empty($_SESSION['wm'])) return null;
    $host = cfgGet('imap_host', 'localhost');
    $imap = @imap_open('{'.$host.':993/imap/ssl/novalidate-cert}INBOX', $_SESSION['wm']['email'], $_SESSION['wm']['pass'], 0, 1);
    @imap_errors(); @imap_alerts();
    return $imap ?: null;
}
function wmDecode(string $s, int $enc): string { if ($enc === 3) return base64_decode($s); if ($enc === 4) return quoted_printable_decode($s); return $s; }
/** Best-effort plain-text body of a message (handles simple + multipart/alternative). */
function wmGetBody($imap, int $uid): string {
    $st = @imap_fetchstructure($imap, $uid, FT_UID);
    if ($st && !empty($st->parts) && is_array($st->parts)) {
        foreach ($st->parts as $i => $p) if (strtoupper($p->subtype ?? '') === 'PLAIN')
            return wmDecode(imap_fetchbody($imap, $uid, (string)($i + 1), FT_UID), (int)($p->encoding ?? 0));
        foreach ($st->parts as $i => $p) if (strtoupper($p->subtype ?? '') === 'HTML')
            return trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", wmDecode(imap_fetchbody($imap, $uid, (string)($i + 1), FT_UID), (int)($p->encoding ?? 0))), ENT_QUOTES)));
    }
    return wmDecode(imap_body($imap, $uid, FT_UID), (int)($st->encoding ?? 0));
}
function wmAddr($a): string { if (empty($a[0])) return ''; $e = ($a[0]->mailbox ?? '').'@'.($a[0]->host ?? ''); $n = trim(imap_utf8($a[0]->personal ?? '')); return $n !== '' ? "$n <$e>" : $e; }

$GLOBALS['__dberr'] = '';
function db(): ?PDO {
    static $pdo = null; static $tried = false;
    if ($tried) return $pdo; $tried = true;
    $user = (string)cfgGet('db_user'); $pass = (string)cfgGet('db_pass');
    if ($user === '') { $GLOBALS['__dberr'] = 'No database user in config (' . CONFIG_FILE . '). Did the installer finish?'; return null; }
    // Try the configured host, then socket (localhost), then TCP - most robust across distros.
    $hosts = array_values(array_unique(array_filter([cfgGet('db_host','localhost'), 'localhost', '127.0.0.1'])));
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5];
    foreach ($hosts as $h) {
        try { $pdo = new PDO("mysql:host=$h;charset=utf8mb4", $user, $pass, $opts); $GLOBALS['__dberr'] = ''; return $pdo; }
        catch (Exception $e) { $GLOBALS['__dberr'] = $e->getMessage(); }
    }
    $pdo = null; return null;
}
function dbError(): string { return $GLOBALS['__dberr'] ?? ''; }
function qIdent(string $s): string { return '`' . str_replace('`','``',$s) . '`'; }
const PAGER_ROWS = 50;
/** A PDO bound to a specific database, reusing the panel's stored credentials. */
function sqlPdo(string $dbname = ''): ?PDO {
    $base = db(); if (!$base) return null;
    if ($dbname === '') return $base;
    $user = (string)cfgGet('db_user'); $pass = (string)cfgGet('db_pass');
    $hosts = array_values(array_unique(array_filter([cfgGet('db_host','localhost'), 'localhost', '127.0.0.1'])));
    $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>5, PDO::ATTR_EMULATE_PREPARES=>false];
    foreach ($hosts as $hh) { try { return new PDO("mysql:host=$hh;dbname=$dbname;charset=utf8mb4", $user, $pass, $opts); } catch (Exception $e) { $GLOBALS['__dberr']=$e->getMessage(); } }
    return null;
}
/** Primary-key column name for a table (empty if none). */
function sqlPk(PDO $pdo, string $table): string {
    try { $r = $pdo->query("SHOW KEYS FROM ".qIdent($table)." WHERE Key_name='PRIMARY'")->fetch(); return $r['Column_name'] ?? ''; }
    catch (Exception $e) { return ''; }
}
/**
 * Stream a (possibly huge) .sql file and execute it statement-by-statement.
 * Handles DELIMITER directives (stored routines/triggers), line + block comments,
 * quoted strings (backslash + doubled-quote escapes) and backtick identifiers.
 * Reads line-by-line so memory stays low regardless of file size.
 */
function streamImportSql(PDO $pdo, string $path): array {
    $fh = @fopen($path, 'rb');
    if (!$fh) return ['executed'=>0,'errors'=>1,'error_msgs'=>['Cannot open the uploaded file']];
    $delim = ';'; $buf = ''; $executed = 0; $err = 0; $errors = [];
    $inStr = false; $sChar = ''; $esc = false; $inBlock = false;
    $run = function () use ($pdo, &$buf, &$executed, &$err, &$errors) {
        $stmt = trim($buf); $buf = '';
        if ($stmt === '') return;
        try { $pdo->exec($stmt); $executed++; }
        catch (Throwable $e) { $err++; if (count($errors) < 8) $errors[] = $e->getMessage(); }
    };
    $first = true;
    while (($line = fgets($fh)) !== false) {
        if ($first) { if (substr($line,0,3) === "\xEF\xBB\xBF") $line = substr($line,3); $first = false; }
        if ($inBlock) { $pos = strpos($line,'*/'); if ($pos === false) continue; $line = substr($line,$pos+2); $inBlock = false; }
        if (!$inStr && trim($buf) === '' && preg_match('/^\s*DELIMITER\s+(\S+)/i', $line, $m)) { $delim = $m[1]; continue; }
        $len = strlen($line); $dlen = strlen($delim); $i = 0;
        while ($i < $len) {
            $c = $line[$i];
            if ($inStr) {
                $buf .= $c;
                if ($esc) $esc = false;
                elseif ($c === '\\') $esc = true;
                elseif ($c === $sChar) { if ($i+1 < $len && $line[$i+1] === $sChar) { $buf .= $line[$i+1]; $i++; } else $inStr = false; }
                $i++; continue;
            }
            if ($c === '/' && $i+1 < $len && $line[$i+1] === '*') { $end = strpos($line,'*/',$i+2); if ($end === false) { $inBlock = true; break; } $i = $end+2; continue; }
            if ($c === '#') break;
            if ($c === '-' && $i+1 < $len && $line[$i+1] === '-' && ($i+2 >= $len || ctype_space($line[$i+2]))) break;
            if ($c === "'" || $c === '"') { $inStr = true; $sChar = $c; $esc = false; $buf .= $c; $i++; continue; }
            if ($c === '`') { $buf .= $c; $i++; while ($i < $len) { $bc = $line[$i]; $buf .= $bc; $i++; if ($bc === '`') break; } continue; }
            if ($c === $delim[0] && substr($line,$i,$dlen) === $delim) { $run(); $i += $dlen; continue; }
            $buf .= $c; $i++;
        }
    }
    $run(); fclose($fh);
    return ['executed'=>$executed,'errors'=>$err,'error_msgs'=>$errors];
}
/** Write a full SQL dump (schema + data) of a database to a file, low-memory (streamed). */
function writeDbDump(string $db, string $path): bool {
    $pdo = sqlPdo($db); if (!$pdo) return false;
    $fh = @fopen($path, 'wb'); if (!$fh) return false;
    fwrite($fh, "-- Orizen Panel backup of `$db` - ".date('c')."\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    try {
        foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $tb) {
            $qt = qIdent($tb);
            fwrite($fh, "DROP TABLE IF EXISTS $qt;\n".$pdo->query("SHOW CREATE TABLE $qt")->fetchColumn(1).";\n\n");
            $off = 0;
            while (true) {
                $rows = $pdo->query("SELECT * FROM $qt LIMIT 500 OFFSET $off")->fetchAll();
                if (!$rows) break;
                foreach ($rows as $row) {
                    $c = implode(', ', array_map('qIdent', array_keys($row)));
                    $v = implode(', ', array_map(fn($x)=>$x===null?'NULL':$pdo->quote($x), array_values($row)));
                    fwrite($fh, "INSERT INTO $qt ($c) VALUES ($v);\n");
                }
                $off += 500;
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh); return true;
    } catch (Exception $e) { fclose($fh); return false; }
}
function validDomain(string $s): bool { return (bool)preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $s) && strlen($s) <= 253; }
function validLabel(string $s): bool { return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $s); }
function validIdent(string $s): bool { return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $s); }
function validEmail(string $s): bool { return (bool)filter_var($s, FILTER_VALIDATE_EMAIL); }

// -- Auth ---------------------------------------------------
function isAuthed(): bool {
    if (empty($_SESSION['auth'])) return false;
    if (time() - ($_SESSION['t'] ?? 0) > SESSION_TTL) { session_destroy(); return false; }
    $_SESSION['t'] = time(); return true;
}
function csrf(): string { return $_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(16))); }
function checkCsrf(): bool { return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''); }

// -- Multi-user auth: admin + hosting accounts (role-scoped) --
function curUser(): string { return $_SESSION['user'] ?? cfgGet('admin_user',''); }
function curRole(): string { return $_SESSION['role'] ?? 'admin'; }   // no session role (e.g. API token) = admin-level
function isAdmin(): bool { return curRole() === 'admin'; }
/** Verify username+password against the admin OR a hosting account. Returns ['role'=>,'user'=>] or null. */
function authCreds(string $u, string $p): ?array {
    if ($u === cfgGet('admin_user') && cfgGet('admin_hash') && password_verify($p, cfgGet('admin_hash'))) return ['role'=>'admin','user'=>$u];
    $acc = loadJson(DATA_DIR.'/accounts.json', []); $list = is_array($acc['accounts'] ?? null) ? $acc['accounts'] : [];
    foreach ($list as $a) if (($a['username']??'')===$u && !empty($a['pass_hash']) && password_verify($p, $a['pass_hash'])) {
        if (!empty($a['suspended'])) return null;   // suspended accounts cannot log in
        return ['role'=>($a['role']??'user'), 'user'=>$u];
    }
    return null;
}
/** Pages a non-admin (reseller/user) may open. Everything else is admin-only (deny by default). */
function userAllowedPages(): array {
    $base = ['dashboard','websites','ssl','dns','webmail'];
    if (curRole() === 'reseller') $base[] = 'accounts';
    return $base;
}
function pageAllowed(string $page): bool { return isAdmin() ? true : in_array($page, userAllowedPages(), true); }
/** Domains owned by the current (non-admin) account. */
function userOwnedDomains(): array { $u = curUser(); $o = []; foreach (loadJson(SITES_FILE, []) as $s) if (($s['owner']??'')===$u) $o[] = strtolower((string)($s['domain']??'')); return array_values(array_filter($o)); }
/** API guard for non-admin accounts: deny-by-default, then scope domain actions to owned domains. Returns an error string, or '' if allowed. */
function userApiGuard(string $action): string {
    $safe = ['dash_stats','dash_cpu','acc_data','ssl_issue','dns_check',
        'webmail_login','webmail_logout','webmail_list','webmail_read','webmail_delete','webmail_send'];
    if (curRole() === 'reseller') $safe = array_merge($safe, ['acc_create','acc_update','acc_setpass','acc_suspend','acc_delete','acc_assign_site']);   // resellers manage their own sub-accounts (module enforces ownership)
    if (!in_array($action, $safe, true)) return 'That action is not available for your account. Contact the administrator.';
    if (in_array($action, ['ssl_issue','dns_check'], true)) {
        $dom = strtolower((string)($_POST['domain'] ?? ''));
        if ($dom !== '' && !in_array($dom, userOwnedDomains(), true)) return 'You can only manage your own domains.';
    }
    return '';
}

// -- API tokens (REST/CLI automation) --
function apiTokens(): array { $t = cfgGet('api_tokens', []); return is_array($t) ? $t : []; }
function apiTokenValid(string $tok): ?array {
    if ($tok === '') return null; $h = hash('sha256', $tok);
    foreach (apiTokens() as $t) if (!empty($t['hash']) && hash_equals((string)$t['hash'], $h)) return $t;
    return null;
}
function apiBearer(): string {
    $a = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($a && preg_match('/Bearer\s+(\S+)/i', $a, $m)) return $m[1];
    // Accept a POST body token, but NEVER from the URL query string ($_GET) - tokens in
    // URLs leak into access logs, browser history and Referer headers.
    return (string)($_POST['api_token'] ?? '');
}

// -- Login audit, brute-force lockout & TOTP 2FA (used by the Security+ module; harmless no-ops when unused) --
function ipAddr(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function auditLog(string $event, string $detail = ''): void {
    @file_put_contents(DATA_DIR.'/audit.log', date('c').' '.ipAddr().' '.$event.($detail!==''?' '.preg_replace('/\s+/',' ',$detail):'')."\n", FILE_APPEND|LOCK_EX);
}
function loginLocked(): int { $e = (loadJson(DATA_DIR.'/loginfails.json', [])[ipAddr()] ?? null); return ($e && ($e['until'] ?? 0) > time()) ? (int)$e['until'] - time() : 0; }
function loginFail(): void {
    $f = DATA_DIR.'/loginfails.json'; $d = loadJson($f, []); if (!is_array($d)) $d = []; $ip = ipAddr(); $now = time();
    $e = $d[$ip] ?? ['count'=>0,'first'=>$now,'until'=>0];
    if ($now - ($e['first'] ?? $now) > 900) $e = ['count'=>0,'first'=>$now,'until'=>0];
    $e['count']++; if ($e['count'] >= 6) $e['until'] = $now + 900;
    $d[$ip] = $e;
    foreach ($d as $k=>$v) if (($v['until'] ?? 0) < $now && ($now - ($v['first'] ?? 0)) > 3600) unset($d[$k]);
    saveJson($f, $d);
}
function loginReset(): void { $f = DATA_DIR.'/loginfails.json'; $d = loadJson($f, []); if (is_array($d)) { unset($d[ipAddr()]); saveJson($f, $d); } }
function base32Decode(string $b32): string {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/','',$b32)); $bits = '';
    for ($i=0;$i<strlen($b32);$i++){ $v=strpos($map,$b32[$i]); if($v===false)continue; $bits.=str_pad(decbin($v),5,'0',STR_PAD_LEFT); }
    $out=''; for($i=0;$i+8<=strlen($bits);$i+=8) $out.=chr(bindec(substr($bits,$i,8))); return $out;
}
function base32Encode(string $data): string {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
    for ($i=0;$i<strlen($data);$i++) $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    $out = ''; for ($i=0;$i<strlen($bits);$i+=5) { $c = substr($bits,$i,5); if (strlen($c)<5) $c = str_pad($c,5,'0'); $out .= $map[bindec($c)]; }
    return $out;
}
function totpAt(string $secret, int $counter): string {
    $key = base32Decode($secret); if ($key === '') return '';
    $h = hash_hmac('sha1', pack('N*',0).pack('N*',$counter), $key, true); $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o])&0x7f)<<24)|((ord($h[$o+1])&0xff)<<16)|((ord($h[$o+2])&0xff)<<8)|(ord($h[$o+3])&0xff);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
function totpVerify(string $secret, string $code): bool {
    $code = preg_replace('/\D/','',$code); if (strlen($code) !== 6 || $secret === '') return false;
    $t = (int)floor(time()/30); for ($w=-1;$w<=1;$w++) if (hash_equals(totpAt($secret,$t+$w),$code)) return true; return false;
}

// -- Login captcha (self-contained SVG, no third-party service) --
function captchaNew(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // omit ambiguous 0/O/1/I
    $code = ''; for ($i=0;$i<5;$i++) $code .= $chars[random_int(0, strlen($chars)-1)];
    $_SESSION['captcha'] = $code; $_SESSION['captcha_t'] = time();
    return $code;
}
function captchaCheck(string $in): bool {
    $want = (string)($_SESSION['captcha'] ?? ''); $t = (int)($_SESSION['captcha_t'] ?? 0);
    unset($_SESSION['captcha'], $_SESSION['captcha_t']);   // single-use
    if ($want === '' || (time() - $t) > 600) return false;
    return strtoupper(preg_replace('/\s+/','',$in)) === $want;
}
function captchaSvg(string $code): string {
    $w=160; $hgt=54; $n=strlen($code);
    $s='<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$hgt.'" viewBox="0 0 '.$w.' '.$hgt.'" role="img" aria-label="captcha">';
    $s.='<rect width="100%" height="100%" fill="#f1f5f9"/>';
    for ($i=0;$i<5;$i++) $s.='<line x1="'.random_int(0,$w).'" y1="'.random_int(0,$hgt).'" x2="'.random_int(0,$w).'" y2="'.random_int(0,$hgt).'" stroke="#cbd5e1" stroke-width="1"/>';
    $x=18;
    for ($i=0;$i<$n;$i++) {
        $rot=random_int(-24,24); $y=random_int(33,40); $fs=random_int(26,33);
        $s.='<text x="'.$x.'" y="'.$y.'" font-family="\'JetBrains Mono\',monospace" font-size="'.$fs.'" font-weight="700" fill="#0f172a" transform="rotate('.$rot.' '.$x.' '.$y.')">'.h($code[$i]).'</text>';
        $x += 26;
    }
    for ($i=0;$i<45;$i++) $s.='<circle cx="'.random_int(0,$w).'" cy="'.random_int(0,$hgt).'" r="1" fill="#94a3b8"/>';
    return $s.'</svg>';
}

// -- Telegram helper + 2FA second-factor (used by staged login; config set in Security+) --
function tgSendMessage(string $token, string $chat, string $text): bool {
    if ($token === '' || $chat === '' || !function_exists('curl_init')) return false;
    $ch = curl_init('https://api.telegram.org/bot'.$token.'/sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8,
        CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$chat, 'text'=>$text, 'disable_web_page_preview'=>'true'])]);
    $out = curl_exec($ch); curl_close($ch);
    return $out !== false && strpos((string)$out, '"ok":true') !== false;
}
/** 2FA applies to the admin login only. Returns ['totp'=>bool,'tg'=>bool]. */
function twofaMethods(string $role): array {
    if ($role !== 'admin') return ['totp'=>false,'tg'=>false];
    return [
        'totp' => (bool)cfgGet('totp_enabled') && cfgGet('totp_secret') !== '',
        'tg'   => (bool)cfgGet('tg2fa_enabled') && cfgGet('tg2fa_token','') !== '' && cfgGet('tg2fa_chat','') !== '',
    ];
}
function twofaSendTelegram(): bool {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['2fa_tg_code'] = $code; $_SESSION['2fa_tg_t'] = time();
    return tgSendMessage((string)cfgGet('tg2fa_token',''), (string)cfgGet('tg2fa_chat',''),
        "Orizen Panel login code: $code\nValid for 5 minutes. If this wasn't you, change your password.");
}
/** True if $code matches the pending session's enabled TOTP or Telegram factor. */
function twofaVerify(array $pend, string $code): bool {
    $code = preg_replace('/\D/','',$code); if (strlen($code) !== 6) return false;
    if (!empty($pend['totp']) && totpVerify((string)cfgGet('totp_secret'), $code)) return true;
    if (!empty($pend['tg'])) {
        $want = (string)($_SESSION['2fa_tg_code'] ?? ''); $t = (int)($_SESSION['2fa_tg_t'] ?? 0);
        if ($want !== '' && (time() - $t) <= 300 && hash_equals($want, $code)) return true;
    }
    return false;
}

// logout
if (($_GET['logout'] ?? '') === '1') { session_destroy(); header('Location: ?'); exit; }

// public captcha image (pre-auth) - fresh challenge each request, stored one-shot in session
if (isset($_GET['captcha']) && !isAuthed()) {
    header('Content-Type: image/svg+xml'); header('Cache-Control: no-store, must-revalidate');
    echo captchaSvg(captchaNew()); exit;
}

// Complete a login: regenerate session and mark authed.
function loginComplete(array $cred): void {
    session_regenerate_id(true);
    $_SESSION['auth'] = true; $_SESSION['t'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $_SESSION['user'] = $cred['user']; $_SESSION['role'] = $cred['role'];
    unset($_SESSION['2fa_pending'], $_SESSION['2fa_tg_code'], $_SESSION['2fa_tg_t']);
    loginReset(); auditLog('login_ok', $cred['user'].' ('.$cred['role'].')');
    header('Location: ?'); exit;
}

// login submit (staged: password [+captcha] -> second factor, with method choice)
$loginErr = ''; $loginStage = 'password'; $login2faShow = '';
if (!isAuthed() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // -- Stage 2a: user picked Telegram (or asked to resend) - send a code, stay on stage 2 --
    if (isset($_POST['login_2fa_send']) && !empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_pending']['tg'])) {
        $loginStage = '2fa'; $login2faShow = 'tg';
        if ((time() - (int)($_SESSION['2fa_pending']['t'] ?? 0)) > 300) { unset($_SESSION['2fa_pending']); $loginStage = 'password'; $loginErr = 'That took too long - please sign in again.'; }
        elseif (!twofaSendTelegram()) $loginErr = "Couldn't reach Telegram to send your code. Check the bot token/chat ID in Settings.";
    }
    // -- Stage 2b: second factor (TOTP app code or Telegram code) --
    elseif (isset($_POST['login_2fa']) && !empty($_SESSION['2fa_pending'])) {
        $pend = $_SESSION['2fa_pending']; $loginStage = '2fa'; $login2faShow = ($_POST['login_2fa_method'] ?? '') === 'tg' ? 'tg' : '';
        if (($lock = loginLocked()) > 0) {
            $loginErr = 'Too many attempts. Try again in '.max(1,(int)ceil($lock/60)).' min.';
        } elseif ((time() - (int)($pend['t'] ?? 0)) > 300) {
            unset($_SESSION['2fa_pending']); $loginStage = 'password';
            $loginErr = 'That took too long - please sign in again.';
        } elseif (twofaVerify($pend, (string)$_POST['login_2fa'])) {
            loginComplete(['user'=>$pend['user'], 'role'=>$pend['role']]);
        } else {
            loginFail(); $loginErr = 'That code is not valid. Check your authenticator app'.(!empty($pend['tg'])?' or the code sent to Telegram.':'.');
            auditLog('login_2fa_fail', (string)$pend['user']);
        }
    }
    // -- Stage 1: username + password + captcha --
    elseif (isset($_POST['login_user'])) {
        $u = $_POST['login_user'] ?? ''; $p = $_POST['login_pass'] ?? '';
        if (($lock = loginLocked()) > 0) {
            $loginErr = 'Too many attempts. Try again in '.max(1,(int)ceil($lock/60)).' min.'; auditLog('login_locked', $u);
        } elseif (!captchaCheck((string)($_POST['login_captcha'] ?? ''))) {
            loginFail(); $loginErr = 'The captcha did not match - please try again.'; auditLog('login_captcha_fail', $u);
        } else {
            $cred = authCreds($u, $p);
            if (!$cred) { loginFail(); $loginErr = 'Invalid username or password.'; auditLog('login_fail', $u); }
            else {
                $m = twofaMethods($cred['role']);
                if (!$m['totp'] && !$m['tg']) { loginComplete($cred); }
                else {
                    $_SESSION['2fa_pending'] = ['user'=>$cred['user'], 'role'=>$cred['role'], 't'=>time(), 'totp'=>$m['totp'], 'tg'=>$m['tg']];
                    // Auto-send the Telegram code only when it's the ONLY method; if the app is also on,
                    // let the user choose (they press "Use Telegram" to receive a code).
                    if ($m['tg'] && !$m['totp']) { if (!twofaSendTelegram()) $loginErr = "Couldn't reach Telegram to send your code - check the bot token/chat ID in Settings."; }
                    if ($m['tg'] && !$m['totp']) $login2faShow = 'tg';
                    $loginStage = '2fa'; auditLog('login_2fa_prompt', (string)$cred['user']);
                }
            }
        }
    }
}

// Load optional feature modules/plugins so they can register API actions + pages before dispatch.
moduleLoadAll();

// -- Public module webhooks (e.g. Git auto-deploy) - no panel session; handler validates its own secret --
if (isset($_GET['hook'])) {
    header('Content-Type: application/json');
    $r = moduleHook((string)$_GET['hook']);
    echo json_encode($r ?? ['ok'=>false,'error'=>'unknown hook'], JSON_UNESCAPED_SLASHES);
    exit;
}

// -- Internal error logging -------------------------------------------------
// Users must never see stack traces, PHP/SQL/filesystem errors or internal
// exceptions. logInternal() records the full detail to a private log; the user
// only ever gets a generic "Not working". Callers use internalFail() in catches.
function logInternal(string $ctx, Throwable $e): void {
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $msg = '[' . date('c') . '] ' . $ctx . ' :: ' . get_class($e) . ': ' . $e->getMessage()
         . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
    @error_log($msg, 3, $dir . '/error.log');
}
function internalFail(Throwable $e, string $ctx = 'api'): array {
    logInternal($ctx, $e);
    return ['ok' => false, 'error' => 'Not working'];
}

// -- JSON API (all privileged actions) ----------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $__action = (string)($_POST['action'] ?? '');
    // Fatal-error safety net: a fatal during dispatch returns generic JSON, never a stack trace.
    register_shutdown_function(function () use ($__action) {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            @error_log('[' . date('c') . '] api:' . $__action . ' FATAL :: ' . $e['message']
                . ' @ ' . $e['file'] . ':' . $e['line'] . "\n", 3, DATA_DIR . '/logs/error.log');
            if (!headers_sent()) { echo json_encode(['ok' => false, 'error' => 'Not working']); }
        }
    });
    $tok = apiTokenValid(apiBearer());   // REST/CLI token auth: bypasses the browser session + CSRF
    if (!isAuthed() && !$tok) { echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }
    if (!$tok && !checkCsrf()) { echo json_encode(['ok'=>false,'error'=>'Bad CSRF token']); exit; }
    if (!$tok && !isAdmin()) { $gErr = userApiGuard($__action); if ($gErr !== '') { echo json_encode(['ok'=>false,'error'=>$gErr]); exit; } }
    try {
        echo json_encode(apiDispatch($__action), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        logInternal('api:' . $__action, $e);
        echo json_encode(['ok' => false, 'error' => 'Not working']);
    }
    exit;
}

function apiDispatch(string $action): array {
    switch ($action) {
        // -- Websites --
        case 'site_add': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Enter a valid domain (e.g. example.com)'];
            $base = cfgGet('webroot_base','/var/www');
            $mode = $_POST['mode'] ?? 'new';          // 'new' = own folder, 'alias' = share an existing site's folder
            $aliasOf = '';
            if ($mode === 'alias') {
                $share = strtolower(trim($_POST['share'] ?? ''));
                $sites = loadJson(SITES_FILE, []);
                $target = null; foreach ($sites as $s) if (($s['domain'] ?? '') === $share) { $target = $s; break; }
                if (!$target) return ['ok'=>false,'error'=>'Pick an existing website to share files with'];
                $docroot = $target['docroot']; $aliasOf = $share;
            } else {
                $docroot = $base . '/' . $d . '/public';
            }
            $r = helper('create-site', [$d, $docroot]);
            if ($r['code'] !== 0) return ['ok'=>false,'error'=>$r['out']];
            // With Cloudflare connected, onboard the site for lifetime HTTPS in one step:
            // a PROXIED (orange-cloud) A record + Full SSL + Always Use HTTPS.
            $cfErr = ''; $cfOk = false;
            if (cfAutoEnabled()) {
                $cf = cfOnboardSite($d, (string)cfgGet('server_ip'));
                if ($cf['ok']) $cfOk = true; else $cfErr = $cf['error'];
            }
            // Best-effort: once DNS points here (via Cloudflare above), issue a real
            // Let's Encrypt cert so HTTPS is valid + auto-renewing (needed for CF Full-strict).
            if ($cfOk && $mode !== 'alias') {
                helper('site-ssl', [$d, (string)cfgGet('admin_email', 'admin@' . $d)]);
            }
            $sites = loadJson(SITES_FILE, []);
            if (!array_filter($sites, fn($s)=>$s['domain']===$d)) {
                $entry = ['domain'=>$d,'docroot'=>$docroot,'ssl'=>$cfOk,'created'=>date('c')];
                if ($aliasOf !== '') $entry['alias_of'] = $aliasOf;
                $sites[] = $entry; saveJson(SITES_FILE,$sites);
            }
            $base = $aliasOf !== '' ? "{$d} now serves the same website as {$aliasOf}." : "Website {$d} created.";
            if ($cfErr !== '') {
                $msg = $base." Could not set it up in Cloudflare: {$cfErr} (add the domain to Cloudflare, or connect the right API token in Settings).";
            } elseif ($cfOk) {
                $msg = $base." Cloudflare is set up: DNS points here (proxied) and HTTPS is on for the life of the site - it goes live within a minute.";
            } else {
                $msg = $base." Now point its DNS A-record at ".cfgGet('server_ip')." - then HTTPS turns on automatically. See the DNS page for the exact record.";
            }
            return ['ok'=>true,'msg'=>$msg];
        }
        case 'redirect_add': {
            $d = strtolower(trim($_POST['domain'] ?? '')); $target = trim($_POST['target'] ?? '');
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Enter a valid domain to redirect'];
            if ($target === '') return ['ok'=>false,'error'=>'Enter the destination URL'];
            if (!preg_match('#^https?://#i', $target)) $target = 'https://' . $target;
            if (!filter_var($target, FILTER_VALIDATE_URL)) return ['ok'=>false,'error'=>'That destination is not a valid URL'];
            $type = ($_POST['type'] ?? '301') === '302' ? '302' : '301';   // 302 = temporary, 301 = permanent
            $r = helper('create-redirect', [$d, $target, $type]);
            if ($r['code'] !== 0) return ['ok'=>false,'error'=>$r['out']];
            // Point + proxy the source domain through Cloudflare so HTTPS works end-to-end (no "invalid SSL certificate").
            if (cfAutoEnabled()) cfOnboardSite($d, (string)cfgGet('server_ip'));
            $reds = loadJson(REDIRECTS_FILE, []);
            $reds = array_values(array_filter($reds, fn($x)=>($x['domain']??'')!==$d));
            $reds[] = ['domain'=>$d,'target'=>$target,'type'=>$type,'created'=>date('c')]; saveJson(REDIRECTS_FILE,$reds);
            $kind = $type === '302' ? 'temporary (302)' : 'permanent (301)';
            $msg = "Redirect added: {$d} now forwards to {$target} ({$kind}).";
            if (cfAutoEnabled()) $msg .= " Cloudflare DNS is set (proxied) with HTTPS - live within a minute.";
            else $msg .= " Point its DNS A-record at ".cfgGet('server_ip')." so visitors reach this server.";
            return ['ok'=>true,'msg'=>$msg];
        }
        case 'redirect_del': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            // Drop the redirect record.
            saveJson(REDIRECTS_FILE, array_values(array_filter(loadJson(REDIRECTS_FILE,[]), fn($x)=>($x['domain']??'')!==$d)));
            // Revert cleanly so the domain keeps working. NEVER delete its DNS record (that is what
            // caused "not pointed yet - resolves to not found"): the domain must stay pointed here.
            // If it is a hosted website/subdomain, rebuild its normal vhost (and re-assert HTTPS if it
            // had SSL); otherwise it was a redirect-only domain, so just remove the redirect vhost.
            $site = null; foreach (loadJson(SITES_FILE, []) as $s) if (strtolower((string)($s['domain'] ?? '')) === $d) { $site = $s; break; }
            if ($site) {
                $docroot = (string)($site['docroot'] ?? (cfgGet('webroot_base','/var/www').'/'.$d.'/public'));
                helper('create-site', [$d, $docroot]);
                if (!empty($site['ssl'])) helper('site-ssl', [$d, (string)cfgGet('admin_email', 'admin@'.$d)]);   // best-effort: restore the HTTPS vhost
                return ['ok'=>true,'msg'=>"Redirect removed - {$d} is serving its website again."];
            }
            helper('delete-site', [$d]);   // redirect-only domain: drop the redirect vhost, keep DNS pointed here
            return ['ok'=>true,'msg'=>"Redirect for {$d} removed."];
        }
        // -- DNS Zone Editor (Cloudflare) --
        case 'zone_list': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!cfConnected()) return ['ok'=>false,'error'=>'no_token'];
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Pick a domain'];
            $zid = cfZoneId($d); if (!$zid) return ['ok'=>false,'error'=>'That domain is not in your Cloudflare account. Add it to Cloudflare first.'];
            $rs = cfApi('GET', "/zones/$zid/dns_records?per_page=200");
            if (!$rs['ok']) return ['ok'=>false,'error'=>$rs['error']];
            $recs = array_map(fn($r)=>['id'=>$r['id'],'type'=>$r['type'],'name'=>$r['name'],'content'=>$r['content'],'ttl'=>$r['ttl'],'proxied'=>$r['proxied']??false], $rs['data'] ?? []);
            return ['ok'=>true,'records'=>$recs,'zone'=>$d];
        }
        case 'zone_save': {
            $d = strtolower(trim($_POST['domain'] ?? '')); $id = trim($_POST['id'] ?? '');
            $type = strtoupper(trim($_POST['type'] ?? '')); $name = trim($_POST['name'] ?? '');
            $content = trim($_POST['content'] ?? ''); $ttl = (int)($_POST['ttl'] ?? 1) ?: 1;
            $proxied = !empty($_POST['proxied']);
            if (!cfConnected()) return ['ok'=>false,'error'=>'no_token'];
            if (!in_array($type, ['A','AAAA','CNAME','MX','TXT','NS'])) return ['ok'=>false,'error'=>'Unsupported record type'];
            if ($name === '' || $content === '') return ['ok'=>false,'error'=>'Name and value are required'];
            // normalise host: '@' -> root, bare label -> label.domain, full name kept
            if ($name === '@') $name = $d;
            elseif (!str_ends_with($name, $d)) $name = rtrim($name, '.') . '.' . $d;
            $zid = cfZoneId($d); if (!$zid) return ['ok'=>false,'error'=>'Domain not in your Cloudflare account'];
            $body = ['type'=>$type,'name'=>$name,'content'=>$content,'ttl'=>$ttl];
            if (in_array($type, ['A','AAAA','CNAME'])) $body['proxied'] = $proxied;
            if ($type === 'MX') $body['priority'] = (int)($_POST['priority'] ?? 10);
            $res = $id !== '' ? cfApi('PUT', "/zones/$zid/dns_records/$id", $body) : cfApi('POST', "/zones/$zid/dns_records", $body);
            return $res['ok'] ? ['ok'=>true,'msg'=>'DNS record saved.'] : ['ok'=>false,'error'=>$res['error']];
        }
        case 'zone_del': {
            $d = strtolower(trim($_POST['domain'] ?? '')); $id = trim($_POST['id'] ?? '');
            if (!cfConnected()) return ['ok'=>false,'error'=>'no_token'];
            if ($id === '') return ['ok'=>false,'error'=>'No record id'];
            $zid = cfZoneId($d); if (!$zid) return ['ok'=>false,'error'=>'Domain not in your Cloudflare account'];
            $res = cfApi('DELETE', "/zones/$zid/dns_records/$id");
            return $res['ok'] ? ['ok'=>true,'msg'=>'DNS record deleted.'] : ['ok'=>false,'error'=>$res['error']];
        }
        case 'dns_check': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'invalid'];
            $ip = cfgGet('server_ip');
            $resolved = trim(sh('dig +short A '.escapeshellarg($d).' @1.1.1.1 2>/dev/null | grep -E "^[0-9.]+$" | tail -n1'));
            if ($resolved === '') { $r = @gethostbyname($d); $resolved = ($r && $r !== $d) ? $r : ''; }
            // A Cloudflare-proxied domain resolves to Cloudflare's IPs, NOT the server - that is
            // correct and fully managed, so treat "proxied on a connected zone" as pointed.
            $cfProxied = false;
            if (cfConnected()) { $z = cfResolveZone($d); if ($z) { $rec = cfApiTok($z['token'],'GET',"/zones/{$z['zoneId']}/dns_records?type=A&name=".urlencode($d)); $cfProxied = !empty($rec['data'][0]['proxied']); } }
            $direct = ($ip !== '' && $resolved === $ip);
            return ['ok'=>true,'pointed'=>($direct || $cfProxied),'direct'=>$direct,'cloudflare'=>$cfProxied,'resolved'=>($resolved ?: 'not found yet'),'expected'=>$ip];
        }
        case 'set_cf_token': {
            $t = trim($_POST['token'] ?? '');
            $c = cfg();
            if ($t === '') { unset($c['cf_token']); return saveCfg($c) ? ['ok'=>true,'msg'=>'Cloudflare disconnected.'] : ['ok'=>false,'error'=>'Could not write config']; }
            // verify the token works (and has zone access) before saving
            if (function_exists('curl_init')) {
                $ch = curl_init('https://api.cloudflare.com/client/v4/zones?per_page=1');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$t, 'Content-Type: application/json']]);
                $resp = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
                if ($resp === false) return ['ok'=>false,'error'=>'Could not reach Cloudflare: '.$cerr];
                $j = json_decode($resp, true) ?: [];
                if (empty($j['success'])) return ['ok'=>false,'error'=>'That token did not work: '.($j['errors'][0]['message'] ?? 'make sure it has the "Zone - DNS - Edit" permission').'.'];
            }
            $c['cf_token'] = $t;
            return saveCfg($c) ? ['ok'=>true,'msg'=>'Cloudflare connected. Adding a website now sets its DNS for you automatically.'] : ['ok'=>false,'error'=>'Could not write config'];
        }
        case 'set_cf_account': {
            // Add a NAMED Cloudflare API token (multiple accounts/websites supported).
            $label = trim($_POST['label'] ?? ''); $t = trim($_POST['token'] ?? '');
            if ($t === '') return ['ok'=>false,'error'=>'Paste a Cloudflare API token.'];
            if ($label === '') $label = 'Cloudflare';
            if (mb_strlen($label) > 40) $label = mb_substr($label, 0, 40);
            $res = cfApiTok($t, 'GET', '/zones?per_page=1');
            if (!$res['ok']) return ['ok'=>false,'error'=>'That token did not work: '.($res['error'] ?: 'make sure it has the "Zone - DNS - Edit" permission').'.'];
            $c = cfg();
            $accts = is_array($c['cf_accounts'] ?? null) ? $c['cf_accounts'] : [];
            foreach ($accts as $a) if (($a['token'] ?? '') === $t) return ['ok'=>false,'error'=>'That token is already added.'];
            $accts[] = ['id'=>substr(bin2hex(random_bytes(4)),0,8), 'label'=>$label, 'token'=>$t, 'added'=>date('c')];
            $c['cf_accounts'] = $accts;
            return saveCfg($c) ? ['ok'=>true,'msg'=>'Cloudflare account "'.$label.'" connected.'] : ['ok'=>false,'error'=>'Could not write config'];
        }
        case 'set_cf_mode': {
            // Switch between automatic Cloudflare handling and manual mode. Never blocks any feature.
            $mode = ($_POST['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
            $c = cfg(); $c['cf_mode'] = $mode;
            $label = $mode === 'manual' ? 'Manual mode - Cloudflare automation off. All actions still work.' : 'Automatic mode - Cloudflare handles DNS, SSL and email.';
            return saveCfg($c) ? ['ok'=>true,'msg'=>$label] : ['ok'=>false,'error'=>'Could not write config'];
        }
        case 'del_cf_account': {
            $id = trim($_POST['id'] ?? '');
            $c = cfg();
            if ($id === 'default') { unset($c['cf_token']); return saveCfg($c) ? ['ok'=>true,'msg'=>'Cloudflare account removed.'] : ['ok'=>false,'error'=>'Could not write config']; }
            $accts = is_array($c['cf_accounts'] ?? null) ? $c['cf_accounts'] : [];
            $c['cf_accounts'] = array_values(array_filter($accts, fn($a)=>($a['id'] ?? '') !== $id));
            return saveCfg($c) ? ['ok'=>true,'msg'=>'Cloudflare account removed.'] : ['ok'=>false,'error'=>'Could not write config'];
        }
        case 'cf_accounts_list': {
            $out = [];
            foreach (cfTokens() as $a) {
                $tok = (string)$a['token'];
                $out[] = ['id'=>$a['id'], 'label'=>$a['label'],
                          'hint'=>substr($tok,0,4).'...'.substr($tok,-4)];
            }
            return ['ok'=>true, 'accounts'=>$out];
        }
        // -- Per-domain SSL / Cloudflare controls --
        case 'cf_site_status': {
            // Works in BOTH modes: reports the Let's Encrypt origin cert (server) AND the
            // Cloudflare edge SSL (if a token is connected + the zone is present).
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            // /etc/letsencrypt/live is root-only (0700), so the unprivileged panel can't stat it -
            // ask the root helper whether a certificate really exists (was showing "Let's Encrypt —" wrongly).
            $le = is_dir('/etc/letsencrypt/live/'.$d);
            if (!$le && is_file(HELPER)) { $ce = helper('cert-status', [$d]); $le = trim((string)($ce['out'] ?? '')) === 'EXISTS'; }
            // Ground truth: actually probe HTTPS. curl (without -k) only returns a status code
            // when the TLS handshake succeeds with a VALID certificate - so any code != 000 means
            // the site is genuinely secured (Cloudflare Universal SSL, Let's Encrypt, anything),
            // independent of whether the token can read the Cloudflare SSL setting.
            $code = trim((string)sh('curl -sS -o /dev/null -w "%{http_code}" --max-time 6 '.escapeshellarg('https://'.$d).' 2>/dev/null'));
            $httpsLive = ($code !== '' && $code !== '000');
            $out = ['ok'=>true, 'domain'=>$d, 'le'=>$le, 'cf'=>false, 'cf_note'=>'no_token', 'https_live'=>$httpsLive];
            if (cfConnected()) {
                $z = cfResolveZone($d);
                if ($z) {
                    $zid = $z['zoneId']; $tok = $z['token'];
                    $ssl = cfApiTok($tok,'GET',"/zones/$zid/settings/ssl");
                    $ah  = cfApiTok($tok,'GET',"/zones/$zid/settings/always_use_https");
                    $ar  = cfApiTok($tok,'GET',"/zones/$zid/settings/automatic_https_rewrites");
                    $rec = cfApiTok($tok,'GET',"/zones/$zid/dns_records?type=A&name=".urlencode($d));
                    $out['cf'] = true; $out['cf_note'] = ''; $out['zone'] = $z['zoneName'];
                    $out['ssl_mode'] = $ssl['data']['value'] ?? null;
                    $out['always_https'] = $ah['data']['value'] ?? null;
                    $out['auto_rewrites'] = $ar['data']['value'] ?? null;
                    $out['proxied'] = !empty($rec['data'][0]['proxied']);
                    $out['has_record'] = !empty($rec['data'][0]['id']);
                    $out['can_edit'] = !empty($ssl['ok']);   // token can read+edit the SSL setting
                } else { $out['cf_note'] = 'not_in_cf'; }
            }
            $out['origin_cert'] = $le;
            // Server-side Force-HTTPS state (Orizen-managed .htaccess block).
            $docroot = ''; foreach (loadJson(SITES_FILE, []) as $s) if (($s['domain'] ?? '') === $d) { $docroot = (string)($s['docroot'] ?? ''); break; }
            $out['force_https'] = ($docroot !== '' && is_file($docroot.'/.htaccess')
                && strpos((string)@file_get_contents($docroot.'/.htaccess'), 'ORIZEN-FORCE-HTTPS-START') !== false);
            // Secured if HTTPS actually works, OR an LE cert exists, OR CF SSL is on.
            $out['secured'] = $httpsLive || $le || (!empty($out['ssl_mode']) && $out['ssl_mode'] !== 'off');
            return $out;
        }
        case 'cf_ssl_mode': {
            $d = strtolower(trim($_POST['domain'] ?? '')); $mode = $_POST['mode'] ?? '';
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            if (!in_array($mode, ['off','flexible','full','strict'], true)) return ['ok'=>false,'error'=>'Invalid SSL mode'];
            $r = cfSetSetting($d, 'ssl', $mode);
            if ($r['ok']) { $sites=loadJson(SITES_FILE,[]); foreach($sites as &$s) if(($s['domain']??'')===$d)$s['ssl']=($mode!=='off'); unset($s); saveJson(SITES_FILE,$sites); }
            return $r['ok'] ? ['ok'=>true,'msg'=>'Cloudflare SSL mode set to '.strtoupper($mode).'.'] : $r;
        }
        case 'cf_always_https': {
            $d = strtolower(trim($_POST['domain'] ?? '')); $state = (($_POST['state'] ?? '')==='on')?'on':'off';
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = cfSetSetting($d, 'always_use_https', $state);
            return $r['ok'] ? ['ok'=>true,'msg'=>'Always Use HTTPS turned '.$state.'.'] : $r;
        }
        case 'cf_purge': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $z = cfResolveZone($d); if (!$z) return ['ok'=>false,'error'=>'not_in_cf'];
            $r = cfApiTok($z['token'], 'POST', "/zones/{$z['zoneId']}/purge_cache", ['purge_everything'=>true]);
            return !empty($r['ok']) ? ['ok'=>true,'msg'=>'Cloudflare cache purged for '.$d.'.']
                                    : ['ok'=>false,'error'=>($r['error'] ?: 'Purge failed').' - your token needs "Cache Purge: Purge".'];
        }
        // -- Script runner (interval jobs: 10s..hourly, start/stop, live logs) --
        case 'scripts_list': {
            $jobs = loadJson(SCRIPTS_FILE, []);
            foreach ($jobs as &$j) { $st = helper('script-run', ['status', (string)$j['id']]); $j['running'] = str_starts_with(trim((string)$st['out']), 'RUNNING'); }
            unset($j);
            return ['ok'=>true, 'jobs'=>array_values($jobs)];
        }
        case 'script_save': {
            $label = trim((string)($_POST['label'] ?? '')); $path = trim((string)($_POST['path'] ?? '')); $interval = (int)($_POST['interval'] ?? 60);
            $rp = realpath($path);
            if (!$rp || !is_file($rp)) return ['ok'=>false,'error'=>'Script file not found. Enter a full path to an existing .sh/.php/.py/.js file.'];
            $rp = str_replace('\\','/',$rp);
            if (!preg_match('#^(/var/www/|/srv/|/opt/orizen/data/scripts/)#', $rp)) return ['ok'=>false,'error'=>'For safety the script must live under /var/www, /srv or /opt/orizen/data/scripts.'];
            if ($interval < 1 || $interval > 604800) return ['ok'=>false,'error'=>'Interval must be between 1 second and 7 days.'];
            if ($label === '') $label = basename($rp);
            $jobs = loadJson(SCRIPTS_FILE, []);
            $id = substr(preg_replace('/[^a-z0-9]/','', strtolower($label).bin2hex(random_bytes(3))), 0, 20) ?: bin2hex(random_bytes(5));
            $jobs[] = ['id'=>$id, 'label'=>$label, 'path'=>$rp, 'interval'=>$interval, 'created'=>date('c')];
            saveJson(SCRIPTS_FILE, $jobs);
            return ['ok'=>true,'msg'=>'Script "'.$label.'" added.'];
        }
        case 'script_del': {
            $id = preg_replace('/[^A-Za-z0-9_-]/','', (string)($_POST['id'] ?? ''));
            helper('script-run', ['stop', $id]);
            saveJson(SCRIPTS_FILE, array_values(array_filter(loadJson(SCRIPTS_FILE, []), fn($j)=>($j['id']??'')!==$id)));
            return ['ok'=>true,'msg'=>'Script removed.'];
        }
        case 'script_ctl': {
            $id = preg_replace('/[^A-Za-z0-9_-]/','', (string)($_POST['id'] ?? ''));
            $op = (string)($_POST['op'] ?? '');
            $job = null; foreach (loadJson(SCRIPTS_FILE, []) as $j) if (($j['id']??'')===$id) { $job = $j; break; }
            if (!$job) return ['ok'=>false,'error'=>'Unknown script'];
            $wu = cfgGet('web_user','www-data');
            if ($op === 'start') { $r = helper('script-run', ['start', $id, (string)$job['interval'], $job['path'], $wu]); }
            elseif ($op === 'stop') { $r = helper('script-run', ['stop', $id]); }
            elseif ($op === 'log') { $r = helper('script-run', ['log', $id, (string)max(20,min(500,(int)($_POST['lines'] ?? 120)))]); return ['ok'=>true,'log'=>(string)$r['out']]; }
            else return ['ok'=>false,'error'=>'Bad op'];
            return ($r['code']??1)===0 ? ['ok'=>true,'msg'=>trim((string)$r['out'])] : internalFail(new RuntimeException('script-run: '.($r['out']??'')), 'script_ctl');
        }
        case 'cf_auto_rewrites': {
            $d = strtolower(trim($_POST['domain'] ?? '')); $state = (($_POST['state'] ?? '')==='on')?'on':'off';
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = cfSetSetting($d, 'automatic_https_rewrites', $state);
            return $r['ok'] ? ['ok'=>true,'msg'=>'Automatic HTTPS Rewrites turned '.$state.'.'] : $r;
        }
        case 'cf_set_proxied': {
            // Choose WHICH certificate serves visitors, using only DNS-edit access:
            //   proxied (orange cloud) => Cloudflare's certificate; DNS-only (grey) => your origin's Let's Encrypt cert.
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $proxied = (($_POST['proxied'] ?? '') === '1');
            $r = cfSetProxied($d, $proxied);
            if (!$r['ok']) return $r;
            return ['ok'=>true,'msg'=>$proxied
                ? "Now using Cloudflare's SSL (proxied / orange cloud) for {$d}."
                : "Now using Let's Encrypt directly (Cloudflare DNS-only / grey cloud) for {$d}. Issue a Let's Encrypt cert below if you haven't."];
        }
        case 'site_force_https': {
            // Server-side http->https redirect that works in EVERY mode (loop-safe behind Cloudflare).
            $d = strtolower(trim($_POST['domain'] ?? '')); $state = (($_POST['state'] ?? '')==='on')?'on':'off';
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = helper('site-force-https', [$d, $state]);
            if (($r['code'] ?? 1) !== 0) return internalFail(new RuntimeException('site-force-https: '.($r['out'] ?? '')), 'site_force_https');
            return ['ok'=>true,'msg'=>$state==='on' ? "Force HTTPS is ON for {$d} (visitors are redirected to https)." : "Force HTTPS is OFF for {$d}."];
        }
        case 'ssl_disable': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = cfSetSetting($d, 'ssl', 'off');
            if (!$r['ok']) return $r;
            $sites=loadJson(SITES_FILE,[]); foreach($sites as &$s) if(($s['domain']??'')===$d)$s['ssl']=false; unset($s); saveJson(SITES_FILE,$sites);
            return ['ok'=>true,'msg'=>'HTTPS turned off for '.$d.' (Cloudflare SSL: Off).'];
        }
        case 'ssl_remove': {
            // Remove the Let's Encrypt certificate + revert the vhost to HTTP-only (used when
            // there is no Cloudflare to toggle - the "Disable SSL" path in manual mode).
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = helper('site-ssl-remove', [$d]);
            if (($r['code'] ?? 1) !== 0) return internalFail(new RuntimeException('site-ssl-remove: '.($r['out'] ?? '')), 'ssl_remove');
            $sites=loadJson(SITES_FILE,[]); foreach($sites as &$s) if(($s['domain']??'')===$d)$s['ssl']=false; unset($s); saveJson(SITES_FILE,$sites);
            return ['ok'=>true,'msg'=>"HTTPS turned off for {$d} (Let's Encrypt certificate removed)."];
        }
        case 'site_del': {
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $sites = loadJson(SITES_FILE, []);
            $rec = null; foreach ($sites as $s) if (($s['domain'] ?? '') === $d) { $rec = $s; break; }
            $docroot = (string)($rec['docroot'] ?? '');
            $isAlias = !empty($rec['aliasOf']);   // alias sites share another site's folder - never delete those files
            // 1) vhost + SSL cert + logs (+ the site's own folder unless it is an alias)
            $r = helper('purge-site', $isAlias ? [$d] : array_values(array_filter([$d, $docroot], fn($x)=>$x!=='')));
            if (($r['code'] ?? 1) !== 0) return internalFail(new RuntimeException('purge-site: '.($r['out']??'')), 'site_del');
            // 2) Cloudflare DNS records for this domain
            if (cfConnected()) cfDnsDelete($d);
            // 3) redirects that forward from this domain
            saveJson(REDIRECTS_FILE, array_values(array_filter(loadJson(REDIRECTS_FILE, []), fn($x)=>($x['domain'] ?? '') !== $d)));
            // 4) staging environments linked to this domain (drop records + purge their resources)
            if (function_exists('stgLoad') && function_exists('stgSave')) {
                $keep = [];
                foreach (stgLoad() as $st) {
                    $isStg  = ($st['stg_domain'] ?? '') === $d;
                    $isProd = ($st['prod_domain'] ?? '') === $d;
                    if ($isStg || $isProd) {
                        $sd = (string)($st['stg_domain'] ?? '');
                        if ($sd !== '' && $sd !== $d) {   // deleting the prod site: also purge its staging copy
                            helper('purge-site', array_values(array_filter([$sd, (string)($st['stg_docroot'] ?? '')], fn($x)=>$x!=='')));
                            if (cfConnected()) cfDnsDelete($sd);
                        }
                        $sdb = (string)($st['stg_db'] ?? '');
                        if ($sdb !== '' && preg_match('/^[A-Za-z0-9_]+$/', $sdb) && db()) { try { db()->exec("DROP DATABASE IF EXISTS `{$sdb}`"); } catch (Throwable $e) {} }
                        continue;   // drop this staging record
                    }
                    $keep[] = $st;
                }
                stgSave($keep);
            }
            // 5) site metadata
            saveJson(SITES_FILE, array_values(array_filter($sites, fn($s)=>($s['domain'] ?? '') !== $d)));
            $note = $isAlias ? ' (shared files kept)' : ' - files, vhost, SSL, DNS and redirects cleaned up';
            return ['ok'=>true,'msg'=>"Website {$d} removed{$note}."];
        }
        case 'sub_add': {
            $sub = strtolower(trim($_POST['sub'] ?? '')); $parent = strtolower(trim($_POST['parent'] ?? ''));
            if (!validLabel($sub) || !validDomain($parent)) return ['ok'=>false,'error'=>'Invalid subdomain or parent'];
            $fqdn = $sub.'.'.$parent;
            $docroot = cfgGet('webroot_base','/var/www').'/'.$fqdn.'/public';
            $r = helper('create-subdomain', [$fqdn, $docroot]);
            if ($r['code'] !== 0) return ['ok'=>false,'error'=>$r['out']];
            // Same one-click treatment as a website: proxied Cloudflare DNS + lifetime HTTPS.
            $cfOk = false; $cfErr = '';
            if (cfAutoEnabled()) {
                $cf = cfOnboardSite($fqdn, (string)cfgGet('server_ip'));
                if ($cf['ok']) { $cfOk = true; helper('site-ssl', [$fqdn, (string)cfgGet('admin_email','admin@'.$parent)]); }
                else $cfErr = $cf['error'];
            }
            $sites = loadJson(SITES_FILE, []);
            if (!array_filter($sites, fn($s)=>$s['domain']===$fqdn)) { $sites[] = ['domain'=>$fqdn,'docroot'=>$docroot,'ssl'=>$cfOk,'sub'=>true,'created'=>date('c')]; saveJson(SITES_FILE,$sites); }
            $msg = "Subdomain {$fqdn} created.";
            if ($cfOk) $msg .= " Cloudflare DNS is set (proxied) and HTTPS is turning on - live within a minute.";
            elseif ($cfErr !== '') $msg .= " Cloudflare: {$cfErr}";
            else $msg .= " Point its DNS A-record at ".cfgGet('server_ip')." to bring it online.";
            return ['ok'=>true,'msg'=>$msg];
        }

        // -- SSL --
        case 'ssl_issue': {
            @set_time_limit(180);   // certbot can take a little while; do not let PHP abort mid-issue
            $d = strtolower(trim($_POST['domain'] ?? ''));
            if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = helper('site-ssl', [$d, cfgGet('admin_email','admin@'.$d)]);
            if ($r['code'] !== 0) return ['ok'=>false,'error'=>"Certificate request failed for {$d}. Its DNS must reach this server (".cfgGet('server_ip')."): a direct A-record, or Cloudflare with the record set to proxied. Details: ".trim((string)$r['out'])];
            $sites = loadJson(SITES_FILE, []);
            foreach ($sites as &$s) if ($s['domain']===$d) $s['ssl']=true; unset($s);
            saveJson(SITES_FILE, $sites);
            return ['ok'=>true,'msg'=>"Let's Encrypt certificate issued for {$d} - HTTPS is active and auto-renewing."];
        }

        // -- Databases --
        case 'db_create': {
            $n = trim($_POST['name'] ?? ''); if (!validIdent($n)) return ['ok'=>false,'error'=>'Invalid database name'];
            try { db()->exec("CREATE DATABASE ".qIdent($n)." CHARACTER SET utf8mb4"); return ['ok'=>true,'msg'=>"Database {$n} created."]; }
            catch (Exception $e) { return internalFail($e); }
        }
        case 'db_drop': {
            $n = trim($_POST['name'] ?? ''); if (!validIdent($n)) return ['ok'=>false,'error'=>'Invalid name'];
            if (in_array($n, ['mysql','information_schema','performance_schema','sys'])) return ['ok'=>false,'error'=>'System database'];
            try { db()->exec("DROP DATABASE ".qIdent($n)); return ['ok'=>true,'msg'=>"Dropped {$n}."]; }
            catch (Exception $e) { return internalFail($e); }
        }
        case 'dbuser_create': {
            $u = trim($_POST['user'] ?? ''); $p = $_POST['pass'] ?? ''; $dbn = trim($_POST['grant_db'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $u)) return ['ok'=>false,'error'=>'Invalid username'];
            try {
                $pdo = db();
                $pdo->exec("CREATE USER '".$u."'@'localhost' IDENTIFIED BY ".$pdo->quote($p));
                $pdo->exec("CREATE USER '".$u."'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($p));
                if ($dbn !== '' && validIdent($dbn)) {
                    $pdo->exec("GRANT ALL PRIVILEGES ON ".qIdent($dbn).".* TO '".$u."'@'localhost'");
                    $pdo->exec("GRANT ALL PRIVILEGES ON ".qIdent($dbn).".* TO '".$u."'@'127.0.0.1'");
                }
                $pdo->exec("FLUSH PRIVILEGES");
                return ['ok'=>true,'msg'=>"User {$u} created".($dbn?" with access to {$dbn}":'').'.'];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'dbuser_drop': {
            $u = trim($_POST['user'] ?? ''); if (!preg_match('/^[A-Za-z0-9_]{1,32}$/',$u)) return ['ok'=>false,'error'=>'Invalid user'];
            if (in_array($u, ['root','mysql','panel_admin','mariadb.sys'])) return ['ok'=>false,'error'=>'Protected account'];
            try { $pdo=db(); foreach(['localhost','127.0.0.1','%'] as $hh){ try{ $pdo->exec("DROP USER '".$u."'@'".$hh."'"); }catch(Exception $e){} } $pdo->exec("FLUSH PRIVILEGES"); return ['ok'=>true,'msg'=>"User {$u} removed."]; }
            catch (Exception $e) { return internalFail($e); }
        }

        // -- SQL Browser (phpMyAdmin-style) --
        case 'sql_tables': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            try {
                $tables = [];
                foreach ($pdo->query("SHOW TABLE STATUS")->fetchAll() as $t) {
                    $tables[] = ['name'=>$t['Name'],'rows'=>(int)($t['Rows']??0),'engine'=>$t['Engine']??'','size'=>fmtSize(($t['Data_length']??0)+($t['Index_length']??0))];
                }
                return ['ok'=>true,'tables'=>$tables,'db'=>$_POST['db']??''];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_browse': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $table = $_POST['table'] ?? ''; $pg = max(1,(int)($_POST['page']??1));
            $sort = $_POST['sort'] ?? ''; $dir = strtoupper($_POST['sort_dir']??'ASC'); if(!in_array($dir,['ASC','DESC']))$dir='ASC';
            try {
                $qt = qIdent($table);
                $count = (int)$pdo->query("SELECT COUNT(*) FROM $qt")->fetchColumn();
                $pages = max(1,(int)ceil($count/PAGER_ROWS)); $offset=($pg-1)*PAGER_ROWS;
                $colMeta = $pdo->query("SHOW COLUMNS FROM $qt")->fetchAll();
                $columns = array_column($colMeta,'Field');
                $order=''; if($sort && in_array($sort,$columns)) $order=" ORDER BY ".qIdent($sort)." $dir";
                $rows = $pdo->query("SELECT * FROM $qt$order LIMIT $offset, ".PAGER_ROWS)->fetchAll();
                return ['ok'=>true,'rows'=>$rows,'columns'=>$columns,'page'=>$pg,'pages'=>$pages,'total'=>$count,'table'=>$table,'pk'=>sqlPk($pdo,$table),'sort'=>$sort,'sort_dir'=>$dir];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_structure': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $qt = qIdent($_POST['table'] ?? '');
            try {
                return ['ok'=>true,'columns'=>$pdo->query("SHOW FULL COLUMNS FROM $qt")->fetchAll(),
                        'indexes'=>$pdo->query("SHOW INDEX FROM $qt")->fetchAll(),
                        'create_sql'=>$pdo->query("SHOW CREATE TABLE $qt")->fetchColumn(1)];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_columns': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            try { return ['ok'=>true,'columns'=>$pdo->query("SHOW COLUMNS FROM ".qIdent($_POST['table']??''))->fetchAll(),'pk'=>sqlPk($pdo,$_POST['table']??'')]; }
            catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_insert_row': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $data = json_decode($_POST['data'] ?? '[]', true); if (!is_array($data)||!$data) return ['ok'=>false,'error'=>'No data'];
            try {
                $cols = implode(', ', array_map('qIdent', array_keys($data)));
                $ph = implode(', ', array_fill(0,count($data),'?'));
                $st = $pdo->prepare("INSERT INTO ".qIdent($_POST['table']??'')." ($cols) VALUES ($ph)");
                $st->execute(array_map(fn($v)=>$v===''?null:$v, array_values($data)));
                return ['ok'=>true,'msg'=>'Row inserted','id'=>$pdo->lastInsertId()];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_edit_row': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $data = json_decode($_POST['data'] ?? '[]', true); $pkc=$_POST['pk_col']??''; $pkv=$_POST['pk_val']??'';
            if (!is_array($data)||!$data) return ['ok'=>false,'error'=>'No data'];
            if ($pkc==='') return ['ok'=>false,'error'=>'This table has no primary key - edit it via the SQL tab'];
            try {
                $sets = implode(', ', array_map(fn($k)=>qIdent($k).' = ?', array_keys($data)));
                $st = $pdo->prepare("UPDATE ".qIdent($_POST['table']??'')." SET $sets WHERE ".qIdent($pkc)." = ?");
                $vals = array_values($data); $vals[]=$pkv; $st->execute($vals);
                return ['ok'=>true,'msg'=>'Row updated'];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_delete_rows': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $pks = (array)($_POST['pks'] ?? []); $pkc=$_POST['pk_col']??'';
            if (!$pks) return ['ok'=>false,'error'=>'No rows selected'];
            if ($pkc==='') return ['ok'=>false,'error'=>'This table has no primary key'];
            try {
                $ph = implode(', ', array_fill(0,count($pks),'?'));
                $st = $pdo->prepare("DELETE FROM ".qIdent($_POST['table']??'')." WHERE ".qIdent($pkc)." IN ($ph)");
                $st->execute(array_values($pks));
                return ['ok'=>true,'msg'=>'Deleted '.$st->rowCount().' row(s)'];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_query': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $q = trim($_POST['query'] ?? ''); if ($q==='') return ['ok'=>false,'error'=>'Empty query'];
            try {
                $st = $pdo->query($q);
                if ($st && $st->columnCount()) { $rows=$st->fetchAll(); return ['ok'=>true,'type'=>'select','rows'=>$rows,'columns'=>$rows?array_keys($rows[0]):[],'count'=>count($rows)]; }
                return ['ok'=>true,'type'=>'exec','affected'=>$st?$st->rowCount():0,'msg'=>'OK - '.($st?$st->rowCount():0).' row(s) affected'];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_table_op': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $qt = qIdent($_POST['table'] ?? ''); $op = $_POST['op'] ?? '';
            try {
                if ($op==='drop')        { $pdo->exec("DROP TABLE $qt"); return ['ok'=>true,'msg'=>'Table dropped']; }
                if ($op==='empty')       { $pdo->exec("TRUNCATE TABLE $qt"); return ['ok'=>true,'msg'=>'Table emptied']; }
                if ($op==='optimize')    { $pdo->exec("OPTIMIZE TABLE $qt"); return ['ok'=>true,'msg'=>'Table optimized']; }
                return ['ok'=>false,'error'=>'Unknown operation'];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_create_table': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $name = trim($_POST['name'] ?? ''); $cols = json_decode($_POST['cols'] ?? '[]', true);
            if (!validIdent($name)) return ['ok'=>false,'error'=>'Invalid table name'];
            if (!is_array($cols)||!$cols) return ['ok'=>false,'error'=>'Add at least one column'];
            try {
                $defs = [];
                foreach ($cols as $c) {
                    $cn = trim($c['name']??''); $ct = trim($c['type']??'VARCHAR(255)');
                    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/',$cn)) continue;
                    if (!preg_match('/^[A-Za-z0-9_(),\s]{1,64}$/',$ct)) $ct='VARCHAR(255)';
                    $d = qIdent($cn).' '.$ct;
                    if (!empty($c['nn'])) $d.=' NOT NULL';
                    if (!empty($c['ai'])) $d.=' AUTO_INCREMENT';
                    if (!empty($c['pk'])) $d.=' PRIMARY KEY';
                    $defs[] = $d;
                }
                if (!$defs) return ['ok'=>false,'error'=>'No valid columns'];
                $pdo->exec("CREATE TABLE ".qIdent($name)." (".implode(', ',$defs).") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                return ['ok'=>true,'msg'=>"Table {$name} created"];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_export': {
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Connect failed: '.dbError()];
            $table = $_POST['table'] ?? ''; $all = ($table==='' || !empty($_POST['all']));
            try {
                $tables = $all ? $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) : [$table];
                $sql = "-- Orizen Panel export . db `".($_POST['db']??'')."` . ".date('Y-m-d H:i:s')."\nSET FOREIGN_KEY_CHECKS=0;\n\n";
                foreach ($tables as $tb) {
                    $qt = qIdent($tb);
                    $sql .= "DROP TABLE IF EXISTS $qt;\n".$pdo->query("SHOW CREATE TABLE $qt")->fetchColumn(1).";\n\n";
                    $off=0;
                    while (true) {
                        $rows = $pdo->query("SELECT * FROM $qt LIMIT 500 OFFSET $off")->fetchAll();
                        if (!$rows) break;
                        foreach ($rows as $row) {
                            $c = implode(', ', array_map('qIdent', array_keys($row)));
                            $v = implode(', ', array_map(fn($x)=>$x===null?'NULL':$pdo->quote($x), array_values($row)));
                            $sql .= "INSERT INTO $qt ($c) VALUES ($v);\n";
                        }
                        $off += 500;
                    }
                    $sql .= "\n";
                }
                $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
                return ['ok'=>true,'dump'=>$sql,'filename'=>($_POST['db']??'export').($all?'':'_'.$table).'.sql'];
            } catch (Exception $e) { return internalFail($e); }
        }
        case 'sql_import': {
            @set_time_limit(0);
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) return ['ok'=>false,'error'=>'Select a database first ('.dbError().')'];
            if (!empty($_FILES['sql_file']) && is_uploaded_file($_FILES['sql_file']['tmp_name']??'')) { $tmp=$_FILES['sql_file']['tmp_name']; $owned=false; }
            elseif (trim((string)($_POST['sql']??'')) !== '') { $tmp=tempnam(sys_get_temp_dir(),'orsql'); file_put_contents($tmp,(string)$_POST['sql']); $owned=true; }
            else return ['ok'=>false,'error'=>'Choose a .sql file or paste SQL'];
            $r = streamImportSql($pdo,$tmp); if (!empty($owned)) @unlink($tmp);
            return ['ok'=>true,'msg'=>"Imported: {$r['executed']} statement(s), {$r['errors']} error(s)",'executed'=>$r['executed'],'errors'=>$r['errors'],'error_msgs'=>$r['error_msgs']];
        }
        case 'sql_import_chunk': {
            $id = $_POST['import_id'] ?? ''; if (!preg_match('/^[A-Za-z0-9_]{1,80}$/',$id)) return ['ok'=>false,'error'=>'Bad import id'];
            if (empty($_FILES['blob']) || !is_uploaded_file($_FILES['blob']['tmp_name']??'')) return ['ok'=>false,'error'=>'No chunk data'];
            $chunk = (int)($_POST['chunk']??0);
            $path = rtrim(sys_get_temp_dir(),'/\\').'/orizen_sqlimport_'.$id.'.sql';
            $data = @file_get_contents($_FILES['blob']['tmp_name']);
            if ($data===false || @file_put_contents($path,$data,$chunk===0?0:FILE_APPEND)===false) return ['ok'=>false,'error'=>'Temp write failed'];
            return ['ok'=>true,'chunk'=>$chunk];
        }
        case 'sql_import_run': {
            @set_time_limit(0);
            $id = $_POST['import_id'] ?? ''; if (!preg_match('/^[A-Za-z0-9_]{1,80}$/',$id)) return ['ok'=>false,'error'=>'Bad import id'];
            $path = rtrim(sys_get_temp_dir(),'/\\').'/orizen_sqlimport_'.$id.'.sql';
            if (!is_file($path)) return ['ok'=>false,'error'=>'Uploaded file not found'];
            $pdo = sqlPdo($_POST['db'] ?? ''); if (!$pdo) { @unlink($path); return ['ok'=>false,'error'=>'Select a database first']; }
            $r = streamImportSql($pdo,$path); @unlink($path);
            return ['ok'=>true,'msg'=>"Imported: {$r['executed']} statement(s), {$r['errors']} error(s)",'executed'=>$r['executed'],'errors'=>$r['errors'],'error_msgs'=>$r['error_msgs']];
        }

        // -- Backups (website files + databases) --
        case 'backup_create': {
            @set_time_limit(0);
            $domain = trim($_POST['domain'] ?? '');
            $dbs = array_values(array_filter(array_map('trim', explode(',', $_POST['dbs'] ?? '')), fn($x)=>$x!=='' && validIdent($x)));
            $site = null; foreach (loadJson(SITES_FILE, []) as $s) if (($s['domain']??'')===$domain) { $site=$s; break; }
            if (!$site) return ['ok'=>false,'error'=>'Pick one of your websites'];
            $docroot = realpath($site['docroot']);
            if (!$docroot || !is_dir($docroot)) return ['ok'=>false,'error'=>"The site's folder was not found: ".$site['docroot']];
            if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0750, true);
            $stamp = date('Ymd-His'); $safe = preg_replace('/[^A-Za-z0-9.-]/','_',$domain);
            $stage = BACKUP_DIR.'/_stage_'.$stamp; @mkdir($stage.'/databases', 0750, true);
            $done = [];
            foreach ($dbs as $db) if (writeDbDump($db, $stage.'/databases/'.$db.'.sql')) $done[] = $db;
            @file_put_contents($stage.'/manifest.json', json_encode(['domain'=>$domain,'docroot'=>$site['docroot'],'databases'=>$done,'created'=>date('c')], JSON_PRETTY_PRINT));
            if (isWin()) {
                $archive = BACKUP_DIR.'/'.$safe.'_'.$stamp.'.zip';
                $paths = "'".str_replace('/','\\',$docroot)."\\*','".str_replace('/','\\',$stage)."\\databases','".str_replace('/','\\',$stage)."\\manifest.json'";
                sh('powershell -NoProfile -Command "Compress-Archive -Path '.$paths.' -DestinationPath \''.str_replace('/','\\',$archive).'\' -Force"');
            } else {
                $archive = BACKUP_DIR.'/'.$safe.'_'.$stamp.'.tar.gz';
                sh('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($docroot).' . -C '.escapeshellarg($stage).' databases manifest.json 2>/dev/null');
            }
            rdelete($stage);
            if (!is_file($archive)) return ['ok'=>false,'error'=>'Could not create the archive (Linux needs "tar", Windows needs PowerShell).'];
            return ['ok'=>true,'msg'=>'Backup created: '.basename($archive).' ('.fmtSize(filesize($archive)).')'.($dbs && !$done ? ' - note: the selected database(s) could not be dumped.' : '')];
        }
        case 'backup_list': {
            $out = [];
            foreach (@scandir(BACKUP_DIR) ?: [] as $f) {
                if ($f === '' || $f[0]==='.' || $f[0]==='_') continue;
                $p = BACKUP_DIR.'/'.$f; if (!is_file($p)) continue;
                $out[] = ['name'=>$f,'path'=>$p,'size'=>fmtSize(@filesize($p) ?: 0),'date'=>date('Y-m-d H:i', @filemtime($p) ?: 0)];
            }
            usort($out, fn($a,$b)=>strcmp($b['name'],$a['name']));
            return ['ok'=>true,'backups'=>$out];
        }
        case 'backup_delete': {
            $name = basename($_POST['name'] ?? '');
            $p = realpath(BACKUP_DIR.'/'.$name); $bd = realpath(BACKUP_DIR);
            if (!$p || !$bd || strpos($p, $bd) !== 0 || !is_file($p)) return ['ok'=>false,'error'=>'Backup not found'];
            return @unlink($p) ? ['ok'=>true,'msg'=>'Backup deleted'] : ['ok'=>false,'error'=>'Could not delete'];
        }
        case 'backup_restore': {
            @set_time_limit(0);
            $name = basename($_POST['name'] ?? '');
            $archive = realpath(BACKUP_DIR.'/'.$name); $bd = realpath(BACKUP_DIR);
            if (!$archive || !$bd || strpos($archive, $bd) !== 0 || !is_file($archive)) return ['ok'=>false,'error'=>'Backup not found'];
            $tmp = BACKUP_DIR.'/_restore_'.time(); @mkdir($tmp, 0750, true);
            if (isWin()) sh('powershell -NoProfile -Command "Expand-Archive -Path \''.str_replace('/','\\',$archive).'\' -DestinationPath \''.str_replace('/','\\',$tmp).'\' -Force"');
            else sh('tar -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($tmp).' 2>/dev/null');
            $manifest = json_decode(@file_get_contents($tmp.'/manifest.json') ?: '{}', true) ?: [];
            $docroot = $manifest['docroot'] ?? '';
            $fileCount = 0;
            if ($docroot && (is_dir($docroot) || @mkdir($docroot, 0755, true))) {
                foreach (@scandir($tmp) ?: [] as $f) { if (in_array($f, ['.','..','databases','manifest.json'], true)) continue; if (rcopy($tmp.'/'.$f, rtrim($docroot,'/').'/'.$f)) $fileCount++; }
            }
            $imported = [];
            foreach (@glob($tmp.'/databases/*.sql') ?: [] as $sqlf) {
                $db = basename($sqlf, '.sql'); if (!validIdent($db)) continue;
                if ($base = db()) { try { $base->exec("CREATE DATABASE IF NOT EXISTS ".qIdent($db)." CHARACTER SET utf8mb4"); } catch (Exception $e) {} }
                if ($pdo = sqlPdo($db)) { $r = streamImportSql($pdo, $sqlf); $imported[] = "$db ({$r['executed']} statements)"; }
            }
            rdelete($tmp);
            return ['ok'=>true,'msg'=>'Restored files to '.($docroot ?: '(unknown)').($imported ? ' and databases: '.implode(', ', $imported) : '').'.'];
        }
        case 'backup_import': {   // upload a backup archive from the user's computer into the backups folder (chunked)
            @set_time_limit(0);
            $name = basename((string)($_POST['name'] ?? ''));
            if (!preg_match('/\.(tar\.gz|tgz|zip)$/i', $name)) return ['ok'=>false,'error'=>'Please choose an Orizen backup file (.tar.gz or .zip).'];
            if (empty($_FILES['blob']) || !is_uploaded_file($_FILES['blob']['tmp_name'] ?? '')) return ['ok'=>false,'error'=>'No file data received.'];
            $chunk = (int)($_POST['chunk'] ?? 0); $chunks = max(1, (int)($_POST['chunks'] ?? 1));
            if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0750, true);
            $part = BACKUP_DIR.'/_import_'.md5($name).'.part';
            if ($chunk === 0) @unlink($part);
            $data = @file_get_contents($_FILES['blob']['tmp_name']);
            if ($data === false || @file_put_contents($part, $data, $chunk === 0 ? 0 : FILE_APPEND) === false) return ['ok'=>false,'error'=>'Could not write the upload (folder permissions?).'];
            if ($chunk >= $chunks - 1) {
                $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                $final = BACKUP_DIR.'/'.$safe;
                if (is_file($final)) $final = BACKUP_DIR.'/imported-'.date('Ymd-His').'-'.$safe;   // never clobber an existing backup
                if (!@rename($part, $final)) { @copy($part, $final); @unlink($part); }
                if (!is_file($final)) return ['ok'=>false,'error'=>'Could not finalize the imported file.'];
                return ['ok'=>true,'done'=>true,'file'=>basename($final),'msg'=>'Backup imported: '.basename($final).'. Use Restore on it below to put it onto the server.'];
            }
            return ['ok'=>true,'chunk'=>$chunk];
        }

        // -- Email --
        case 'mail_install': {   // one-click: install Postfix+Dovecot(+OpenDKIM) and bootstrap them
            if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
            if (cfgGet('mail_enabled')) return ['ok'=>true,'msg'=>'Mail server is already installed.'];
            $mailname = (string)cfgGet('mail_host', 'mail.'.cfgGet('primary_domain', 'localhost'));
            $ip = (string)cfgGet('server_ip', '127.0.0.1');
            $r = helper('mail-install', [$mailname]);
            if (($r['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>'Mail packages did not install: '.trim((string)$r['out'])];
            $r2 = helper('mail-bootstrap', [$mailname, $ip]);
            if (($r2['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>'Mail installed but setup had a problem: '.trim((string)$r2['out'])];
            $c = cfg(); $c['mail_enabled'] = true; saveCfg($c);
            auditLog('mail_installed', $mailname);
            return ['ok'=>true,'msg'=>'Mail server installed and enabled. You can now create email accounts.'];
        }
        case 'mail_domain_add': {
            $d = strtolower(trim($_POST['domain'] ?? '')); if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            $r = helper('mail-add-domain', [$d]); if ($r['code']!==0) return internalFail(new RuntimeException('mail-add-domain: '.$r['out']), 'mail_domain_add');
            $m = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
            if (!in_array($d, $m['domains'])) { $m['domains'][]=$d; saveJson(MAIL_FILE,$m); }
            // Cloudflare auto mode: set the email DNS (MX/SPF/DKIM/DMARC) for the operator.
            if (cfAutoEnabled()) {
                $e = cfEnsureEmailDns($d);
                if (!empty($e['ok'])) return ['ok'=>true,'msg'=>"Email configured successfully for {$d}.".(!empty($e['dkim_pending'])?' (DKIM key finishes generating shortly - re-open this page in a minute to publish it.)':'')];
            }
            $mh = (string)cfgGet('mail_host', ''); if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $mh)) $mh = 'mail.'.$d;
            return ['ok'=>true,'msg'=>"Mail domain {$d} added. Point an A record {$mh} -> ".cfgGet('server_ip', 'your server IP')." (DNS-only), set MX {$d} -> {$mh}, and add the SPF/DKIM/DMARC records from the DNS page."];
        }
        case 'mail_domain_del': {
            $d = strtolower(trim($_POST['domain'] ?? '')); if (!validDomain($d)) return ['ok'=>false,'error'=>'Invalid domain'];
            helper('mail-del-domain', [$d]);
            $m = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
            $m['domains'] = array_values(array_diff($m['domains'], [$d]));
            $m['mailboxes'] = array_values(array_filter($m['mailboxes'], fn($b)=>!str_ends_with($b,'@'.$d)));
            saveJson(MAIL_FILE,$m);
            return ['ok'=>true,'msg'=>"Removed {$d}."];
        }
        case 'mailbox_add': {
            if (!cfgGet('mail_enabled')) return ['ok'=>false,'error'=>'The mail server isn\'t installed yet. Open the Email page and click "Install mail server" first, then create the account.'];
            $e = strtolower(trim($_POST['email'] ?? '')); $p = $_POST['pass'] ?? '';
            if (!validEmail($e)) return ['ok'=>false,'error'=>'Enter a valid email like you@yourdomain.com'];
            if (strlen($p) < 6) return ['ok'=>false,'error'=>'Password too short (min 6 characters)'];
            $domain = substr(strrchr($e, '@'), 1);
            if (!validDomain($domain)) return ['ok'=>false,'error'=>'The domain part of that email looks invalid'];
            $m = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
            $newDomain = false;
            if (!in_array($domain, $m['domains'], true)) {   // auto-create the mail domain - one-step account setup
                $rd = helper('mail-add-domain', [$domain]);
                if ($rd['code'] !== 0) return ['ok'=>false,'error'=>"Could not set up mail for {$domain}: ".$rd['out']];
                $m['domains'][] = $domain; $newDomain = true;
            }
            $hash = crypt($p, '$6$' . bin2hex(random_bytes(8)) . '$'); // SHA-512 crypt for Dovecot
            $r = helper('mail-add-box', [$e, $hash]); if ($r['code']!==0) return ['ok'=>false,'error'=>$r['out']];
            if (!in_array($e, $m['mailboxes'], true)) $m['mailboxes'][] = $e;
            saveJson(MAIL_FILE, $m);
            $msg = "Email account {$e} is ready. Open it in Webmail, or connect a mail app (IMAP 993 / SMTP 587).";
            if ($newDomain) {
                if (cfAutoEnabled() && !empty(cfEnsureEmailDns($domain)['ok'])) $msg .= " Email DNS for {$domain} was configured on Cloudflare automatically.";
                else $msg .= " This is the first account for {$domain} - add its mail DNS records (MX/SPF/DKIM/DMARC) from the DNS page so other servers trust it.";
            }
            return ['ok'=>true,'msg'=>$msg];
        }
        case 'mailbox_passwd': {
            $e = strtolower(trim($_POST['email'] ?? '')); $p = $_POST['pass'] ?? '';
            if (!validEmail($e) || strlen($p)<6) return ['ok'=>false,'error'=>'Invalid email or password'];
            $hash = crypt($p, '$6$' . bin2hex(random_bytes(8)) . '$');
            $r = helper('mail-passwd', [$e, $hash]); if ($r['code']!==0) return ['ok'=>false,'error'=>$r['out']];
            return ['ok'=>true,'msg'=>"Password updated for {$e}."];
        }
        case 'mailbox_del': {
            $e = strtolower(trim($_POST['email'] ?? '')); if (!validEmail($e)) return ['ok'=>false,'error'=>'Invalid email'];
            helper('mail-del-box', [$e]);
            $m = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
            $m['mailboxes'] = array_values(array_diff($m['mailboxes'], [$e]));
            saveJson(MAIL_FILE,$m);
            return ['ok'=>true,'msg'=>"Mailbox {$e} removed."];
        }

        // -- Webmail (read / reply / delete via IMAP) --
        case 'webmail_login': {
            if (!wmAvailable()) return ['ok'=>false,'error'=>'The PHP IMAP extension is not installed on the server. Run: sudo apt install php-imap (or php8.x-imap), then restart Apache.'];
            $email = strtolower(trim($_POST['email'] ?? '')); $pass = (string)($_POST['pass'] ?? '');
            if (!validEmail($email) || $pass === '') return ['ok'=>false,'error'=>'Enter the email address and its password'];
            $host = cfgGet('imap_host', 'localhost');
            $imap = @imap_open('{'.$host.':993/imap/ssl/novalidate-cert}INBOX', $email, $pass, 0, 1);
            if (!$imap) { $err = imap_last_error(); @imap_errors(); return ['ok'=>false,'error'=>'Could not sign in: '.($err ?: 'check the email and password').'.']; }
            @imap_close($imap);
            $_SESSION['wm'] = ['email'=>$email, 'pass'=>$pass];
            return ['ok'=>true,'msg'=>'Signed in as '.$email];
        }
        case 'webmail_logout': { unset($_SESSION['wm']); return ['ok'=>true]; }
        case 'webmail_list': {
            $imap = wmConn(); if (!$imap) return ['ok'=>false,'error'=>'not_connected'];
            $total = imap_num_msg($imap); $per = 25;
            $page = max(1, (int)($_POST['page'] ?? 1)); $pages = max(1, (int)ceil($total / $per));
            $start = $total - ($page - 1) * $per; $end = max(1, $start - $per + 1);
            $msgs = [];
            for ($i = $start; $i >= $end && $i >= 1; $i--) {
                $h = @imap_headerinfo($imap, $i); if (!$h) continue;
                $msgs[] = ['uid'=>imap_uid($imap, $i), 'from'=>wmAddr($h->from ?? []) ?: '(unknown)',
                    'subject'=>trim(imap_utf8($h->subject ?? '')) ?: '(no subject)',
                    'date'=>date('M j, H:i', strtotime($h->date ?? 'now') ?: time()),
                    'unread'=>(($h->Unseen ?? '') === 'U' || ($h->Recent ?? '') === 'N')];
            }
            @imap_close($imap);
            return ['ok'=>true,'messages'=>$msgs,'total'=>$total,'page'=>$page,'pages'=>$pages];
        }
        case 'webmail_read': {
            $imap = wmConn(); if (!$imap) return ['ok'=>false,'error'=>'not_connected'];
            $uid = (int)($_POST['uid'] ?? 0); if (!$uid) { @imap_close($imap); return ['ok'=>false,'error'=>'bad id']; }
            $h = @imap_headerinfo($imap, imap_msgno($imap, $uid));
            $body = wmGetBody($imap, $uid);
            @imap_setflag_full($imap, (string)$uid, "\\Seen", ST_UID);
            $fromAddr = !empty($h->from[0]) ? (($h->from[0]->mailbox ?? '').'@'.($h->from[0]->host ?? '')) : '';
            @imap_close($imap);
            return ['ok'=>true,'from'=>wmAddr($h->from ?? []),'from_addr'=>$fromAddr,'to'=>wmAddr($h->to ?? []),
                'subject'=>trim(imap_utf8($h->subject ?? '')) ?: '(no subject)',
                'date'=>date('M j, Y H:i', strtotime($h->date ?? 'now') ?: time()),'body'=>$body];
        }
        case 'webmail_delete': {
            $imap = wmConn(); if (!$imap) return ['ok'=>false,'error'=>'not_connected'];
            $uid = (int)($_POST['uid'] ?? 0); if (!$uid) { @imap_close($imap); return ['ok'=>false,'error'=>'bad id']; }
            imap_delete($imap, (string)$uid, FT_UID); imap_expunge($imap); @imap_close($imap);
            return ['ok'=>true,'msg'=>'Message deleted'];
        }
        case 'webmail_send': {
            if (empty($_SESSION['wm'])) return ['ok'=>false,'error'=>'not_connected'];
            $to = trim($_POST['to'] ?? ''); $subject = trim($_POST['subject'] ?? ''); $body = (string)($_POST['body'] ?? '');
            $first = trim(explode(',', $to)[0] ?? '');
            if (!validEmail($first)) return ['ok'=>false,'error'=>'Enter a valid recipient email address'];
            $from = $_SESSION['wm']['email'];
            $headers = "From: $from\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: Orizen Panel Webmail";
            $ok = @mail($to, $subject !== '' ? $subject : '(no subject)', $body, $headers, '-f'.$from);
            return $ok ? ['ok'=>true,'msg'=>'Message sent to '.$to] : ['ok'=>false,'error'=>'Could not send the message (is the mail server running?).'];
        }

        // -- Firewall --
        case 'fw': {
            $op = $_POST['op'] ?? ''; $port = (string)($_POST['port'] ?? ''); $proto = ($_POST['proto'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp';
            if (!in_array($op, ['allow','deny','delete-allow','enable','disable'], true)) return ['ok'=>false,'error'=>'Invalid op'];
            if (in_array($op, ['allow','deny','delete-allow'], true) && !preg_match('/^\d{1,5}$/', $port)) return ['ok'=>false,'error'=>'Enter a port number (1-65535)'];
            $r = helper('firewall', [$op, $port, $proto]);
            if (($r['code'] ?? 1) !== 0) return ['ok'=>false,'error'=>trim((string)$r['out'])];
            $msg = ['allow'=>"Port $port/$proto is now OPEN.", 'deny'=>"Port $port/$proto is DENIED.", 'delete-allow'=>"Port $port is CLOSED.", 'enable'=>'Firewall enabled (SSH, web and the panel port stay open).', 'disable'=>'Firewall disabled - all ports are open.'][$op] ?? 'Done.';
            return ['ok'=>true,'msg'=>$msg];
        }
        case 'fw_info': {   // active state + list of open ports, for the Firewall page UI
            $act  = trim((string)(helper('firewall', ['active'])['out'] ?? ''));
            $list = trim((string)(helper('firewall', ['list'])['out'] ?? ''));
            $ports = array_values(array_filter(array_map('trim', preg_split('/\s+/', $list))));
            return ['ok'=>true, 'active'=>($act==='active'), 'ports'=>$ports];
        }

        // -- Services --
        case 'service': {
            $svc = $_POST['svc'] ?? ''; $act = $_POST['act'] ?? '';
            $r = helper('service', [$svc, $act]); return $r['code']===0 ? ['ok'=>true,'msg'=>"{$svc} {$act} ok\n".$r['out']] : ['ok'=>false,'error'=>$r['out']];
        }

        // -- Console (as web user) --
        case 'console': {
            $c = $_POST['cmd'] ?? ''; if ($c==='') return ['ok'=>false,'error'=>'empty'];
            return ['ok'=>true,'out'=>sh($c)];
        }

        // -- File Manager --
        case 'file_save': {
            $rf = realpath($_POST['file'] ?? '');
            if (!$rf || !is_file($rf)) return ['ok'=>false,'error'=>'File not found'];
            if (!is_writable($rf)) return ['ok'=>false,'error'=>'Not writable by the panel - fix ownership: sudo chown -R '.cfgGet('web_user','www-data').' '.escapeshellarg(dirname($rf))];
            return @file_put_contents($rf, $_POST['content'] ?? '') !== false ? ['ok'=>true,'msg'=>'Saved'] : ['ok'=>false,'error'=>'Write failed'];
        }
        case 'file_load': {
            $rf = realpath($_POST['file'] ?? ''); if (!$rf || !is_file($rf)) return ['ok'=>false,'error'=>'Not found'];
            return ['ok'=>true,'content'=>(string)@file_get_contents($rf),'writable'=>is_writable($rf),'path'=>str_replace('\\','/',$rf)];
        }
        case 'file_new': {
            $dir = realpath($_POST['dir'] ?? ''); $name = basename($_POST['name'] ?? '');
            if (!$dir || $name==='') return ['ok'=>false,'error'=>'Invalid name/folder'];
            $t = $dir.'/'.$name;
            if (file_exists($t)) return ['ok'=>false,'error'=>'Already exists'];
            $ok = !empty($_POST['isdir']) ? @mkdir($t,0755) : (@file_put_contents($t,'')!==false);
            return $ok ? ['ok'=>true,'msg'=>'Created '.$name] : ['ok'=>false,'error'=>'Could not create (permission?)'];
        }
        case 'file_del': {
            $paths = $_POST['paths'] ?? ($_POST['path'] ?? []); if (!is_array($paths)) $paths = [$paths];
            $n = 0; $failed = [];
            foreach ($paths as $p) {
                $r = realpath($p); if (!$r) continue;
                if (rdelete($r)) { $n++; continue; }
                // Permission fallback: some files are owned by root / the isolated site user and the
                // web user cannot remove them. Delete via the validated root helper (path-restricted to web roots).
                $hr = helper('fs-delete', [$r]);
                if (($hr['code'] ?? 1) === 0 && !file_exists($r)) $n++; else $failed[] = basename($r);
            }
            if ($n && !$failed) return ['ok'=>true,'msg'=>"Deleted $n item(s)"];
            if ($n) return ['ok'=>true,'msg'=>"Deleted $n item(s); could not remove: ".implode(', ', $failed)];
            return ['ok'=>false,'error'=>'Could not delete the selected item(s).'];
        }
        case 'file_rename': {
            $rf = realpath($_POST['old'] ?? ''); $new = basename($_POST['new'] ?? '');
            if (!$rf || $new==='') return ['ok'=>false,'error'=>'Invalid'];
            $t = dirname($rf).'/'.$new;
            if (file_exists($t)) return ['ok'=>false,'error'=>'A file with that name already exists'];
            return @rename($rf,$t) ? ['ok'=>true,'msg'=>'Renamed'] : ['ok'=>false,'error'=>'Rename failed (permission?)'];
        }
        case 'file_copy': {
            $files = (array)($_POST['files'] ?? []); $dest = realpath($_POST['dest'] ?? '');
            if (!$dest || !is_dir($dest)) return ['ok'=>false,'error'=>'Destination folder not found'];
            $n = 0; foreach ($files as $f) { $r = realpath($f); if (!$r) continue; $t = $dest.'/'.basename($r); if ($r===$t) continue; if (rcopy($r,$t)) $n++; }
            return ['ok'=>true,'msg'=>"Copied $n item(s)"];
        }
        case 'file_move': {
            $files = (array)($_POST['files'] ?? []); $dest = realpath($_POST['dest'] ?? '');
            if (!$dest || !is_dir($dest)) return ['ok'=>false,'error'=>'Destination folder not found'];
            $n = 0; foreach ($files as $f) { $r = realpath($f); if (!$r) continue; $t = $dest.'/'.basename($r); if ($r===$t) continue; if (@rename($r,$t)) $n++; }
            return ['ok'=>true,'msg'=>"Moved $n item(s)"];
        }
        case 'file_duplicate': {
            $r = realpath($_POST['file'] ?? ''); if (!$r) return ['ok'=>false,'error'=>'Not found'];
            $dir = dirname($r); $base = basename($r); $ext = pathinfo($base, PATHINFO_EXTENSION); $stem = $ext!=='' ? substr($base,0,-(strlen($ext)+1)) : $base;
            $i=1; do { $t = $dir.'/'.$stem.' - copy'.($i>1?" ($i)":'').($ext!==''?'.'.$ext:''); $i++; } while (file_exists($t) && $i<200);
            return rcopy($r,$t) ? ['ok'=>true,'msg'=>'Duplicated','name'=>basename($t)] : ['ok'=>false,'error'=>'Duplicate failed'];
        }
        case 'file_chmod': {
            $rf = realpath($_POST['path'] ?? ''); $mode = $_POST['mode'] ?? '';
            if (!$rf) return ['ok'=>false,'error'=>'Not found'];
            if (!preg_match('/^[0-7]{3,4}$/', $mode)) return ['ok'=>false,'error'=>'Invalid mode - use 3-4 octal digits (e.g. 755)'];
            return @chmod($rf, octdec($mode)) ? ['ok'=>true,'msg'=>'Permissions set to '.$mode] : ['ok'=>false,'error'=>'chmod failed (permission?)'];
        }
        case 'file_stat': {
            $rf = realpath($_POST['path'] ?? '');
            if (!$rf || !file_exists($rf)) return ['ok'=>false,'error'=>'Not found'];
            $isDir = is_dir($rf); $st = @stat($rf) ?: [];
            $owner = (function_exists('posix_getpwuid') && isset($st['uid'])) ? (posix_getpwuid($st['uid'])['name'] ?? $st['uid']) : ($st['uid'] ?? '');
            $group = (function_exists('posix_getgrgid') && isset($st['gid'])) ? (posix_getgrgid($st['gid'])['name'] ?? $st['gid']) : ($st['gid'] ?? '');
            $mime = (!$isDir && function_exists('mime_content_type')) ? (@mime_content_type($rf) ?: '') : '';
            $items = $isDir ? count(array_diff(@scandir($rf) ?: [], ['.','..'])) : null;
            return ['ok'=>true, 'name'=>basename($rf), 'path'=>str_replace('\\','/',$rf), 'type'=>$isDir?'folder':'file',
                    'size'=>$isDir?null:(int)($st['size'] ?? 0), 'items'=>$items,
                    'perms_octal'=>substr(sprintf('%o',@fileperms($rf)),-4), 'perms'=>formatPerms($rf),
                    'owner'=>(string)$owner, 'group'=>(string)$group, 'mime'=>$mime,
                    'mtime'=>@filemtime($rf) ?: 0, 'writable'=>is_writable($rf), 'readable'=>is_readable($rf)];
        }
        case 'file_zip': {
            $files = (array)($_POST['files'] ?? []); $dir = realpath($_POST['dir'] ?? ''); $name = basename($_POST['name'] ?? 'archive.zip');
            if (!$dir || !is_dir($dir)) return ['ok'=>false,'error'=>'Invalid folder'];
            if (!str_ends_with(strtolower($name), '.zip')) $name .= '.zip';
            $zipPath = $dir.'/'.$name;
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return ['ok'=>false,'error'=>'Cannot create zip'];
                foreach ($files as $f) { $r = realpath($f); if (!$r) continue; $b = basename($r);
                    if (is_dir($r)) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS)); foreach ($it as $x) $zip->addFile($x->getPathname(), $b.'/'.$it->getSubPathName()); }
                    else $zip->addFile($r, $b);
                }
                $zip->close();
                return is_file($zipPath) ? ['ok'=>true,'msg'=>'Created '.$name] : ['ok'=>false,'error'=>'Zip failed'];
            }
            if (isWin()) {
                $src = []; foreach ($files as $f) { $r = realpath($f); if ($r) $src[] = "'".str_replace('/','\\',$r)."'"; }
                if ($src) sh('powershell -NoProfile -Command "Compress-Archive -Path '.implode(',',$src).' -DestinationPath \''.str_replace('/','\\',$zipPath).'\' -Force"');
            } else {
                $list = ''; foreach ($files as $f) { $r = realpath($f); if ($r) $list .= ' ' . escapeshellarg($r); }
                sh('cd ' . escapeshellarg($dir) . ' && zip -r ' . escapeshellarg($zipPath) . $list);
            }
            return is_file($zipPath) ? ['ok'=>true,'msg'=>'Created '.$name] : ['ok'=>false,'error'=>'Could not create archive (install php-zip or the zip command)'];
        }
        case 'file_unzip': {
            $rf = realpath($_POST['file'] ?? ''); if (!$rf || !is_file($rf)) return ['ok'=>false,'error'=>'Not found'];
            $dir = dirname($rf);
            if (class_exists('ZipArchive')) { $zip = new ZipArchive(); if ($zip->open($rf) !== true) return ['ok'=>false,'error'=>'Cannot open archive']; $zip->extractTo($dir); $n = $zip->numFiles; $zip->close(); return ['ok'=>true,'msg'=>"Extracted $n item(s)"]; }
            if (isWin()) sh('powershell -NoProfile -Command "Expand-Archive -Path \''.str_replace('/','\\',$rf).'\' -DestinationPath \''.str_replace('/','\\',$dir).'\' -Force"');
            else sh('cd ' . escapeshellarg($dir) . ' && unzip -o ' . escapeshellarg($rf));
            return ['ok'=>true,'msg'=>'Extracted'];
        }
        case 'file_upload_chunk': {
            $dir = realpath($_POST['dir'] ?? ''); $name = basename($_POST['name'] ?? '');
            $chunk = (int)($_POST['chunk'] ?? 0); $chunks = (int)($_POST['chunks'] ?? 1);
            if (!$dir || !is_dir($dir) || $name==='') return ['ok'=>false,'error'=>'Invalid upload target'];
            if (empty($_FILES['blob']) || !is_uploaded_file($_FILES['blob']['tmp_name'] ?? '')) return ['ok'=>false,'error'=>'No data'];
            $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/opul_' . md5($dir.'|'.$name) . '.part';
            $data = @file_get_contents($_FILES['blob']['tmp_name']);
            if (@file_put_contents($tmp, $data, $chunk === 0 ? 0 : FILE_APPEND) === false) return ['ok'=>false,'error'=>'Temp write failed'];
            if ($chunk >= $chunks - 1) {
                $final = $dir.'/'.$name;
                if (!@rename($tmp, $final)) { @copy($tmp, $final); @unlink($tmp); }
                return is_file($final) ? ['ok'=>true,'done'=>true,'file'=>$name] : ['ok'=>false,'error'=>'Could not finalize (permission?)'];
            }
            return ['ok'=>true,'chunk'=>$chunk];
        }
        case 'fm_tree': {   // folders + files of a directory, for the File Manager tree sidebar
            $dir = realpath($_POST['dir'] ?? '');
            if (!$dir || !is_dir($dir)) return ['ok'=>false,'error'=>'Folder not found'];
            $dir = rtrim(str_replace('\\','/',$dir), '/'); if ($dir === '') $dir = '/';
            $dirs = []; $files = [];
            foreach (@scandir($dir) ?: [] as $n) {
                if ($n === '.' || $n === '..') continue;
                $p = $dir.'/'.$n;
                if (is_dir($p)) {
                    $hasChild = false;
                    foreach (@scandir($p) ?: [] as $c) { if ($c !== '.' && $c !== '..') { $hasChild = true; break; } }
                    $dirs[] = ['name'=>$n, 'path'=>$p, 'file'=>false, 'children'=>$hasChild];
                } else {
                    $files[] = ['name'=>$n, 'path'=>$p, 'file'=>true, 'size'=>(int)(@filesize($p) ?: 0)];
                }
            }
            usort($dirs,  fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
            usort($files, fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
            return ['ok'=>true, 'dir'=>$dir, 'items'=>array_merge($dirs, $files)];
        }
        case 'dash_cpu': {     // light endpoint the dashboard polls so CPU/RAM update live
            [$mt, $mUsed] = sysMem();
            return ['ok'=>true, 'cpu'=>cpuPct().'%', 'ram'=>$mt ? fmtSize($mt-$mUsed).' / '.fmtSize($mt) : 'n/a', 'uptime'=>sysUptime()];
        }
        case 'dash_stats': {   // heavy server metrics, loaded async so the dashboard renders instantly
            $dRoot = isWin() ? (substr(__DIR__,0,3) ?: 'C:\\') : '/';
            $df = @disk_free_space($dRoot) ?: 0; $dt = @disk_total_space($dRoot) ?: 1;
            [$mt, $mUsed] = sysMem();
            $dbCount = 0; if (db()){ try { $dbCount = count(array_diff(db()->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN), ['mysql','information_schema','performance_schema','sys'])); } catch(Exception $e){} }
            $svc = [['Apache',webProc()],['MariaDB','mysqld'],['Postfix','master'],['Dovecot','dovecot']];
            $services = []; foreach ($svc as [$label,$proc]) $services[] = ['label'=>$label, 'on'=>svcRunning($proc)];
            return ['ok'=>true,
                'disk'    => fmtSize($dt-$df).' / '.fmtSize($dt),
                'ram'     => $mt ? fmtSize($mt-$mUsed).' / '.fmtSize($mt) : 'n/a',
                'cpu'     => cpuPct().'%',
                'uptime'  => sysUptime(),
                'dbCount' => $dbCount,
                'services'=> $services,
            ];
        }

        // -- Settings --
        case 'set_password': {
            $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? '';
            if (!password_verify($cur, cfgGet('admin_hash'))) return ['ok'=>false,'error'=>'Current password is wrong'];
            if (strlen($new) < 6) return ['ok'=>false,'error'=>'New password too short (min 6)'];
            $c = cfg(); $c['admin_hash'] = password_hash($new, PASSWORD_DEFAULT);
            return saveCfg($c) ? ['ok'=>true,'msg'=>'Password changed.'] : ['ok'=>false,'error'=>'Could not write config'];
        }
        case 'set_features': {
            $c = cfg(); $cur = is_array($c['features'] ?? null) ? $c['features'] : cfgDefaults()['features'];
            foreach ($_POST as $k => $v) {                 // accept any well-formed enable* flag (works for module-added flags too)
                if (!preg_match('/^enable[A-Za-z0-9]+$/', $k)) continue;
                $cur[$k] = ($v === '1' || $v === 1 || $v === true);
            }
            $c['features'] = $cur;
            return saveCfg($c) ? ['ok'=>true,'msg'=>'Features saved.'] : ['ok'=>false,'error'=>'Could not write config'];
        }

        // -- Two-factor authentication (core, managed from Settings; admin-only via deny-by-default) --
        case '2fa_status': {
            return ['ok'=>true,
                'totp'=>(bool)cfgGet('totp_enabled') && cfgGet('totp_secret','')!=='',
                'tg'=>(bool)cfgGet('tg2fa_enabled') && cfgGet('tg2fa_token','')!=='' && cfgGet('tg2fa_chat','')!=='',
                'tg_chat'=>(string)cfgGet('tg2fa_chat','')];
        }
        case '2fa_begin': {   // start authenticator enrolment: secret + otpauth URI + QR (SVG, generated locally)
            $secret = base32Encode(random_bytes(20));
            $_SESSION['totp_pending'] = $secret;
            $issuer = 'Orizen Panel';
            $uri = 'otpauth://totp/'.rawurlencode($issuer.':'.cfgGet('admin_user','admin')).'?secret='.$secret.'&issuer='.rawurlencode($issuer);
            $qr = '';
            if (is_file(HELPER)) { $r = helper('qr', [$uri]); if (($r['code'] ?? 1) === 0) { $s = (string)$r['out']; $p = strpos($s, '<svg'); if ($p !== false) $qr = substr($s, $p); } }
            return ['ok'=>true, 'secret'=>$secret, 'uri'=>$uri, 'qr'=>$qr];
        }
        case '2fa_enable': {
            $pending = (string)($_SESSION['totp_pending'] ?? ''); if ($pending === '') return ['ok'=>false,'error'=>'Start setup again.'];
            if (!totpVerify($pending, (string)($_POST['code'] ?? ''))) return ['ok'=>false,'error'=>'That code is not valid - check your authenticator app and try again.'];
            $c = cfg(); $c['totp_secret'] = $pending; $c['totp_enabled'] = true; saveCfg($c); unset($_SESSION['totp_pending']);
            auditLog('2fa_enabled', cfgGet('admin_user','')); return ['ok'=>true,'msg'=>'Authenticator 2FA is on. You will need a code at your next login.'];
        }
        case '2fa_disable': {   // disable requires a valid current code (verified by the method)
            if (!cfgGet('totp_enabled')) return ['ok'=>true,'msg'=>'Authenticator 2FA is already off.'];
            if (!totpVerify((string)cfgGet('totp_secret',''), (string)($_POST['code'] ?? ''))) return ['ok'=>false,'error'=>'Enter a valid authenticator code to turn it off.'];
            $c = cfg(); $c['totp_enabled'] = false; $c['totp_secret'] = ''; saveCfg($c); auditLog('2fa_disabled', cfgGet('admin_user',''));
            return ['ok'=>true,'msg'=>'Authenticator 2FA turned off.'];
        }
        case 'tg2fa_send': {   // save bot token + chat, send a one-time test code
            $token = trim((string)($_POST['token'] ?? '')); $chat = trim((string)($_POST['chat'] ?? ''));
            if ($token === '' || $chat === '') return ['ok'=>false,'error'=>'Enter both the bot token and your chat ID.'];
            if (!preg_match('~^\d{6,}:[A-Za-z0-9_-]{30,}$~', $token)) return ['ok'=>false,'error'=>'That does not look like a valid Telegram bot token.'];
            $c = cfg(); $c['tg2fa_token'] = $token; $c['tg2fa_chat'] = $chat; saveCfg($c);
            $code = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT); $_SESSION['tg2fa_test'] = $code; $_SESSION['tg2fa_test_t'] = time();
            if (!tgSendMessage($token, $chat, "Orizen Panel test code: $code\nEnter it to switch on Telegram 2FA.")) return ['ok'=>false,'error'=>'Saved, but Telegram would not deliver the message. Check the token and make sure you pressed Start on the bot, then try again.'];
            return ['ok'=>true,'msg'=>'Test code sent to your Telegram - enter it below to enable.'];
        }
        case 'tg2fa_enable': {
            $want = (string)($_SESSION['tg2fa_test'] ?? ''); $t = (int)($_SESSION['tg2fa_test_t'] ?? 0); $code = preg_replace('/\D/','',(string)($_POST['code'] ?? ''));
            if ($want === '' || (time()-$t) > 600) return ['ok'=>false,'error'=>'Send a fresh test code first.'];
            if (strlen($code) !== 6 || !hash_equals($want, $code)) return ['ok'=>false,'error'=>'That code does not match the one we sent.'];
            $c = cfg(); $c['tg2fa_enabled'] = true; saveCfg($c); unset($_SESSION['tg2fa_test'],$_SESSION['tg2fa_test_t']);
            auditLog('tg2fa_enabled', cfgGet('admin_user','')); return ['ok'=>true,'msg'=>'Telegram 2FA is on. A code is sent to your Telegram at each login.'];
        }
        case 'tg2fa_disable': {   // disable requires confirming a fresh Telegram code (verified by the method)
            if (!cfgGet('tg2fa_enabled')) return ['ok'=>true,'msg'=>'Telegram 2FA is already off.'];
            if (($_POST['step'] ?? '') === 'send') { return twofaSendTelegram() ? ['ok'=>true,'msg'=>'Code sent to your Telegram - enter it to confirm turning it off.'] : ['ok'=>false,'error'=>'Could not reach Telegram to send a code.']; }
            $want = (string)($_SESSION['2fa_tg_code'] ?? ''); $t = (int)($_SESSION['2fa_tg_t'] ?? 0); $code = preg_replace('/\D/','',(string)($_POST['code'] ?? ''));
            if ($want === '' || (time()-$t) > 300 || strlen($code) !== 6 || !hash_equals($want, $code)) return ['ok'=>false,'error'=>'Enter the valid Telegram code to turn it off.'];
            $c = cfg(); $c['tg2fa_enabled'] = false; saveCfg($c); unset($_SESSION['2fa_tg_code'],$_SESSION['2fa_tg_t']);
            auditLog('tg2fa_disabled', cfgGet('admin_user','')); return ['ok'=>true,'msg'=>'Telegram 2FA turned off.'];
        }
        // -- Logs --
        case 'log_list': {
            $r = helper('log-list', []); $logs = [];
            foreach (explode("\n", trim($r['out'])) as $ln) { if ($ln==='') continue; $p = explode('|', $ln, 3); if (count($p)===3) $logs[] = ['path'=>$p[1],'name'=>$p[2]]; }
            return ['ok'=>true,'logs'=>$logs];
        }
        case 'log_view': {
            $path = $_POST['path'] ?? ''; $lines = max(10,min(5000,(int)($_POST['lines']??200))); $filter = trim($_POST['filter']??'');
            if (strpos($path,'/var/log/')!==0) return ['ok'=>false,'error'=>'invalid log path'];
            $r = helper('log-tail', [$path, $lines]); $text = $r['out'];
            if ($filter !== '') $text = implode("\n", array_filter(explode("\n",$text), fn($x)=>stripos($x,$filter)!==false));
            return ['ok'=>true,'text'=>$text,'path'=>$path];
        }
        case 'log_clear': {
            $path = $_POST['path'] ?? ''; if (strpos($path,'/var/log/')!==0) return ['ok'=>false,'error'=>'invalid'];
            $r = helper('log-clear', [$path]); return $r['code']===0 ? ['ok'=>true,'msg'=>'Log cleared'] : ['ok'=>false,'error'=>$r['out']];
        }

        // -- Processes --
        case 'proc_list': {
            $out = sh("ps -eo pid,user,pcpu,pmem,comm --sort=-pcpu 2>/dev/null | head -n 60");
            $rows = []; $lines = explode("\n", trim($out)); array_shift($lines);
            foreach ($lines as $ln) { $p = preg_split('/\s+/', trim($ln), 5); if (count($p)>=5) $rows[] = ['pid'=>$p[0],'user'=>$p[1],'cpu'=>$p[2],'mem'=>$p[3],'cmd'=>$p[4]]; }
            return ['ok'=>true,'procs'=>$rows];
        }
        case 'proc_kill': {
            $pid = $_POST['pid'] ?? ''; if (!preg_match('/^[0-9]+$/',$pid)) return ['ok'=>false,'error'=>'bad pid'];
            $r = helper('kill-pid', [$pid]); return $r['code']===0 ? ['ok'=>true,'msg'=>'Killed PID '.$pid] : ['ok'=>false,'error'=>$r['out']];
        }

        // -- Disk usage --
        case 'disk_usage': {
            $real = realpath($_POST['dir'] ?? cfgGet('webroot_base','/var/www'));
            if (!$real || !is_dir($real)) return ['ok'=>false,'error'=>'directory not found'];
            $real = rtrim(str_replace('\\','/',$real),'/'); if ($real==='') $real='/';
            $items = @scandir($real) ?: []; $out = []; $total = 0;
            foreach ($items as $n) { if ($n==='.'||$n==='..') continue; $p=$real.'/'.$n; $sz = is_dir($p) ? dirSize($p) : (@filesize($p)?:0); $total += $sz; $out[] = ['name'=>$n,'path'=>$p,'isDir'=>is_dir($p),'size'=>$sz,'size_h'=>fmtSize($sz)]; }
            usort($out, fn($a,$b)=>$b['size']<=>$a['size']);
            foreach ($out as &$o) $o['pct'] = $total>0 ? round($o['size']/$total*100,1) : 0; unset($o);
            return ['ok'=>true,'dir'=>$real,'items'=>$out,'total_h'=>fmtSize($total),'parent'=>str_replace('\\','/',dirname($real))];
        }

        // -- Network tools --
        case 'net_run': {
            $tool = $_POST['tool'] ?? ''; $host = trim($_POST['host']??''); $win = stripos(PHP_OS,'WIN')===0;
            $vh = $host!=='' && preg_match('#^[A-Za-z0-9._:\-/]{1,255}$#',$host);
            switch ($tool) {
                case 'ping':  if(!$vh) return ['ok'=>false,'error'=>'Enter a host']; return ['ok'=>true,'output'=>sh(($win?'ping -n 4 ':'ping -c 4 ').escapeshellarg($host))];
                case 'dns':   if(!$vh) return ['ok'=>false,'error'=>'Enter a host']; return ['ok'=>true,'output'=>sh('nslookup '.escapeshellarg($host))];
                case 'trace': if(!$vh) return ['ok'=>false,'error'=>'Enter a host']; return ['ok'=>true,'output'=>sh(($win?'tracert -d -h 15 ':'traceroute -m 15 ').escapeshellarg($host))];
                case 'port':  $port=(int)($_POST['port']??0); if(!$vh||$port<1||$port>65535) return ['ok'=>false,'error'=>'Enter host + port']; $t0=microtime(true); $fp=@fsockopen($host,$port,$e,$s,5); $ms=round((microtime(true)-$t0)*1000); if($fp){fclose($fp);return ['ok'=>true,'output'=>"$host:$port is OPEN ({$ms} ms)"];} return ['ok'=>true,'output'=>"$host:$port closed/filtered - $s"];
                case 'ipconfig': return ['ok'=>true,'output'=>sh($win?'ipconfig /all':'ip addr 2>/dev/null; echo "--- routes ---"; ip route 2>/dev/null')];
            }
            return ['ok'=>false,'error'=>'unknown tool'];
        }

        // -- Security (.htaccess / privacy / IP block) --
        case 'sec_load': {
            $real = realpath($_POST['dir'] ?? cfgGet('webroot_base','/var/www')); if (!$real||!is_dir($real)) return ['ok'=>false,'error'=>'directory not found'];
            $real = str_replace('\\','/',$real); $ht = $real.'/.htaccess';
            return ['ok'=>true,'dir'=>$real,'htaccess'=>is_file($ht)?(string)@file_get_contents($ht):'','exists'=>is_file($ht),'protected'=>is_file($real.'/.htpasswd'),'writable'=>is_file($ht)?is_writable($ht):is_writable($real)];
        }
        case 'sec_save': {
            $real = realpath($_POST['dir'] ?? ''); if (!$real||!is_dir($real)) return ['ok'=>false,'error'=>'directory not found'];
            return @file_put_contents(str_replace('\\','/',$real).'/.htaccess', $_POST['content']??'')!==false ? ['ok'=>true,'msg'=>'.htaccess saved'] : ['ok'=>false,'error'=>'write failed (permission?)'];
        }
        case 'sec_protect': {
            $real = realpath($_POST['dir'] ?? ''); if (!$real||!is_dir($real)) return ['ok'=>false,'error'=>'directory not found'];
            $u = trim($_POST['user']??''); $p = $_POST['pass']??'';
            if (!preg_match('/^[A-Za-z0-9._\-]{1,32}$/',$u)) return ['ok'=>false,'error'=>'invalid username'];
            if ($p==='') return ['ok'=>false,'error'=>'password required'];
            $real = str_replace('\\','/',$real); $htp = $real.'/.htpasswd';
            if (@file_put_contents($htp, $u.':'.password_hash($p,PASSWORD_BCRYPT)."\n")===false) return ['ok'=>false,'error'=>'cannot write .htpasswd'];
            $cur = is_file($real.'/.htaccess') ? (string)@file_get_contents($real.'/.htaccess') : '';
            if (stripos($cur,'AuthType')===false) @file_put_contents($real.'/.htaccess', "# password protection by panel\nAuthType Basic\nAuthName \"Restricted Area\"\nAuthUserFile \"$htp\"\nRequire valid-user\n\n".$cur);
            return ['ok'=>true,'msg'=>"Directory protected - user \"$u\" can sign in."];
        }
        case 'sec_block_ip': {
            $real = realpath($_POST['dir'] ?? ''); if (!$real||!is_dir($real)) return ['ok'=>false,'error'=>'directory not found'];
            $ip = trim($_POST['ip']??''); if (!preg_match('#^[0-9A-Fa-f.:/]{1,43}$#',$ip)) return ['ok'=>false,'error'=>'invalid IP/CIDR'];
            $real = str_replace('\\','/',$real); $ht = $real.'/.htaccess'; $cur = is_file($ht)?(string)@file_get_contents($ht):'';
            $ipEsc = str_replace('.', '\\.', $ip); // escape dots for the Apache regex
            // Block the direct connection IP AND the IP when it arrives via a proxy/CDN header,
            // so the rule can't be bypassed by X-Forwarded-For / X-Real-IP.
            $block = "\n# IP blocked by Orizen Panel - covers direct connection AND forwarded headers (proxy/CDN safe)\n"
                . 'SetEnvIf X-Forwarded-For "(^|[, ])' . $ipEsc . '([, ]|$)" orizen_blocked' . "\n"
                . 'SetEnvIf X-Real-IP "^' . $ipEsc . '$" orizen_blocked' . "\n"
                . "<RequireAll>\n  Require all granted\n  Require not ip $ip\n  Require not env orizen_blocked\n</RequireAll>\n";
            if (@file_put_contents($ht, $cur.$block)===false) return ['ok'=>false,'error'=>'write failed'];
            return ['ok'=>true,'msg'=>"Blocked $ip (direct and via proxy headers)"];
        }
    }
    // Optional modules/plugins may handle their own actions.
    $mod = moduleApi($action);
    if ($mod !== null) return $mod;
    return ['ok'=>false,'error'=>'Unknown action: '.$action];
}

// -- Require auth for the UI --------------------------------
if (!isAuthed()) { renderLogin($loginErr, $loginStage, $login2faShow ?? ''); exit; }

// Old configs silently gain any new default keys here (never fails, never needs a reinstall).
cfgMigrate();

// Binary streams (download / inline preview). File Manager + Backups are admin-only, so
// these are too: without this, any authed non-admin (reseller/user) could read arbitrary
// web-user-readable files (config.json, other tenants' secrets) via ?dl=/?view=.
if (isset($_GET['dl']) || isset($_GET['view'])) {
    if (!isAdmin()) { http_response_code(403); echo 'Forbidden'; exit; }
    if (isset($_GET['dl']))   fileDownload((string)$_GET['dl']);
    if (isset($_GET['view'])) fileView((string)$_GET['view']);
}

$page = $_GET['page'] ?? 'dashboard';
$pages = [
    'dashboard'=>'Dashboard',
    'websites'=>'Websites','subdomains'=>'Subdomains','redirects'=>'Redirects','dns'=>'DNS','zone'=>'Zone Editor','ssl'=>'SSL',
    'databases'=>'Databases','sql'=>'SQL Browser','email'=>'Email','webmail'=>'Webmail',
    'files'=>'File Manager','disk'=>'Disk Usage','backups'=>'Backups','security'=>'Security',
    'logs'=>'Logs','processes'=>'Processes','network'=>'Network',
    'services'=>'Services','firewall'=>'Firewall','cron'=>'Cron Jobs','console'=>'Console',
    'settings'=>'Settings',
    'file_edit'=>'Code Editor', // reachable but not shown in nav
];
// Optional modules/plugins contribute their own pages (feature-gated).
foreach (modulePages() as $slug => $mp) $pages[$slug] = $mp['title'] ?? ucfirst($slug);
if (!isset($pages[$page])) $page = 'dashboard';
if (!pageAllowed($page)) $page = 'dashboard';   // non-admin accounts: deny by default (admins unaffected)

renderHead($pages[$page]);
echo pageGuideBar($page);
if (isset(pageHelp()[$page])) { [$ht,$hh] = pageHelp()[$page]; echo helpBox($ht,$hh); }
$fn = ($page === 'file_edit') ? 'pageFileEdit' : ('page' . ucfirst($page));
if (function_exists($fn)) $fn();
elseif (isset(modulePages()[$page])) call_user_func(modulePages()[$page]['render']);
else pageDashboard();
renderFoot();

// ------------------------------------------------------------
//  VIEWS
// ------------------------------------------------------------
/** The Orizen brand mark - an orbit with a star. */
function orizenMark(int $sz = 20): string {
    return '<svg class="mark" viewBox="0 0 24 24" width="'.$sz.'" height="'.$sz.'" fill="none" stroke="currentColor" stroke-width="1.8">'
        .'<ellipse cx="12" cy="12" rx="10.5" ry="4.4" transform="rotate(-28 12 12)"/>'
        .'<circle cx="12" cy="12" r="2.6" fill="currentColor" stroke="none"/>'
        .'<circle cx="19" cy="7" r="1" fill="currentColor" stroke="none"/>'
        .'<circle cx="5" cy="17" r="1" fill="currentColor" stroke="none"/></svg>';
}
/** Inline monochrome SVG icons (stroke = currentColor). Keeps the UI clean - no emoji. */
function icon(string $n, int $sz = 16): string {
    static $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'websites'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
        'subdomains'=> '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M6 8.5V14a4 4 0 0 0 4 4h5.5"/>',
        'redirects' => '<path d="M3 12h13M13 7l5 5-5 5"/><path d="M21 5v14"/>',
        'dns'       => '<circle cx="12" cy="12" r="9"/><path d="M3 9h18M3 15h18"/>',
        'zone'      => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18"/><path d="M15.5 14.5 18 17l-1 3-3 1 2.5-2.5z"/>',
        'ssl'       => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'databases' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'sql'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11M3 14h18"/>',
        'email'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'webmail'   => '<path d="M22 6 12 13 2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 12h6l2 3h4l2-3h6"/>',
        'files'     => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'file_edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'cron'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'security'  => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'logs'      => '<path d="M5 3h10l4 4v14H5z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
        'processes' => '<rect x="6" y="6" width="12" height="12" rx="1"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>',
        'disk'      => '<rect x="3" y="13" width="18" height="8" rx="2"/><path d="M5 13 8 4h8l3 9"/><circle cx="8" cy="17" r="1"/>',
        'backups'   => '<path d="M3 8V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2M3 8h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 12h6"/>',
        'network'   => '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="3" y="16" width="6" height="6" rx="1"/><rect x="15" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M6 16v-2h12v2"/>',
        'firewall'  => '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 9h18M3 15h18M9 4v5M15 9v6M9 15v5"/>',
        'services'  => '<rect x="3" y="4" width="18" height="7" rx="1"/><rect x="3" y="13" width="18" height="7" rx="1"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'console'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/>',
        'phpinfo'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'settings'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'paygateway'=> '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h5"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    ];
    $inner = $p[$n] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" width="'.$sz.'" height="'.$sz.'">'.$inner.'</svg>';
}

/** Collapsible sidebar groups (Dashboard is a fixed item, Settings/Logout live top-right). */
function navSections(): array {
    // Fixed display order. Empty groups (PAYMENT/APPS/RESELLER) are placeholders so
    // module-contributed pages land in the right spot; array_filter drops any that stay empty.
    return [
        'DOMAINS'=>['websites','subdomains','redirects','dns','zone','ssl'],
        'FILES'=>['files','disk','backups','security'],
        'DATABASE'=>['databases','sql'],
        'PAYMENT'=>[],   // populated by the payment gateway module
        'APPS'=>[],      // populated by modules: Git Deploy, One-Click Apps, Staging, Migration, Website Tools
        'MAIL'=>['email','webmail'],
        'MONITOR'=>['logs','processes','network'],
        'SYSTEM'=>['services','firewall','cron','console'],
        'RESELLER'=>[],  // populated by the accounts module (hosting accounts & resellers)
    ];
}
/** navSections() plus any pages contributed by modules/plugins, appended to their chosen section. */
function navSectionsAll(): array {
    $s = navSections();
    // App/deploy-style modules belong in their own "Apps" section, not under Domains.
    $remap = ['gitdeploy'=>'APPS','oneclick'=>'APPS','staging'=>'APPS','migrate'=>'APPS','webtools'=>'APPS'];
    foreach (modulePages() as $slug => $p) {
        $sec = strtoupper($remap[$slug] ?? ($p['section'] ?? 'SYSTEM'));
        if (!isset($s[$sec])) $s[$sec] = [];
        if (!in_array($slug, $s[$sec], true)) $s[$sec][] = $slug;
    }
    // Drop any section that ended up with no visible pages (e.g. all its modules off).
    return array_filter($s, fn($pages) => !empty($pages));
}
/** Groups that start collapsed (until the user expands them). Domains + Files stay open. */
function navDefaultCollapsed(): array { return ['DATABASE','PAYMENT','APPS','MAIL','MONITOR','SYSTEM','RESELLER']; }
/** Search keywords + one-line descriptions, so typing a few words finds the right tool. */
function navKeywords(): array {
    return [
        'dashboard'=>['home overview stats server status load uptime cpu ram disk start','Server overview, status and quick start'],
        'websites'=>['domain site hosting create add wordpress vhost public_html host new website','Create and host websites'],
        'subdomains'=>['blog shop sub subdomain prefix','Add blog.yourdomain.com style subdomains'],
        'redirects'=>['redirect forward 301 302 url move point domain to another','Forward a domain to another URL'],
        'dns'=>['dns records a mx txt spf dkim dmarc nameserver point copy paste','Exact DNS records to copy to your registrar'],
        'zone'=>['zone editor dns record cloudflare manage edit add delete a cname mx txt','Edit live DNS records (via Cloudflare)'],
        'ssl'=>['ssl https certificate secure lets encrypt tls lock padlock','Turn on free HTTPS for a site'],
        'databases'=>['database db mysql mariadb create user grant password phpmyadmin','Create databases and database users'],
        'sql'=>['sql browser phpmyadmin table rows query import export edit insert delete structure','Browse and edit database tables'],
        'email'=>['email mailbox account create imap smtp inbox postfix dovecot send receive mail address','Create email accounts'],
        'webmail'=>['webmail read reply delete inbox messages compose mail open view email','Read and reply to your email'],
        'files'=>['file manager upload download edit folder code editor zip unzip rename move','Browse, upload and edit website files'],
        'disk'=>['disk usage space storage size big folders breakdown analyze','See what is using disk space'],
        'backups'=>['backup restore download archive save copy website files database dump export snapshot','Back up a site (files + database)'],
        'security'=>['security htaccess password protect block ip directory privacy basic auth','Protect folders and block visitors'],
        'logs'=>['logs error access view tail filter clear apache php mysql mail','View and search server logs'],
        'processes'=>['processes cpu memory kill task top ps running program','See and stop running programs'],
        'network'=>['network ping dns lookup traceroute port check connectivity tools','Ping, DNS lookup, port checks'],
        'services'=>['services apache mariadb mysql postfix dovecot start stop restart running','Start/stop/restart server services'],
        'firewall'=>['firewall port open close allow deny ufw firewalld block','Open or close server ports'],
        'cron'=>['cron schedule task job timer automatic recurring crontab','Schedule automatic tasks'],
        'console'=>['console terminal shell command line run bash','Run shell commands'],
        'settings'=>['settings password change cloudflare token developer credit facebook config features flags','Password, Cloudflare, features, developer credit'],
        'paygateway'=>['payment gateway crypto bitcoin btc ltc eth xrp usdt usdc orizen pay checkout invoice merchant install deploy','Install a crypto payment gateway on a domain'],
    ];
}
/** Beginner instructions shown automatically at the top of pages that don't define their own. */
function pageHelp(): array {
    return [
        'subdomains'=>['What is a subdomain?','A subdomain is a section in front of your domain, like <span class="mono">blog.yoursite.com</span> or <span class="mono">shop.yoursite.com</span>. Pick a name, choose which domain it belongs to, and it gets its own folder. Also add a DNS record pointing it to this server.'],
        'dns'=>['What is DNS?','DNS records tell the internet where your domain points. This page shows the <b>exact records to copy</b> into your domain registrar (where you bought the domain). Copy each value to the matching record there - changes can take minutes to a few hours.'],
        'ssl'=>['What is SSL / HTTPS?','SSL puts the padlock on your site and turns <span class="mono">http://</span> into secure <span class="mono">https://</span>. It is free (Let\'s Encrypt) and renews itself. Click <b>Secure with HTTPS</b> next to a site - its domain must already point to this server.'],
        'files'=>['Using the File Manager','This is like Windows Explorer for your server. Double-click a folder to open it, click a file to edit it, or use <b>Upload</b> (you can also drag and drop). Website files live under <span class="mono">/var/www</span>. The toolbar lets you create, copy, move, zip or delete.'],
        'disk'=>['What is this?','See which folders use the most disk space, so you can find what is filling up your server. Type a folder and click <b>Analyze</b>; click any folder in the results to look deeper.'],
        'security'=>['What is this?','Protect a folder so visitors must enter a username/password, or block an IP address. Pick the folder to manage, then use the boxes. It works by writing a standard <span class="mono">.htaccess</span> file, which you can also edit directly below.'],
        'logs'=>['What are logs?','Logs are the server\'s diary - they record visits and errors. If a site misbehaves, pick its log here and click <b>View</b>. Use the filter box to find a word like "error" or "404".'],
        'processes'=>['What is this?','A live list of the programs running on your server, busiest first. If something is using too much CPU or memory you can stop it here. Most users rarely need this.'],
        'network'=>['What is this?','Quick connection tools: <b>Ping</b> checks if a server is reachable, <b>DNS Lookup</b> shows where a domain points, <b>Port check</b> tests if a port is open. Type a host and click a button.'],
        'firewall'=>['What is the firewall?','The firewall decides which network ports (doors) are open to the internet. Web (80/443) and the panel port are already open. Only open a port if an app needs it. Type the port number and click Allow or Close.'],
        'cron'=>['What are scheduled tasks?','Cron runs a command automatically on a schedule (for example a backup every night). Each line is: <span class="mono">minute hour day month weekday command</span>. Edit the box and click <b>Save crontab</b>. If unsure, leave this alone.'],
        'console'=>['What is the console?','Run server commands here, like a built-in terminal. It runs as the limited web user (not root). If you do not know shell commands you can ignore this page - everything important has its own page in the menu.'],
    ];
}
/** Small trash icon for delete buttons. */
function trashSvg(): string { return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>'; }
/** Beginner-friendly instruction box shown at the top of each tool. */
function helpBox(string $title, string $html): string {
    return '<div class="help"><svg class="hb-i" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.2a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1.1.9-1.1 1.8M12 17h.01"/></svg>'
        .'<div><b>'.$title.'</b><div class="hb-t">'.$html.'</div></div></div>';
}
/** Build one "How to use" guide: a title, a plain-English summary, numbered steps, and optional tips. */
function gd(string $title, string $what, array $steps, array $tips = []): string {
    $h = "<div class='guide'><h3 style='margin-top:0'>{$title}</h3><p class='g-what'>{$what}</p>";
    if ($steps) { $h .= "<div class='g-h'>Step by step</div><ol class='g-steps'>"; foreach ($steps as $s) $h .= "<li>{$s}</li>"; $h .= "</ol>"; }
    if ($tips)  { $h .= "<div class='g-h'>Good to know</div><ul class='g-tips'>"; foreach ($tips as $t) $h .= "<li>{$t}</li>"; $h .= "</ul>"; }
    return $h . "</div>";
}
/** Detailed, plain-English "How to use this page" guide for every page (core + modules), keyed by page slug. */
function pageGuide(): array {
    static $g = null; if ($g !== null) return $g; $g = [];
    $g['dashboard'] = gd('Dashboard', 'Your home screen. It shows live server health (CPU, memory, disk, uptime) and quick counts of your websites, databases and email accounts.', [
        'Read the four cards at the top for a quick health check. CPU and memory refresh on their own every few seconds.',
        'Use the shortcut tiles to jump straight to the most common tasks (add a website, a database, an email account).',
        'If a number looks wrong, open that section from the left menu for the full details.'],
        ['A brand-new idle server showing near <code>0%</code> CPU is normal, not a bug.',
         'Everything here is read-only - you cannot break anything from the Dashboard.']);
    $g['websites'] = gd('Websites', 'This is where you host sites. Each website you add gets its own folder under <code>/var/www</code> and its own web address.', [
        'Type the domain you own (for example <code>example.com</code>) in the Add box.',
        'Choose the type: <b>New</b> makes a brand-new website with its own folder (use this for every separate site). <b>Alias</b> makes another domain show an existing site (pick which site to share).',
        'Click <b>Add</b>. The folder and web config are created for you.',
        'Point your domain at this server: open the <b>DNS</b> page, copy the A record it shows into your domain registrar.',
        'When the domain resolves, secure it on the <b>SSL</b> page (one click, free HTTPS).'],
        ['To forward one domain to another address instead of hosting it, use the <b>Redirects</b> page.',
         'Upload your site files with the <b>File Manager</b> (they go inside that site\'s folder).',
         'Deleting a website here removes its web config; ask before deleting if you are unsure.']);
    $g['subdomains'] = gd('Subdomains', 'A subdomain is a section in front of your domain, such as <code>blog.example.com</code> or <code>shop.example.com</code>. Each one gets its own folder.', [
        'Pick the parent domain, type the prefix (the <code>blog</code> part), and click Create.',
        'A new folder is made for that subdomain\'s files.',
        'Add a DNS record for the subdomain pointing at this server (the DNS page shows the value).'],
        ['Subdomains are perfect for a separate blog, shop, or staging copy of a site.']);
    $g['redirects'] = gd('Redirects', 'A redirect forwards visitors from one domain to another web address - nothing is hosted, it just bounces people onward.', [
        'Type the domain you own that should forward (for example <code>old-name.com</code>).',
        'Type the full destination it should go to (for example <code>https://new-name.com</code>).',
        'Click <b>Create redirect</b>.',
        'Point the forwarding domain\'s DNS at THIS server so the redirect can catch it - the target stays wherever it already lives.'],
        ['Common uses: send a spare/old domain to your main site, or move an address to a new one.',
         'Do not point both domains at the same server IP - only the forwarding domain comes here.']);
    $g['dns'] = gd('DNS', 'DNS records tell the internet where your domain points. This page shows the exact records to copy into your registrar (where you bought the domain).', [
        'Pick the website whose records you want to see.',
        'Copy each value shown here into the matching record at your domain registrar or DNS host.',
        'Save at the registrar, then wait - changes can take a few minutes up to a few hours to spread.',
        'Use <b>Check domain</b> to see whether the domain now points at this server.'],
        ['The most important one is the <b>A record</b> - it points your domain at this server\'s IP.',
         'If you use Cloudflare, connect a token in <b>Settings</b> and Orizen can create these records for you.']);
    $g['zone'] = gd('Zone Editor', 'Edit your Cloudflare DNS records directly from the panel - add, change or delete A, CNAME, TXT, MX and more without leaving Orizen.', [
        'First connect a Cloudflare API token in <b>Settings</b> (the page will tell you if it is missing).',
        'Pick the domain (zone) to edit.',
        'Add a record by choosing its type, name and value, then Save. Edit or delete existing rows inline.'],
        ['No Cloudflare? Use the <b>DNS</b> page instead - it shows the values to enter at any registrar.']);
    $g['ssl'] = gd('SSL / HTTPS', 'SSL puts the padlock on your site and turns <code>http://</code> into secure <code>https://</code>. It is free (Let\'s Encrypt) and renews itself.', [
        'Make sure the domain already points at this server (check on the <b>DNS</b> page).',
        'Find the site in the list and click <b>Secure with HTTPS</b>.',
        'Wait a few seconds while the certificate is issued and installed.'],
        ['If it fails, the domain almost always is not pointing here yet - fix DNS first, then retry.',
         'Renewal is automatic; you do not need to come back.']);
    $g['databases'] = gd('Databases', 'A database stores your app\'s data (WordPress posts, users, orders and so on). Make one database per app, plus a user to connect with.', [
        'Click <b>Create database</b> and give it a name.',
        'Create a database <b>user</b> with a password.',
        'Grant that user access to the database.',
        'In your app\'s config, connect with: host <code>localhost</code>, the database name, and that user + password.'],
        ['To view or edit the actual rows/tables, open the <b>SQL Browser</b>.',
         'Keep the user password somewhere safe - your app needs it to connect.']);
    $g['sql'] = gd('SQL Browser', 'A built-in phpMyAdmin-style tool to browse and edit what is inside your databases - tables, rows, and raw SQL.', [
        'Pick a database on the left, then a table to see its rows.',
        'Use the tabs: <b>Browse</b> rows, <b>Structure</b> (columns), <b>Insert</b> a row, <b>SQL</b> to run a query, or <b>Search</b>.',
        'Double-click a cell to edit it (the table needs a primary key). Tick rows to delete them.',
        'Use <b>Export</b> to download a <code>.sql</code> copy, or <b>Import</b> to load one in (any size, via chunked upload).'],
        ['It connects automatically using the panel\'s own database login - no setup needed.',
         'Exporting before you change anything is a cheap safety net.']);
    $g['email'] = gd('Email accounts', 'Create real mailboxes on your own domain, like <code>you@yourdomain.com</code>, in a single step.', [
        'Type the full email address you want and a password.',
        'Click <b>Create account</b> - Orizen sets up the mail domain automatically the first time.',
        'Add the mail DNS records it shows (MX, SPF, DKIM) at your registrar so mail can flow.',
        'Read and reply to messages in <b>Webmail</b>, or connect the account in any mail app.'],
        ['Full sending/receiving needs the mail server installed (Postfix/Dovecot) - the installer can do this.',
         'The MX record must point mail at this server or messages will not arrive.']);
    $g['webmail'] = gd('Webmail', 'Read, reply to and delete your email right inside the panel - no separate app needed.', [
        'Sign in with one of your email addresses and its mailbox password.',
        'Click a message to read it; use Reply or Compose to send.',
        'Delete removes a message from the server.'],
        ['The login is the mailbox password you set on the <b>Email accounts</b> page, not your panel password.']);
    $g['files'] = gd('File Manager', 'Like Windows Explorer for your server. Browse, upload, edit, zip and delete the files that make up your websites.', [
        'Use the folder tree on the left to open a folder; website files live under <code>/var/www</code>.',
        'Click a file to edit it in the built-in code editor, or use the toolbar to create, copy, move, zip or delete.',
        'Upload files with the <b>Upload</b> button or by dragging them in (large files upload in chunks).'],
        ['The <b>Web root</b> button jumps to the folder your website serves from.',
         'It runs as the limited web user, so it can only touch web files - not the whole system.']);
    $g['disk'] = gd('Disk Usage', 'Find out what is filling up your disk by seeing which folders use the most space.', [
        'Type a folder to scan (or use the default) and click <b>Analyze</b>.',
        'Look at the biggest folders at the top.',
        'Click into any folder in the results to drill deeper and find the culprit.'],
        ['Old backups and logs are common space hogs - clean them from their own pages.']);
    $g['security'] = gd('Directory Security', 'Password-protect a folder (visitors must log in) or block specific IP addresses from your sites.', [
        'Pick the folder you want to manage.',
        'To password-protect it, add a username and password.',
        'To block someone, enter their IP address in the block box.'],
        ['It works by writing a standard <code>.htaccess</code> file, which you can also edit directly below.',
         'IP blocks here also resist common proxy/CDN header spoofing.']);
    $g['logs'] = gd('Logs', 'Logs are the server\'s diary - they record visits and errors. The place to look when a site misbehaves.', [
        'Pick the log you want (a site\'s access or error log, or a system log).',
        'Click <b>View</b>.',
        'Use the filter box to jump to a word like <code>error</code>, <code>404</code>, or a URL.'],
        ['The error log is the fastest way to find out why a page shows a 500 error.']);
    $g['processes'] = gd('Processes', 'A live list of the programs running on your server, busiest first. Most people rarely need this.', [
        'Read the list - the heaviest CPU/memory users are at the top.',
        'If something is clearly stuck and hogging resources, you can stop it here.'],
        ['Do not stop a process unless you know what it is - you could interrupt your own website.']);
    $g['network'] = gd('Network Tools', 'Quick connection checks: is a host reachable, where does a domain point, and is a port open.', [
        'Type a host or domain.',
        'Click <b>Ping</b> (reachable?), <b>DNS Lookup</b> (where it points), or <b>Port check</b> (is a port open).'],
        ['Handy for confirming your domain now points at this server, or whether a service is listening.']);
    $g['services'] = gd('Services', 'The background programs that run your server (web server, database, mail...). A green dot means running.', [
        'Find the service in the list.',
        'Use <b>Restart</b> if a site or email stopped working, <b>Start</b> to turn one on, <b>Stop</b> to turn it off.'],
        ['Restarting the web server briefly interrupts your sites for a second - that is normal.',
         'Do not stop the database while your websites are live.']);
    $g['firewall'] = gd('Firewall', 'The firewall decides which network ports (doors) are open to the internet. Fewer open ports means a safer server.', [
        'Web (80/443) and the panel port are already open for you.',
        'To open a port an app needs, type the port number and click <b>Allow</b>.',
        'To close one, type it and click <b>Close</b>.'],
        ['Only open a port when an app truly needs it - every open port is a possible way in.']);
    $g['cron'] = gd('Scheduled Tasks (Cron)', 'Cron runs a command automatically on a schedule - for example a nightly backup or a WordPress task.', [
        'Each line is: <code>minute hour day month weekday command</code>.',
        'Add or edit a line in the box.',
        'Click <b>Save crontab</b>.'],
        ['Example - run at 3am daily: <code>0 3 * * * /path/to/script</code>.',
         'If you are unsure, leave this page alone; nothing here is required for normal hosting.']);
    $g['console'] = gd('Console', 'A built-in terminal for running server commands. It runs as the limited web user, not root.', [
        'Type a command and press Enter.',
        'Read the output below.'],
        ['Everything important already has its own page in the menu - you can ignore this page if shell commands are unfamiliar.',
         'Because it is the limited web user, system-level commands will be refused (that is intentional).']);
    $g['settings'] = gd('Settings', 'Change your panel password, see your server details, connect Cloudflare, and switch optional modules on or off.', [
        'Update your login password in the password box and Save.',
        'Connect a Cloudflare API token to enable automatic DNS.',
        'In <b>Modules &amp; features</b>, turn optional tools on or off - only installed modules are listed.'],
        ['Turning a module off just hides it; your data stays and comes back when you re-enable it.']);
    $g['backups'] = gd('Backups', 'A backup is a single file holding a full copy of a website - its whole public folder plus the databases you tick. Make one before big changes, keep a copy on your PC, or restore one back onto the server.', [
        'Pick the website to back up.',
        'Tick the database(s) that website uses (so they are included).',
        'Click <b>Create backup</b>. When it finishes it appears in <b>Your backups</b> below.',
        '<b>Download</b> saves the backup file to your computer for safe-keeping.',
        '<b>Restore</b> puts a backup back onto the server - it overwrites the current files and re-imports its databases.',
        '<b>Import from your computer:</b> if you already have an Orizen backup file (<code>.tar.gz</code> or <code>.zip</code>) on your PC, use the <b>Import a backup</b> card to upload it - then it shows in the list and you can Restore it.'],
        ['Make a fresh backup right before updating a site or its plugins - it is your undo button.',
         'Restore overwrites current files, so only restore when you are sure.',
         'For automatic, scheduled backups (and off-server copies), use the <b>Scheduled Backups</b> page.']);
    // -- modules --
    $g['accounts'] = gd('Hosting Accounts &amp; Resellers - full guide',
        'This is the part that makes Orizen work like WHM. It lets you hand out hosting to other people (customers or resellers) without giving them your admin login. Each account owns its own websites, databases and email, sees only its own things, and can be given its own panel login. An <b>account is just a named owner</b> with limits; a <b>package</b> is the set of limits you attach to it.', [
        '<b>Make a package</b> (optional but recommended): click <b>New package</b> and set the limits - disk space, how many websites, databases and email accounts are allowed. <code>0</code> means unlimited.',
        '<b>Create an account:</b> pick a username, choose the <b>Type</b>, pick who owns it and (optionally) a package, then <b>Create account</b>.',
        '<b>Give them a login:</b> click <b>Manage</b> on the account, then under <b>Login password</b> set a password (min 6). This is the step that lets them sign in.',
        '<b>Assign their stuff:</b> still in Manage, assign one or more websites and databases to the account so those items count as theirs.',
        '<b>They log in</b> at the same panel address (<code>https://your-server:1337</code>) using their <b>username</b> and the <b>login password</b> you set. They see a trimmed panel with only their own websites, SSL, DNS and webmail - no Settings, no other people\'s sites.'],
        ['<b>Type - End user vs Reseller:</b> an <b>end user</b> just owns their own websites. A <b>reseller</b> can create and manage their own sub-accounts (their own customers) under themselves - useful if you resell hosting.',
         '<b>What an account can actually do</b> once it has a login: manage its own Websites, get SSL, view DNS, and use Webmail. Admin-only areas (Services, Firewall, Settings, other accounts) are hidden and blocked for them.',
         '<b>Suspend</b> instantly makes that account\'s websites show a "suspended" page (and locks its Linux user if it has one). Unsuspend reverses it - nothing is deleted.',
         '<b>Transfer</b> moves all of an account\'s websites and databases to another account in one click.',
         '<b>Delete</b> removes the account but <b>keeps</b> its websites and databases - they simply become unassigned, so nothing is lost.',
         '<b>Dedicated Linux user</b> (the checkbox) gives the account its own system user for stronger file separation. Optional - leave it off if you are not sure.',
         'Without a login password, an account still exists as an "owner" for organising websites - it just cannot sign in yet.']);
    $g['backupx'] = gd('Scheduled Backups', 'Automatically back up all your websites and databases on a schedule, keep a set number of copies, and optionally copy each backup to another server so it lives off this machine.', [
        'Tick <b>Enable scheduled backups</b> and choose how often (hourly, daily or weekly).',
        'Set how many copies to keep - older ones are deleted automatically.',
        'Click <b>Save schedule</b>. Use <b>Back up now</b> to make one immediately and confirm it works.',
        'Optional: pick an off-server destination (FTP/FTPS/SFTP/WebDAV) and enter its host, user, password and path so a copy is sent there too.'],
        ['This adds to the manual <b>Backups</b> page - use that one to restore, download, or import a backup from your PC.',
         'An off-server copy protects you even if this whole server is lost.']);
    $g['staging'] = gd('Staging', 'A staging site is a private copy of a live website where you can safely test changes, updates or a redesign. When it looks good, you push it back to the live site.', [
        'Pick the live website you want to copy.',
        'Click <b>Create staging</b> - Orizen copies its files and database into a separate staging copy (links inside the database are rewritten to the staging address automatically).',
        'Work on the staging copy: test plugin/theme updates, edits, anything - the live site is untouched.',
        'When happy, click <b>Push to production</b>. Orizen backs up the live site first, then replaces it with your tested copy.',
        'Delete the staging copy when you no longer need it.'],
        ['Push always takes a safety backup of the live site first, so you can roll back if needed.',
         'Great for WordPress updates you are nervous about - break the staging copy, not the real one.']);
    $g['gitdeploy'] = gd('Git Deploy', 'Deploy a website straight from a Git repository (GitHub, GitLab...). Push your code and the server pulls the latest version - no manual uploading.', [
        'Click Add and paste the repository\'s HTTPS URL, the branch, and the folder to deploy into.',
        'Optionally add a build command (for example an install/build step) that runs after each pull.',
        'Click <b>Deploy</b> to pull the code now.',
        'For automatic deploys, copy the <b>webhook URL</b> and paste it into your repo\'s webhook settings - every push will redeploy.',
        'Use <b>Rollback</b> to jump back to the previous commit if a deploy goes wrong.'],
        ['Only HTTPS repositories are supported (no SSH keys needed).',
         'The webhook has a secret key in it - keep the URL private.',
         'Every deploy is recorded in the history so you can see what changed and when.']);
    $g['oneclick'] = gd('One-Click Apps', 'Install ready-made apps (like WordPress) onto a website in one step - Orizen downloads the app, creates its database, and wires everything up.', [
        'Pick the app (for example WordPress) and the website to install it on.',
        'Click Install. Orizen downloads the app, creates a database and user, and writes the config for you.',
        'Open your site and finish the app\'s own setup screen (for WordPress, pick a site title and admin user).'],
        ['The database name, user and password are created automatically - you do not need to make them first.',
         'Install onto an empty website folder to avoid overwriting existing files.']);
    $g['docker'] = gd('Docker', 'Run apps in isolated containers. This page lets you see, start, stop and remove Docker containers and images from the panel.', [
        'If Docker is not installed yet, use the Install button first.',
        'See running containers in the list; use the buttons to Start, Stop, restart, view Logs or Remove them.',
        'Paste a <code>docker-compose</code> file to bring up a multi-container app.'],
        ['Containers are advanced - only use this if you already work with Docker images.',
         'Removing a container does not delete its image; removing an image frees disk space.']);
    $g['isolation'] = gd('Site Isolation', 'Give a website its own dedicated system user and PHP pool so it is walled off from your other sites. If one site is hacked, the others stay safe.', [
        'Pick the website to isolate.',
        'Click <b>Isolate</b> - Orizen creates a dedicated Linux user and a private PHP-FPM pool locked to that site\'s folder.',
        'The site keeps working exactly as before, just under its own user with its own limits.',
        'Use <b>Un-isolate</b> to revert it back to the shared setup.'],
        ['Best for servers hosting several different people\'s sites.',
         'Isolation also sets a per-site memory/child-process limit so one site cannot starve the others.']);
    $g['runtime'] = gd('PHP Versions', 'Install more than one PHP version and choose which one each website uses - handy when one app needs an older PHP and another needs the newest.', [
        'Install the PHP version you need from the list (for example 8.2 or 8.3).',
        'Pick a website and choose which PHP version it should run.',
        'Save - that site now runs on the chosen version; others are unaffected.'],
        ['Installing a new PHP version does not change your existing sites until you assign it.',
         'If a site breaks after a change, switch it back to the previous version.']);
    $g['webtools'] = gd('Website Tools', 'A toolbox of handy per-site fixes: maintenance mode, fixing file permissions, find-and-replace across files, and clearing PHP/Redis caches.', [
        '<b>Maintenance mode:</b> turn it on to show visitors a "be right back" page while you work; turn it off when done.',
        '<b>Fix permissions:</b> resets a site\'s files/folders to safe ownership and permissions if uploads or a move broke them.',
        '<b>Search &amp; replace:</b> change a piece of text across a site\'s files (for example an old URL) in one go.',
        '<b>Caches:</b> view or reset OPcache, and install/check Redis or Memcached for faster apps.'],
        ['Always know what you are replacing before using search &amp; replace - it edits real files.',
         'Reset OPcache after deploying new code if old code seems to linger.']);
    $g['notify'] = gd('Notifications', 'Get alerted when something needs attention - low disk space, a service going down, or high CPU - by email, Telegram or Discord.', [
        'Choose where alerts go: enter an email address, a Telegram bot token + chat ID, or a Discord webhook URL.',
        'Save, then use <b>Send test</b> to confirm the alert arrives.',
        'Orizen checks your server every few minutes and messages you when a problem starts (and again when it recovers).'],
        ['Telegram and Discord are the fastest to set up if you do not have outgoing email configured.',
         'You are only pinged when a state changes, so you will not be spammed.']);
    $g['secplus'] = gd('Security+', 'Extra hardening in one place: two-factor login (2FA), Fail2Ban to auto-ban attackers, an optional malware scanner, and the login audit trail.', [
        '<b>2FA:</b> click Begin, scan the code with an authenticator app, enter the 6-digit code to enable it. Your admin login then asks for a code each time.',
        '<b>Fail2Ban:</b> install it to automatically ban IPs that repeatedly fail to log in (SSH and more).',
        '<b>Malware scan (ClamAV):</b> install on demand and scan your web folders for known bad files.',
        'Review the <b>audit log</b> and the list of currently locked-out IPs at the bottom.'],
        ['Turn on 2FA before exposing the panel to the public internet - it is the single biggest login upgrade.',
         'ClamAV uses a lot of memory while updating its database; install it only if your server has room.']);
    $g['mailplus'] = gd('Mail Tools', 'Diagnostics and maintenance for your mail server: view and clear the outgoing queue, check blacklists, and verify your DKIM/SPF records.', [
        'View the mail <b>queue</b> to see messages waiting to send; flush it to retry, or delete stuck items.',
        'Run a <b>blacklist (DNSBL)</b> check to see if your server IP is flagged by spam lists.',
        'Check that your <b>DKIM</b> and SPF DNS records are published correctly so your mail is trusted.'],
        ['A growing queue usually means a DNS or destination problem - check the blacklist and records first.',
         'Needs a mail server installed (Postfix); without it the page shows a friendly notice.']);
    $g['migrate'] = gd('Migrate', 'Move an existing website onto this server by importing a backup archive from another control panel (for example a cPanel-style export).', [
        'Get a backup/export archive of the site from your old host.',
        'Upload it here and start the import.',
        'Orizen extracts it, creates the website, restores the files, and imports the databases it finds.',
        'Point the domain\'s DNS at this server, then secure it with SSL.'],
        ['Best results come from archives that follow the common cPanel-style layout.',
         'Check the site works on this server before you switch DNS, so there is no downtime.']);
    $g['webmode'] = gd('Web Server', 'See and tune how your sites are served. Orizen detects your web server (Apache) and can generate an Nginx reverse-proxy config for extra speed.', [
        'View the detected web server and current setup.',
        'Generate an Nginx reverse-proxy configuration if you want Nginx in front for caching/performance.',
        'Apply it yourself when you are ready (Orizen does not hot-swap the running server for safety).'],
        ['This is an advanced, optional tuning page - the default Apache setup already serves your sites fine.']);
    $g['multiserver'] = gd('Multi-Server', 'Watch more than one Orizen server from a single dashboard. Each extra server runs as a lightweight "node" that reports its stats back.', [
        'On each other server, get its node token.',
        'Add that server here by its address and token.',
        'See all your servers\' health (CPU, memory, disk) side by side from this one place.'],
        ['Off by default - turn it on in Settings only if you run several servers.',
         'Nodes use a shared secret token; keep it private.']);
    $g['monitoring'] = gd('Monitoring', 'Live and historical graphs of your server\'s CPU, memory, disk and network - so you can spot trends and problems over time, not just this second.', [
        'Open the page - it starts collecting samples and draws live graphs that update every few seconds.',
        'Read the history charts to see patterns (for example a nightly spike or slowly filling memory).',
        'A background job keeps recording in the background so history builds up even when you are away.'],
        ['A near-<b>0% CPU</b> and <b>0.00 load</b> are normal and healthy when the server is idle - it is not broken. The numbers and graphs climb as soon as there is real activity (visitors, a backup, a build).',
         'The green pulsing dot with "updated ..." confirms it is polling live; it turns red and says "Reconnecting" if it loses contact.',
         'If graphs look empty at first, give it a minute to gather the first samples.',
         'Rising memory that never comes down can point to a leaky app worth restarting.']);
    $g['apitokens'] = gd('REST API &amp; Tokens', 'Automate Orizen from scripts, CI/CD or your own tools. Create a token, then call any panel action over HTTPS - no browser login needed. A command-line tool <code>orizen</code> is installed on the server too.', [
        'Type a name (what it is for, e.g. <code>ci-deploy</code>) and click <b>Create token</b>.',
        'Copy the token immediately - it is shown only once. Store it somewhere safe.',
        'Call the API: send an HTTP <b>POST</b> to the panel URL with an <code>Authorization: Bearer &lt;token&gt;</code> header and an <code>action</code> field.',
        'Or use the built-in CLI on the server: <code>export ORIZEN_TOKEN=...</code> then <code>orizen &lt;action&gt; key=value</code>.',
        'Browse the <b>Command reference</b> lower on this page for every action, its parameters and copy-paste examples.',
        'Revoke any token you no longer need - anything using it stops working instantly.'],
        ['Tokens have full admin rights, so treat them like passwords.',
         'Every action name in the reference is exactly what the panel\'s own buttons use.',
         'Responses are JSON with an <code>ok</code> field you can check in scripts.']);
    return $g;
}
/** The "How to use this page" button (opens the detailed guide in a modal). Empty when a page has no guide. */
function pageGuideBar(string $page): string {
    $guide = pageGuide()[$page] ?? (modulePages()[$page]['guide'] ?? '');
    if ($guide === '') return '';
    $id = 'guide-'.preg_replace('/[^a-z0-9_-]/i','',$page);
    return "<div class='guidebar'><button type='button' class='btn-help' onclick=\"uiHtml(document.getElementById('{$id}').innerHTML,true)\">"
        . "<svg viewBox='0 0 24 24' width='15' height='15' fill='none' stroke='currentColor' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='9'/><path d='M9.6 9.2a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1.1.9-1.1 1.8M12 17h.01'/></svg>"
        . "How to use this page</button></div>"
        . "<div id='{$id}' style='display:none'>{$guide}</div>";
}
/** Unique tab/title favicon - the Orizen orbit mark as an SVG data URI. */
function faviconTag(): string {
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='#0a0a1a'/><ellipse cx='16' cy='16' rx='13' ry='5.5' transform='rotate(-28 16 16)' fill='none' stroke='#818cf8' stroke-width='2.4'/><circle cx='16' cy='16' r='3.6' fill='#c4b5fd'/><circle cx='25' cy='9' r='1.5' fill='#fff'/></svg>";
    return '<link rel="icon" href="data:image/svg+xml,'.rawurlencode($svg).'">';
}

function themeCss(): string { return <<<CSS
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
/* Orizen - premium neutral enterprise theme: soft cool-gray canvas, white
   cards, hairline borders, layered shadows, restrained indigo accent.
   Both :root and html.dark are the same so the theme never changes (single,
   consistent look everywhere - matches Orizen Pay exactly). */
:root,html.dark,html.light{
  --bg:#f6f7f9;--sidebar:#ffffff;--card:#ffffff;--surface2:#f3f4f7;--input-bg:#ffffff;
  --text:#171a21;--text2:#5d6472;--heading:#6b7280;--border:#e7e9ee;--border2:#d9dce3;
  --accent:#4f46e5;--accent-h:#4338ca;--accent-soft:rgba(79,70,229,.07);--ring:rgba(79,70,229,.16);
  --grad:linear-gradient(135deg,#6366f1 0%,#4f46e5 55%,#4338ca 100%);
  --grad-soft:rgba(79,70,229,.06);
  --shadow:0 1px 2px rgba(18,22,33,.04),0 2px 8px -2px rgba(18,22,33,.06);
  --shadow-lg:0 24px 64px -24px rgba(18,22,33,.28),0 8px 24px -12px rgba(18,22,33,.12);
  --green:#0e7c3f;--green-bg:rgba(14,124,63,.09);--red:#c02940;--red-bg:rgba(192,41,64,.08);
  --blue:#1f50d6;--blue-bg:rgba(31,80,214,.08);--amber:#97640a;--amber-bg:rgba(151,100,10,.1);
  --code-bg:#f6f7f9;--radius:14px;--radius-sm:10px;
}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:var(--text);display:flex;min-height:100vh;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;
  background:var(--bg)}
a{color:var(--accent);text-decoration:none;transition:color .15s}a:not(.btn):hover{color:var(--accent-h)}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
::selection{background:rgba(79,70,229,.16)}
.sidebar{width:236px;flex-shrink:0;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.logo{display:flex;align-items:center;gap:9px;padding:18px 18px 16px;font-size:16px;font-weight:700;letter-spacing:-.2px;color:var(--text);border-bottom:1px solid var(--border)}
.logo .mark{color:var(--accent);flex-shrink:0}
.logo b{background:linear-gradient(135deg,#2a2c36 0%,#14161c 100%);-webkit-background-clip:text;background-clip:text;color:transparent;font-weight:800}
.sidebar nav{padding:7px 0;flex:1}
.sidebar a{display:flex;gap:10px;align-items:center;margin:1px 8px;padding:8px 11px;border-radius:8px;color:var(--text2);font-size:13px;font-weight:500;transition:background .14s,color .14s}
.sidebar a svg{opacity:.65;flex-shrink:0;transition:opacity .14s,color .14s}
.sidebar a:hover{color:var(--text);background:var(--surface2)}
.sidebar a:hover svg{opacity:.9}
.sidebar a.active{color:var(--accent-h);background:var(--accent-soft);font-weight:600;box-shadow:inset 3px 0 0 0 var(--accent)}
.sidebar a.active svg{opacity:1;color:var(--accent)}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.top{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 26px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.82);backdrop-filter:blur(14px) saturate(1.4);position:sticky;top:0;z-index:50}
.top h2{font-size:16px;font-weight:700;color:var(--text);letter-spacing:-.01em}
.content{padding:24px 26px;flex:1;width:100%;max-width:1520px;animation:pageIn .35s ease}
@keyframes pageIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px;box-shadow:var(--shadow);transition:box-shadow .2s ease}
.card h3{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--heading);margin-bottom:14px;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow);position:relative;overflow:hidden;transition:box-shadow .2s ease,transform .2s ease,border-color .2s ease}
.stat:hover{box-shadow:0 4px 12px -4px rgba(18,22,33,.1),0 12px 32px -16px rgba(18,22,33,.14);border-color:var(--border2);transform:translateY(-1px)}
.stat::after{content:'';position:absolute;inset:0 0 auto 0;height:3px;background:var(--grad);opacity:0;transition:opacity .2s ease}
.stat:hover::after{opacity:1}
.stat .l{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text2);font-weight:650;margin-bottom:6px}
.stat .v{font-size:22px;font-weight:800;color:var(--text);letter-spacing:-.02em;font-variant-numeric:tabular-nums}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:10px 13px;text-align:left;border-bottom:1px solid var(--border)}
td{font-variant-numeric:tabular-nums}
th{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--text2);font-weight:700}
tbody tr td{transition:background .12s}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:var(--surface2)}
input,select,textarea{width:100%;background:var(--input-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);padding:9px 12px;font-size:13px;outline:none;font-family:inherit;box-shadow:inset 0 1px 2px rgba(18,22,33,.03);transition:border-color .14s,box-shadow .14s}
input::placeholder,textarea::placeholder{color:var(--text2);opacity:.7}
input:hover,select:hover,textarea:hover{border-color:#b9bec9}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--ring)}
textarea{font-family:'JetBrains Mono',Consolas,monospace;font-size:12px;resize:vertical}
label{font-size:11px;color:var(--text2);display:block;margin-bottom:5px;font-weight:600;letter-spacing:.01em}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 15px;border:1px solid transparent;border-radius:var(--radius-sm);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:transform .12s,box-shadow .16s,filter .16s,background .16s,color .16s,border-color .16s;white-space:nowrap}
.btn:active{transform:translateY(0) scale(.99)}
.btn-p{background:var(--grad);color:#fff;border:none;box-shadow:0 1px 2px rgba(18,22,33,.12),0 6px 16px -8px rgba(79,70,229,.5)}.btn-p:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 2px 4px rgba(18,22,33,.1),0 10px 24px -10px rgba(79,70,229,.55)}
.btn-s{background:var(--card);color:var(--text);border-color:var(--border2);box-shadow:0 1px 2px rgba(18,22,33,.05)}.btn-s:hover{border-color:var(--accent);color:var(--accent)}
.btn-d{background:var(--red);color:#fff;box-shadow:0 1px 2px rgba(18,22,33,.12),0 6px 16px -8px rgba(192,41,64,.45)}.btn-d:hover{filter:brightness(1.06);transform:translateY(-1px)}
.btn-g{background:transparent;color:var(--text2);border-color:var(--border2)}.btn-g:hover{color:var(--accent);border-color:var(--accent);background:var(--accent-soft)}
.btn-xs{padding:5px 9px;font-size:11px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.row>div{flex:1;min-width:140px}
.flex{display:flex;gap:10px;align-items:center}.wrap{flex-wrap:wrap}
.muted{color:var(--text2)}.sm{font-size:12px}.xs{font-size:11px}.mono{font-family:'JetBrains Mono',Consolas,monospace}
.mt{margin-top:14px}.mb{margin-bottom:12px}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3.5px 9px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.02em;border:1px solid transparent;white-space:nowrap}
.bg-green{background:var(--green-bg);color:var(--green);border-color:rgba(14,124,63,.22)}.bg-red{background:var(--red-bg);color:var(--red);border-color:rgba(192,41,64,.22)}.bg-blue{background:var(--blue-bg);color:var(--blue);border-color:rgba(31,80,214,.2)}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}.dot.on{background:var(--green);box-shadow:0 0 0 3px var(--green-bg)}.dot.off{background:var(--red);box-shadow:0 0 0 3px var(--red-bg)}
.alert{padding:12px 15px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:12px;border:1px solid transparent;line-height:1.5}
.alert-i{background:var(--blue-bg);color:var(--blue);border-color:rgba(31,80,214,.16)}
.alert-ok{background:var(--green-bg);color:var(--green);border-color:rgba(14,124,63,.18)}
.alert-e{background:var(--red-bg);color:var(--red);border-color:rgba(192,41,64,.18)}
.code{font-family:'JetBrains Mono',Consolas,monospace;font-size:12px;background:var(--code-bg);padding:13px;border-radius:var(--radius-sm);white-space:pre-wrap;word-break:break-word;border:1px solid var(--border);overflow:auto;color:var(--text)}
.empty{padding:34px;text-align:center;color:var(--text2);font-size:13px}
/* Reusable loading / state utilities (consistent skeletons + empty/success states everywhere). */
.skel{display:block;height:14px;border-radius:6px;background:linear-gradient(90deg,var(--surface2) 25%,var(--border) 37%,var(--surface2) 63%);background-size:400% 100%;animation:skel 1.3s ease infinite}
.skel+.skel{margin-top:9px}.skel.w60{width:60%}.skel.w40{width:40%}.skel.w80{width:80%}.skel.lg{height:22px}
@keyframes skel{0%{background-position:100% 0}100%{background-position:-100% 0}}
.state{padding:30px 20px;text-align:center;color:var(--text2)}
.state .ico{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;margin:0 auto 12px;background:var(--surface2);color:var(--text2)}
.state.ok .ico{background:var(--green-bg);color:var(--green)}.state .t{font-weight:700;color:var(--text);font-size:14px}.state .d{font-size:12.5px;margin-top:4px}
.step{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--accent);color:#fff;font-size:11px;font-weight:700;margin-right:6px;vertical-align:middle}
.help{display:flex;gap:11px;align-items:flex-start;background:var(--blue-bg);border:1px solid transparent;border-radius:var(--radius-sm);padding:13px 15px;margin-bottom:16px;font-size:13px;color:var(--text)}
.help .hb-i{flex-shrink:0;margin-top:1px;color:var(--accent)}
.help b{color:var(--text);font-weight:700}
.help .hb-t{margin-top:3px;line-height:1.55;color:var(--text2)}
.help .hb-t b{color:var(--text)}
.help code,.help .mono{background:var(--card);padding:1px 6px;border-radius:5px;font-family:'JetBrains Mono',monospace;font-size:12px;border:1px solid var(--border)}
.copy{cursor:pointer;background:var(--surface2);border:1px solid var(--border2);border-radius:6px;padding:2px 8px;font-size:10px;color:var(--accent)}
::-webkit-scrollbar{width:10px;height:10px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:8px;border:2px solid var(--bg)}::-webkit-scrollbar-thumb:hover{background:var(--text2)}
#toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:2000;display:flex;flex-direction:column;gap:8px;align-items:center}
.toast{background:var(--card);border:1px solid var(--border);color:var(--text);padding:11px 18px;border-radius:12px;font-size:13px;max-width:520px;box-shadow:var(--shadow-lg);white-space:pre-wrap;animation:toastIn .18s ease}
@keyframes toastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.toast.err{border-color:rgba(192,41,64,.4);background:#fff;box-shadow:var(--shadow-lg),inset 3px 0 0 0 var(--red)}
.omnibtn{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border2);border-radius:9px;padding:7px 12px;color:var(--text2);font-size:12px;cursor:pointer;font-family:inherit;transition:.15s}
.omnibtn:hover{color:var(--text);border-color:var(--accent)}
.omnibtn kbd{background:var(--card);border:1px solid var(--border2);border-radius:5px;padding:1px 6px;font-size:10px;font-family:'JetBrains Mono',monospace}
.iconbtn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;background:var(--surface2);border:1px solid var(--border2);color:var(--text2);cursor:pointer;transition:.15s}
.iconbtn:hover{color:var(--accent);border-color:var(--accent)}
.themetgl{display:none!important}.themetgl .sun{display:none}.themetgl .moon{display:inline-flex}
html.dark .themetgl .sun{display:inline-flex}html.dark .themetgl .moon{display:none}
.pal{position:fixed;inset:0;background:rgba(16,18,27,.44);backdrop-filter:blur(3px);z-index:1500;display:none;align-items:flex-start;justify-content:center;padding-top:11vh}
.pal.show{display:flex;animation:ovIn .16s ease}
.pal-box{width:620px;max-width:93vw;background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-lg);overflow:hidden;animation:mIn .16s ease}
.pal-in{width:100%;border:none;border-bottom:1px solid var(--border);background:transparent;color:var(--text);padding:16px 18px;font-size:15px;border-radius:0}
.pal-in:focus{box-shadow:none}
.pal-res{max-height:52vh;overflow-y:auto;padding:6px}
.pal-item{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:9px;cursor:pointer}
.pal-item.sel,.pal-item:hover{background:var(--accent-soft)}
.pal-item svg{flex-shrink:0;opacity:.85;color:var(--accent)}
.pal-item .pl{font-size:13.5px;font-weight:600;color:var(--text)}
.pal-item .pd{font-size:11.5px;color:var(--text2)}
.pal-item .ps{margin-left:auto;font-size:10px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px}
.pal-empty{padding:24px;text-align:center;color:var(--text2);font-size:13px}
.navgrp{border-bottom:1px solid var(--border)}
.navgrp:last-of-type{border-bottom:none}
.navhdr{display:flex;align-items:center;justify-content:space-between;padding:13px 18px 7px;font-size:10px;letter-spacing:1.1px;font-weight:700;color:var(--text2);opacity:.7;cursor:pointer;user-select:none;text-transform:uppercase;transition:.15s}
.navhdr:hover{opacity:1;color:var(--accent)}
.navhdr .chev{transition:transform .2s;opacity:.6}
.navgrp.collapsed .chev{transform:rotate(-90deg)}
.navgrp.collapsed .navitems{display:none}
.navitems{padding-bottom:6px}
.navfixed{display:flex;gap:10px;align-items:center;margin:6px 8px 2px;padding:8px 11px;border-radius:8px;color:var(--text2);font-size:13px;font-weight:500;transition:.13s}
.navfixed svg{opacity:.7;flex-shrink:0}
.navfixed:hover{color:var(--text);background:var(--surface2)}
.navfixed.active{color:var(--accent);background:var(--accent-soft);font-weight:600}
.navfixed.active svg{opacity:1;color:var(--accent)}
.acct{position:relative}
.acct-menu{position:absolute;right:0;top:calc(100% + 9px);min-width:200px;background:var(--card);border:1px solid var(--border);border-radius:11px;box-shadow:var(--shadow-lg);padding:6px;display:none;z-index:200}
.acct-menu.open{display:block}
.acct-host{font-size:11px;color:var(--text2);padding:7px 12px 9px;border-bottom:1px solid var(--border);margin-bottom:5px;font-family:'JetBrains Mono',monospace;word-break:break-all}
.acct-menu a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--text2);font-size:13px;font-weight:500}
.acct-menu a:hover{background:var(--surface2);color:var(--text)}
.acct-menu a.active{color:var(--accent);background:var(--accent-soft)}
.acct-menu a svg{opacity:.8}
.acct-sep{height:1px;background:var(--border);margin:7px 6px}
.acct-menu a.acct-logout{color:var(--red)}
.acct-menu a.acct-logout:hover{background:rgba(225,29,72,.1);color:var(--red)}
.modal-ov{position:fixed;inset:0;background:rgba(16,18,27,.44);backdrop-filter:blur(3px);z-index:3000;display:flex;align-items:center;justify-content:center;padding:20px;animation:ovIn .16s ease}
@keyframes ovIn{from{opacity:0}to{opacity:1}}
.modal{width:430px;max-width:94vw;max-height:90vh;background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-lg);overflow:hidden;display:flex;flex-direction:column;animation:mIn .16s ease}
@keyframes mIn{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.modal-b{padding:20px 22px;overflow-y:auto;flex:1 1 auto;min-height:0}
.modal-t{font-size:15px;font-weight:700;color:var(--text);margin-bottom:8px}
.modal-m{font-size:13.5px;color:var(--text2);line-height:1.55;white-space:pre-wrap}
.modal-input{margin-top:14px}
.modal-f{display:flex;justify-content:flex-end;gap:9px;padding:13px 22px;border-top:1px solid var(--border);background:var(--surface2);flex-shrink:0}
.guidebar{display:flex;justify-content:flex-end;margin-bottom:12px;margin-top:-2px}
.btn-help{display:inline-flex;align-items:center;gap:7px;background:var(--accent-soft);color:var(--accent);border:1px solid transparent;border-radius:9px;padding:7px 13px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s}
.btn-help:hover{background:var(--accent);color:#fff}
.guide{font-size:13.5px;color:var(--text);line-height:1.6;max-height:66vh;overflow:auto}
.guide h3{font-size:16px}
.guide .g-what{color:var(--text2);margin:6px 0 4px;line-height:1.6}
.guide .g-h{font-size:11px;letter-spacing:.6px;text-transform:uppercase;font-weight:700;color:var(--accent);margin:16px 0 8px}
.guide ol.g-steps{margin:0 0 4px;padding-left:20px}
.guide ol.g-steps li{margin-bottom:9px;padding-left:3px}
.guide ul.g-tips{margin:0;padding-left:2px;list-style:none}
.guide ul.g-tips li{position:relative;margin-bottom:8px;padding-left:16px;color:var(--text2)}
.guide ul.g-tips li:before{content:"";position:absolute;left:0;top:8px;width:6px;height:6px;border-radius:50%;background:var(--accent);opacity:.7}
.guide b{color:var(--text)}
.guide code,.guide .mono{background:var(--surface2);padding:1px 6px;border-radius:5px;font-family:'JetBrains Mono',monospace;font-size:12px;border:1px solid var(--border);word-break:break-word}
CSS;
}

function renderLogin(string $err, string $stage = 'password', string $show = ''): void { $pend = $_SESSION['2fa_pending'] ?? []; ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="author" content="<?=h(ozAttr()['name'])?>"><meta name="generator" content="Orizen Panel"><meta name="description" content="Web hosting management with a built-in self-hosted cryptocurrency payment gateway.">
<script>(function(){try{if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark');}catch(e){}})();</script>
<title>Orizen Panel - Web Hosting Management &amp; Self-Hosted Crypto Payments</title><?=faviconTag()?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono&display=swap" rel="stylesheet">
<style><?=themeCss()?>
body{flex-direction:column;align-items:center;justify-content:center}
.box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:40px 36px;width:384px;max-width:92vw;box-shadow:var(--shadow-lg)}
.box-credit{margin-top:18px;font-size:12px;color:var(--text2);text-align:center}.box-credit a{color:var(--accent);font-weight:600}
.box .ico{text-align:center;display:flex;justify-content:center;margin-bottom:12px;color:var(--accent)}
.box h1{text-align:center;font-size:23px;font-weight:800;margin-bottom:4px;letter-spacing:-.3px;color:var(--text)}
.box p{text-align:center;color:var(--text2);font-size:13px;margin-bottom:24px}
.box input{margin-bottom:14px}
.box .btn{width:100%;justify-content:center;padding:11px}
.cap{display:flex;gap:10px;align-items:stretch;margin-bottom:6px}
.cap-img{height:46px;border:1px solid var(--border);border-radius:8px;cursor:pointer;flex:0 0 auto}
.cap input{margin-bottom:0;flex:1 1 auto;min-width:0}
.cap-hint{font-size:11px;color:var(--text2);margin-bottom:16px}
.cap-hint a{color:var(--accent);cursor:pointer;font-weight:600}
.l2f-tabs{display:flex;gap:6px;margin-bottom:14px;background:var(--surface2,rgba(125,125,160,.08));border-radius:10px;padding:4px}
.l2f-tab{flex:1;padding:8px;border:0;border-radius:7px;background:none;color:var(--text2);font-size:13px;font-weight:600;cursor:pointer;box-shadow:none}
.l2f-tab.on{background:var(--card);color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.12)}
</style></head><body>
<?php if ($stage === '2fa'):
  $both = !empty($pend['tg']) && !empty($pend['totp']);
  $tab  = ($show === 'tg' || (empty($pend['totp']) && !empty($pend['tg']))) ? 'tg' : 'app';
?>
<form class="box" method="post" id="l2f">
  <span class="ico"><?=orizenMark(44)?></span><h1>Two-step verification</h1>
  <?php if ($err): ?><div class="alert alert-e"><?=h($err)?></div><?php endif; ?>
  <input type="hidden" name="login_2fa_method" id="l2fMethod" value="<?=h($tab)?>">
  <?php if ($both): ?>
  <div class="l2f-tabs">
    <button type="button" class="l2f-tab" data-m="app" onclick="l2fTab('app')">Authenticator app</button>
    <button type="button" class="l2f-tab" data-m="tg" onclick="l2fTab('tg')">Telegram</button>
  </div>
  <?php endif; ?>
  <p id="l2fHint"></p>
  <?php if (!empty($pend['tg'])): ?>
  <div id="l2fSend" style="display:none;text-align:center;margin-bottom:12px">
    <button class="btn btn-g btn-xs" type="submit" name="login_2fa_send" value="1"><?= $show==='tg' ? 'Resend code to Telegram' : 'Send code to my Telegram' ?></button>
  </div>
  <?php endif; ?>
  <label>Verification code</label><input name="login_2fa" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" autofocus>
  <button class="btn btn-p" type="submit">Verify</button>
  <div style="text-align:center;margin-top:14px"><a class="xs" href="?logout=1" style="color:var(--text2)">Cancel and start over</a></div>
</form>
<script>
var L2F_HINTS={app:'Enter the 6-digit code from your authenticator app.',tg:<?= $show==='tg' ? "'We sent a 6-digit code to your Telegram. Enter it below.'" : "'Press \"Send code to my Telegram\", then enter the code we send you.'" ?>};
function l2fTab(m){ document.getElementById('l2fMethod').value=m; var h=document.getElementById('l2fHint'); if(h)h.textContent=L2F_HINTS[m]||L2F_HINTS.app; var s=document.getElementById('l2fSend'); if(s)s.style.display=(m==='tg')?'':'none'; document.querySelectorAll('.l2f-tab').forEach(function(b){b.classList.toggle('on',b.dataset.m===m);}); }
l2fTab('<?=h($tab)?>');
</script>
<?php else: ?>
<form class="box" method="post">
  <span class="ico"><?=orizenMark(44)?></span><h1>Orizen Panel</h1><p>Web hosting + self-hosted crypto payments</p>
  <?php if ($err): ?><div class="alert alert-e"><?=h($err)?></div><?php endif; ?>
  <label>Username</label><input name="login_user" autofocus required>
  <label>Password</label><input name="login_pass" type="password" required>
  <label>Captcha</label>
  <div class="cap">
    <img id="capImg" class="cap-img" src="?captcha=1&amp;r=<?=time()?>" alt="Security code" onclick="this.src='?captcha=1&amp;r='+Date.now()" title="Click for a new image">
    <input name="login_captcha" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Type the characters" required>
  </div>
  <div class="cap-hint">Not case-sensitive &middot; <a onclick="document.getElementById('capImg').src='?captcha=1&amp;r='+Date.now()">get a new image</a></div>
  <button class="btn btn-p">Sign in</button>
</form>
<?php endif; ?>
<div class="box-credit">Developed by <a href="<?=h(ozAttr()['url'])?>" target="_blank" rel="noopener"><?=h(ozAttr()['name'])?></a></div>
</body></html>
<?php }

function renderHead(string $title): void {
    global $page, $pages;
    $secs = navSectionsAll();
    ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="author" content="<?=h(ozAttr()['name'])?>"><meta name="generator" content="Orizen Panel"><meta name="description" content="Web hosting management with a built-in self-hosted cryptocurrency payment gateway.">
<script>(function(){try{if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark');}catch(e){}})();</script>
<title><?=h($title)?> . Orizen Panel</title><?=faviconTag()?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono&display=swap" rel="stylesheet">
<style><?=themeCss()?></style></head><body>
<script>
// Core helpers - defined up-front so any page script (which runs before the footer) can use them.
var CSRF='<?=csrf()?>';
function api(action,data){ var b=new URLSearchParams(); b.append('action',action); b.append('csrf',CSRF); for(var k in (data||{})) b.append(k,data[k]); return fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(function(r){return r.json();}); }
function toast(msg,kind){ var c=document.getElementById('toast'); if(!c){ c=document.createElement('div'); c.id='toast'; document.body.appendChild(c); } var t=document.createElement('div'); t.className='toast'+(kind==='e'?' err':''); t.textContent=msg; c.appendChild(t); setTimeout(function(){ t.style.transition='.3s'; t.style.opacity='0'; setTimeout(function(){t.remove();},300); }, kind==='e'?6000:3200); }
</script>
<aside class="sidebar">
  <div class="logo"><?=orizenMark(21)?> <span>Orizen<b> Panel</b></span></div>
  <nav id="nav">
  <a href="?page=dashboard" class="navfixed <?=$page==='dashboard'?'active':''?>"><?=icon('dashboard')?><span>Dashboard</span></a>
  <?php foreach ($secs as $sec=>$items): $vis = array_values(array_filter($items, 'pageAllowed')); if (!$vis) continue; ?>
    <div class="navgrp" data-sec="<?=h($sec)?>" data-defcol="<?=in_array($sec, navDefaultCollapsed(), true)?'1':'0'?>">
      <div class="navhdr" onclick="toggleSec(this.parentNode)"><span><?=$sec?></span><svg class="chev" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></div>
      <div class="navitems">
      <?php foreach ($vis as $p): ?>
        <a href="?page=<?=$p?>" class="<?=$page===$p?'active':''?>"><?=icon($p)?><span><?=h($pages[$p])?></span></a>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </nav>
  <script>
  function navLoad(){ try{ return JSON.parse(localStorage.getItem('navState3')||'{}'); }catch(e){ return {}; } }
  function toggleSec(g){ g.classList.toggle('collapsed'); var st=navLoad(); st[g.dataset.sec]=g.classList.contains('collapsed'); try{localStorage.setItem('navState3',JSON.stringify(st));}catch(e){} }
  (function(){ var st=navLoad(); document.querySelectorAll('.navgrp').forEach(function(g){ var col=(st[g.dataset.sec]!==undefined)?st[g.dataset.sec]:(g.dataset.defcol==='1'); if(g.querySelector('a.active'))col=false; g.classList.toggle('collapsed',col); }); })();
  </script>
</aside>
<div class="main">
  <div class="top"><h2><?=h($title)?></h2>
    <div class="flex" style="gap:12px;align-items:center">
      <button class="omnibtn" onclick="openPalette()" title="Search tools (Ctrl F)">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <span>Search tools</span><kbd>Ctrl F</kbd>
      </button>
      <button class="iconbtn themetgl" onclick="toggleTheme()" title="Toggle light / dark theme">
        <svg class="moon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        <svg class="sun" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5 4 4M19 19l1 1M19 5l1-1M5 19l-1 1"/></svg>
      </button>
      <div class="acct">
        <button class="iconbtn" onclick="toggleAcct(event)" title="Account (Settings &amp; Logout)">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4.5 20.5a8 8 0 0 1 15 0"/></svg>
        </button>
        <div class="acct-menu" id="acctMenu">
          <div class="acct-host"><b><?=h(curUser())?></b> . <?=h(curRole())?><br><span class="mono xs"><?=h(cfgGet('server_ip'))?></span></div>
          <?php if (isAdmin()): ?><a href="?page=settings" class="<?=$page==='settings'?'active':''?>"><?=icon('settings')?><span>Settings</span></a><?php endif; ?>
          <div class="acct-sep"></div>
          <a href="?logout=1" class="acct-logout"><?=icon('logout')?><span>Logout</span></a>
        </div>
      </div>
    </div>
  </div>
  <div class="content">
<?php }

function renderFoot(): void {
    global $pages;
    $tools = [];
    // Dashboard + Settings aren't in the sidebar groups but must still be searchable.
    $searchSecs = ['MAIN'=>['dashboard']] + navSectionsAll() + ['SETTINGS'=>['settings']];
    foreach ($searchSecs as $sec=>$items) foreach ($items as $slug) {
        if (!isset($pages[$slug])) continue;
        $km = navKeywords()[$slug] ?? ['',''];
        $tools[] = ['slug'=>$slug,'label'=>$pages[$slug],'sec'=>$sec,'kw'=>$km[0],'desc'=>$km[1],'icon'=>icon($slug,16)];
    }
    ?>
  </div></div>
<div class="pal" id="pal" onclick="if(event.target===this)closePalette()">
  <div class="pal-box">
    <input class="pal-in" id="palIn" placeholder="Search for a tool... (e.g. 'add domain', 'email', 'backup database')" oninput="palFilter()" onkeydown="palKey(event)" autocomplete="off">
    <div class="pal-res" id="palRes"></div>
  </div>
</div>
<script>
var TOOLS=<?=json_encode($tools, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
var palSel=0, palList=[];
function openPalette(){ var p=document.getElementById('pal'); p.classList.add('show'); var i=document.getElementById('palIn'); i.value=''; palFilter(); i.focus(); }
function closePalette(){ document.getElementById('pal').classList.remove('show'); }
function palScore(t,q){ var lbl=t.label.toLowerCase(), kw=t.kw.toLowerCase(), hay=(t.label+' '+t.kw+' '+t.desc+' '+t.sec).toLowerCase(); var toks=q.toLowerCase().split(/\s+/).filter(Boolean); if(!toks.length) return 1; var s=0, matched=0; for(var i=0;i<toks.length;i++){ var tk=toks[i], idx=hay.indexOf(tk); if(idx<0) continue; matched++; s+=(lbl.indexOf(tk)===0?8:0)+(lbl.indexOf(tk)>=0?5:0)+(kw.indexOf(tk)>=0?2:0)+1; } if(!matched) return -1; return s + matched*2; }
function palFilter(){ var q=document.getElementById('palIn').value.trim();
  palList=TOOLS.map(function(t){return {t:t,s:palScore(t,q)};}).filter(function(x){return x.s>=0;}).sort(function(a,b){return b.s-a.s;}).map(function(x){return x.t;});
  palSel=0; palRender();
}
function palRender(){ var r=document.getElementById('palRes'); if(!palList.length){ r.innerHTML='<div class="pal-empty">No tool matches that. Try simpler words like "domain", "ssl", "files".</div>'; return; }
  r.innerHTML=palList.map(function(t,i){ return '<div class="pal-item'+(i===palSel?' sel':'')+'" onclick="palGo('+i+')" onmousemove="palSel='+i+';palMark()">'+t.icon+'<div><div class="pl">'+t.label+'</div><div class="pd">'+t.desc+'</div></div><span class="ps">'+t.sec+'</span></div>'; }).join('');
}
function palMark(){ document.querySelectorAll('#palRes .pal-item').forEach(function(el,i){ el.classList.toggle('sel', i===palSel); }); }
function palGo(i){ var t=palList[i]; if(t) location.href='?page='+t.slug; }
function palKey(e){ if(e.key==='ArrowDown'){e.preventDefault();palSel=Math.min(palSel+1,palList.length-1);palMark();palScroll();} else if(e.key==='ArrowUp'){e.preventDefault();palSel=Math.max(palSel-1,0);palMark();palScroll();} else if(e.key==='Enter'){e.preventDefault();palGo(palSel);} else if(e.key==='Escape'){closePalette();} }
function palScroll(){ var el=document.querySelectorAll('#palRes .pal-item')[palSel]; if(el) el.scrollIntoView({block:'nearest'}); }
document.addEventListener('keydown',function(e){ if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='f'){ e.preventDefault(); var p=document.getElementById('pal'); if(p.classList.contains('show')) closePalette(); else openPalette(); } });
function toggleTheme(){ var d=document.documentElement.classList.toggle('dark'); try{localStorage.setItem('theme',d?'dark':'light');}catch(e){} }
function toggleAcct(e){ e.stopPropagation(); document.getElementById('acctMenu').classList.toggle('open'); }
document.addEventListener('click',function(){ var m=document.getElementById('acctMenu'); if(m) m.classList.remove('open'); });
// Persistent "Working..." toast with a spinner - returned function dismisses it.
var __actBusy = {};
function busyToast(msg){
  if(!document.getElementById('__tspinCss')){ var st=document.createElement('style'); st.id='__tspinCss';
    st.textContent='.toast.busy{display:flex;align-items:center;gap:8px}.tspin{width:13px;height:13px;border:2px solid rgba(150,150,170,.35);border-top-color:currentColor;border-radius:50%;display:inline-block;animation:tspin .7s linear infinite;flex:0 0 auto}@keyframes tspin{to{transform:rotate(360deg)}}';
    document.head.appendChild(st); }
  var c=document.getElementById('toast'); if(!c){ c=document.createElement('div'); c.id='toast'; document.body.appendChild(c); }
  var t=document.createElement('div'); t.className='toast busy'; t.innerHTML='<span class="tspin"></span><span></span>';
  t.lastChild.textContent=msg||'Working...'; c.appendChild(t);
  return function(){ if(!t)return; var el=t; t=null; el.style.transition='.2s'; el.style.opacity='0'; setTimeout(function(){el.remove();},200); };
}
function act(action,data,reload){
  var key=action+'|'+JSON.stringify(data||{});
  if(__actBusy[key]){ toast('Still working - please wait...'); return Promise.resolve({ok:false,busy:true}); }
  __actBusy[key]=1;
  // Immediate feedback + block double-clicks: some actions (site + Cloudflare + HTTPS) take 20-30s.
  var btn=(document.activeElement && document.activeElement.tagName==='BUTTON')?document.activeElement:null;
  if(btn) btn.disabled=true;
  var done=busyToast('Working...');
  return api(action,data||{}).then(function(r){
    if(r&&r.ok){ toast(r.msg||'Done'); if(reload) setTimeout(function(){location.reload();},700); }
    else toast((r&&r.error)||'Error','e');
    return r;
  }).catch(function(e){ toast('Request failed: '+e.message,'e'); })
    .then(function(r){ delete __actBusy[key]; done(); if(btn) btn.disabled=false; return r; });
}
// -- Styled HTML modals (replace native confirm/prompt/alert) --
function uiModal(o){
  var ov=document.createElement('div'); ov.className='modal-ov';
  ov.innerHTML='<div class="modal"><div class="modal-b"><div class="modal-t"></div>'
    +'<div class="modal-m"></div>'+(o.input?'<input class="modal-input mono" id="__mIn">':'')+'</div>'
    +'<div class="modal-f">'+(o.single?'':'<button class="btn btn-g" id="__mX">'+(o.cancelText||'Cancel')+'</button>')
    +'<button class="btn '+(o.danger?'btn-d':'btn-p')+'" id="__mOk">'+(o.okText||'OK')+'</button></div></div>';
  ov.querySelector('.modal-t').textContent=o.title||'Confirm';
  var __mb=ov.querySelector('.modal-m');
  // Default to safe text. Callers that need rich content pass o.html:true with
  // markup they have already built/escaped - never raw, unescaped user input.
  if(o.html){__mb.innerHTML=o.body||'';}else{__mb.textContent=o.body||'';}
  document.body.appendChild(ov);
  var inp=ov.querySelector('#__mIn'); if(inp){ if(o.value!=null)inp.value=o.value; if(o.placeholder)inp.placeholder=o.placeholder; }
  function close(){ ov.remove(); document.removeEventListener('keydown',key,true); }
  function done(){ var v=inp?inp.value:true; close(); if(o.onOk)o.onOk(v); }
  function key(e){ if(e.key==='Escape'){e.preventDefault();close();} else if(e.key==='Enter'){e.preventDefault();done();} }
  ov.querySelector('#__mOk').onclick=done; var x=ov.querySelector('#__mX'); if(x)x.onclick=close;
  ov.addEventListener('mousedown',function(e){ if(e.target===ov)close(); });
  document.addEventListener('keydown',key,true);
  setTimeout(function(){ (inp||ov.querySelector('#__mOk')).focus(); if(inp&&inp.select)inp.select(); },20);
}
function uiConfirm(msg,cb,opts){ opts=opts||{}; uiModal({title:opts.title||'Please confirm',body:msg,okText:opts.okText||'Yes, continue',danger:opts.danger!==false,onOk:function(){cb&&cb();}}); }
function uiPrompt(title,def,cb,opts){ opts=opts||{}; uiModal({title:title,body:opts.body||'',input:true,value:def,placeholder:opts.placeholder||'',okText:opts.okText||'OK',onOk:function(v){ v=(v==null?'':(''+v).trim()); if(v!=='')cb&&cb(v); }}); }
function uiAlert(msg,cb){ uiModal({title:'Notice',body:msg,single:true,okText:'OK',onOk:function(){cb&&cb();}}); }
/* Rich HTML modal (renders markup) with its own Close button. uiClose() dismisses the top one. */
function uiHtml(html,wide){ var ov=document.createElement('div'); ov.className='modal-ov';
  ov.innerHTML='<div class="modal"'+(wide?' style="max-width:640px"':'')+'><div class="modal-b" id="__mh"></div><div class="modal-f"><button class="btn btn-g" id="__mhx">Close</button></div></div>';
  ov.querySelector('#__mh').innerHTML=html; document.body.appendChild(ov);
  function close(){ ov.remove(); if(window.__uiClose===close) window.__uiClose=null; }
  ov.querySelector('#__mhx').onclick=close; ov.addEventListener('mousedown',function(e){ if(e.target===ov)close(); });
  window.__uiClose=close; return close;
}
function uiClose(){ if(window.__uiClose) window.__uiClose(); }
function confirmAct(msg,action,data,reload){ uiConfirm(msg,function(){ act(action,data,reload); }); }
function copyText(t){ navigator.clipboard.writeText(t).then(function(){toast('Copied ');}); }
// Small HTML escaper used by rich modals (e.g. File Manager properties).
function uEsc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
</script>
</body></html>
<?php }

// ------------------------------------------------------------
//  PAGES
// ------------------------------------------------------------
function svcRunning(string $needle): bool { return svcActive($needle, $needle); }

/** RAM as [total_bytes, used_bytes] - works on every Linux distro and on Windows. */
function sysMem(): array {
    if (isWin()) {   // wmic spawns ~5x faster than PowerShell; PowerShell only as a fallback
        $o = sh('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /format:value 2>NUL');
        if (preg_match('/FreePhysicalMemory=(\d+)/',$o,$f) && preg_match('/TotalVisibleMemorySize=(\d+)/',$o,$tt)) { $t=(int)$tt[1]*1024; return [$t, max(0,$t-(int)$f[1]*1024)]; }
        $o = sh('powershell -NoProfile -Command "$o=Get-CimInstance Win32_OperatingSystem; \'{0} {1}\' -f $o.TotalVisibleMemorySize,$o.FreePhysicalMemory"');
        if (preg_match('/(\d+)\s+(\d+)/', $o, $m)) { $t=(int)$m[1]*1024; return [$t, max(0,$t-(int)$m[2]*1024)]; }
        return [0,0];
    }
    $mem = @file_get_contents('/proc/meminfo');   // universal across Linux distros
    if ($mem) {
        $t = preg_match('/MemTotal:\s+(\d+)/',$mem,$x) ? (int)$x[1]*1024 : 0;
        $a = preg_match('/MemAvailable:\s+(\d+)/',$mem,$x) ? (int)$x[1]*1024 : (preg_match('/MemFree:\s+(\d+)/',$mem,$x)?(int)$x[1]*1024:0);
        if ($t) return [$t, max(0,$t-$a)];
    }
    $o = sh("free -b 2>/dev/null | awk '/^Mem:/{print \$2, \$3}'");
    if (preg_match('/(\d+)\s+(\d+)/',$o,$m)) return [(int)$m[1],(int)$m[2]];
    return [0,0];
}
/** Load average triple (CPU% fallback on Windows). */
function sysLoad(): array {
    if (!isWin() && function_exists('sys_getloadavg')) { $l = @sys_getloadavg(); if (is_array($l)) return $l; }
    if (isWin()) {
        $o = sh('wmic cpu get loadpercentage /format:value 2>NUL');
        if (preg_match('/LoadPercentage=(\d+)/',$o,$m)) { $c=round(((float)$m[1])/100,2); return [$c,$c,$c]; }
        return [0,0,0];
    }
    return [0,0,0];
}
/** Human uptime string cross-platform. */
function sysUptime(): string {
    if (isWin()) {
        $fmt = function(int $s): string { $s=max(0,$s); $d=intdiv($s,86400); $h=intdiv($s%86400,3600); $mn=intdiv($s%3600,60); return ($d?$d.'d ':'').$h.'h '.$mn.'m'; };
        $o = sh('wmic os get lastbootuptime /format:value 2>NUL');
        if (preg_match('/LastBootUpTime=(\d{14})/',$o,$m)) {
            $b = DateTime::createFromFormat('YmdHis', $m[1]);
            if ($b) return $fmt(time()-$b->getTimestamp());
        }
        // wmic can return empty via PHP exec; CIM is the reliable fallback (seconds since boot)
        $o = trim(sh('powershell -NoProfile -Command "[int]((Get-Date)-(Get-CimInstance Win32_OperatingSystem).LastBootUpTime).TotalSeconds"'));
        if (is_numeric($o) && (int)$o > 0) return $fmt((int)$o);
        return 'n/a';
    }
    $u = trim(sh('uptime -p 2>/dev/null')); if ($u !== '') return $u;
    $up = @file_get_contents('/proc/uptime');
    if ($up && preg_match('/^(\d+)/',$up,$m)) { $s=(int)$m[1]; $d=intdiv($s,86400); $h=intdiv($s%86400,3600); $mn=intdiv($s%3600,60); return ($d?$d.'d ':'').$h.'h '.$mn.'m'; }
    return trim(sh('uptime')) ?: 'n/a';
}

/** Current CPU usage as a simple 0-100 percentage. */
function cpuPct(): int {
    if (isWin()) {
        // CIM LoadPercentage is the reliable current CPU load on Windows (wmic can return empty).
        $o = trim(sh('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"'));
        return is_numeric($o) ? (int)round((float)$o) : 0;
    }
    // Linux: true CPU% from two /proc/stat snapshots (universal across every distro).
    $snap = function () {
        $s = @file_get_contents('/proc/stat');
        if (!$s || !preg_match('/^cpu\s+(.+)$/m', $s, $m)) return null;
        $p = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $idle = ($p[3] ?? 0) + ($p[4] ?? 0);   // idle + iowait
        return [$idle, array_sum($p)];
    };
    $a = $snap();
    if (!$a) {   // last-resort estimate from load average / core count
        $cores = (int)trim(sh('nproc 2>/dev/null')); if ($cores < 1) $cores = preg_match_all('/^processor\s*:/m', @file_get_contents('/proc/cpuinfo') ?: '') ?: 1;
        $la = function_exists('sys_getloadavg') ? (@sys_getloadavg() ?: [0]) : [0];
        return (int)min(100, max(0, round(($la[0] / max(1, $cores)) * 100)));
    }
    usleep(200000);
    $b = $snap(); if (!$b) return 0;
    $dIdle = $b[0] - $a[0]; $dTotal = $b[1] - $a[1];
    if ($dTotal <= 0) return 0;
    return (int)min(100, max(0, round((1 - $dIdle / $dTotal) * 100)));
}

function pageDashboard(): void {
    $sites = loadJson(SITES_FILE, []);
    $mail = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
    ?>
<div class="grid mb">
  <div class="stat"><div class="l">Websites</div><div class="v"><?=count($sites)?></div></div>
  <div class="stat"><div class="l">Databases</div><div class="v" id="dsDb">-</div></div>
  <div class="stat"><div class="l">Mailboxes</div><div class="v"><?=count($mail['mailboxes'])?></div></div>
  <div class="stat"><div class="l">PHP</div><div class="v" style="font-size:16px"><?=h(PHP_VERSION)?></div></div>
</div>
<div class="grid mb">
  <div class="stat"><div class="l">Disk</div><div class="v" id="dsDisk" style="font-size:15px">...</div></div>
  <div class="stat"><div class="l">RAM</div><div class="v" id="dsRam" style="font-size:15px">...</div></div>
  <div class="stat"><div class="l">CPU Usage</div><div class="v" id="dsCpu">...</div></div>
  <div class="stat"><div class="l">Uptime</div><div class="v" id="dsUp" style="font-size:14px">...</div></div>
</div>
<div class="card">
  <h3>Services</h3>
  <div class="flex wrap" id="dsSvc"><span class="xs muted">Checking services...</span></div>
</div>
<div class="card">
  <h3>Quick start - get a site online</h3>
  <div class="sm" style="line-height:2.2">
    <span class="step">1</span> Add your domain in <a href="?page=websites"><b>Websites</b></a> (creates <span class="mono">/var/www/&lt;domain&gt;/public</span>).<br>
    <span class="step">2</span> At your registrar, point an <b>A record</b> <span class="mono">@ -> <?=h(cfgGet('server_ip'))?></span> &amp; <span class="mono">www -> <?=h(cfgGet('server_ip'))?></span> &nbsp;<a href="?page=dns">(exact records ->)</a><br>
    <span class="step">3</span> <b>HTTPS turns on automatically</b> once DNS points here (or <a href="?page=ssl">Secure</a> it now).<br>
    <span class="step">4</span> Manage your files in the <a href="?page=files"><b>File Manager</b></a> and databases in the <a href="?page=sql"><b>SQL Browser</b></a> - built right in.
  </div>
</div>
<div class="card"><div class="sm muted">Orizen Panel is your whole control panel in one place: <b>domains, DNS, SSL, email, databases, files, logs &amp; services</b> - no extra tools needed.</div></div>
<div class="card credit-card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
  <div class="flex" style="gap:12px;align-items:center">
    <?=orizenMark(30)?>
    <div><div class="xs muted" style="text-transform:uppercase;letter-spacing:1px">Developed by</div><div style="font-weight:800;font-size:16px"><?=h(ozAttr()['name'])?></div></div>
  </div>
  <a class="btn btn-p" href="<?=h(ozAttr()['url'])?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.2 0-1.6.8-1.6 1.5V12h2.7l-.4 2.9h-2.3v7A10 10 0 0 0 22 12z"/></svg> Facebook</a>
</div>
<script>
(function(){
  function setTxt(id,v){ var e=document.getElementById(id); if(e) e.textContent=v; }
  function load(){
    api('dash_stats',{}).then(function(r){
      if(!r||!r.ok) return;
      setTxt('dsDb', r.dbCount); setTxt('dsDisk', r.disk); setTxt('dsRam', r.ram);
      setTxt('dsCpu', r.cpu); setTxt('dsUp', r.uptime);
      var box=document.getElementById('dsSvc'); if(box){
        box.innerHTML = (r.services||[]).map(function(s){
          return '<div class="flex" style="background:var(--surface2);padding:8px 14px;border-radius:10px">'+
                 '<span class="dot '+(s.on?'on':'off')+'"></span><b class="sm">'+s.label+'</b>'+
                 '<span class="xs muted">'+(s.on?'running':'stopped')+'</span></div>';
        }).join('') || '<span class="xs muted">No services detected.</span>';
      }
    }).catch(function(){});
  }
  function tick(){ api('dash_cpu',{}).then(function(r){ if(r&&r.ok){ setTxt('dsCpu', r.cpu); setTxt('dsRam', r.ram); if(r.uptime) setTxt('dsUp', r.uptime); } }).catch(function(){}); }
  if(document.readyState!=='loading') load(); else document.addEventListener('DOMContentLoaded',load);
  setInterval(tick, 5000);   // keep CPU / RAM live so the reading isn't a frozen snapshot
})();
</script>
<?php }

function pageWebsites(): void {
    $sites = loadJson(SITES_FILE, []);
    if (!isAdmin()) $sites = array_values(array_filter($sites, fn($s)=>($s['owner']??'')===curUser()));   // tenants see only their own sites
    $ip = cfgGet('server_ip'); $cf = cfConnected(); $cfAuto = cfAutoEnabled();
    ?>
<?=helpBox('How websites work here', 'Each website gets its own folder under <span class="mono">/var/www</span>. Add a domain to make a <b>new website</b> with its own files - that is also how you host <b>several different sites</b> on one server (each domain = its own folder). To make <b>several domains show the SAME website</b>, choose type <b>Alias</b> and pick the site to share. To forward a domain somewhere else, use <a href="?page=redirects">Redirects</a> instead.')?>
<div class="card">
  <h3>Add a website</h3>
  <div class="row">
    <div style="max-width:215px"><label>Type</label>
      <select id="wMode" onchange="document.getElementById('wShareWrap').style.display=(this.value==='alias')?'':'none'">
        <option value="new">New website (its own files)</option>
        <option value="alias">Alias (same files as another site)</option>
      </select>
    </div>
    <div><label>Domain name</label><input id="wDomain" placeholder="example.com"></div>
    <div id="wShareWrap" style="display:none"><label>Show the same site as</label>
      <select id="wShare">
        <?php foreach(array_filter($sites, fn($s)=>empty($s['alias_of'])&&empty($s['sub'])) as $s):?><option><?=h($s['domain'])?></option><?php endforeach;?>
      </select>
    </div>
    <button class="btn btn-p" onclick="act('site_add',{domain:wDomain.value,mode:wMode.value,share:(document.getElementById('wShare')||{}).value||''},true)">Create</button>
  </div>
  <div class="xs muted mt">New site root: <span class="mono"><?=h(cfgGet('webroot_base','/var/www'))?>/&lt;domain&gt;/public</span> with <code>.htaccess</code> support (WordPress-ready).</div>
</div>

<?php
  $cfChip = function(string $label, bool $on): string {
      return '<span class="cfchip '.($on?'on':'off').'">'.($on?'&#10003;':'&#8226;').' '.h($label).'</span>';
  };
?>
<div class="card cfcard">
  <div class="cfcard-h">
    <div class="flex" style="gap:10px;align-items:center">
      <span class="cf-cloud">&#9729;&#65039;</span>
      <div>
        <b>Cloudflare automation</b>
        <?php if ($cfAuto): ?><span class="badge bg-green">Automatic</span>
        <?php elseif ($cf): ?><span class="badge bg-amber">Manual mode</span>
        <?php else: ?><span class="badge bg-red">Not connected</span><?php endif; ?>
        <div class="xs muted">
          <?php if ($cfAuto): ?>New sites, subdomains, redirects and email are pointed, proxied and secured automatically - you never touch DNS.
          <?php elseif ($cf): ?>A token is connected but automation is <b>off</b>. Everything still works the manual way.
          <?php else: ?>Optional. Connect a token for one-click DNS + lifetime HTTPS + email records. Manual DNS always works too.<?php endif; ?>
        </div>
      </div>
    </div>
    <a class="btn btn-s btn-xs" href="?page=settings">Manage Cloudflare &rarr;</a>
  </div>
  <div class="cfchips">
    <?=$cfChip('DNS records', $cfAuto)?>
    <?=$cfChip('SSL / HTTPS', $cfAuto)?>
    <?=$cfChip('Email DNS', $cfAuto)?>
    <?=$cfChip('Cache purge', $cf)?>
    <?=$cfChip('Firewall / proxy', $cf)?>
  </div>
  <?php if (!$cf): ?>
  <div class="xs muted mt">Manual setup: at your registrar set an <b>A record</b> to <span class="mono"><?=h($ip)?></span> for <span class="mono">@</span> and <span class="mono">www</span>, then HTTPS turns on by itself. <a href="?page=dns">See exact records</a>.</div>
  <?php endif; ?>
</div>
<style>
.cfcard{border:1px solid var(--border)}
.cfcard-h{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.cf-cloud{font-size:20px;line-height:1}
.cfchips{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.cfchip{font-size:11.5px;font-weight:600;padding:5px 11px;border-radius:999px;border:1px solid var(--border)}
.cfchip.on{color:var(--green);background:var(--green-bg);border-color:var(--green-bg)}
.cfchip.off{color:var(--text2);background:var(--surface2)}
</style>

<?php
  // Nest subdomains under their parent website (tree view). A sub belongs to the
  // longest listed main domain it ends with; subs with no listed parent stand alone.
  $mains = array_values(array_filter($sites, fn($s)=>empty($s['sub'])));
  $subs  = array_values(array_filter($sites, fn($s)=>!empty($s['sub'])));
  $children = []; $orphans = [];
  foreach ($subs as $sub) {
      $sd = strtolower($sub['domain']); $parent = null;
      foreach ($mains as $m) {
          $md = strtolower($m['domain']);
          if (str_ends_with($sd, '.'.$md) && ($parent === null || strlen($md) > strlen($parent))) $parent = $md;
      }
      if ($parent !== null) $children[$parent][] = $sub; else $orphans[] = $sub;
  }
  $ordered = [];
  foreach ($mains as $m) { $ordered[] = [$m, false]; foreach (($children[strtolower($m['domain'])] ?? []) as $c) $ordered[] = [$c, true]; }
  foreach ($orphans as $o) $ordered[] = [$o, false];
?>
<style>.sub-row td{background:rgba(120,130,170,.06)} .sub-tree{color:var(--text2);margin-right:3px;font-family:monospace}</style>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Your websites (<?=count($mains)?><?php if($subs):?> + <?=count($subs)?> subdomain<?=count($subs)===1?'':'s'?><?php endif;?>)</h3></div>
  <?php if (!$sites): ?><div class="empty">No websites yet - add your first one above.</div><?php else: ?>
  <table><thead><tr><th>Domain</th><th>Document root</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($ordered as [$s, $isChild]): $d=$s['domain']; ?>
    <tr<?=$isChild?' class="sub-row"':''?>>
      <td<?=$isChild?' style="padding-left:26px"':''?>>
        <?php if($isChild):?><span class="sub-tree" aria-hidden="true">&#9492;&#9472;</span><?php endif;?>
        <b><?=h($d)?></b>
        <?php if(!empty($s['paygw'])):?><span class="badge bg-green">payment gateway</span><?php elseif(!empty($s['sub'])):?><span class="badge bg-blue">sub</span><?php endif;?>
        <?php if(!empty($s['alias_of'])):?><span class="badge bg-blue">alias of <?=h($s['alias_of'])?></span><?php endif;?>
        <br><a class="xs" href="http://<?=h($d)?>" target="_blank" rel="noopener">visit &gt;</a>
      </td>
      <td class="mono xs muted"><?=h($s['docroot'])?></td>
      <td>
        <?php if(!empty($s['ssl'])):?><span class="badge bg-green">live + secured</span>
        <?php else:?><span class="badge bg-red">http only</span> <span id="dns-<?=h($d)?>" class="xs muted"></span><?php endif;?>
      </td>
      <td class="flex">
        <?php if(!empty($s['paygw'])):?>
          <a class="btn btn-s btn-xs" href="?page=paygateway">Manage gateway &gt;</a>
        <?php else:?>
          <a class="btn btn-g btn-xs" href="?page=files&dir=<?=urlencode($s['docroot'])?>" title="Manage this site's files">Files</a>
          <button class="btn btn-s btn-xs" onclick="checkDns('<?=h($d)?>')">Check domain</button>
          <button class="btn btn-p btn-xs" onclick="sslOpen('<?=h($d)?>')">SSL &amp; HTTPS</button>
          <button class="btn btn-d btn-xs" onclick="confirmAct('Remove website <?=h($d)?>? (files are kept)','site_del',{domain:'<?=h($d)?>'},true)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
        <?php endif;?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<style>
.ovl{position:fixed;inset:0;background:rgba(10,12,20,.55);display:flex;align-items:center;justify-content:center;z-index:200;padding:16px}
.ovl-box{background:var(--card);border:1px solid var(--border);border-radius:14px;max-width:560px;width:100%;max-height:88vh;overflow:auto;padding:20px 22px;box-shadow:0 30px 80px rgba(0,0,0,.4)}
.ssl-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 0;border-bottom:1px solid var(--border)}
.ssl-row:last-child{border-bottom:0}
.sw{position:relative;width:44px;height:24px;flex-shrink:0;display:inline-block}
.sw input{opacity:0;width:0;height:0}
.sw span{position:absolute;inset:0;background:var(--border2,#c7ccd8);border-radius:999px;cursor:pointer;transition:.2s}
.sw span:before{content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.sw input:checked+span{background:var(--accent)}
.sw input:checked+span:before{transform:translateX(20px)}
.spinner{display:inline-block;width:12px;height:12px;border:2px solid var(--border2,#ccc);border-top-color:var(--accent);border-radius:50%;animation:sspin .7s linear infinite;vertical-align:-2px}
@keyframes sspin{to{transform:rotate(360deg)}}
</style>
<div class="ovl" id="sslOvl" onclick="if(event.target===this)sslClose()" style="display:none">
  <div class="ovl-box">
    <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:8px"><h3 id="sslTitle" style="margin:0">SSL &amp; HTTPS</h3><button class="btn btn-g btn-xs" onclick="sslClose()">Close</button></div>
    <div id="sslBody"><div class="sm muted">Loading...</div></div>
  </div>
</div>
<script>
function checkDns(domain){
  var el=document.getElementById('dns-'+domain); if(el) el.textContent='checking...';
  toast('Checking '+domain+'...');
  api('dns_check',{domain:domain}).then(function(r){
    if(!r||!r.ok){ toast('Could not check '+domain,'e'); if(el)el.textContent=''; return; }
    var txt,col;
    if(r.cloudflare){ txt='on Cloudflare (proxied)'; col='#34d399'; toast(domain+' is on Cloudflare (proxied) - DNS and HTTPS are managed automatically.'); }
    else if(r.direct){ txt='points here'; col='#34d399'; toast(domain+' points to this server. Open SSL & HTTPS to secure it.'); }
    else { txt='not pointed yet ('+r.resolved+')'; col='#fbbf24'; toast(domain+' is not pointing here yet.\nSet an A record to '+r.expected+' at your registrar or Cloudflare.','e'); }
    if(el) el.innerHTML='<span style="color:'+col+'">'+txt+'</span>';
  }).catch(function(){ toast('Check failed','e'); if(el)el.textContent=''; });
}
var SSL_DOM='';
function sslOpen(d){ SSL_DOM=d; document.getElementById('sslTitle').textContent='SSL & HTTPS - '+d; document.getElementById('sslOvl').style.display='flex'; sslLoad(); }
function sslClose(){ document.getElementById('sslOvl').style.display='none'; }
function sslLoad(){
  var b=document.getElementById('sslBody'); b.innerHTML='<div class="sm muted"><span class="spinner"></span> Checking SSL status...</div>';
  api('cf_site_status',{domain:SSL_DOM}).then(function(r){
    if(!r||!r.ok){ b.innerHTML='<div class="alert alert-e">'+((r&&r.error)||'Could not read SSL status.')+'</div>'; return; }
    b.innerHTML=sslPanel(r);
  }).catch(function(){ b.innerHTML='<div class="alert alert-e">Could not read SSL status.</div>'; });
}
function sslBadge(on,txt){ return '<span class="badge '+(on?'bg-green':'bg-red')+'">'+txt+'</span>'; }
function sslPanel(r){
  var cfOn = r.cf && r.ssl_mode && r.ssl_mode!=='off';
  // Cloudflare is serving HTTPS if: mode is on, OR the record is proxied, OR HTTPS works with
  // no local origin cert (a valid cert with no Let's Encrypt on the server can only be Cloudflare's).
  var cfSecuring = r.cf && (cfOn || (r.https_live && (r.proxied || !r.le)));
  var secured = !!r.secured;
  var prov=[];
  if(cfSecuring) prov.push('Cloudflare'+(r.ssl_mode?(' ('+({flexible:'Flexible',full:'Full',strict:'Full-strict'}[r.ssl_mode]||r.ssl_mode)+')'):' (Universal SSL)'));
  if(r.le) prov.push("Let's Encrypt origin cert");
  if(!prov.length && r.https_live) prov.push('a valid certificate');
  var h='<div class="alert '+(secured?'alert-ok':'alert-i')+'" style="margin-bottom:12px"><b>HTTPS is '+(secured?'ON':'OFF')+'.</b> '
    +(secured?('Secured via '+prov.join(' + ')+'.'):'This site is served over plain HTTP right now.')+'</div>';
  // live-detected providers
  var cfBadge = !r.cf ? '<span class="badge bg-red">Cloudflare —</span>'
    : cfSecuring ? '<span class="badge bg-green">Cloudflare ✓</span>'
    : (!r.can_edit ? '<span class="badge bg-amber">Cloudflare (limited token)</span>' : '<span class="badge bg-red">Cloudflare off</span>');
  h+='<div class="ssl-row"><div><b>Detected (live check)</b><div class="xs muted">Probed on this domain just now.</div></div><div class="flex" style="gap:6px;flex-wrap:wrap">'
    + sslBadge(r.https_live, r.https_live?'HTTPS working ✓':'HTTPS not reachable')
    + sslBadge(r.le, r.le?"Let's Encrypt ✓":"Let's Encrypt —")
    + cfBadge+'</div></div>';
  // single toggle
  if(secured){
    h+='<div class="ssl-row"><div><b>Disable SSL</b><div class="xs muted">Turn HTTPS off for this site.</div></div><button class="btn btn-d btn-xs" onclick="sslToggle(false,'+(r.cf?1:0)+','+(cfOn?1:0)+','+(r.le?1:0)+')">Disable SSL</button></div>';
  } else {
    h+='<div class="ssl-row"><div><b>Enable SSL</b><div class="xs muted">'+(r.cf?'Proxy via Cloudflare (Full) and issue a Let\'s Encrypt origin certificate.':'Issue a free Let\'s Encrypt certificate on this server.')+'</div></div><button class="btn btn-p btn-xs" onclick="sslToggle(true,'+(r.cf?1:0)+',0,'+(r.le?1:0)+')">Enable SSL</button></div>';
  }
  // Cloudflare: provider switch (works with DNS-edit) + mode (needs zone-settings edit)
  if(r.cf){
    // WHICH certificate serves visitors - switchable with the CURRENT token (DNS edit only)
    var usingCf = cfSecuring || r.proxied===true;   // what is actually serving visitors right now
    h+='<div class="ssl-row"><div><b>Certificate provider - switch anytime</b><div class="xs muted"><b>Cloudflare</b> (orange cloud) serves Cloudflare\'s certificate; <b>Let\'s Encrypt</b> (DNS-only / grey cloud) serves your server\'s certificate directly. Works with your current token.</div></div>'
      +'<select onchange="sslAct(\'cf_set_proxied\',{domain:SSL_DOM,proxied:this.value},this.value===\'1\'?\'Switching to Cloudflare...\':\'Switching to Let\\\'s Encrypt (direct)...\')">'
      +'<option value="1"'+(usingCf?' selected':'')+'>Cloudflare (proxied)</option>'
      +'<option value="0"'+(!usingCf?' selected':'')+'>Let\'s Encrypt (direct / DNS-only)</option>'
      +'</select></div>';
    // Only warn about a missing origin cert when the site is genuinely served DIRECT and HTTPS is NOT working.
    if(!usingCf && !r.le && !r.https_live) h+='<div class="alert alert-w" style="margin:8px 0">This site is served directly but has no certificate yet - click <b>Issue now</b> below (Let\'s Encrypt) or switch back to <b>Cloudflare (proxied)</b>.</div>';
    // Cloudflare SSL mode + rewrites - only when the token can actually change them (no more auth errors)
    if(r.can_edit){
      var L={off:'Off (no HTTPS)',flexible:'Flexible - Cloudflare edge certificate only',full:'Full - Cloudflare + server origin cert',strict:'Full (strict) - needs a Let\'s Encrypt origin cert'};
      var sel='<select id="sslMode" onchange="sslAct(\'cf_ssl_mode\',{domain:SSL_DOM,mode:this.value},\'Updating SSL mode...\')">';
      ['off','flexible','full','strict'].forEach(function(m){ var avail=(m!=='strict')||r.le; sel+='<option value="'+m+'"'+(r.ssl_mode===m?' selected':'')+(avail?'':' disabled')+'>'+L[m]+(avail?'':' (issue cert first)')+'</option>'; });
      sel+='</select>';
      h+='<div class="ssl-row"><div><b>Cloudflare SSL mode</b><div class="xs muted">How Cloudflare connects to your origin. Full-strict is most secure (needs a Let\'s Encrypt cert).</div></div>'+sel+'</div>';
      h+='<div class="ssl-row"><div><b>Automatic HTTPS Rewrites</b><div class="xs muted">Cloudflare rewrites insecure resources to https to avoid mixed-content warnings.</div></div><label class="sw"><input type="checkbox" '+(r.auto_rewrites==='on'?'checked':'')+' onchange="sslAct(\'cf_auto_rewrites\',{domain:SSL_DOM,state:this.checked?\'on\':\'off\'},\'Updating...\')"><span></span></label></div>';
    } else {
      h+='<div class="alert alert-i" style="margin:8px 0"><b>Cloudflare SSL mode + Auto-Rewrites need a fuller token.</b> Add <b>SSL and Certificates: Edit</b> + <b>Zone Settings: Edit</b> (<a href="?page=settings">Settings &rarr; Cloudflare</a>) to switch Flexible / Full / Full-strict. Everything else here works with your current token.</div>';
    }
    h+='<div class="ssl-row"><div><b>Purge Cloudflare cache</b><div class="xs muted">Clear the cached copy so visitors get your latest files.</div></div><button class="btn btn-s btn-xs" onclick="sslAct(\'cf_purge\',{domain:SSL_DOM},\'Purging cache...\')">Purge cache</button></div>';
  } else {
    h+='<div class="alert alert-i" style="margin:8px 0">'+(r.cf_note==='not_in_cf'?'This domain isn\'t in a connected Cloudflare account. ':'')+'Connect a Cloudflare token in <a href="?page=settings">Settings</a> for edge SSL, provider switching and cache controls. Let\'s Encrypt below works on its own.</div>';
  }
  // Force HTTPS (server-side) - works in EVERY mode, even without Cloudflare edit access (loop-safe behind Cloudflare)
  h+='<div class="ssl-row"><div><b>Force HTTPS (redirect http to https)</b><div class="xs muted">Server-side redirect - works in every mode. Use this instead of Cloudflare\'s "Always Use HTTPS" when your token can\'t change zone settings.</div></div><label class="sw"><input type="checkbox" '+(r.force_https?'checked':'')+' onchange="sslAct(\'site_force_https\',{domain:SSL_DOM,state:this.checked?\'on\':\'off\'},\'Updating redirect...\')"><span></span></label></div>';
  // Let's Encrypt origin cert (works in BOTH modes)
  h+='<div class="ssl-row"><div><b>Let\'s Encrypt certificate '+(r.le?'✓':'')+'</b><div class="xs muted">'+(r.le?'Installed and auto-renewing on this server.':'Free, auto-renewing certificate on this server (also enables Cloudflare Full-strict).')+'</div></div><button class="btn btn-s btn-xs" onclick="sslIssue()">'+(r.le?'Renew / reissue':'Issue now')+'</button></div>';
  h+='<div class="ssl-row"><div><b>Re-check domain</b><div class="xs muted">Confirm DNS + HTTPS are live.</div></div><button class="btn btn-s btn-xs" onclick="sslRecheck()">Re-check</button></div>';
  h+='<div id="sslRecheckOut" class="xs muted" style="padding-top:6px"></div>';
  return h;
}
function sslToggle(on,cf,cfOn,le){
  if(on){ sslEnable(!!cf); return; }
  uiConfirm('Turn HTTPS off for '+SSL_DOM+'?',function(){
    if(cf && cfOn) sslAct('ssl_disable',{domain:SSL_DOM},'Turning SSL off...');
    else if(le) sslAct('ssl_remove',{domain:SSL_DOM},'Removing certificate...');
    else sslAct('ssl_disable',{domain:SSL_DOM},'Turning SSL off...');
  },{title:'Disable SSL',okText:'Turn off'});
}
function sslEnable(cf){
  toast('Enabling SSL for '+SSL_DOM+' - this can take up to a minute...','');
  if(cf){ api('cf_ssl_mode',{domain:SSL_DOM,mode:'full'}).then(function(){ return api('ssl_issue',{domain:SSL_DOM}); }).then(function(r){ toast(r&&r.ok?'SSL enabled (Cloudflare Full + Let\'s Encrypt).':((r&&r.error)||'Requested - the certificate may still be issuing.'), r&&r.ok?'':'e'); sslLoad(); }).catch(function(){ toast('Enable failed','e'); sslLoad(); }); }
  else { api('ssl_issue',{domain:SSL_DOM}).then(function(r){ toast(r&&r.ok?(r.msg||'HTTPS enabled.'):((r&&r.error)||'Could not issue certificate'), r&&r.ok?'':'e'); sslLoad(); }).catch(function(){ toast('Enable failed','e'); sslLoad(); }); }
}
function sslIssue(){ toast('Issuing Let\'s Encrypt certificate - this can take up to a minute...',''); api('ssl_issue',{domain:SSL_DOM}).then(function(r){ toast(r&&r.ok?(r.msg||'Certificate issued.'):((r&&r.error)||'Could not issue certificate'), r&&r.ok?'':'e'); sslLoad(); }).catch(function(){ toast('Issue failed','e'); sslLoad(); }); }
function sslAct(action,params,msg){ if(msg) toast(msg,''); api(action,params).then(function(r){ toast(r.ok?(r.msg||'Done'):(r.error||'Failed'), r.ok?'':'e'); sslLoad(); }).catch(function(){ toast('Request failed','e'); sslLoad(); }); }
function sslRecheck(){ var o=document.getElementById('sslRecheckOut'); if(o)o.textContent='Checking DNS...'; api('dns_check',{domain:SSL_DOM}).then(function(r){ if(!o)return; o.innerHTML = r.cloudflare ? '<span style="color:var(--green)">On Cloudflare (proxied) - HTTPS managed automatically.</span>' : r.direct ? '<span style="color:var(--green)">DNS points here ('+r.resolved+'). HTTPS ready.</span>' : '<span style="color:var(--amber)">Not pointed yet - resolves to '+r.resolved+', expected '+r.expected+'.</span>'; }); }
</script>
<?php }

function pageSubdomains(): void {
    $sites = array_values(array_filter(loadJson(SITES_FILE, []), fn($s)=>empty($s['sub'])));
    ?>
<div class="card">
  <h3>Create a subdomain</h3>
  <div class="row">
    <div style="max-width:160px"><label>Subdomain</label><input id="sSub" placeholder="blog"></div>
    <div><label>of domain</label>
      <select id="sParent">
        <?php foreach($sites as $s):?><option><?=h($s['domain'])?></option><?php endforeach;?>
        <?php if(!$sites):?><option value="">- add a website first -</option><?php endif;?>
      </select>
    </div>
    <button class="btn btn-p" onclick="act('sub_add',{sub:sSub.value,parent:sParent.value},true)">Create</button>
  </div>
  <div class="xs muted mt">Also add a DNS A-record for the subdomain pointing to <span class="mono"><?=h(cfgGet('server_ip'))?></span>.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Subdomains</h3></div>
  <?php $subs=array_values(array_filter(loadJson(SITES_FILE,[]),fn($s)=>!empty($s['sub']))); if(!$subs):?><div class="empty">No subdomains yet.</div><?php else:?>
  <table><thead><tr><th>Subdomain</th><th>Document root</th><th></th></tr></thead><tbody>
  <?php foreach($subs as $s):?><tr><td><b><?=h($s['domain'])?></b></td><td class="mono xs muted"><?=h($s['docroot'])?></td><td><button class="btn btn-d btn-xs" onclick="confirmAct('Remove <?=h($s['domain'])?>?','site_del',{domain:'<?=h($s['domain'])?>'},true)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button></td></tr><?php endforeach;?>
  </tbody></table><?php endif;?>
</div>
<?php }

function pageDatabases(): void {
    $pdo = db(); $dbs=[]; $users=[];
    if ($pdo){
        try {
            $sys=['mysql','information_schema','performance_schema','sys'];
            foreach($pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN) as $d) if(!in_array($d,$sys)) $dbs[]=$d;
            foreach($pdo->query("SELECT User,Host FROM mysql.user WHERE User NOT IN ('root','panel_admin','mysql','mariadb.sys','')")->fetchAll() as $u) $users[]=$u;
        } catch(Exception $e){}
    }
    ?>
<?php if(!$pdo):?><div class="alert alert-e"><b>Cannot connect to MariaDB.</b><br>
Tried user <span class="mono"><?=h(cfgGet('db_user') ?: '(none - config missing)')?></span> via <span class="mono"><?=h(cfgGet('db_host') ?: 'localhost')?></span>.<br>
<span class="xs">Reason: <?=h(dbError() ?: 'no database credentials in config')?></span><br>
<span class="xs muted">On a VPS, run <span class="mono">sudo systemctl status mariadb</span> (start it with <span class="mono">sudo systemctl start mariadb</span>). If you're running this panel locally under XAMPP, start MySQL in the XAMPP Control Panel.</span></div><?php endif;?>
<?=helpBox('What is this?', 'A <b>database</b> stores your website\'s data (WordPress posts, users, orders...). Make one database per app. Then make a <b>user</b> with a password and grant it that database. Your app connects with: host <span class="mono">localhost</span>, the database name, and that user + password. To browse or edit the data inside, use <a href="?page=sql">SQL Browser</a>.')?>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card">
    <h3>Databases (<?=count($dbs)?>)</h3>
    <div class="row mb"><div><input id="dbName" placeholder="new_database"></div><button class="btn btn-p" onclick="act('db_create',{name:dbName.value},true)">Create</button></div>
    <table><tbody>
    <?php foreach($dbs as $d):?><tr><td><b><?=h($d)?></b></td><td style="text-align:right"><button class="btn btn-d btn-xs" onclick="confirmAct('Drop database <?=h($d)?> and ALL its data?','db_drop',{name:'<?=h($d)?>'},true)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button></td></tr><?php endforeach;?>
    <?php if(!$dbs):?><tr><td class="muted sm">No databases yet.</td></tr><?php endif;?>
    </tbody></table>
  </div>
  <div class="card">
    <h3>Database users</h3>
    <div class="mb"><label>Username</label><input id="duUser" placeholder="appuser"></div>
    <div class="mb"><label>Password</label><input id="duPass" type="password"></div>
    <div class="mb"><label>Grant access to database (optional)</label>
      <select id="duDb"><option value="">- none -</option><?php foreach($dbs as $d):?><option><?=h($d)?></option><?php endforeach;?></select></div>
    <button class="btn btn-p" onclick="act('dbuser_create',{user:duUser.value,pass:duPass.value,grant_db:duDb.value},true)">Create user</button>
    <table class="mt"><tbody>
    <?php foreach($users as $u):?><tr><td><b><?=h($u['User'])?></b><span class="muted xs">@<?=h($u['Host'])?></span></td><td style="text-align:right"><button class="btn btn-d btn-xs" onclick="confirmAct('Drop user <?=h($u['User'])?>?','dbuser_drop',{user:'<?=h($u['User'])?>'},true)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button></td></tr><?php endforeach;?>
    </tbody></table>
  </div>
</div>
<?php }

function pageSql(): void {
    $pdo = db(); $dbs = []; $err = '';
    if ($pdo) { try { $sys=['mysql','information_schema','performance_schema','sys']; foreach($pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN) as $d) $dbs[]=['name'=>$d,'sys'=>in_array($d,$sys)]; } catch(Exception $e){ $err=$e->getMessage(); } }
    else $err = dbError() ?: 'no database credentials in config';
    ?>
<style>
.sqlwrap{display:flex;gap:14px;align-items:flex-start}
.sqltree{width:248px;flex-shrink:0;max-height:calc(100vh - 150px);overflow:auto;padding:0}
.sqltree .th{padding:11px 14px;border-bottom:1px solid var(--border);font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:1px;position:sticky;top:0;background:var(--card);z-index:1}
.sqltree .di{display:flex;align-items:center;gap:8px;padding:7px 14px;font-size:12.5px;color:var(--text2);cursor:pointer;border-left:2px solid transparent}
.sqltree .di:hover{color:var(--text);background:var(--surface2)}
.sqltree .di.active{color:var(--text);background:var(--accent-soft);border-left-color:var(--accent)}
.sqltree .di svg{opacity:.7;flex-shrink:0}
.sqltree .ti{padding-left:30px;font-size:12px}
.sqltree .ti .c{margin-left:auto;font-size:10px;color:var(--text2)}
.sqlmain{flex:1;min-width:0}
.tabs{display:flex;gap:4px;flex-wrap:wrap}
.tabs .tb{padding:7px 13px;font-size:12px;font-weight:600;color:var(--text2);cursor:pointer;border-radius:8px;border:1px solid transparent}
.tabs .tb:hover{color:var(--text);background:var(--surface2)}
.tabs .tb.active{color:#fff;background:var(--accent)}
.dtable{display:block;overflow:auto;max-width:100%}
.dtable table{min-width:100%}
.dtable td,.dtable th{white-space:nowrap;max-width:340px;overflow:hidden;text-overflow:ellipsis}
.cell{cursor:pointer}
.cell:hover{background:var(--accent-soft);outline:1px solid rgba(129,140,248,.5)}
.cell.nl{color:var(--text2);font-style:italic}
.cell input{width:100%;min-width:120px;padding:3px 6px;font-size:12px}
.pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.qbox{width:100%;min-height:130px;font-family:'JetBrains Mono',monospace}
</style>
<?php if(!$pdo): ?>
<div class="alert alert-e"><b>Cannot connect to the database.</b><br><span class="xs">Reason: <?=h($err)?></span><br>
<span class="xs muted">On a VPS: <span class="mono">sudo systemctl start mariadb</span>. Locally under XAMPP: start MySQL in the XAMPP Control Panel.</span></div>
<?php return; endif; ?>
<?=helpBox('How this works', 'The SQL Browser is <b>already connected</b> - it signs in automatically with the server\'s database admin account (<span class="mono">'.h(cfgGet('db_user','')).'@'.h(cfgGet('db_host','localhost')).'</span>) that the installer created, so there is no separate database login or logout. Pick a database on the left, then a table, then use the tabs to <b>Browse</b> rows, edit cells (double-click), <b>Insert</b>, run <b>SQL</b>, or <b>Import/Export</b> a <span class="mono">.sql</span> file.')?>
<div class="sqlwrap">
  <div class="card sqltree">
    <div class="th">Databases (<?=count($dbs)?>)</div>
    <div id="sqlTree"></div>
  </div>
  <div class="sqlmain">
    <div class="card" id="sqlBar" style="padding:11px 14px"><div class="sm muted">Select a database on the left to begin.</div></div>
    <div id="sqlContent"></div>
  </div>
</div>
<script>
var SQLDBS=<?=json_encode($dbs)?>, curDb='', curTable='', curTab='browse', curPage=1, curSort='', curDir='ASC';
function sesc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function sapi(action,data){ var b=new URLSearchParams(); b.append('action',action); b.append('csrf',CSRF); for(var k in data){ if(Array.isArray(data[k])) data[k].forEach(function(v){b.append(k+'[]',v);}); else b.append(k,data[k]); } return fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(function(r){return r.json();}); }
function svgDb(){ return '<?=addslashes(icon('databases',15))?>'; }
function svgTb(){ return '<?=addslashes(icon('sql',14))?>'; }

function buildTree(){
  var h=''; SQLDBS.forEach(function(d){
    h+='<div class="di'+(d.name===curDb?' active':'')+'" onclick="selDb(\''+sesc(d.name)+'\')">'+svgDb()+'<span>'+sesc(d.name)+'</span></div>';
    if(d.name===curDb && d.tables){ d.tables.forEach(function(t){ h+='<div class="di ti'+(t.name===curTable?' active':'')+'" onclick="event.stopPropagation();selTable(\''+sesc(t.name)+'\')">'+svgTb()+'<span>'+sesc(t.name)+'</span><span class="c">'+t.rows+'</span></div>'; }); }
  });
  document.getElementById('sqlTree').innerHTML=h;
}
function selDb(db){ curDb=db; curTable=''; sapi('sql_tables',{db:db}).then(function(r){ if(!r.ok){toast(r.error,'e');return;} SQLDBS.forEach(function(d){ if(d.name===db) d.tables=r.tables; }); buildTree(); dbOverview(r.tables); }); }
function selTable(t){ curTable=t; curTab='browse'; curPage=1; curSort=''; buildTree(); renderBar(); browse(); }

function renderBar(){
  if(!curTable){ document.getElementById('sqlBar').innerHTML='<div class="flex wrap"><b class="sm">'+sesc(curDb)+'</b><span style="flex:1"></span><button class="btn btn-s btn-xs" onclick="curTab=\'newtable\';renderBar();newTableForm()">New table</button><button class="btn btn-s btn-xs" onclick="curTab=\'sql\';renderBar();sqlTab()">SQL</button><button class="btn btn-s btn-xs" onclick="curTab=\'import\';renderBar();importTab()">Import</button><button class="btn btn-g btn-xs" onclick="doExport(\'\',true)">Export DB</button></div>'; return; }
  var tabs=['browse','structure','insert','sql','search','export','import'];
  var lbl={browse:'Browse',structure:'Structure',insert:'Insert',sql:'SQL',search:'Search',export:'Export',import:'Import'};
  var h='<div class="flex wrap" style="gap:10px;align-items:center"><b class="sm mono">'+sesc(curDb)+' . '+sesc(curTable)+'</b><span style="flex:1"></span><div class="tabs">';
  tabs.forEach(function(t){ h+='<span class="tb'+(t===curTab?' active':'')+'" onclick="openTab(\''+t+'\')">'+lbl[t]+'</span>'; });
  h+='</div></div>';
  document.getElementById('sqlBar').innerHTML=h;
}
function openTab(t){ curTab=t; renderBar(); if(t==='browse')browse(); else if(t==='structure')structure(); else if(t==='insert')insertForm(); else if(t==='sql')sqlTab(); else if(t==='search')searchTab(); else if(t==='export')exportTab(); else if(t==='import')importTab(); }

function dbOverview(tables){
  renderBar();
  var h='<div class="card" style="padding:0"><table><thead><tr><th>Table</th><th>Rows</th><th>Engine</th><th>Size</th><th style="width:200px">Actions</th></tr></thead><tbody>';
  tables.forEach(function(t){
    h+='<tr><td><span class="cell" style="color:var(--accent2)" onclick="selTable(\''+sesc(t.name)+'\')"><b>'+sesc(t.name)+'</b></span></td><td class="muted">'+t.rows+'</td><td class="xs muted">'+sesc(t.engine)+'</td><td class="xs muted">'+sesc(t.size)+'</td><td class="flex" style="gap:5px"><button class="btn btn-g btn-xs" onclick="selTable(\''+sesc(t.name)+'\')">Browse</button><button class="btn btn-g btn-xs" onclick="doExport(\''+sesc(t.name)+'\')">Export</button><button class="btn btn-s btn-xs" onclick="tableOp(\''+sesc(t.name)+'\',\'empty\')">Empty</button><button class="btn btn-d btn-xs" onclick="tableOp(\''+sesc(t.name)+'\',\'drop\')">Drop</button></td></tr>';
  });
  if(!tables.length) h+='<tr><td colspan="5" class="empty">No tables yet - create one or import a .sql file.</td></tr>';
  h+='</tbody></table></div>';
  document.getElementById('sqlContent').innerHTML=h;
}
function tableOp(t,op){ var msg=op==='drop'?'Drop table '+t+' and all its data?':op==='empty'?'Delete ALL rows from '+t+'?':'Optimize '+t+'?'; uiConfirm(msg,function(){ sapi('sql_table_op',{db:curDb,table:t,op:op}).then(function(r){ if(r.ok){toast(r.msg);selDb(curDb);}else toast(r.error,'e'); }); },{danger:op!=='optimize'}); }

function browse(){
  document.getElementById('sqlContent').innerHTML='<div class="empty">Loading...</div>';
  sapi('sql_browse',{db:curDb,table:curTable,page:curPage,sort:curSort,sort_dir:curDir}).then(function(r){
    if(!r.ok){ document.getElementById('sqlContent').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>'; return; }
    var pk=r.pk; var h='<div class="card" style="padding:10px 12px"><div class="flex wrap" style="gap:8px;align-items:center"><span class="sm muted">'+r.total+' rows'+(pk?'':' . <span style="color:var(--yellow)">no primary key - read-only</span>')+'</span><span style="flex:1"></span>';
    if(pk) h+='<button class="btn btn-d btn-xs" onclick="delSel()">Delete selected</button>';
    h+='<button class="btn btn-s btn-xs" onclick="openTab(\'insert\')">Insert row</button>';
    h+=pager(r.page,r.pages)+'</div></div>';
    h+='<div class="card dtable" style="padding:0"><table><thead><tr>';
    if(pk) h+='<th style="width:26px"><input type="checkbox" onchange="document.querySelectorAll(\'.rcb\').forEach(c=>c.checked=this.checked)"></th>';
    r.columns.forEach(function(c){ var ar=(c===r.sort)?(r.sort_dir==='ASC'?' ^':' v'):''; h+='<th class="cell" onclick="sortBy(\''+sesc(c)+'\')">'+sesc(c)+ar+'</th>'; });
    h+='<th style="width:60px"></th></tr></thead><tbody>';
    r.rows.forEach(function(row,ri){
      var pv=pk?row[pk]:'';
      h+='<tr data-pk="'+sesc(pv)+'">';
      if(pk) h+='<td><input type="checkbox" class="rcb" value="'+sesc(pv)+'"></td>';
      r.columns.forEach(function(c){ var v=row[c]; var isn=(v===null); h+='<td class="cell'+(isn?' nl':'')+'" data-col="'+sesc(c)+'" '+(pk?'ondblclick="editCell(this)"':'')+'>'+(isn?'NULL':sesc(v))+'</td>'; });
      h+='<td>'+(pk?'<button class="btn btn-d btn-xs" onclick="delRow(\''+sesc(pv)+'\')">x</button>':'')+'</td></tr>';
    });
    if(!r.rows.length) h+='<tr><td colspan="'+(r.columns.length+2)+'" class="empty">No rows.</td></tr>';
    h+='</tbody></table></div>';
    if(pk) h+='<div class="xs muted mt">Double-click a cell to edit . Enter to save . Esc to cancel.</div>';
    document.getElementById('sqlContent').innerHTML=h;
    window._pk=pk;
  });
}
function pager(pg,pages){ if(pages<=1) return '<span class="sm muted">page 1/1</span>'; var h='<div class="pager">'; h+='<button class="btn btn-g btn-xs" '+(pg<=1?'disabled':'')+' onclick="curPage='+(pg-1)+';browse()"><</button><span class="sm muted">'+pg+' / '+pages+'</span><button class="btn btn-g btn-xs" '+(pg>=pages?'disabled':'')+' onclick="curPage='+(pg+1)+';browse()">></button></div>'; return h; }
function sortBy(c){ if(curSort===c) curDir=(curDir==='ASC'?'DESC':'ASC'); else {curSort=c;curDir='ASC';} curPage=1; browse(); }
function editCell(td){ if(td.querySelector('input'))return; var old=td.classList.contains('nl')?'':td.textContent; var col=td.dataset.col; var pv=td.closest('tr').dataset.pk; td.innerHTML='<input value="'+sesc(old)+'">'; var inp=td.querySelector('input'); inp.focus(); inp.select();
  function save(){ var nv=inp.value; var o={}; o[col]=nv; sapi('sql_edit_row',{db:curDb,table:curTable,pk_col:window._pk,pk_val:pv,data:JSON.stringify(o)}).then(function(r){ if(r.ok){toast('Saved');td.classList.remove('nl');td.textContent=nv;}else{toast(r.error,'e');td.textContent=old;} }); }
  inp.onblur=save; inp.onkeydown=function(e){ if(e.key==='Enter'){e.preventDefault();inp.blur();} else if(e.key==='Escape'){inp.onblur=null;td.textContent=old;} };
}
function delRow(pv){ uiConfirm('Delete this row?',function(){ sapi('sql_delete_rows',{db:curDb,table:curTable,pk_col:window._pk,pks:[pv]}).then(function(r){ if(r.ok){toast(r.msg);browse();}else toast(r.error,'e'); }); }); }
function delSel(){ var ids=[...document.querySelectorAll('.rcb:checked')].map(c=>c.value); if(!ids.length){toast('Select rows first','e');return;} uiConfirm('Delete '+ids.length+' row(s)?',function(){ sapi('sql_delete_rows',{db:curDb,table:curTable,pk_col:window._pk,pks:ids}).then(function(r){ if(r.ok){toast(r.msg);browse();}else toast(r.error,'e'); }); }); }

function structure(){
  sapi('sql_structure',{db:curDb,table:curTable}).then(function(r){
    if(!r.ok){ document.getElementById('sqlContent').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>'; return; }
    var h='<div class="card" style="padding:0"><div style="padding:13px 16px 0"><h3>Columns</h3></div><div class="dtable"><table><thead><tr><th>Name</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
    r.columns.forEach(function(c){ h+='<tr><td><b>'+sesc(c.Field)+'</b></td><td class="mono xs">'+sesc(c.Type)+'</td><td class="xs">'+sesc(c.Null)+'</td><td class="xs">'+sesc(c.Key)+'</td><td class="xs muted">'+sesc(c.Default)+'</td><td class="xs muted">'+sesc(c.Extra)+'</td></tr>'; });
    h+='</tbody></table></div></div>';
    h+='<div class="card" style="padding:0"><div style="padding:13px 16px 0"><h3>Indexes</h3></div><div class="dtable"><table><thead><tr><th>Key</th><th>Column</th><th>Unique</th><th>Type</th></tr></thead><tbody>';
    (r.indexes||[]).forEach(function(i){ h+='<tr><td>'+sesc(i.Key_name)+'</td><td>'+sesc(i.Column_name)+'</td><td>'+(i.Non_unique=='0'?'yes':'no')+'</td><td class="xs">'+sesc(i.Index_type)+'</td></tr>'; });
    h+='</tbody></table></div></div>';
    h+='<div class="card"><div class="flex"><h3 style="margin:0">CREATE statement</h3><button class="btn btn-g btn-xs" style="margin-left:auto" onclick="copyText(this.nextElementSibling.textContent)">Copy</button><span style="display:none">'+sesc(r.create_sql)+'</span></div><pre class="code mt">'+sesc(r.create_sql)+'</pre></div>';
    document.getElementById('sqlContent').innerHTML=h;
  });
}

function insertForm(){
  sapi('sql_columns',{db:curDb,table:curTable}).then(function(r){
    if(!r.ok){ document.getElementById('sqlContent').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>'; return; }
    var h='<div class="card"><h3>Insert a row into '+sesc(curTable)+'</h3>';
    r.columns.forEach(function(c){ var extra=(c.Extra||'').indexOf('auto_increment')>=0; h+='<div class="mb"><label>'+sesc(c.Field)+' <span class="muted">'+sesc(c.Type)+(extra?' . auto':'')+'</span></label><input data-col="'+sesc(c.Field)+'" placeholder="'+(extra?'(auto)':(c.Default==null?'':sesc(c.Default)))+'"></div>'; });
    h+='<button class="btn btn-p" onclick="doInsert(this)">Insert row</button></div>';
    document.getElementById('sqlContent').innerHTML=h;
  });
}
function doInsert(btn){ var d={}; btn.closest('.card').querySelectorAll('input[data-col]').forEach(function(i){ if(i.value!=='') d[i.dataset.col]=i.value; }); sapi('sql_insert_row',{db:curDb,table:curTable,data:JSON.stringify(d)}).then(function(r){ if(r.ok){toast(r.msg);openTab('browse');}else toast(r.error,'e'); }); }

function sqlTab(){
  var h='<div class="card"><h3>Run SQL'+(curDb?(' on '+sesc(curDb)):'')+'</h3><textarea id="qbox" class="qbox" placeholder="SELECT * FROM ...">'+(curTable?('SELECT * FROM `'+curTable+'` LIMIT 50'):'')+'</textarea><div class="mt"><button class="btn btn-p" onclick="runQuery()">Run</button></div></div><div id="qres"></div>';
  document.getElementById('sqlContent').innerHTML=h;
}
function runQuery(){ var q=document.getElementById('qbox').value; if(!q.trim())return; sapi('sql_query',{db:curDb,query:q}).then(function(r){
  if(!r.ok){ document.getElementById('qres').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>'; return; }
  if(r.type==='exec'){ document.getElementById('qres').innerHTML='<div class="alert alert-ok">'+sesc(r.msg)+'</div>'; if(curTable)selDb(curDb); return; }
  var h='<div class="card" style="padding:8px 12px"><span class="sm muted">'+r.count+' row(s)</span></div><div class="card dtable" style="padding:0"><table><thead><tr>';
  r.columns.forEach(function(c){ h+='<th>'+sesc(c)+'</th>'; }); h+='</tr></thead><tbody>';
  r.rows.forEach(function(row){ h+='<tr>'; r.columns.forEach(function(c){ h+='<td>'+(row[c]===null?'<span class="muted">NULL</span>':sesc(row[c]))+'</td>'; }); h+='</tr>'; });
  if(!r.rows.length) h+='<tr><td class="empty">empty result</td></tr>';
  h+='</tbody></table></div>'; document.getElementById('qres').innerHTML=h;
}); }

function searchTab(){
  sapi('sql_columns',{db:curDb,table:curTable}).then(function(r){
    if(!r.ok){document.getElementById('sqlContent').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>';return;}
    var opts=r.columns.map(function(c){return '<option>'+sesc(c.Field)+'</option>';}).join('');
    var h='<div class="card"><h3>Search '+sesc(curTable)+'</h3><div class="row"><div style="max-width:200px"><label>Column</label><select id="schCol"><option value="">any column</option>'+opts+'</select></div><div><label>Contains</label><input id="schVal" placeholder="text to find"></div><button class="btn btn-p" onclick="doSearch()">Search</button></div></div><div id="schres"></div>';
    document.getElementById('sqlContent').innerHTML=h; window._schCols=r.columns.map(c=>c.Field);
  });
}
function doSearch(){ var col=document.getElementById('schCol').value, val=document.getElementById('schVal').value.replace(/'/g,"''"); var where; if(col) where='`'+col+"` LIKE '%"+val+"%'"; else where=window._schCols.map(function(c){return '`'+c+"` LIKE '%"+val+"%'";}).join(' OR '); var q='SELECT * FROM `'+curTable+'` WHERE '+where+' LIMIT 200'; sapi('sql_query',{db:curDb,query:q}).then(function(r){ if(!r.ok){document.getElementById('schres').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>';return;} var h='<div class="card" style="padding:8px 12px"><span class="sm muted">'+r.count+' match(es)</span></div><div class="card dtable" style="padding:0"><table><thead><tr>'; r.columns.forEach(function(c){h+='<th>'+sesc(c)+'</th>';}); h+='</tr></thead><tbody>'; r.rows.forEach(function(row){h+='<tr>';r.columns.forEach(function(c){h+='<td>'+(row[c]===null?'<span class="muted">NULL</span>':sesc(row[c]))+'</td>';});h+='</tr>';}); if(!r.rows.length)h+='<tr><td class="empty">no matches</td></tr>'; h+='</tbody></table></div>'; document.getElementById('schres').innerHTML=h; }); }

function exportTab(){
  document.getElementById('sqlContent').innerHTML='<div class="card"><h3>Export</h3><div class="flex wrap"><button class="btn btn-p" onclick="doExport(\''+sesc(curTable)+'\')">Download '+sesc(curTable)+'.sql</button><button class="btn btn-s" onclick="doExport(\'\',true)">Download whole database</button></div><div class="xs muted mt">Generates a .sql dump (schema + data) you can re-import anywhere.</div></div>';
}
function doExport(table,all){ toast('Preparing export...'); sapi('sql_export',{db:curDb,table:table||'',all:all?1:''}).then(function(r){ if(!r.ok){toast(r.error,'e');return;} var b=new Blob([r.dump],{type:'application/sql'}); var a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download=r.filename||'export.sql'; a.click(); toast('Downloaded '+(r.filename||'export.sql')); }); }

function importTab(){
  document.getElementById('sqlContent').innerHTML='<div class="card"><h3>Import into '+sesc(curDb)+'</h3>'
    +'<label>Upload a .sql file (any size - uploaded in chunks)</label><input type="file" id="impFile" accept=".sql" class="mb">'
    +'<div class="mb"><button class="btn btn-p" onclick="doImportFile()">Import file</button></div>'
    +'<label>...or paste SQL</label><textarea id="impSql" class="qbox" placeholder="CREATE TABLE ...; INSERT INTO ...;"></textarea>'
    +'<div class="mt"><button class="btn btn-s" onclick="doImportPaste()">Run pasted SQL</button></div>'
    +'<div id="impOut" class="mt"></div></div>';
}
function impResult(r){ var h='<div class="alert '+(r.errors>0?'alert-e':'alert-ok')+'">'+sesc(r.msg)+'</div>'; if(r.error_msgs&&r.error_msgs.length) h+='<pre class="code">'+r.error_msgs.map(sesc).join('\n')+'</pre>'; document.getElementById('impOut').innerHTML=h; if(curDb)selDb(curDb); }
function doImportPaste(){ var sql=document.getElementById('impSql').value; if(!sql.trim()){toast('Paste some SQL first','e');return;} document.getElementById('impOut').innerHTML='<div class="muted sm">Importing...</div>'; sapi('sql_import',{db:curDb,sql:sql}).then(function(r){ if(!r.ok){toast(r.error,'e');document.getElementById('impOut').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>';return;} impResult(r); }); }
function doImportFile(){ var f=document.getElementById('impFile').files[0]; if(!f){toast('Choose a .sql file','e');return;} var id='imp'+Date.now(); var CH=1.8*1024*1024, chunks=Math.ceil(f.size/CH)||1, idx=0; document.getElementById('impOut').innerHTML='<div class="muted sm">Uploading...</div>';
  (function send(){ if(idx>=chunks){ document.getElementById('impOut').innerHTML='<div class="muted sm">Running import...</div>'; sapi('sql_import_run',{db:curDb,import_id:id}).then(function(r){ if(!r.ok){document.getElementById('impOut').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>';return;} impResult(r); }); return; }
    var blob=f.slice(idx*CH,Math.min((idx+1)*CH,f.size)); var fd=new FormData(); fd.append('action','sql_import_chunk'); fd.append('csrf',CSRF); fd.append('import_id',id); fd.append('chunk',idx); fd.append('blob',blob);
    fetch('',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){ if(!r.ok){document.getElementById('impOut').innerHTML='<div class="alert alert-e">'+sesc(r.error)+'</div>';return;} idx++; document.getElementById('impOut').innerHTML='<div class="muted sm">Uploading... '+Math.round(idx/chunks*100)+'%</div>'; send(); }).catch(function(e){ document.getElementById('impOut').innerHTML='<div class="alert alert-e">'+sesc(e.message)+'</div>'; }); })();
}
function newTableForm(){
  var h='<div class="card"><h3>Create table in '+sesc(curDb)+'</h3><div class="mb"><label>Table name</label><input id="ntName" placeholder="my_table"></div><div id="ntCols"></div><button class="btn btn-g btn-xs" onclick="ntAddCol()">+ Add column</button><div class="mt"><button class="btn btn-p" onclick="ntCreate()">Create table</button></div></div>';
  document.getElementById('sqlContent').innerHTML=h; ntAddCol(true); ntAddCol();
}
function ntAddCol(pk){ var d=document.createElement('div'); d.className='row mb nt-col'; d.style.alignItems='center'; d.innerHTML='<div><input class="nt-n" placeholder="column name" '+(pk?'value="id"':'')+'></div><div style="max-width:150px"><input class="nt-t" placeholder="INT / VARCHAR(255)" value="'+(pk?'INT':'VARCHAR(255)')+'"></div><div style="max-width:70px"><label class="xs">NN</label><input type="checkbox" class="nt-nn" '+(pk?'checked':'')+'></div><div style="max-width:70px"><label class="xs">AI</label><input type="checkbox" class="nt-ai" '+(pk?'checked':'')+'></div><div style="max-width:70px"><label class="xs">PK</label><input type="checkbox" class="nt-pk" '+(pk?'checked':'')+'></div>'; document.getElementById('ntCols').appendChild(d); }
function ntCreate(){ var cols=[]; document.querySelectorAll('.nt-col').forEach(function(r){ var n=r.querySelector('.nt-n').value.trim(); if(!n)return; cols.push({name:n,type:r.querySelector('.nt-t').value.trim()||'VARCHAR(255)',nn:r.querySelector('.nt-nn').checked?1:0,ai:r.querySelector('.nt-ai').checked?1:0,pk:r.querySelector('.nt-pk').checked?1:0}); }); sapi('sql_create_table',{db:curDb,name:document.getElementById('ntName').value,cols:JSON.stringify(cols)}).then(function(r){ if(r.ok){toast(r.msg);selDb(curDb);}else toast(r.error,'e'); }); }

document.addEventListener('keydown',function(e){ if((e.ctrlKey||e.metaKey)&&e.key==='Enter'&&document.getElementById('qbox')&&document.activeElement===document.getElementById('qbox')){ e.preventDefault(); runQuery(); } });
// Lazy loading: render the database LIST only. Nothing is queried until the user
// clicks a database (loads its tables) and then a table (loads its rows). We never
// auto-select or auto-expand a database - especially not information_schema.
buildTree();
</script>
<?php }

function pageEmail(): void {
    if (!cfgGet('mail_enabled')) { ?>
      <div class="card">
        <h3>Mail server not installed</h3>
        <div class="sm muted mb">To create <b>@your-domain</b> email accounts (with webmail and IMAP/SMTP), this server needs a mail server (Postfix + Dovecot). Install it in one click - it takes about a minute, then you can create accounts right here.</div>
        <button class="btn btn-p" id="mailInstallBtn" onclick="mailInstall()">Install mail server</button>
        <div class="xs muted mt">Installs Postfix, Dovecot and OpenDKIM and opens the mail ports.</div>
      </div>
      <script>
      function mailInstall(){ var b=document.getElementById('mailInstallBtn'); b.disabled=true; b.innerHTML='Installing... (about a minute)'; api('mail_install',{}).then(function(r){ toast(r.ok?r.msg:(r.error||'Install failed'), r.ok?'':'e'); if(r.ok){ setTimeout(function(){location.reload();},1500); } else { b.disabled=false; b.textContent='Install mail server'; } }); }
      </script>
    <?php return; }
    $m = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
    ?>
<?=helpBox('Create email in one step', 'Just type the email address you want (like <span class="mono">you@yourdomain.com</span>) and a password, then click <b>Create account</b>. Orizen Panel sets up its domain automatically the first time - you don\'t add anything else. To read and reply to messages, open <a href="?page=webmail">Webmail</a>.')?>
<div class="card">
  <h3>Add an email account</h3>
  <div class="row">
    <div><label>Email address</label><input id="bEmail" placeholder="you@yourdomain.com" onkeydown="if(event.key==='Enter')bAdd()"></div>
    <div><label>Password</label><input id="bPass" type="password" placeholder="at least 6 characters" onkeydown="if(event.key==='Enter')bAdd()"></div>
    <button class="btn btn-p" onclick="bAdd()">Create account</button>
  </div>
  <div class="xs muted mt">Works in any mail app too - IMAP 993 / SMTP 587, server <span class="mono"><?=h(cfgGet('primary_domain',cfgGet('server_ip')))?></span>, username = the full email address.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Email accounts (<?=count($m['mailboxes'])?>)</h3></div>
  <?php if(!$m['mailboxes']):?><div class="empty">No email accounts yet - create one above.</div><?php else:?>
  <table><thead><tr><th>Email address</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($m['mailboxes'] as $b):?>
    <tr><td><b><?=h($b)?></b></td>
      <td class="flex" style="gap:5px">
        <a class="btn btn-p btn-xs" href="?page=webmail&box=<?=urlencode($b)?>">Open Webmail</a>
        <button class="btn btn-s btn-xs" onclick="uiPrompt('New password','',function(p){act('mailbox_passwd',{email:'<?=h($b)?>',pass:p})},{body:'Change the password for <?=h($b)?>',okText:'Set password'})">Password</button>
        <button class="btn btn-d btn-xs" onclick="confirmAct('Delete the email account <?=h($b)?>? Its messages will be removed.','mailbox_del',{email:'<?=h($b)?>'},true)"><?=trashSvg()?></button>
      </td></tr>
  <?php endforeach;?>
  </tbody></table><?php endif;?>
</div>
<?php if($m['domains']):?>
<div class="card">
  <h3>Mail domains &amp; DNS</h3>
  <div class="sm muted mb">For your email to be trusted and delivered, add each domain's mail DNS records (MX, SPF, DKIM, DMARC) - the <a href="?page=dns">DNS</a> page shows the exact values to copy.</div>
  <div class="flex wrap" style="gap:8px">
    <?php foreach($m['domains'] as $d):?><span class="badge bg-blue" style="font-size:11px"><?=h($d)?></span><?php endforeach;?>
  </div>
</div>
<?php endif;?>
<script>
function bAdd(){ var e=document.getElementById('bEmail').value.trim(), p=document.getElementById('bPass').value; if(!e||!p){toast('Enter an email address and a password','e');return;} act('mailbox_add',{email:e,pass:p},true); }
</script>
<?php }

function pageWebmail(): void {
    $prefill = strtolower(trim($_GET['box'] ?? ''));
    $signedIn = !empty($_SESSION['wm']);
    ?>
<style>
.wm-layout{display:flex;gap:14px;align-items:flex-start}
.wm-list{width:380px;flex-shrink:0;max-height:calc(100vh - 210px);overflow:auto;padding:0}
.wm-item{padding:11px 15px;border-bottom:1px solid var(--border);cursor:pointer}
.wm-item:hover{background:var(--surface2)}.wm-item.active{background:var(--accent-soft)}
.wm-item .r1{display:flex;justify-content:space-between;gap:8px}
.wm-item .who{font-weight:600;font-size:13px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wm-item.unread .who{font-weight:800}
.wm-item .when{font-size:11px;color:var(--text2);flex-shrink:0}
.wm-item .subj{font-size:12.5px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px}
.wm-view{flex:1;min-width:0;max-height:calc(100vh - 210px);overflow:auto}
.wm-body{white-space:pre-wrap;word-break:break-word;font-size:13.5px;line-height:1.6;color:var(--text)}
</style>
<?php if (!cfgGet('mail_enabled')): ?>
  <div class="alert alert-i">The mail server isn't installed yet, so there's no webmail. Install mail first (see the <a href="?page=email">Email</a> page).</div>
<?php return; endif; ?>
<?php if (!wmAvailable()): ?>
  <div class="alert alert-e"><b>Webmail needs the PHP IMAP extension.</b><br><span class="xs">On the server run <span class="mono">sudo apt install php-imap</span> (Debian/Ubuntu) or <span class="mono">sudo dnf install php-imap</span> (RHEL), then restart the web server. Creating accounts and using a normal mail app already work without it.</span></div>
<?php return; endif; ?>
<?php if (!$signedIn): ?>
<?=helpBox('Webmail', 'Sign in with one of your email accounts to read, reply to and delete messages right here.')?>
<div class="card" style="max-width:440px">
  <h3>Sign in to your mailbox</h3>
  <div class="mb"><label>Email address</label><input id="wmEmail" value="<?=h($prefill)?>" placeholder="you@yourdomain.com"></div>
  <div class="mb"><label>Password</label><input id="wmPass" type="password" onkeydown="if(event.key==='Enter')wmLogin()"></div>
  <button class="btn btn-p" onclick="wmLogin()">Sign in</button>
  <div class="xs muted mt">This is your <b>mailbox</b> password (the one you set when creating the account) - not the panel password.</div>
</div>
<script>
function wmLogin(){ var e=document.getElementById('wmEmail').value.trim(), p=document.getElementById('wmPass').value; if(!e||!p){toast('Enter your email and password','e');return;} toast('Signing in...'); api('webmail_login',{email:e,pass:p}).then(function(r){ if(r.ok){location.reload();}else toast(r.error,'e'); }); }
<?php if($prefill):?>setTimeout(function(){document.getElementById('wmPass').focus();},50);<?php endif;?>
</script>
<?php return; endif; ?>
<div class="card" style="padding:12px 16px">
  <div class="flex wrap" style="gap:10px;align-items:center">
    <b class="sm">Inbox</b> <span class="muted sm">- <?=h($_SESSION['wm']['email'])?></span>
    <span style="flex:1"></span>
    <button class="btn btn-p btn-xs" onclick="wmCompose()">Compose</button>
    <button class="btn btn-s btn-xs" onclick="wmLoad(wmPage)">Refresh</button>
    <span class="pager" id="wmPager"></span>
    <button class="btn btn-g btn-xs" onclick="wmLogout()">Sign out</button>
  </div>
</div>
<div class="wm-layout">
  <div class="card wm-list" id="wmList"><div class="empty">Loading...</div></div>
  <div class="card wm-view" id="wmView"><div class="empty">Select a message to read it.</div></div>
</div>
<script>
var wmPage=1;
function wmEsc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function wmLoad(p){ wmPage=p||1; document.getElementById('wmList').innerHTML='<div class="empty">Loading...</div>';
  api('webmail_list',{page:wmPage}).then(function(r){ var el=document.getElementById('wmList');
    if(!r.ok){ el.innerHTML='<div class="alert alert-e" style="margin:12px">'+(r.error==='not_connected'?'Session expired - please sign in again.':wmEsc(r.error))+'</div>'; return; }
    if(!r.messages.length){ el.innerHTML='<div class="empty">This inbox is empty.</div>'; }
    else el.innerHTML=r.messages.map(function(m){ return '<div class="wm-item'+(m.unread?' unread':'')+'" id="wm-'+m.uid+'" onclick="wmOpen('+m.uid+')"><div class="r1"><span class="who">'+wmEsc(m.from)+'</span><span class="when">'+wmEsc(m.date)+'</span></div><div class="subj">'+wmEsc(m.subject)+'</div></div>'; }).join('');
    var pg=document.getElementById('wmPager'); pg.innerHTML = r.pages>1 ? '<button class="btn btn-g btn-xs" '+(wmPage<=1?'disabled':'')+' onclick="wmLoad('+(wmPage-1)+')">newer</button> <span class="xs muted">'+wmPage+' / '+r.pages+'</span> <button class="btn btn-g btn-xs" '+(wmPage>=r.pages?'disabled':'')+' onclick="wmLoad('+(wmPage+1)+')">older</button>' : '<span class="xs muted">'+r.total+' message(s)</span>';
  });
}
function wmOpen(uid){ document.querySelectorAll('.wm-item').forEach(function(x){x.classList.remove('active');}); var it=document.getElementById('wm-'+uid); if(it){it.classList.add('active');it.classList.remove('unread');}
  document.getElementById('wmView').innerHTML='<div class="empty">Loading...</div>';
  api('webmail_read',{uid:uid}).then(function(r){ var v=document.getElementById('wmView'); if(!r.ok){v.innerHTML='<div class="alert alert-e" style="margin:12px">'+wmEsc(r.error)+'</div>';return;}
    v.innerHTML='<div style="padding:16px 18px"><div class="flex wrap" style="justify-content:space-between;gap:8px"><b style="font-size:15px;color:var(--text)">'+wmEsc(r.subject)+'</b><div class="flex" style="gap:5px"><button class="btn btn-p btn-xs" onclick="wmReply()">Reply</button><button class="btn btn-d btn-xs" onclick="wmDelete('+uid+')">Delete</button></div></div><div class="xs muted mt">From: '+wmEsc(r.from)+'<br>Date: '+wmEsc(r.date)+'</div><hr style="border:none;border-top:1px solid var(--border);margin:12px 0"><div class="wm-body">'+wmEsc(r.body)+'</div></div>';
    window._wmCur={uid:uid,from:r.from_addr,subject:r.subject,body:r.body};
  });
}
function wmDelete(uid){ uiConfirm('Delete this message?',function(){ api('webmail_delete',{uid:uid}).then(function(r){ if(r.ok){toast(r.msg);document.getElementById('wmView').innerHTML='<div class="empty">Select a message to read it.</div>';wmLoad(wmPage);}else toast(r.error,'e'); }); }); }
function wmCompose(){ wmForm('','',''); }
function wmReply(){ var c=window._wmCur||{}; wmForm(c.from||'', (/^re:/i.test(c.subject||'')?c.subject:'Re: '+(c.subject||'')), '\n\n----- Original message -----\n'+(c.body||'')); }
function wmForm(to,subject,body){ var v=document.getElementById('wmView');
  v.innerHTML='<div style="padding:16px 18px"><b style="font-size:15px;color:var(--text)">New message</b>'
    +'<div class="mb mt"><label>To</label><input id="wmTo" value="'+wmEsc(to)+'" placeholder="someone@example.com"></div>'
    +'<div class="mb"><label>Subject</label><input id="wmSubj" value="'+wmEsc(subject)+'"></div>'
    +'<div class="mb"><label>Message</label><textarea id="wmBody" style="min-height:220px">'+wmEsc(body)+'</textarea></div>'
    +'<div class="flex" style="gap:8px"><button class="btn btn-p" onclick="wmSend()">Send</button><button class="btn btn-g" onclick="wmLoad(wmPage);document.getElementById(\'wmView\').innerHTML=\'<div class=&quot;empty&quot;>Select a message to read it.</div>\'">Cancel</button></div></div>';
  document.getElementById('wmTo').focus();
}
function wmSend(){ var to=document.getElementById('wmTo').value.trim(), subj=document.getElementById('wmSubj').value, body=document.getElementById('wmBody').value; if(!to){toast('Enter a recipient email','e');return;} toast('Sending...'); api('webmail_send',{to:to,subject:subj,body:body}).then(function(r){ if(r.ok){toast(r.msg);document.getElementById('wmView').innerHTML='<div class="empty">Message sent.</div>';}else toast(r.error,'e'); }); }
function wmLogout(){ api('webmail_logout',{}).then(function(){ location.href='?page=email'; }); }
wmLoad(1);
</script>
<?php }

function pageRedirects(): void {
    $reds = loadJson(REDIRECTS_FILE, []);
    $allSites = array_values(array_filter(array_map(fn($s)=>$s['domain']??'', loadJson(SITES_FILE, []))));
    ?>
<?=helpBox('What is a redirect?', 'A redirect <b>forwards</b> visitors from one of your domains to another web address. For example, send <span class="mono">old-name.com</span> to <span class="mono">https://new-name.com</span>, or point a spare domain at your main site. <b>Pick one of your added domains/subdomains</b> as the source, choose where it should go, then press <b>Create redirect</b>. Removing a redirect later restores the domain - it never deletes your DNS.')?>
<div class="card">
  <h3>Add a redirect</h3>
  <div class="row">
    <div><label>Source domain / subdomain</label>
      <?php if($allSites): ?>
      <select id="rDomain"><?php foreach($allSites as $sd) echo '<option value="'.h($sd).'">'.h($sd).'</option>'; ?></select>
      <?php else: ?>
      <select id="rDomain" disabled><option value="">- add a website or subdomain first -</option></select>
      <?php endif; ?>
    </div>
    <div><label>Forward visitors to (full URL, domain, subdomain or path)</label><input id="rTarget" placeholder="https://your-main-site.com"></div>
    <div style="max-width:180px"><label>Redirect type</label>
      <select id="rType">
        <option value="302">302 - Temporary (recommended)</option>
        <option value="301">301 - Permanent (browsers cache it hard)</option>
      </select>
      <div class="xs muted" style="margin-top:4px">Use <b>302</b> unless you're sure: a <b>301</b> is cached by visitors' browsers and keeps redirecting even after you remove it (they must clear their cache).</div>
      </div>
    <button class="btn btn-p" onclick="act('redirect_add',{domain:rDomain.value,target:rTarget.value,type:rType.value},true)">Create redirect</button>
  </div>
  <div class="xs muted mt">The source domain's DNS must point to this server (<span class="mono"><?=h(cfgGet('server_ip'))?></span>). If a Cloudflare token is connected it is pointed, proxied and secured (HTTPS) automatically.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Your redirects (<?=count($reds)?>)</h3></div>
  <?php if(!$reds):?><div class="empty">No redirects yet - add one above.</div><?php else:?>
  <table><thead><tr><th>Domain</th><th>Forwards to</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($reds as $x):?>
    <tr><td><b><?=h($x['domain'])?></b></td>
      <td class="mono xs"><?=h($x['target'])?> &nbsp;<a href="<?=h($x['target'])?>" target="_blank" rel="noopener">test &gt;</a></td>
      <td><button class="btn btn-d btn-xs" onclick="confirmAct('Remove redirect for <?=h($x['domain'])?>?','redirect_del',{domain:'<?=h($x['domain'])?>'},true)"><?=trashSvg()?></button></td></tr>
  <?php endforeach;?>
  </tbody></table><?php endif;?>
</div>
<?php }

function pageZone(): void {
    $hasToken = cfConnected();
    $sites = loadJson(SITES_FILE, []);
    $domains = [];
    if (cfgGet('primary_domain')) $domains[] = cfgGet('primary_domain');
    foreach ($sites as $s) { if (empty($s['domain'])) continue; $p=explode('.', $s['domain']); $root=implode('.', array_slice($p,-2)); if(!in_array($root,$domains,true)) $domains[]=$root; }
    ?>
<?=helpBox('What is the Zone Editor?', 'This edits your <b>live DNS records</b> - the settings that tell the internet where your domain points. It works through your <b>Cloudflare</b> account. Pick a domain, then add or edit records: <span class="mono">A</span> points a name to a server IP, <span class="mono">CNAME</span> points to another name, <span class="mono">MX</span> is for email, and <span class="mono">TXT</span> is for verification/SPF. Use <span class="mono">@</span> as the name for the root domain.')?>
<?php if(!$hasToken): ?>
<div class="alert alert-i">The Zone Editor needs a <b>Cloudflare API token</b>. Add one in <a href="?page=settings"><b>Settings</b></a> (Cloudflare section), then come back here. Without Cloudflare, use the <a href="?page=dns"><b>DNS</b></a> page to copy records into your registrar by hand.</div>
<?php return; endif; ?>
<div class="card">
  <div class="row">
    <div><label>Domain (DNS zone)</label>
      <?php if($domains): ?><select id="znDom" onchange="znLoad()"><?php foreach($domains as $d):?><option><?=h($d)?></option><?php endforeach;?></select>
      <?php else: ?><input id="znDom" placeholder="example.com" onkeydown="if(event.key==='Enter')znLoad()"><?php endif; ?>
    </div>
    <button class="btn btn-s" onclick="znLoad()">Load records</button>
    <button class="btn btn-p" onclick="znNew()">+ Add record</button>
  </div>
</div>
<div class="card" style="padding:0"><div id="znBody"><div class="empty">Pick a domain and click Load records.</div></div></div>
<div class="pal" id="znModal" onclick="if(event.target===this)znClose()"><div class="pal-box" style="width:460px">
  <div style="padding:15px 18px;border-bottom:1px solid var(--border)"><b id="znTitle">DNS record</b></div>
  <div style="padding:16px 18px;display:flex;flex-direction:column;gap:11px">
    <input type="hidden" id="znId">
    <div><label>Type</label><select id="znType" onchange="znTypeChange()"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option></select></div>
    <div><label>Name / host (use @ for the root domain)</label><input id="znName" placeholder="@  or  www  or  mail"></div>
    <div><label id="znValLbl">Value</label><input id="znContent" placeholder="<?=h(cfgGet('server_ip'))?>"></div>
    <div id="znPrioWrap" style="display:none"><label>Priority (MX)</label><input id="znPrio" value="10"></div>
    <label class="flex" id="znProxWrap" style="gap:8px;cursor:pointer"><input type="checkbox" id="znProx" style="width:auto"> <span class="sm">Proxy through Cloudflare (orange cloud)</span></label>
    <div class="flex" style="justify-content:flex-end;gap:8px"><button class="btn btn-g" onclick="znClose()">Cancel</button><button class="btn btn-p" onclick="znSave()">Save record</button></div>
  </div>
</div></div>
<script>
function znEsc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function znDomVal(){ var e=document.getElementById('znDom'); return (e.value||'').trim(); }
function znLoad(){ var d=znDomVal(); if(!d)return; document.getElementById('znBody').innerHTML='<div class="empty">Loading...</div>';
  api('zone_list',{domain:d}).then(function(r){ if(!r.ok){ document.getElementById('znBody').innerHTML='<div class="alert alert-e" style="margin:14px">'+(r.error==='no_token'?'Add a Cloudflare token in Settings first.':znEsc(r.error))+'</div>'; return; }
    var h='<table><thead><tr><th>Type</th><th>Name</th><th>Value</th><th>TTL</th><th>Proxy</th><th>Actions</th></tr></thead><tbody>';
    r.records.forEach(function(rec){ h+='<tr><td><span class="badge bg-blue">'+rec.type+'</span></td><td class="mono xs">'+znEsc(rec.name)+'</td><td class="mono xs" style="max-width:260px;overflow:hidden;text-overflow:ellipsis">'+znEsc(rec.content)+'</td><td class="xs muted">'+(rec.ttl===1?'Auto':rec.ttl)+'</td><td class="xs">'+(rec.proxied?'<span style="color:var(--amber)">on</span>':'-')+'</td><td class="flex" style="gap:5px"><button class="btn btn-g btn-xs" onclick=\'znEdit('+znEsc(JSON.stringify(rec))+')\'>Edit</button><button class="btn btn-d btn-xs" onclick="znDel(\''+rec.id+'\')">Del</button></td></tr>'; });
    if(!r.records.length) h+='<tr><td colspan="6" class="empty">No records yet.</td></tr>';
    h+='</tbody></table>'; document.getElementById('znBody').innerHTML=h;
  });
}
function znTypeChange(){ var t=document.getElementById('znType').value; document.getElementById('znPrioWrap').style.display=(t==='MX')?'':'none'; document.getElementById('znProxWrap').style.display=(['A','AAAA','CNAME'].indexOf(t)>=0)?'':'none'; var lbl={A:'IPv4 address',AAAA:'IPv6 address',CNAME:'Target hostname',MX:'Mail server hostname',TXT:'Text value'}; document.getElementById('znValLbl').textContent=lbl[t]||'Value'; }
function znNew(){ document.getElementById('znId').value=''; document.getElementById('znType').value='A'; document.getElementById('znName').value=''; document.getElementById('znContent').value=''; document.getElementById('znPrio').value='10'; document.getElementById('znProx').checked=false; document.getElementById('znTitle').textContent='Add DNS record'; znTypeChange(); document.getElementById('znModal').classList.add('show'); }
function znEdit(rec){ document.getElementById('znId').value=rec.id; document.getElementById('znType').value=rec.type; document.getElementById('znName').value=rec.name; document.getElementById('znContent').value=rec.content; document.getElementById('znProx').checked=!!rec.proxied; document.getElementById('znTitle').textContent='Edit DNS record'; znTypeChange(); document.getElementById('znModal').classList.add('show'); }
function znClose(){ document.getElementById('znModal').classList.remove('show'); }
function znSave(){ api('zone_save',{domain:znDomVal(),id:document.getElementById('znId').value,type:document.getElementById('znType').value,name:document.getElementById('znName').value,content:document.getElementById('znContent').value,priority:document.getElementById('znPrio').value,proxied:document.getElementById('znProx').checked?1:''}).then(function(r){ if(r.ok){toast(r.msg);znClose();znLoad();}else toast(r.error,'e'); }); }
function znDel(id){ uiConfirm('Delete this DNS record? This changes your live DNS.',function(){ api('zone_del',{domain:znDomVal(),id:id}).then(function(r){ if(r.ok){toast(r.msg);znLoad();}else toast(r.error,'e'); }); }); }
window.addEventListener('DOMContentLoaded',function(){ if(znDomVal()) znLoad(); });
</script>
<?php }

function pageDns(): void {
    $ip = cfgGet('server_ip'); $mailHost = cfgGet('primary_domain', $ip);
    $sites = loadJson(SITES_FILE, []);
    $mail = loadJson(MAIL_FILE, ['domains'=>[],'mailboxes'=>[]]);
    $rec = function($type,$name,$value,$extra='') {
        echo '<tr><td><span class="badge bg-blue">'.h($type).'</span></td><td class="mono">'.h($name).'</td><td class="mono xs">'.h($value).' '.$extra.'</td><td><span class="copy" onclick="copyText(\''.h($value).'\')">copy</span></td></tr>';
    };
    ?>
<div class="alert alert-i">Set these records at your <b>domain registrar</b> (where you bought the domain) - GoDaddy, Namecheap, Cloudflare, etc. DNS changes can take a few minutes to a few hours to take effect.</div>
<?php if(!$sites && !$mail['domains']):?><div class="empty">Add a website or mail domain first - then the exact records appear here.</div><?php endif;?>
<?php foreach ($sites as $s): $d=$s['domain']; if(!empty($s['sub'])) continue; ?>
<div class="card" style="padding:0">
  <div style="padding:15px 18px 4px"><h3><?=h($d)?> - website records</h3></div>
  <table><thead><tr><th>Type</th><th>Name / Host</th><th>Value</th><th></th></tr></thead><tbody>
  <?php $rec('A','@',$ip); $rec('A','www',$ip); ?>
  </tbody></table>
</div>
<?php endforeach; ?>
<?php foreach ($mail['domains'] as $d): $dk=helper('get-dkim',[$d]); $dkim=trim($dk['out']); ?>
<div class="card" style="padding:0">
  <div style="padding:15px 18px 4px"><h3><?=h($d)?> - mail records</h3></div>
  <table><thead><tr><th>Type</th><th>Name / Host</th><th>Value</th><th></th></tr></thead><tbody>
  <?php
    $rec('MX','@',$mailHost,'<span class="muted">(priority 10)</span>');
    $rec('TXT','@','v=spf1 a mx ip4:'.$ip.' ~all');
    $rec('TXT','_dmarc','v=DMARC1; p=quarantine; rua=mailto:'.cfgGet('admin_email'));
    if ($dkim && $dkim!=='NO_DKIM_KEY') {
        if (preg_match('/p=([A-Za-z0-9+\/=\s"]+)/',$dkim,$mm)) {
            $p = preg_replace('/["\s]/','',$mm[1]);
            $rec('TXT','mail._domainkey','v=DKIM1; k=rsa; p='.$p);
        }
    }
  ?>
  </tbody></table>
  <?php if($dkim && $dkim!=='NO_DKIM_KEY' && !preg_match('/p=/',$dkim)):?><div class="xs muted" style="padding:0 18px 14px">DKIM key still generating - refresh in a moment.</div><?php endif;?>
</div>
<?php endforeach; ?>
<div class="card"><h3>Reverse DNS (PTR) - for mail deliverability</h3><div class="sm muted">For email to reach Gmail/Outlook, set the <b>PTR / rDNS</b> for <span class="mono"><?=h($ip)?></span> to <span class="mono"><?=h($mailHost)?></span> in your <b>VPS provider's</b> control panel (DigitalOcean/Hetzner/etc. - not the registrar).</div></div>
<?php }

function pageSsl(): void {
    $sites = loadJson(SITES_FILE, []);
    $certs = helper('cert-list', []);
    ?>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>HTTPS / SSL certificates</h3></div>
  <table><thead><tr><th>Domain</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($sites as $s): $d=$s['domain']; ?>
    <tr><td><b><?=h($d)?></b></td>
      <td><?=!empty($s['ssl'])?'<span class="badge bg-green">secured</span>':'<span class="badge bg-red">not secured</span>'?></td>
      <td><button class="btn btn-p btn-xs" onclick="toast('Requesting certificate...');act('ssl_issue',{domain:'<?=h($d)?>'},true)"><?=!empty($s['ssl'])?'Renew/Reissue':'Secure with HTTPS'?></button></td></tr>
  <?php endforeach; if(!$sites):?><tr><td class="empty">Add a website first.</td></tr><?php endif;?>
  </tbody></table>
</div>
<div class="card"><h3>Certbot status</h3><div class="code"><?=h(trim($certs['out']) ?: 'No certificates yet.')?></div></div>
<div class="xs muted">Free certificates from Let's Encrypt auto-renew via a system timer. The domain's DNS must point to <span class="mono"><?=h(cfgGet('server_ip'))?></span> before securing.</div>
<?php }

function pageFiles(): void {
    $base = cfgGet('webroot_base','/var/www');
    $rdir = realpath($_GET['dir'] ?? $base) ?: ($base ?: '/');
    $rdir = rtrim(str_replace('\\','/',$rdir),'/'); if ($rdir==='') $rdir='/';
    $parent = str_replace('\\','/',dirname($rdir));
    $entries = []; $numD=0; $numF=0; $total=0;
    foreach (@scandir($rdir) ?: [] as $name) {
        if ($name==='.'||$name==='..') continue;
        $p = $rdir.'/'.$name; $isDir = is_dir($p); $sz = $isDir ? 0 : (@filesize($p) ?: 0);
        $entries[] = ['name'=>$name,'path'=>$p,'isDir'=>$isDir,'size'=>$sz,'mtime'=>@filemtime($p) ?: 0,'perms'=>formatPerms($p),'writable'=>is_writable($p)];
        if ($isDir) $numD++; else { $numF++; $total += $sz; }
    }
    usort($entries, fn($a,$b)=>$a['isDir']!==$b['isDir'] ? ($b['isDir']<=>$a['isDir']) : strnatcasecmp($a['name'],$b['name']));
    $previewExt = ['png','jpg','jpeg','gif','webp','svg','bmp','ico','pdf','mp4','webm','mp3','wav','ogg'];
    ?>
<style>
.fm-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.fm-search{min-width:150px;flex:1;max-width:260px}
tr.fm-row td{cursor:default}
tr.fm-row.sel td{background:var(--accent-soft)!important}
.fm-dot{display:none!important}
.sortable{cursor:pointer;user-select:none}.sortable .ar{opacity:.6;font-size:9px}
.fname{color:var(--accent2);cursor:pointer}.fname:hover{text-decoration:underline}
.ctx{position:fixed;z-index:1000;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:6px;min-width:180px;box-shadow:var(--shadow-lg);display:none}
.ctx.show{display:block}
.ctx-i{padding:7px 12px;font-size:13px;color:var(--text);border-radius:7px;cursor:pointer;white-space:nowrap}
.ctx-i:hover{background:var(--accent-soft)}.ctx-i.dan{color:var(--red)}
.ctx-sep{height:1px;background:var(--border);margin:4px 6px}
.pv{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:999;display:none;align-items:center;justify-content:center}
.pv.show{display:flex}
.pv-box{max-width:92vw;max-height:92vh;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:10px}
.pv-box img{max-width:86vw;max-height:78vh;border-radius:8px;object-fit:contain;background:var(--surface2)}
.fm-drop{outline:2px dashed transparent;outline-offset:-6px;transition:.15s}
.fm-drop.drag{outline-color:var(--accent);background:var(--surface2)}
.fmwrap{display:flex;gap:14px;align-items:flex-start}
.fmtree{width:262px;flex-shrink:0;max-height:calc(100vh - 150px);overflow-y:auto;overflow-x:hidden;padding:0;scrollbar-width:thin;scrollbar-color:var(--border2) transparent}
.fmtree::-webkit-scrollbar{width:8px}
.fmtree::-webkit-scrollbar-thumb{background:var(--border2);border-radius:8px;border:2px solid var(--card)}
.fmtree::-webkit-scrollbar-thumb:hover{background:var(--text2)}
.trow.tfile{color:var(--text2)}
.trow.tfile .tlabel svg{opacity:.8}
.fmtree-h{padding:11px 14px;border-bottom:1px solid var(--border);font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:1px;position:sticky;top:0;background:var(--card);z-index:1}
.fmmain{flex:1;min-width:0}
.trow{display:flex;align-items:center;gap:4px;padding:5px 8px;cursor:pointer;border-radius:6px;font-size:12.5px;white-space:nowrap;color:var(--text)}
.trow:hover{background:var(--surface2)}
.trow.active{background:var(--accent-soft);color:var(--accent);font-weight:600}
.tcaret{width:15px;text-align:center;color:var(--text2);font-size:9px;transition:transform .15s;flex-shrink:0}
.tcaret.open{transform:rotate(90deg)}
.tlabel{display:flex;align-items:center;gap:6px;overflow:hidden;text-overflow:ellipsis}
.tlabel svg{flex-shrink:0}
.tkids{padding-left:15px}
.tload{padding:6px 10px;font-size:11px;color:var(--text2)}
@media(max-width:900px){.fmtree{display:none}}
</style>
<input type="hidden" id="fmDir" value="<?=h($rdir)?>">
<div class="fmwrap">
<div class="card fmtree"><div class="fmtree-h">Folders</div><div id="fmTree"><div class="tload">Loading...</div></div></div>
<div class="fmmain">
<div class="card" style="padding:10px 14px">
  <div class="fm-tools">
    <a class="btn btn-g btn-xs" href="?page=files&dir=<?=urlencode($parent)?>">Up</a>
    <input id="goPath" class="mono" style="flex:1;min-width:180px" value="<?=h($rdir)?>" onkeydown="if(event.key==='Enter')fmGo()">
    <button class="btn btn-s btn-xs" onclick="fmGo()">Go</button>
    <a class="btn btn-g btn-xs" href="?page=files&dir=<?=urlencode($base)?>">Web root</a>
  </div>
</div>
<div class="card" style="padding:10px 14px">
  <div class="fm-tools">
    <button class="btn btn-p btn-xs" onclick="fmNew(1)">Folder</button>
    <button class="btn btn-p btn-xs" onclick="fmNew(0)">File</button>
    <button class="btn btn-p btn-xs" onclick="document.getElementById('upInput').click()">Upload</button>
    <input type="file" id="upInput" multiple hidden onchange="fmUpload(this.files);this.value=''">
    <input class="fm-search" id="fmSearch" placeholder="Filter..." oninput="fmFilter(this.value)">
    <span style="flex:1"></span>
    <button class="btn btn-s btn-xs" onclick="fmClip('copy')">Copy</button>
    <button class="btn btn-s btn-xs" onclick="fmClip('cut')">Cut</button>
    <button class="btn btn-s btn-xs hidden" id="pasteBtn" onclick="fmPaste()">Paste</button>
    <button class="btn btn-s btn-xs" onclick="fmZip()">Zip</button>
    <button class="btn btn-g btn-xs" id="hideBtn" onclick="fmToggleHidden()">Hide dotfiles</button>
    <button class="btn btn-d btn-xs hidden" id="delBtn" onclick="fmDelSel()">Delete</button>
  </div>
  <div class="xs muted mt" style="display:flex;gap:8px"><span><?=$numD?> folders</span><span>.</span><span><?=$numF?> files</span><span>.</span><span><?=fmtSize($total)?></span><span id="fmSel" style="margin-left:auto;color:var(--accent2)"></span></div>
</div>
<div class="card fm-drop" id="fmDrop" style="padding:0" oncontextmenu="return fmEmptyCtx(event)">
  <table><thead><tr>
    <th style="width:28px"><input type="checkbox" id="selAll" onchange="fmAll(this.checked)"></th>
    <th class="sortable" onclick="fmSort('name')">Name <span class="ar" id="ar-name"></span></th>
    <th class="sortable" style="width:110px" onclick="fmSort('size')">Size <span class="ar" id="ar-size"></span></th>
    <th class="sortable" style="width:150px" onclick="fmSort('mtime')">Modified <span class="ar" id="ar-mtime"></span></th>
    <th style="width:110px">Perms</th><th style="width:150px">Actions</th>
  </tr></thead><tbody id="fmList">
  <?php foreach ($entries as $e): $n=$e['name']; $p=$e['path']; $isd=$e['isDir'];
      $ext=strtolower(pathinfo($n,PATHINFO_EXTENSION)); $prev=!$isd && in_array($ext,$previewExt); $oct=substr(sprintf('%o',@fileperms($p)),-3); ?>
    <tr class="fm-row" data-name="<?=h($n)?>" data-lower="<?=h(strtolower($n))?>" data-size="<?=$isd?-1:(int)$e['size']?>" data-mtime="<?=$e['mtime']?>" data-type="<?=$isd?'dir':'file'?>" data-path="<?=h($p)?>" data-ext="<?=$ext?>" data-perms="<?=$oct?>" oncontextmenu="return fmCtx(event,this)">
      <td><input type="checkbox" class="cb" onchange="fmUpd()"></td>
      <td><?php if($isd):?><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#fbbf24" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> <a class="fname" href="?page=files&dir=<?=urlencode($p)?>"><b><?=h($n)?></b></a><?php else:?><?=fileIcon($n)?> <span class="fname" onclick="fmOpen('<?=h(addslashes($p))?>','<?=h(addslashes($n))?>','<?=$ext?>')"><?=h($n)?></span><?php endif;?></td>
      <td class="muted xs"><?=$isd?'-':fmtSize($e['size'])?></td>
      <td class="muted xs"><?=$e['mtime']?date('Y-m-d H:i',$e['mtime']):''?></td>
      <td class="muted xs mono" style="cursor:pointer" onclick="fmChmod('<?=h(addslashes($p))?>','<?=$oct?>')"><?=$e['perms']?> <?=$oct?></td>
      <td class="flex" style="gap:5px">
        <?php if($prev):?><button class="btn btn-g btn-xs" title="Preview" onclick="fmPreview('<?=h(addslashes($p))?>','<?=h(addslashes($n))?>','<?=$ext?>')"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></button><?php endif;?>
        <?php if(!$isd):?><a class="btn btn-g btn-xs" title="Download" href="?dl=<?=urlencode($p)?>"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg></a>
        <a class="btn btn-g btn-xs" title="Edit" href="?page=file_edit&file=<?=urlencode($p)?>"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></a><?php endif;?>
        <button class="btn btn-g btn-xs" title="Rename" onclick="fmRename('<?=h(addslashes($p))?>','<?=h(addslashes($n))?>')"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
        <?php if(!$isd && in_array($ext,['zip','tar','gz'])):?><button class="btn btn-g btn-xs" title="Unzip" onclick="confirmAct('Extract <?=h($n)?>?','file_unzip',{file:'<?=h(addslashes($p))?>'},true)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg></button><?php endif;?>
        <button class="btn btn-d btn-xs" title="Delete" onclick="fmDel1('<?=h(addslashes($p))?>','<?=h(addslashes($n))?>')"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
      </td>
    </tr>
  <?php endforeach; if(!$entries):?><tr><td colspan="6" class="empty">Empty folder - drop files here or use Upload.</td></tr><?php endif;?>
  </tbody></table>
</div>
<div class="ctx" id="ctx"></div>
<div class="pv" id="pv" onclick="if(event.target===this)fmClosePv()"><div class="pv-box"><div class="flex" style="justify-content:space-between"><b id="pvName"></b><span class="flex gap2"><a class="btn btn-g btn-xs" id="pvDl" href="#">Download</a><button class="btn btn-g btn-xs" onclick="fmClosePv()">Close</button></span></div><div id="pvBody" style="display:flex;align-items:center;justify-content:center;min-height:100px"></div></div></div>
<div class="xs muted mt">Editing/uploads run as <b><?=h(cfgGet('web_user','www-data'))?></b>. If something is "not writable", fix it from <a href="?page=console">Console</a>: <span class="mono">sudo chown -R <?=h(cfgGet('web_user','www-data'))?>:<?=h(cfgGet('web_user','www-data'))?> /var/www/yoursite</span></div>
</div><!-- .fmmain -->
</div><!-- .fmwrap -->
<script>
var FDIR=document.getElementById('fmDir').value;
var WEBROOT=<?=json_encode(rtrim(str_replace('\\','/',$base),'/'))?>;
function fapi(action,data){ var b=new URLSearchParams(); b.append('action',action); b.append('csrf',CSRF); for(var k in data){ if(Array.isArray(data[k])) data[k].forEach(function(v){b.append(k+'[]',v);}); else b.append(k,data[k]); } return fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(function(r){return r.json();}); }
function fact(action,data,reload){ return fapi(action,data).then(function(r){ if(r.ok){ toast(r.msg||'Done '); if(reload) setTimeout(function(){location.reload();},550);} else toast(r.error||'Error','e'); return r; }); }
function fmGo(){ var v=document.getElementById('goPath').value.trim(); if(v) location.href='?page=files&dir='+encodeURIComponent(v); }
function sel(){ return [...document.querySelectorAll('#fmList tr.fm-row')].filter(function(r){return r.querySelector('.cb').checked;}); }
function selPaths(){ return sel().map(function(r){return r.dataset.path;}); }
function fmAll(c){ document.querySelectorAll('#fmList .cb').forEach(function(b){b.checked=c;}); fmUpd(); }
function fmUpd(){ var s=sel(); document.querySelectorAll('#fmList tr.fm-row').forEach(function(r){ r.classList.toggle('sel', r.querySelector('.cb').checked); }); document.getElementById('delBtn').classList.toggle('hidden', !s.length); document.getElementById('fmSel').textContent = s.length?(' '+s.length+' selected'):''; }
function fmSort(key){ var tb=document.getElementById('fmList'); var rows=[...tb.querySelectorAll('tr.fm-row')]; window._sd=(window._sk===key)?-window._sd:1; window._sk=key; rows.sort(function(a,b){ if(a.dataset.type!==b.dataset.type) return a.dataset.type==='dir'?-1:1; if(key==='name') return window._sd*a.dataset.lower.localeCompare(b.dataset.lower); return window._sd*((+a.dataset[key])-(+b.dataset[key])); }); rows.forEach(function(r){tb.appendChild(r);}); ['name','size','mtime'].forEach(function(k){document.getElementById('ar-'+k).textContent=(k===key)?(window._sd>0?'^':'v'):'';}); }
function fmFilter(q){ q=(q||'').trim().toLowerCase(); document.querySelectorAll('#fmList tr.fm-row').forEach(function(r){ r.style.display=(q&&r.dataset.lower.indexOf(q)<0)?'none':''; }); }
var _hidden=true; function fmToggleHidden(){ _hidden=!_hidden; document.querySelectorAll('#fmList tr.fm-row').forEach(function(r){ if(r.dataset.name.charAt(0)==='.') r.classList.toggle('fm-dot', !_hidden); }); document.getElementById('hideBtn').textContent=_hidden?'Hide dotfiles':'Show dotfiles'; }
function fmNew(isdir){ uiPrompt(isdir?'New folder':'New file','',function(n){ fact('file_new',{dir:FDIR,name:n,isdir:isdir?1:''},true); },{placeholder:isdir?'folder name':'file name.txt',okText:'Create'}); }
function fmRename(p,n){ uiPrompt('Rename',n,function(v){ if(v!==n) fact('file_rename',{old:p,new:v},true); },{okText:'Rename'}); }
function fmDel1(p,n){ uiConfirm('Delete "'+n+'"?',function(){ fact('file_del',{paths:[p]},true); }); }
function fmDelSel(){ var f=selPaths(); if(!f.length)return; uiConfirm('Delete '+f.length+' item(s)?',function(){ fact('file_del',{paths:f},true); }); }
function fmChmod(p,cur){ uiPrompt('Permissions (octal)',cur,function(m){ fact('file_chmod',{path:p,mode:m},true); },{body:'Enter 3-4 octal digits, e.g. 755 or 644.',okText:'Apply'}); }
function fmZip(){ var f=selPaths(); if(!f.length){toast('Select items first','e');return;} uiPrompt('Create ZIP archive','archive.zip',function(n){ fact('file_zip',{dir:FDIR,files:f,name:n},true); },{okText:'Create ZIP'}); }
function fmClip(mode){ var f=selPaths(); if(!f.length){toast('Select items first','e');return;} sessionStorage.setItem('fmClip',JSON.stringify({mode:mode,files:f})); upd(); toast((mode==='cut'?'Cut ':'Copied ')+f.length+' - open a folder & Paste'); }
function getClip(){ try{return JSON.parse(sessionStorage.getItem('fmClip')||'null');}catch(e){return null;} }
function upd(){ var c=getClip(),b=document.getElementById('pasteBtn'); var has=c&&c.files&&c.files.length; b.classList.toggle('hidden',!has); if(has)b.textContent='Paste ('+c.files.length+')'; }
function fmPaste(){ var c=getClip(); if(!c||!c.files.length)return; if(c.mode==='cut')sessionStorage.removeItem('fmClip'); fact(c.mode==='cut'?'file_move':'file_copy',{files:c.files,dest:FDIR},true); }
function fmDuplicate(p){ fact('file_duplicate',{file:p},true); }
function isPrev(e){ return ['png','jpg','jpeg','gif','webp','svg','bmp','ico','pdf','mp4','webm','mp3','wav','ogg'].indexOf((e||'').toLowerCase())>=0; }
function fmOpen(p,n,e){ if(isPrev(e)) fmPreview(p,n,e); else location.href='?page=file_edit&file='+encodeURIComponent(p); }
function fmPreview(p,n,e){ var url='?view='+encodeURIComponent(p); e=(e||'').toLowerCase(); var h='';
  if(['png','jpg','jpeg','gif','webp','svg','bmp','ico'].indexOf(e)>=0) h='<img src="'+url+'">';
  else if(['mp4','webm'].indexOf(e)>=0) h='<video src="'+url+'" controls autoplay style="max-width:86vw;max-height:78vh"></video>';
  else if(['mp3','wav','ogg'].indexOf(e)>=0) h='<audio src="'+url+'" controls autoplay style="width:60vw"></audio>';
  else if(e==='pdf') h='<iframe src="'+url+'" style="width:86vw;height:80vh;border:none;background:#fff;border-radius:8px"></iframe>';
  else h='<div class="muted">No preview.</div>';
  document.getElementById('pvName').textContent=n; document.getElementById('pvDl').href='?dl='+encodeURIComponent(p); document.getElementById('pvBody').innerHTML=h; document.getElementById('pv').classList.add('show'); }
function fmClosePv(){ document.getElementById('pv').classList.remove('show'); document.getElementById('pvBody').innerHTML=''; }
function ctxShow(ev,it){ var m=document.getElementById('ctx'); m.innerHTML=it.map(function(x){return x[0]==='SEP'?'<div class="ctx-sep"></div>':'<div class="ctx-i'+(x[2]?' '+x[2]:'')+'" onclick="hideCtx();'+x[1]+'">'+x[0]+'</div>';}).join(''); m.classList.add('show'); m.style.left=Math.min(ev.clientX,innerWidth-210)+'px'; m.style.top=Math.min(ev.clientY,innerHeight-m.offsetHeight-8)+'px'; return false; }
function fmCtx(ev,row){ ev.preventDefault(); ev.stopPropagation();
  // If the right-clicked row is not selected, make it the sole selection.
  if(!row.querySelector('.cb').checked){ document.querySelectorAll('#fmList .cb').forEach(function(b){b.checked=false;}); row.querySelector('.cb').checked=true; fmUpd(); }
  var p=row.dataset.path,n=row.dataset.name,isd=row.dataset.type==='dir',e=row.dataset.ext,pm=row.dataset.perms;
  var P=p.replace(/\\/g,'\\\\').replace(/'/g,"\\'"),N=n.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
  var multi=selPaths().length>1, arch=['zip','tar','gz','tgz','rar','7z'].indexOf((e||'').toLowerCase())>=0, clip=getClip(); var it=[];
  if(!multi){
    if(isd) it.push(['Open',"location.href='?page=files&dir='+encodeURIComponent('"+P+"')"]);
    if(!isd&&isPrev(e)) it.push(['Preview',"fmPreview('"+P+"','"+N+"','"+e+"')"]);
    if(!isd) it.push(['Edit',"location.href='?page=file_edit&file='+encodeURIComponent('"+P+"')"]);
    if(!isd) it.push(['Download',"location.href='?dl='+encodeURIComponent('"+P+"')"]);
    it.push(['SEP']);
  }
  it.push(['Copy'+(multi?' ('+selPaths().length+')':''), multi?"fmClip('copy')":"oneClip('copy','"+P+"')"]);
  it.push(['Cut'+(multi?' ('+selPaths().length+')':''), multi?"fmClip('cut')":"oneClip('cut','"+P+"')"]);
  if(clip&&clip.files&&clip.files.length&&isd) it.push(['Paste into folder',"fmPasteInto('"+P+"')"]);
  if(!multi) it.push(['Duplicate',"fmDuplicate('"+P+"')"]);
  it.push(['Compress'+(multi?' ('+selPaths().length+')':' to ZIP'),"fmZip()"]);
  if(!isd&&arch) it.push(['Extract here',"confirmAct('Extract "+N+"?','file_unzip',{file:'"+P+"'},true)"]);
  if(!multi){ it.push(['Rename',"fmRename('"+P+"','"+N+"')"]); it.push(['Permissions',"fmChmod('"+P+"','"+pm+"')"]); }
  it.push(['SEP']);
  if(!multi) it.push(['Properties',"fmProps('"+P+"')"]);
  it.push(['Delete'+(multi?' ('+selPaths().length+')':''), multi?"fmDelSel()":"fmDel1('"+P+"','"+N+"')",'dan']);
  return ctxShow(ev,it);
}
function fmEmptyCtx(ev){ if(ev.target.closest('tr.fm-row')) return; ev.preventDefault();
  var clip=getClip(); var it=[['New folder',"fmNew(1)"],['New file',"fmNew(0)"],['Upload',"document.getElementById('upInput').click()"]];
  if(clip&&clip.files&&clip.files.length) it.push(['Paste ('+clip.files.length+')',"fmPaste()"]);
  it.push(['SEP']); it.push(['Select all',"document.getElementById('selAll').checked=true;fmAll(true)"]); it.push(['Refresh',"location.reload()"]);
  return ctxShow(ev,it);
}
function fmProps(p){
  fapi('file_stat',{path:p}).then(function(r){ if(!r||!r.ok){toast((r&&r.error)||'Not working','e');return;}
    function row(k,v){ return v===''||v==null?'':'<tr><td class="muted xs" style="padding:5px 10px;white-space:nowrap">'+k+'</td><td class="xs mono" style="padding:5px 10px;word-break:break-all">'+uEsc(String(v))+'</td></tr>'; }
    var sz = r.type==='folder' ? (r.items+' item(s)') : fmtBytes(r.size);
    var h='<table style="width:100%">'
      + row('Name', r.name) + row('Type', r.type + (r.mime?' &middot; '+r.mime:'')) + row('Location', r.path)
      + row('Size', sz) + row('Permissions', r.perms+'  ('+r.perms_octal+')')
      + row('Owner', r.owner + (r.group?' : '+r.group:'')) + row('Modified', r.mtime?new Date(r.mtime*1000).toLocaleString():'')
      + row('Access', (r.readable?'read':'')+(r.writable?(r.readable?', write':'write'):'')||'none')
      + '</table>'
      + '<div class="mt"><button class="btn btn-s btn-xs" onclick="uiClose();fmChmod(\''+p.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"','"+r.perms_octal.slice(-3)+'\')">Change permissions</button></div>';
    uiHtml('<h3 style="margin-bottom:8px">Properties</h3>'+h, true);
  });
}
function fmtBytes(b){ b=+b||0; if(b<1024)return b+' B'; var u=['KB','MB','GB','TB'],i=-1; do{b/=1024;i++;}while(b>=1024&&i<u.length-1); return b.toFixed(1)+' '+u[i]; }
function fmPasteInto(dir){ var c=getClip(); if(!c||!c.files.length)return; if(c.mode==='cut')sessionStorage.removeItem('fmClip'); fact(c.mode==='cut'?'file_move':'file_copy',{files:c.files,dest:dir},true); }
function oneClip(mode,p){ sessionStorage.setItem('fmClip',JSON.stringify({mode:mode,files:[p]})); upd(); toast((mode==='cut'?'Cut':'Copied')+' 1 item'); }
function hideCtx(){ document.getElementById('ctx').classList.remove('show'); }
document.addEventListener('click',hideCtx); document.addEventListener('keydown',function(e){ if(/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName||''))return; var k=e.key.toLowerCase(); if(e.key==='Escape'){hideCtx();fmClosePv();} else if(e.key==='Delete'){if(selPaths().length){e.preventDefault();fmDelSel();}} else if((e.ctrlKey||e.metaKey)&&k==='a'){e.preventDefault();document.getElementById('selAll').checked=true;fmAll(true);} else if((e.ctrlKey||e.metaKey)&&k==='c'){if(selPaths().length){e.preventDefault();fmClip('copy');}} else if((e.ctrlKey||e.metaKey)&&k==='x'){if(selPaths().length){e.preventDefault();fmClip('cut');}} else if((e.ctrlKey||e.metaKey)&&k==='v'){if(getClip()){e.preventDefault();fmPaste();}} });
// -- chunked upload + drag-drop --
function fmUpload(files){ var arr=[...files]; if(!arr.length)return; var i=0; (function next(){ if(i>=arr.length){ toast('Upload complete'); setTimeout(function(){location.reload();},600); return;} var f=arr[i]; toast(' Uploading '+f.name+'...'); chunkUp(f,function(){i++;next();},function(err){toast(''+f.name+': '+err,'e');}); })(); }
function chunkUp(file,done,fail){ var CH=2*1024*1024,chunks=Math.ceil(file.size/CH)||1,idx=0; (function send(){ if(idx>=chunks){done();return;} var blob=file.slice(idx*CH,Math.min((idx+1)*CH,file.size)); var fd=new FormData(); fd.append('action','file_upload_chunk'); fd.append('csrf',CSRF); fd.append('dir',FDIR); fd.append('name',file.name); fd.append('chunk',idx); fd.append('chunks',chunks); fd.append('blob',blob); fetch('',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){ if(!r.ok){fail(r.error);return;} idx++; send(); }).catch(function(e){fail(e.message);}); })(); }
var dz=document.getElementById('fmDrop'); ['dragover','dragenter'].forEach(function(ev){dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.add('drag');});}); ['dragleave','drop'].forEach(function(ev){dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.remove('drag');});}); dz.addEventListener('drop',function(e){ if(e.dataTransfer.files.length) fmUpload(e.dataTransfer.files); });
// -- folder-tree sidebar --
function tSvg(){ return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#fbbf24" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>'; }
function tFileSvg(){ return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>'; }
function tEsc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
// Right-click context menu for the folder-tree sidebar (folders AND files).
function tCtx(ev,item){ ev.preventDefault(); ev.stopPropagation();
  var p=item.path, n=item.name, isd=!item.file, e=(n.indexOf('.')>=0?n.split('.').pop():'').toLowerCase();
  var P=p.replace(/\\/g,'\\\\').replace(/'/g,"\\'"), N=n.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
  var clip=getClip(); var it=[];
  if(isd){ it.push(['Open',"location.href='?page=files&dir='+encodeURIComponent('"+P+"')"]); }
  else { if(isPrev(e)) it.push(['Preview',"fmPreview('"+P+"','"+N+"','"+e+"')"]); it.push(['Edit',"location.href='?page=file_edit&file='+encodeURIComponent('"+P+"')"]); it.push(['Download',"location.href='?dl='+encodeURIComponent('"+P+"')"]); }
  it.push(['SEP']);
  it.push(['Copy',"oneClip('copy','"+P+"')"]); it.push(['Cut',"oneClip('cut','"+P+"')"]);
  if(isd&&clip&&clip.files&&clip.files.length) it.push(['Paste into folder',"fmPasteInto('"+P+"')"]);
  it.push(['Rename',"fmRename('"+P+"','"+N+"')"]);
  it.push(['SEP']); it.push(['Properties',"fmProps('"+P+"')"]);
  it.push(['Delete',"fmDel1('"+P+"','"+N+"')",'dan']);
  return ctxShow(ev,it);
}
function tNode(item){ var wrap=document.createElement('div'); wrap.className='tnode'; var isFile=!!item.file;
  var row=document.createElement('div'); row.className='trow'+(isFile?' tfile':''); row.dataset.path=item.path; if(item.path===FDIR&&!isFile)row.classList.add('active');
  var car=document.createElement('span'); car.className='tcaret'; car.innerHTML=(!isFile&&item.children)?'&#9656;':''; if(!isFile) car.onclick=function(e){e.stopPropagation();tToggle(wrap);};
  var lab=document.createElement('span'); lab.className='tlabel'; lab.innerHTML=(isFile?tFileSvg():tSvg())+' '+tEsc(item.name);
  if(isFile){ var ext=(item.name.indexOf('.')>=0?item.name.split('.').pop():'').toLowerCase(); lab.title=item.name; lab.onclick=function(){ fmOpen(item.path,item.name,ext); }; }
  else { lab.onclick=function(){ location.href='?page=files&dir='+encodeURIComponent(item.path); }; }
  row.oncontextmenu=function(ev){ return tCtx(ev,item); };
  row.appendChild(car); row.appendChild(lab); wrap.appendChild(row);
  if(!isFile){ var kids=document.createElement('div'); kids.className='tkids'; kids.style.display='none'; wrap.appendChild(kids); }
  return wrap;
}
function tFetch(kids,path,cb){ kids.innerHTML='<div class="tload">Loading...</div>'; fapi('fm_tree',{dir:path}).then(function(r){ kids.innerHTML=''; kids.dataset.loaded='1'; if(r.ok) r.items.forEach(function(it){ kids.appendChild(tNode(it)); }); else kids.innerHTML='<div class="tload">'+(r.error||'error')+'</div>'; if(cb)cb(); }); }
function tToggle(wrap){ var kids=wrap.querySelector('.tkids'), car=wrap.querySelector('.tcaret'), path=wrap.querySelector('.trow').dataset.path;
  if(kids.style.display==='none'){ if(!kids.dataset.loaded){ tFetch(kids,path); } kids.style.display=''; car.classList.add('open'); }
  else { kids.style.display='none'; car.classList.remove('open'); } }
function tExpand(wrap,base,target){ var kids=wrap.querySelector('.tkids'), car=wrap.querySelector('.tcaret'), path=wrap.querySelector('.trow').dataset.path;
  tFetch(kids,path,function(){ kids.style.display=''; car.classList.add('open');
    if(target===base || target.indexOf(base+'/')!==0) return;
    var np=base+'/'+target.substring(base.length+1).split('/')[0];
    var child=[...kids.children].find(function(c){ var t=c.querySelector('.trow'); return t&&t.dataset.path===np; });
    if(child){ if(np===target) child.querySelector('.trow').classList.add('active'); else tExpand(child,np,target); }
  }); }
function fmTreeInit(){ var el=document.getElementById('fmTree'); el.innerHTML=''; var root=tNode({name:'Web root',path:WEBROOT,children:true}); el.appendChild(root);
  if(FDIR===WEBROOT || FDIR.indexOf(WEBROOT+'/')===0) tExpand(root,WEBROOT,FDIR); else tToggle(root); }
upd();
window.addEventListener('DOMContentLoaded', fmTreeInit);   // wait until CSRF (defined in the footer) exists
</script>
<?php }

function pageFileEdit(): void {
    $rf = realpath($_GET['file'] ?? '');
    if (!$rf || !is_file($rf)) { echo '<div class="alert alert-e">File not found.</div>'; return; }
    $rf = str_replace('\\','/',$rf);
    $dir = dirname($rf);
    ?>
<style>
.edtabs{display:flex;gap:2px;overflow-x:auto;background:var(--surface2);border-bottom:1px solid var(--border)}
.edtabs::-webkit-scrollbar{height:5px}
.edtab{display:flex;align-items:center;gap:8px;padding:8px 11px;font-size:12.5px;color:var(--text2);cursor:pointer;border-right:1px solid var(--border);white-space:nowrap;max-width:230px;flex-shrink:0}
.edtab.active{color:var(--text);background:var(--accent-soft)}
.edtab .nm{overflow:hidden;text-overflow:ellipsis}
.edtab.dirty .nm::after{content:' *';color:var(--yellow)}
.edtab .x{opacity:.5;border-radius:4px;padding:0 5px;font-size:14px;line-height:1}
.edtab .x:hover{opacity:1;background:rgba(248,113,113,.3);color:#fff}
</style>
<div class="card" style="padding:10px 14px">
  <div class="flex" style="gap:10px;flex-wrap:wrap">
    <a class="btn btn-g btn-xs" href="?page=files&dir=<?=urlencode($dir)?>">Back to folder</a>
    <span class="mono sm" id="edPath" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
    <span class="sm" id="edStatus"></span>
    <button class="btn btn-p btn-xs" onclick="edSave()">Save <span class="muted">(Ctrl+S)</span></button>
    <button class="btn btn-s btn-xs" onclick="edSaveAll()">Save all</button>
  </div>
  <div class="xs muted mt">Tip: open more files from the File Manager (Edit) - they stack as tabs here. Ctrl+S saves the current tab.</div>
</div>
<div class="card" style="padding:0;overflow:hidden">
  <div class="edtabs" id="edTabs"></div>
  <div id="monaco" style="height:calc(100vh - 250px);min-height:360px"></div>
  <textarea id="edFallback" style="display:none;width:100%;height:60vh;font-family:monospace"></textarea>
</div>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>
<script>
var OPEN_FILE=<?=json_encode($rf)?>;
var edEditor=null, edModels={}, edActive='';
function edEsc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function edJsq(s){ return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function edLangFor(name){ var x=(name.split('.').pop()||'').toLowerCase(); var m={php:'php',phtml:'php',html:'html',htm:'html',xml:'xml',svg:'xml',css:'css',scss:'scss',js:'javascript',mjs:'javascript',jsx:'javascript',ts:'typescript',json:'json',md:'markdown',py:'python',rb:'ruby',go:'go',rs:'rust',java:'java',c:'c',h:'c',cpp:'cpp',cs:'csharp',sh:'shell',bash:'shell',yml:'yaml',yaml:'yaml',ini:'ini',conf:'ini',env:'ini',sql:'sql',htaccess:'apache',dockerfile:'dockerfile'}; return m[x]||'plaintext'; }
function fapi(action,data){ var b=new URLSearchParams(); b.append('action',action); b.append('csrf',CSRF); for(var k in data) b.append(k,data[k]); return fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(function(r){return r.json();}); }
function edSaveTabs(){ try{ sessionStorage.setItem('edTabs', JSON.stringify(Object.keys(edModels))); }catch(e){} }
function edLoadTabs(){ try{ return JSON.parse(sessionStorage.getItem('edTabs')||'[]'); }catch(e){ return []; } }
function edRenderTabs(){ var c=document.getElementById('edTabs'); if(!c)return; c.innerHTML=Object.keys(edModels).map(function(p){ var nm=p.split('/').pop(); var dirty=edModels[p].getValue()!==edModels[p]._saved; return '<div class="edtab'+(p===edActive?' active':'')+(dirty?' dirty':'')+'" onclick="edActivate(\''+edJsq(p)+'\')" title="'+edEsc(p)+'"><span class="nm">'+edEsc(nm)+'</span><span class="x" onclick="event.stopPropagation();edClose(\''+edJsq(p)+'\')">&times;</span></div>'; }).join(''); }
function edActivate(path){ var m=edModels[path]; if(!m||!edEditor)return; edActive=path; edEditor.setModel(m); edEditor.updateOptions({readOnly:!m._writable}); document.getElementById('edPath').textContent=path; document.getElementById('edStatus').innerHTML=m._writable?'':'<span class="badge bg-red">read-only</span>'; edRenderTabs(); }
function edAddTab(path, makeActive, done){ if(edModels[path]){ if(makeActive)edActivate(path); if(done)done(); return; }
  fapi('file_load',{file:path}).then(function(r){ if(!r.ok){ toast('Cannot open '+path.split('/').pop()+': '+r.error,'e'); if(done)done(); return; }
    var pth=r.path||path; var m=monaco.editor.createModel(r.content, edLangFor(pth)); m._writable=r.writable; m._saved=r.content;
    edModels[pth]=m; m.onDidChangeContent(function(){ edRenderTabs(); }); edRenderTabs(); edSaveTabs(); if(makeActive)edActivate(pth); if(done)done();
  }); }
function edCloseDo(path){ var m=edModels[path]; if(m)m.dispose(); delete edModels[path]; var keys=Object.keys(edModels); if(!keys.length){ sessionStorage.removeItem('edTabs'); location.href='?page=files&dir='+encodeURIComponent(path.substring(0,path.lastIndexOf('/'))); return; } edSaveTabs(); if(edActive===path) edActivate(keys[0]); else edRenderTabs(); }
function edClose(path){ var m=edModels[path]; if(m && m.getValue()!==m._saved){ uiConfirm('Close "'+path.split('/').pop()+'" without saving changes?',function(){ edCloseDo(path); }); } else edCloseDo(path); }
function edSave(){ var m=edModels[edActive]; if(!m)return; if(!m._writable){toast('This file is read-only','e');return;} var val=m.getValue(); fapi('file_save',{file:edActive,content:val}).then(function(r){ if(r.ok){ m._saved=val; toast('Saved '+edActive.split('/').pop()); edRenderTabs(); } else toast(r.error,'e'); }); }
function edSaveAll(){ var n=0; Object.keys(edModels).forEach(function(p){ var m=edModels[p]; if(m._writable && m.getValue()!==m._saved){ n++; (function(p,m){ var val=m.getValue(); fapi('file_save',{file:p,content:val}).then(function(r){ if(r.ok){m._saved=val;edRenderTabs();} }); })(p,m); } }); toast(n?('Saving '+n+' file(s)...'):'Nothing changed'); }
document.addEventListener('keydown',function(e){ if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){ e.preventDefault(); edSave(); }});
var edFellBack=false;
try {
  require.config({ paths:{ vs:'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' }});
  require(['vs/editor/editor.main'], function(){
    monaco.editor.defineTheme('mk',{base:'vs-dark',inherit:true,rules:[{token:'comment',foreground:'75715E',fontStyle:'italic'},{token:'keyword',foreground:'F92672'},{token:'string',foreground:'E6DB74'},{token:'number',foreground:'AE81FF'},{token:'type',foreground:'66D9EF',fontStyle:'italic'},{token:'function',foreground:'A6E22E'}],colors:{'editor.background':'#272822','editor.foreground':'#F8F8F2','editor.lineHighlightBackground':'#3E3D32','editorCursor.foreground':'#F8F8F0','editor.selectionBackground':'#49483E','editorLineNumber.foreground':'#90908A'}});
    edEditor=monaco.editor.create(document.getElementById('monaco'),{ theme:'mk', fontFamily:"'JetBrains Mono',monospace", fontSize:13, minimap:{enabled:true}, automaticLayout:true, scrollBeyondLastLine:false, bracketPairColorization:{enabled:true}, mouseWheelZoom:true });
    edEditor.addCommand(monaco.KeyMod.CtrlCmd|monaco.KeyCode.KeyS, edSave);
    // restore previously-open tabs, then ensure the requested file is open + active
    var want=edLoadTabs(); if(want.indexOf(OPEN_FILE)<0) want.push(OPEN_FILE);
    var i=0; (function next(){ if(i>=want.length){ edActivate(OPEN_FILE in edModels?OPEN_FILE:Object.keys(edModels)[0]); return; } edAddTab(want[i], false, function(){ i++; next(); }); })();
  });
} catch(e){ edFellBack=true; }
// hard fallback if Monaco can't load at all
setTimeout(function(){ if(!edEditor && !window.monaco){ var ta=document.getElementById('edFallback'); document.getElementById('monaco').style.display='none'; ta.style.display='block'; fapi('file_load',{file:OPEN_FILE}).then(function(r){ if(r.ok){ ta.value=r.content; ta.readOnly=!r.writable; document.getElementById('edPath').textContent=OPEN_FILE; } }); window.edSave=function(){ fapi('file_save',{file:OPEN_FILE,content:ta.value}).then(function(r){ toast(r.ok?'Saved':r.error, r.ok?'':'e'); }); }; } }, 8000);
</script>
<?php }

function pageCron(): void {
    $list = sh('crontab -l 2>/dev/null');
    ?>
<?=helpBox('Scheduled tasks &amp; script runner', 'Two ways to automate: the <b>Script runner</b> below runs a script on a repeating interval (as fast as every 10 seconds) with <b>start/stop</b> and <b>live logs</b> - great for workers, queue processors and health checks. For classic time-of-day jobs (e.g. a 3am backup), use the <b>crontab</b> editor at the bottom.')?>
<div class="card">
  <h3>Script runner</h3>
  <div class="row" style="align-items:flex-end">
    <div style="flex:1;min-width:180px"><label>Name</label><input id="scLabel" placeholder="e.g. Queue worker"></div>
    <div style="flex:2;min-width:240px"><label>Script path</label><input id="scPath" class="mono" placeholder="/var/www/site/worker.php"></div>
    <div style="max-width:170px"><label>Run every</label>
      <select id="scEvery" onchange="document.getElementById('scCustom').style.display=this.value==='custom'?'':'none'">
        <option value="10">10 seconds</option><option value="30">30 seconds</option>
        <option value="60" selected>1 minute</option><option value="300">5 minutes</option>
        <option value="900">15 minutes</option><option value="3600">1 hour</option>
        <option value="custom">Custom (seconds)</option>
      </select>
    </div>
    <div id="scCustom" style="max-width:120px;display:none"><label>Seconds</label><input id="scSec" type="number" min="1" value="120"></div>
    <button class="btn btn-p" onclick="scAdd()">Add script</button>
  </div>
  <div class="xs muted mt">Runs as <b><?=h(cfgGet('web_user','www-data'))?></b>. Interpreter is picked from the extension (<span class="mono">.php .py .js .sh</span>). Scripts must live under <span class="mono">/var/www</span>, <span class="mono">/srv</span> or <span class="mono">/opt/orizen/data/scripts</span>.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:14px 18px 2px"><h3>Your scripts</h3></div>
  <table><thead><tr><th>Name</th><th>Script</th><th>Interval</th><th>Status</th><th style="width:230px">Actions</th></tr></thead>
  <tbody id="scList"><tr><td colspan="5" class="empty">Loading...</td></tr></tbody></table>
</div>
<div class="card" id="scLogCard" style="display:none">
  <div class="flex" style="justify-content:space-between;align-items:center"><h3 id="scLogTitle">Live log</h3>
    <span><label class="sw" style="vertical-align:middle"><input type="checkbox" id="scAuto" checked><span></span></label> <span class="xs muted">auto-refresh</span> <button class="btn btn-g btn-xs" onclick="scLogClose()">Close</button></span></div>
  <pre class="code" id="scLog" style="max-height:300px;overflow:auto;white-space:pre-wrap">-</pre>
</div>
<div class="card">
  <h3>Crontab (classic time-of-day jobs)</h3>
  <div class="alert alert-i sm">Format: <span class="mono">min hour day month weekday command</span>. Example - every night at 3am: <span class="mono">0 3 * * * php /var/www/site/cron.php</span></div>
  <textarea id="cronArea" style="min-height:150px" placeholder="# one job per line"><?=h($list)?></textarea>
  <div class="mt"><button class="btn btn-p" onclick="saveCron()">Save crontab</button></div>
</div>
<script>
function saveCron(){ api('console',{cmd:'printf %s '+JSON.stringify(cronArea.value)+' | crontab -'}).then(function(r){ toast(r.ok?'Crontab saved':'Failed', r.ok?'':'e'); }); }
function scEsc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function scFmtEvery(n){ n=+n; if(n<60)return 'every '+n+'s'; if(n<3600)return 'every '+(n/60)+'m'; return 'every '+(n/3600)+'h'; }
function scLoad(){ api('scripts_list',{}).then(function(r){ if(!r.ok)return; var t=document.getElementById('scList');
  if(!r.jobs.length){ t.innerHTML='<tr><td colspan="5" class="empty">No scripts yet - add one above.</td></tr>'; return; }
  t.innerHTML=r.jobs.map(function(j){ return '<tr><td><b>'+scEsc(j.label)+'</b></td><td class="xs mono muted">'+scEsc(j.path)+'</td><td class="xs">'+scFmtEvery(j.interval)+'</td>'
    +'<td>'+(j.running?'<span class="badge bg-green">running</span>':'<span class="badge bg-red">stopped</span>')+'</td>'
    +'<td class="flex" style="gap:5px">'
    +(j.running?'<button class="btn btn-g btn-xs" onclick="scCtl(\''+j.id+'\',\'stop\')">Stop</button>':'<button class="btn btn-p btn-xs" onclick="scCtl(\''+j.id+'\',\'start\')">Start</button>')
    +'<button class="btn btn-s btn-xs" onclick="scLogOpen(\''+j.id+'\',\''+scEsc(j.label)+'\')">Live log</button>'
    +'<button class="btn btn-d btn-xs" onclick="scDel(\''+j.id+'\',\''+scEsc(j.label)+'\')">Delete</button></td></tr>'; }).join(''); }); }
function scAdd(){ var lbl=document.getElementById('scLabel').value.trim(), path=document.getElementById('scPath').value.trim();
  var ev=document.getElementById('scEvery').value; var iv=ev==='custom'?parseInt(document.getElementById('scSec').value,10):parseInt(ev,10);
  if(!path){ toast('Enter the script path','e'); return; }
  api('script_save',{label:lbl,path:path,interval:iv}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){ document.getElementById('scLabel').value='';document.getElementById('scPath').value=''; scLoad(); } }); }
function scCtl(id,op){ api('script_ctl',{id:id,op:op}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); scLoad(); }); }
function scDel(id,l){ uiConfirm('Delete script "'+l+'"? It is stopped first.',function(){ api('script_del',{id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(SC_LOG_ID===id)scLogClose(); scLoad(); }); }); }
var SC_LOG_ID=null, SC_LOG_TIMER=null;
function scLogOpen(id,l){ SC_LOG_ID=id; document.getElementById('scLogTitle').textContent='Live log - '+l; document.getElementById('scLogCard').style.display=''; document.getElementById('scLogCard').scrollIntoView({block:'nearest'}); scLogTick(); if(SC_LOG_TIMER)clearInterval(SC_LOG_TIMER); SC_LOG_TIMER=setInterval(function(){ if(document.getElementById('scAuto').checked) scLogTick(); },2500); }
function scLogTick(){ if(!SC_LOG_ID)return; api('script_ctl',{id:SC_LOG_ID,op:'log',lines:200}).then(function(r){ if(!r.ok)return; var el=document.getElementById('scLog'); var atBottom=el.scrollTop+el.clientHeight>=el.scrollHeight-20; el.textContent=r.log||'(no output yet)'; if(atBottom)el.scrollTop=el.scrollHeight; }); }
function scLogClose(){ SC_LOG_ID=null; if(SC_LOG_TIMER){clearInterval(SC_LOG_TIMER);SC_LOG_TIMER=null;} document.getElementById('scLogCard').style.display='none'; }
scLoad(); setInterval(function(){ if(!SC_LOG_ID) scLoad(); }, 8000);
</script>
<?php }

function pageFirewall(): void { ?>
<?=helpBox('Firewall', 'The firewall controls which network ports are reachable from the internet. Web (80/443), SSH (22) and the panel port stay open automatically so you can never lock yourself out. Open a port only when an app needs it.')?>
<div class="card">
  <div class="flex" style="align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <div><h3 style="margin:0">Status: <span id="fwState" class="badge">checking...</span></h3>
      <div class="xs muted mt">When <b>active</b>, only the ports below are reachable. When off, every port is open.</div></div>
    <div class="flex" style="gap:6px"><button class="btn btn-p btn-xs" id="fwEnable" onclick="act('fw',{op:'enable'},true)" style="display:none">Enable firewall</button>
      <button class="btn btn-d btn-xs" id="fwDisable" onclick="uiConfirm('Turn the firewall OFF? Every port becomes reachable from the internet.',function(){act('fw',{op:'disable'},true);})" style="display:none">Disable</button></div>
  </div>
</div>
<div class="card">
  <h3>Open a port</h3>
  <div class="row" style="align-items:flex-end;gap:8px;flex-wrap:wrap">
    <div style="max-width:130px"><label>Port (1-65535)</label><input id="fwPort" inputmode="numeric" placeholder="8080"></div>
    <div style="max-width:120px"><label>Protocol</label><select id="fwProto"><option value="tcp">TCP</option><option value="udp">UDP</option><option value="both">TCP + UDP</option></select></div>
    <button class="btn btn-p" onclick="fwOpen()">Open port</button>
  </div>
  <div class="xs muted mt">Opening a port only lets traffic through the firewall - the app that uses it must also be running and listening on that port.</div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Open ports</h3></div>
  <div id="fwPorts" class="sm" style="padding:6px 18px 18px">Loading...</div>
</div>
<script>
function fwLoad(){ api('fw_info',{}).then(function(r){ if(!r.ok)return;
  var st=document.getElementById('fwState'); st.textContent=r.active?'active':'off'; st.className='badge '+(r.active?'bg-green':'bg-red');
  document.getElementById('fwEnable').style.display=r.active?'none':'';
  document.getElementById('fwDisable').style.display=r.active?'':'none';
  var el=document.getElementById('fwPorts');
  if(!r.ports.length){ el.innerHTML='<span class="muted">No ports explicitly opened'+(r.active?'':' (firewall is off - everything is reachable)')+'.</span>'; return; }
  el.innerHTML=r.ports.map(function(p){ var port=(''+p).split('/')[0];
    return '<div class="flex" style="justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border)"><span class="mono">'+String(p).replace(/[<>&]/g,'')+'</span><button class="btn btn-d btn-xs" onclick="fwClose(\''+port.replace(/[^0-9]/g,'')+'\')">Close</button></div>'; }).join('');
}); }
function fwOpen(){ var p=(document.getElementById('fwPort').value||'').trim(); if(!/^\d{1,5}$/.test(p)||+p<1||+p>65535){toast('Enter a port number 1-65535','e');return;}
  var pr=document.getElementById('fwProto').value;
  var protos = pr==='both' ? ['tcp','udp'] : [pr];
  var i=0; (function next(){ if(i>=protos.length){ toast('Port '+p+' opened'); document.getElementById('fwPort').value=''; fwLoad(); return; }
    api('fw',{op:'allow',port:p,proto:protos[i]}).then(function(r){ if(!r.ok){toast(r.error||'Failed','e');} i++; next(); }); })(); }
function fwClose(p){ if(!p)return; uiConfirm('Close port '+p+'? Apps using it will stop being reachable from the internet.',function(){ api('fw',{op:'delete-allow',port:p,proto:'tcp'}).then(function(r){ toast(r.ok?('Port '+p+' closed'):(r.error||'Failed'), r.ok?'':'e'); fwLoad(); }); }); }
fwLoad();
</script>
<?php }

function pageServices(): void {
    $svc = [['Apache (web)',webSvc(),webProc()],['MariaDB (database)',mariaSvc(),'mysqld'],['Postfix (mail-out)','postfix','master'],['Dovecot (mail-in)','dovecot','dovecot']];
    ?>
<?=helpBox('What is this?', 'These are the background programs that run your server. A green dot means it is running. If a website or email stops working, find the service here and click <b>Restart</b>. <b>Start</b> turns one on, <b>Stop</b> turns it off.')?>
<div class="grid">
<?php foreach($svc as [$label,$unit,$proc]): $on=svcActive($unit,$proc); ?>
  <div class="card">
    <div class="flex mb"><span class="dot <?=$on?'on':'off'?>"></span><b><?=h($label)?></b><span class="badge <?=$on?'bg-green':'bg-red'?>"><?=$on?'running':'stopped'?></span></div>
    <div class="flex wrap">
      <button class="btn btn-s btn-xs" onclick="act('service',{svc:'<?=$unit?>',act:'restart'},true)">Restart</button>
      <button class="btn btn-g btn-xs" onclick="act('service',{svc:'<?=$unit?>',act:'start'},true)">Start</button>
      <button class="btn btn-g btn-xs" onclick="act('service',{svc:'<?=$unit?>',act:'stop'},true)">Stop</button>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php }

function pageConsole(): void { ?>
<div class="card">
  <h3>Console <span class="muted xs">(runs as the web user - for root commands use SSH)</span></h3>
  <div class="row"><div><input id="cmd" class="mono" placeholder="e.g. ls -la /var/www" onkeydown="if(event.key==='Enter')runCmd()"></div><button class="btn btn-p" onclick="runCmd()">Run</button></div>
  <div class="code mt" id="cmdOut" style="min-height:260px;max-height:60vh">$ ready</div>
</div>
<script>
function runCmd(){ var c=document.getElementById('cmd').value; if(!c)return; var o=document.getElementById('cmdOut'); o.textContent='$ '+c+'\n...';
  api('console',{cmd:c}).then(function(r){ o.textContent='$ '+c+'\n'+(r.out||r.error||''); }); }
</script>
<?php }

function pageSettings(): void { ?>
<?=helpBox('What is this?', 'This is where you change your login password, see your server details, and turn on optional Cloudflare DNS automation. Type a new value and press <b>Save</b> / <b>Update</b> for each box.')?>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card">
    <h3>Change panel password</h3>
    <div class="mb"><label>Current password</label><input id="pCur" type="password"></div>
    <div class="mb"><label>New password</label><input id="pNew" type="password"></div>
    <button class="btn btn-p" onclick="act('set_password',{current:pCur.value,new:pNew.value})">Update</button>
  </div>
  <div class="card">
    <h3>Server</h3>
    <table><tbody>
      <tr><td class="muted">Public IP</td><td class="mono"><?=h(cfgGet('server_ip'))?></td></tr>
      <tr><td class="muted">Primary domain</td><td class="mono"><?=h(cfgGet('primary_domain') ?: '-')?></td></tr>
      <tr><td class="muted">Panel URL</td><td class="mono">https://<?=h(cfgGet('server_ip'))?>:<?=h(cfgGet('panel_port'))?></td></tr>
      <tr><td class="muted">PHP</td><td class="mono"><?=h(PHP_VERSION)?></td></tr>
      <tr><td class="muted">Web root</td><td class="mono"><?=h(cfgGet('webroot_base'))?></td></tr>
      <tr><td class="muted">Mail server</td><td><?=cfgGet('mail_enabled')?'<span class="badge bg-green">enabled</span>':'<span class="badge bg-red">disabled</span>'?></td></tr>
    </tbody></table>
  </div>
</div>
<div class="card">
  <h3>Two-factor authentication (2FA)</h3>
  <div class="sm muted mb">Add a second step to your admin login using an <b>authenticator app</b> and/or <b>Telegram</b> - turn on either or both. Strongly recommended before exposing the panel to the internet. A login <b>captcha</b> is always on. Turning a method off requires a valid code from it.</div>
  <div class="grid" style="grid-template-columns:1fr 1fr;gap:16px;align-items:start">
    <div><h4 style="margin:0 0 7px">Authenticator app</h4><div id="s2App" class="sm">Loading...</div></div>
    <div><h4 style="margin:0 0 7px">Telegram</h4><div id="s2Tg" class="sm">Loading...</div></div>
  </div>
</div>
<script>
function s2Esc(s){return (s==null?'':String(s)).replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
function s2Load(){ api('2fa_status',{}).then(function(r){ if(!r.ok)return;
  var a=document.getElementById('s2App');
  if(r.totp){ a.innerHTML='<span class="badge bg-green">enabled</span> An app code is required at login.<div class="mt"><button class="btn btn-d btn-xs" onclick="s2AppOff()">Turn off</button></div>'; }
  else { a.innerHTML='<div class="muted mb">Use Google Authenticator, Authy, 1Password, Microsoft Authenticator...</div><button class="btn btn-p" onclick="s2AppBegin()">Set up authenticator</button><div id="s2AppSetup"></div>'; }
  var g=document.getElementById('s2Tg');
  if(r.tg){ g.innerHTML='<span class="badge bg-green">enabled</span> A login code is sent to chat <span class="mono">'+s2Esc(r.tg_chat)+'</span>.<div class="mt"><button class="btn btn-d btn-xs" onclick="s2TgOff()">Turn off</button></div>'; }
  else { g.innerHTML='<div class="muted mb">Create a bot with <span class="mono">@BotFather</span>, press <b>Start</b> on it, then get your numeric chat ID (e.g. <span class="mono">@userinfobot</span>).</div><label>Bot token</label><input id="s2TgTok" placeholder="123456789:ABC-DEF..." autocomplete="off"><label class="mt">Your chat ID</label><input id="s2TgChat" placeholder="123456789" autocomplete="off"><div class="mt"><button class="btn btn-p" onclick="s2TgSend()">Send test code</button></div><div id="s2TgVerify"></div>'; }
}); }
function s2AppBegin(){ api('2fa_begin',{}).then(function(r){ if(!r.ok){toast(r.error||'Failed','e');return;}
  var qr = r.qr ? '<div style="width:172px;max-width:62vw;background:#fff;padding:8px;border-radius:8px;display:inline-block">'+r.qr+'</div>' : '<div class="xs muted">(QR unavailable - use the key below)</div>';
  document.getElementById('s2AppSetup').innerHTML='<hr class="sep"><div class="sm mb">1. Scan this QR code in your authenticator app:</div>'+qr+'<div class="xs muted mt">Can\'t scan? Enter this setup key: <span class="mono" style="font-size:13px;letter-spacing:1px">'+s2Esc(r.secret)+'</span></div><div class="sm mt">2. Enter the 6-digit code it shows:</div><div class="row"><input id="s2AppCode" inputmode="numeric" placeholder="123456" style="max-width:140px"><button class="btn btn-p" onclick="s2AppEnable()">Verify &amp; enable</button></div>';
}); }
function s2AppEnable(){ api('2fa_enable',{code:document.getElementById('s2AppCode').value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok)s2Load(); }); }
function s2AppOff(){ uiPrompt('Turn off authenticator 2FA','',function(code){ api('2fa_disable',{code:code}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok)s2Load(); }); },{body:'Enter a current 6-digit code from your authenticator app to confirm.',okText:'Turn off'}); }
function s2TgSend(){ var tok=document.getElementById('s2TgTok').value.trim(), chat=document.getElementById('s2TgChat').value.trim(); if(!tok||!chat){toast('Enter the bot token and chat ID','e');return;} toast('Sending test code...'); api('tg2fa_send',{token:tok,chat:chat}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok){ document.getElementById('s2TgVerify').innerHTML='<hr class="sep"><label>Enter the code we sent you</label><div class="row"><input id="s2TgCode" inputmode="numeric" placeholder="123456" style="max-width:140px"><button class="btn btn-p" onclick="s2TgEnable()">Verify &amp; enable</button></div>'; } }); }
function s2TgEnable(){ api('tg2fa_enable',{code:document.getElementById('s2TgCode').value}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); if(r.ok)s2Load(); }); }
function s2TgOff(){ api('tg2fa_disable',{step:'send'}).then(function(r){ if(!r.ok){toast(r.error||'Failed','e');return;} toast(r.msg); uiPrompt('Turn off Telegram 2FA','',function(code){ api('tg2fa_disable',{code:code}).then(function(rr){ toast(rr.ok?rr.msg:(rr.error||'Failed'), rr.ok?'':'e'); if(rr.ok)s2Load(); }); },{body:'We sent a code to your Telegram. Enter it to confirm turning it off.',okText:'Turn off'}); }); }
s2Load();
</script>
<div class="card">
  <h3>Cloudflare - automatic DNS + lifetime HTTPS</h3>
  <div class="sm muted mb">Connect one or more Cloudflare API tokens (one per Cloudflare account). Then adding a website - or a payment gateway - automatically points its DNS here, turns on the orange-cloud proxy, and gives it <b>free HTTPS for the life of the site</b>: no manual DNS, no certificate renewals.</div>
  <div class="row" style="align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:11px 14px;margin-bottom:12px">
    <div style="flex:1"><b class="sm">Cloudflare mode</b>
      <div class="xs muted">Cloudflare is always optional. In <b>Manual</b> mode every action still works - Orizen just shows you the DNS records to set yourself instead of doing it automatically.</div></div>
    <select id="cfMode" style="max-width:210px" onchange="cfSetMode(this.value)">
      <option value="auto"<?=cfgGet('cf_mode','auto')!=='manual'?' selected':''?>>Automatic (Cloudflare handles DNS/SSL/email)</option>
      <option value="manual"<?=cfgGet('cf_mode','auto')==='manual'?' selected':''?>>Manual (traditional - no automation)</option>
    </select>
  </div>
  <div id="cfAccts" class="mb"><div class="sm muted">Loading connected accounts...</div></div>
  <div class="row" style="align-items:flex-end">
    <div style="width:180px"><label>Name (any label)</label><input id="cfLabel" placeholder="e.g. Main account" autocomplete="off"></div>
    <div style="flex:1;min-width:220px"><label>Cloudflare API token</label><input id="cfTok" type="password" placeholder="paste token to connect" autocomplete="off"></div>
    <button class="btn btn-p" onclick="cfAddAccount()">Add account</button>
  </div>
  <div class="xs muted mt">Create a token at Cloudflare &gt; <b>My Profile &gt; API Tokens &gt; Create Custom Token</b> with <b>DNS&middot;Edit</b>, <b>Zone Settings&middot;Edit</b>, <b>SSL and Certificates&middot;Edit</b>, <b>Cache Purge&middot;Purge</b> and <b>Zone&middot;Read</b>. <a href="#" onclick="cfGuide();return false;"><b>Full step-by-step guide &rarr;</b></a></div>
</div>
<script>
function cfGuide(){ uiHtml(
 '<h3 style="margin-bottom:8px">Create a Cloudflare API token</h3>'
 +'<ol class="pg-steps" style="line-height:1.7">'
 +'<li>Sign in to <b>dash.cloudflare.com</b> and add your domain(s) to Cloudflare (Websites &rarr; Add a site), so its DNS is on Cloudflare.</li>'
 +'<li>Top-right avatar &rarr; <b>My Profile</b> &rarr; <b>API Tokens</b> (or go to <span class="mono">dash.cloudflare.com/profile/api-tokens</span>).</li>'
 +'<li>Click <b>Create Token</b> &rarr; scroll to the bottom &rarr; <b>Create Custom Token &rarr; Get started</b>.</li>'
 +'<li>Name it (e.g. <span class="mono">Orizen Panel</span>). Under <b>Permissions</b> add these rows (all under group <b>Zone</b>):'
 +'<div class="card" style="margin:8px 0;padding:10px 12px"><table><thead><tr><th>Group</th><th>Item</th><th>Access</th></tr></thead><tbody>'
 +'<tr><td>Zone</td><td>DNS</td><td>Edit</td></tr>'
 +'<tr><td>Zone</td><td>Zone Settings</td><td>Edit</td></tr>'
 +'<tr><td>Zone</td><td>SSL and Certificates</td><td>Edit</td></tr>'
 +'<tr><td>Zone</td><td>Cache Purge</td><td>Purge</td></tr>'
 +'<tr><td>Zone</td><td>Zone</td><td>Read</td></tr>'
 +'</tbody></table></div></li>'
 +'<li>Under <b>Zone Resources</b> choose <b>Include &rarr; All zones</b> (or pick specific zones).</li>'
 +'<li><b>Continue to summary &rarr; Create Token</b>. Copy the token <b>now</b> - it is shown only once.</li>'
 +'<li>Paste it into the <b>Cloudflare API token</b> field here and click <b>Add account</b>.</li>'
 +'</ol>'
 +'<div class="alert alert-w" style="margin-top:10px"><b>Keep it secret.</b> The token can edit your DNS - treat it like a password. Never share or commit it. You can <b>Roll</b> or <b>Delete</b> it anytime from the same API Tokens page; removing it here or in Cloudflare instantly revokes access.</div>'
 +'<div class="xs muted mt">DNS/Zone-Settings/SSL power the one-click DNS + HTTPS + email automation; Cache Purge enables cache controls on the Websites page.</div>'
 , true); }
</script>
<script>
function cfRenderAccts(){
  api('cf_accounts_list',{}).then(function(r){
    if(!r.ok) return;
    var el=document.getElementById('cfAccts'); if(!el) return;
    if(!r.accounts.length){ el.innerHTML='<div class="alert alert-i" style="margin:0">No Cloudflare accounts connected yet. Add one below for automatic DNS + lifetime HTTPS.</div>'; return; }
    el.innerHTML = r.accounts.map(function(a){
      var lbl=String(a.label||'Cloudflare').replace(/[<>&"]/g,'');
      return '<div class="row" style="align-items:center;justify-content:space-between;border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin-bottom:6px">'
        + '<span>&#9729;&#65039; <b>'+lbl+'</b> <span class="xs mono muted">'+a.hint+'</span></span>'
        + '<button class="btn btn-g" onclick="cfDelAccount(\''+a.id+'\')">Remove</button></div>';
    }).join('');
  });
}
function cfAddAccount(){
  var t=document.getElementById('cfTok').value.trim(), l=document.getElementById('cfLabel').value.trim();
  if(!t){ toast('Paste a Cloudflare API token','e'); return; }
  api('set_cf_account',{label:l, token:t}).then(function(r){
    toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e');
    if(r.ok){ document.getElementById('cfTok').value=''; document.getElementById('cfLabel').value=''; cfRenderAccts(); }
  });
}
function cfDelAccount(id){
  uiConfirm('Remove this Cloudflare account? Sites already set up keep working; you just cannot manage them from here anymore.',function(){
    api('del_cf_account',{id:id}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); cfRenderAccts(); });
  },{title:'Remove Cloudflare account',okText:'Remove'});
}
function cfSetMode(m){ api('set_cf_mode',{mode:m}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); }); }
cfRenderAccts();
</script>
<?php
  $mods = moduleMeta();
  // Work out WHERE each module shows up (which sidebar section + its menu label + slug),
  // so the card can tell the user exactly where to find it after enabling.
  $remap = ['gitdeploy'=>'APPS','oneclick'=>'APPS','staging'=>'APPS','migrate'=>'APPS','webtools'=>'APPS'];
  $modLoc = [];
  foreach ($GLOBALS['ORIZEN_MOD']['pages'] as $slug => $p) {
      $fk = $p['feature'] ?? '';
      $sec = strtoupper($remap[$slug] ?? ($p['section'] ?? 'SYSTEM'));
      $modLoc[$fk !== '' ? $fk : ('__'.$slug)] = ['section'=>$sec, 'title'=>$p['title'] ?? $slug, 'slug'=>$slug];
  }
?>
<div class="card">
  <h3>Modules &amp; add-ons</h3>
  <?php if (!$mods): ?>
    <div class="sm muted">No optional modules are installed yet. Add one under <span class="mono">panel/modules/</span> and it will appear here to switch on or off.</div>
  <?php else: ?>
    <div class="sm muted mb">Turn optional tools on or off. Enabling one adds its page to the sidebar (shown below) and opens it - it <b>never changes your existing sites</b>.</div>
    <div class="modgrid" id="featWrap">
    <?php foreach ($mods as $m): if (($m['key'] ?? '') === 'paygateway') continue; /* Crypto Gateway is a fixed core feature, not a toggleable module */ $fk = $m['feature'] ?? ''; $on = $fk ? feat($fk) : true; $loc = $modLoc[$fk] ?? null; ?>
      <div class="modcard <?=$on?'on':''?>">
        <div class="modcard-h">
          <b><?=h($m['name'] ?? $m['key'])?></b>
          <label class="sw" title="<?=$fk?'Enable / disable':'Always on'?>">
            <input type="checkbox" data-feat="<?=h($fk)?>" data-sec="<?=h($loc['section'] ?? '')?>" data-slug="<?=h($loc['slug'] ?? '')?>" <?=$on?'checked':''?> <?=$fk?'':'disabled'?> onchange="modToggle(this)"><span></span>
          </label>
        </div>
        <div class="modcard-d xs muted"><?=h($m['desc'] ?? '')?></div>
        <?php if ($loc): ?><div class="modcard-loc xs">Appears in&nbsp; <span class="modpill"><?=h($loc['section'])?></span> <span class="modarrow">&rarr;</span> <b><?=h($loc['title'])?></b><?php if($on):?> <a href="?page=<?=h($loc['slug'])?>" class="modopen">Open &rarr;</a><?php endif;?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<style>
.modgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.modcard{border:1px solid var(--border);border-radius:12px;padding:13px 14px;background:var(--card);transition:.15s;display:flex;flex-direction:column;gap:7px}
.modcard.on{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent-soft)}
.modcard-h{display:flex;align-items:center;justify-content:space-between;gap:10px}
.modcard-h b{font-size:13.5px;color:var(--text)}
.modcard-d{line-height:1.5}
.modcard-loc{display:flex;align-items:center;gap:6px;flex-wrap:wrap;color:var(--text2);margin-top:2px}
.modpill{font-size:10px;font-weight:700;letter-spacing:.5px;padding:2px 8px;border-radius:999px;background:var(--surface2);color:var(--text2)}
.modcard.on .modpill{background:var(--accent-soft);color:var(--accent)}
.modarrow{opacity:.6}
.modopen{margin-left:auto;font-weight:600}
.modcard .sw{position:relative;width:42px;height:23px;flex-shrink:0;display:inline-block}
.modcard .sw input{opacity:0;width:0;height:0}
.modcard .sw span{position:absolute;inset:0;background:var(--border2,#c7ccd8);border-radius:999px;cursor:pointer;transition:.2s}
.modcard .sw span:before{content:"";position:absolute;height:17px;width:17px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.modcard .sw input:checked+span{background:var(--accent)}
.modcard .sw input:checked+span:before{transform:translateX(19px)}
.modcard .sw input:disabled+span{opacity:.5;cursor:default}
</style>
<script>
function modToggle(cb){
  var f={}; document.querySelectorAll('#featWrap input[data-feat]').forEach(function(c){ var k=c.getAttribute('data-feat'); if(k) f[k]=c.checked?1:0; });
  api('set_features',f).then(function(r){
    if(!r.ok){ toast(r.error||'Failed','e'); cb.checked=!cb.checked; return; }
    if(cb.checked){
      // pre-expand the section so the newly added tool is visible after reload
      var sec=cb.getAttribute('data-sec'); if(sec){ try{ var st=JSON.parse(localStorage.getItem('navState3')||'{}'); st[sec]=false; localStorage.setItem('navState3',JSON.stringify(st)); }catch(e){} }
      var slug=cb.getAttribute('data-slug');
      toast('Enabled - opening it...');
      setTimeout(function(){ location.href = slug ? ('?page='+slug) : '?page=settings'; }, 650);
    } else {
      toast('Disabled.'); setTimeout(function(){ location.reload(); }, 650);
    }
  });
}
</script>
<div class="card"><h3>About</h3><div class="sm muted">Orizen Panel v<?=h(appVersion())?> . a self-hosted control panel for websites, domains, DNS, SSL, email and databases. Privileged actions run through <span class="mono">/usr/local/bin/orizen-helper</span> via a locked sudoers rule. Config in <span class="mono"><?=h(DATA_DIR)?></span>.</div>
  <div class="flex" style="gap:10px;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
    <?=orizenMark(26)?>
    <div style="line-height:1.35"><div class="xs muted" style="text-transform:uppercase;letter-spacing:1px">Developer</div><div style="font-weight:800"><?=h(ozAttr()['name'])?></div></div>
    <a class="btn btn-g btn-xs" style="margin-left:auto" href="<?=h(ozAttr()['url'])?>" target="_blank" rel="noopener">Facebook &rarr;</a>
  </div></div>
<?php }

// -- Logs ---------------------------------------------------
function pageLogs(): void { ?>
<div class="card">
  <div class="row">
    <div><label>Log file</label><select id="lgSel"></select></div>
    <div style="max-width:120px"><label>Lines</label><select id="lgLines"><option>100</option><option selected>200</option><option>1000</option><option>5000</option></select></div>
    <div><label>Filter (contains)</label><input id="lgFilter" placeholder="e.g. error, 404"></div>
    <button class="btn btn-p" onclick="lgView()">View</button>
    <button class="btn btn-g" onclick="lgDl()">Download</button>
    <button class="btn btn-d" onclick="lgClear()">Clear</button>
  </div>
</div>
<div class="card" style="padding:0"><div style="padding:14px 16px 0"><h3 id="lgName">Logs</h3></div>
<pre class="code" id="lgOut" style="margin:14px;max-height:62vh;overflow:auto">Select a log and click View.</pre></div>
<script>
function lgView(){ var p=document.getElementById('lgSel').value; if(!p)return; document.getElementById('lgOut').textContent=' loading...'; api('log_view',{path:p,lines:document.getElementById('lgLines').value,filter:document.getElementById('lgFilter').value}).then(function(r){ if(!r.ok){document.getElementById('lgOut').textContent=''+r.error;return;} document.getElementById('lgName').textContent=p; var o=document.getElementById('lgOut'); o.textContent=r.text&&r.text.trim()?r.text:'(empty)'; o.scrollTop=o.scrollHeight; }); }
function lgClear(){ var p=document.getElementById('lgSel').value; if(!p)return; uiConfirm('Clear this log file? Its contents will be erased.',function(){ act('log_clear',{path:p}).then(lgView); }); }
function lgDl(){ var b=new Blob([document.getElementById('lgOut').textContent],{type:'text/plain'}); var a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download='log.txt'; a.click(); }
api('log_list',{}).then(function(r){ var s=document.getElementById('lgSel'); if(!r.ok||!r.logs||!r.logs.length){ s.innerHTML='<option value="">no readable logs found</option>'; document.getElementById('lgOut').textContent='No logs found (or the helper is unavailable).'; return; } s.innerHTML=r.logs.map(function(l){return '<option value="'+l.path+'">'+l.name+'</option>';}).join(''); lgView(); });
</script>
<?php }

// -- Processes ----------------------------------------------
function pageProcesses(): void { ?>
<div class="card"><div class="flex"><h3 style="margin:0">Running processes (top by CPU)</h3><button class="btn btn-g btn-xs" onclick="procLoad()" style="margin-left:auto">Refresh</button></div></div>
<div class="card" style="padding:0"><table><thead><tr><th>PID</th><th>User</th><th>CPU%</th><th>Mem%</th><th>Command</th><th></th></tr></thead><tbody id="procBody"></tbody></table></div>
<script>
function procLoad(){ api('proc_list',{}).then(function(r){ if(!r.ok){document.getElementById('procBody').innerHTML='<tr><td colspan="6" class="empty">'+(r.error||'no data')+'</td></tr>';return;} document.getElementById('procBody').innerHTML=r.procs.map(function(p){ return '<tr><td class="mono">'+p.pid+'</td><td class="xs muted">'+p.user+'</td><td>'+p.cpu+'</td><td>'+p.mem+'</td><td class="mono xs">'+p.cmd+'</td><td><button class="btn btn-d btn-xs" onclick="uiConfirm(\'Stop process PID '+p.pid+'?\',function(){act(\'proc_kill\',{pid:'+p.pid+'}).then(procLoad)})"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button></td></tr>'; }).join('')||'<tr><td colspan="6" class="empty">none</td></tr>'; }); }
procLoad();
</script>
<?php }

// -- Disk Usage ---------------------------------------------
function pageBackups(): void {
    $sites = loadJson(SITES_FILE, []);
    $pdo = db(); $dbs = [];
    if ($pdo) { try { $sys=['mysql','information_schema','performance_schema','sys']; foreach($pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN) as $d) if(!in_array($d,$sys,true)) $dbs[]=$d; } catch(Exception $e){} }
    ?>
<?=helpBox('What is a backup?', 'A backup is a single file that contains a full copy of a website: its <b>whole public folder</b> (all files) plus the <b>databases</b> you tick. Make one before big changes, or <b>Download</b> it to your computer for safe-keeping. <b>Restore</b> puts a backup back onto the server (overwriting the current files and re-importing its databases).')?>
<div class="card">
  <h3>Create a backup</h3>
  <div class="row">
    <div><label>Website to back up</label>
      <select id="bkSite"><?php foreach($sites as $s):?><option value="<?=h($s['domain'])?>"><?=h($s['domain'])?> - <?=h($s['docroot'])?></option><?php endforeach; if(!$sites):?><option value="">- add a website first -</option><?php endif;?></select>
    </div>
    <button class="btn btn-p" onclick="bkCreate()">Create backup</button>
  </div>
  <div class="mt"><label>Databases to include (tick the one(s) this website uses)</label>
    <div class="flex wrap" style="gap:8px 18px">
      <?php foreach($dbs as $d):?><label class="flex" style="gap:7px;cursor:pointer;min-width:auto;align-items:center"><input type="checkbox" class="bkdb" value="<?=h($d)?>" style="width:auto"> <span class="sm mono"><?=h($d)?></span></label><?php endforeach;?>
      <?php if(!$dbs):?><span class="xs muted">No databases found.</span><?php endif;?>
    </div>
  </div>
  <div class="xs muted mt">The backup contains the site's full folder plus the selected databases. Large sites can take a little while. Backups are stored in <span class="mono"><?=h(BACKUP_DIR)?></span>.</div>
</div>
<div class="card">
  <h3>Import a backup from your computer</h3>
  <div class="sm muted mb">Already have an Orizen backup file (<span class="mono">.tar.gz</span> or <span class="mono">.zip</span>) saved on your PC? Upload it here and it will appear in the list below, ready to <b>Restore</b>. Large files upload in the background.</div>
  <div class="flex" style="gap:10px;align-items:center">
    <input type="file" id="bkFile" accept=".zip,.gz,.tgz,.tar.gz" style="flex:1">
    <button class="btn btn-p" id="bkImportBtn" onclick="bkImport()">Upload backup</button>
  </div>
  <div id="bkImportProg" class="xs muted mt" style="display:none"></div>
</div>
<div class="card" style="padding:0">
  <div style="padding:16px 18px 0"><h3>Your backups</h3></div>
  <div id="bkList"><div class="empty">Loading...</div></div>
</div>
<script>
function bkImport(){ var inp=document.getElementById('bkFile'); var f=inp.files&&inp.files[0]; if(!f){toast('Choose a backup file first','e');return;}
  if(!/\.(tar\.gz|tgz|zip)$/i.test(f.name)){toast('Only .tar.gz or .zip backup files','e');return;}
  var btn=document.getElementById('bkImportBtn'), prog=document.getElementById('bkImportProg'); btn.disabled=true; prog.style.display='block';
  var CH=2*1024*1024, chunks=Math.ceil(f.size/CH)||1, idx=0;
  (function send(){ if(idx>=chunks){return;} prog.textContent='Uploading '+f.name+'... '+Math.round(idx/chunks*100)+'%';
    var blob=f.slice(idx*CH,Math.min((idx+1)*CH,f.size)); var fd=new FormData();
    fd.append('action','backup_import'); fd.append('csrf',CSRF); fd.append('name',f.name); fd.append('chunk',idx); fd.append('chunks',chunks); fd.append('blob',blob);
    fetch('',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){
      if(!r.ok){ toast(r.error||'Upload failed','e'); btn.disabled=false; prog.style.display='none'; return; }
      idx++;
      if(r.done){ prog.textContent=''; prog.style.display='none'; btn.disabled=false; inp.value=''; toast(r.msg); bkLoad(); return; }
      send();
    }).catch(function(e){ toast(e.message,'e'); btn.disabled=false; prog.style.display='none'; });
  })();
}
function bkCreate(){ var site=document.getElementById('bkSite').value; if(!site){toast('Pick a website first','e');return;}
  var dbs=[...document.querySelectorAll('.bkdb:checked')].map(function(c){return c.value;}).join(',');
  toast('Creating backup - this can take a moment...');
  api('backup_create',{domain:site,dbs:dbs}).then(function(r){ if(r.ok){toast(r.msg);bkLoad();}else toast(r.error,'e'); });
}
function bkLoad(){ api('backup_list',{}).then(function(r){ var el=document.getElementById('bkList');
  if(!r.ok||!r.backups.length){ el.innerHTML='<div class="empty">No backups yet - create one above.</div>'; return; }
  var h='<table><thead><tr><th>Backup file</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
  r.backups.forEach(function(b){ var n=b.name.replace(/'/g,"\\'");
    h+='<tr><td class="mono xs">'+b.name+'</td><td class="xs muted">'+b.size+'</td><td class="xs muted">'+b.date+'</td><td class="flex" style="gap:5px"><a class="btn btn-g btn-xs" href="?dl='+encodeURIComponent(b.path)+'">Download</a><button class="btn btn-s btn-xs" onclick="bkRestore(\''+n+'\')">Restore</button><button class="btn btn-d btn-xs" onclick="bkDel(\''+n+'\')">Delete</button></td></tr>';
  });
  h+='</tbody></table>'; el.innerHTML=h;
}); }
function bkRestore(n){ uiConfirm('Restore "'+n+'"? This OVERWRITES the site\'s current files and re-imports its databases.',function(){ toast('Restoring...'); api('backup_restore',{name:n}).then(function(r){ if(r.ok)toast(r.msg); else toast(r.error,'e'); }); }); }
function bkDel(n){ uiConfirm('Delete the backup "'+n+'"?',function(){ api('backup_delete',{name:n}).then(function(r){ if(r.ok){toast(r.msg);bkLoad();}else toast(r.error,'e'); }); }); }
bkLoad();
</script>
<?php }

function pageDisk(): void { $base=cfgGet('webroot_base','/var/www'); ?>
<div class="card"><div class="row"><div><input id="duDir" value="<?=h($base)?>"></div><button class="btn btn-p" onclick="duScan()">Analyze</button><button class="btn btn-g" onclick="duUp()">Up</button></div></div>
<div class="card" style="padding:0"><div style="padding:14px 16px 0"><h3 id="duTotal">Breakdown</h3></div>
<table><thead><tr><th>Name</th><th style="width:120px">Size</th><th style="width:42%">Share</th></tr></thead><tbody id="duBody"></tbody></table></div>
<script>
var duParent='';
function duScan(){ document.getElementById('duBody').innerHTML='<tr><td colspan="3" class="empty"> scanning...</td></tr>'; api('disk_usage',{dir:document.getElementById('duDir').value}).then(function(r){ if(!r.ok){toast(r.error,'e');document.getElementById('duBody').innerHTML='';return;} duParent=r.parent; document.getElementById('duTotal').textContent='Total: '+r.total_h+'  -  '+r.dir; document.getElementById('duBody').innerHTML=r.items.map(function(it){ var nm=it.isDir?'<a href="#" onclick="document.getElementById(\'duDir\').value=this.dataset.p;duScan();return false" data-p="'+it.path+'">'+it.name+'</a>':''+it.name; return '<tr><td>'+nm+'</td><td class="sm">'+it.size_h+'</td><td><div style="background:var(--accent);border-radius:5px;height:8px;width:'+Math.max(it.pct,1)+'%"></div><span class="xs muted">'+it.pct+'%</span></td></tr>'; }).join('')||'<tr><td colspan="3" class="empty">empty</td></tr>'; }); }
function duUp(){ if(duParent){document.getElementById('duDir').value=duParent;duScan();} }
duScan();
</script>
<?php }

// -- Network Tools ------------------------------------------
function pageNetwork(): void { ?>
<div class="card">
  <div class="row"><div><label>Host / domain / IP</label><input id="nHost" value="google.com"></div><div style="max-width:100px"><label>Port</label><input id="nPort" placeholder="443"></div></div>
  <div class="flex wrap" style="margin-top:12px"><button class="btn btn-p btn-xs" onclick="nRun('ping')">Ping</button><button class="btn btn-s btn-xs" onclick="nRun('dns')">DNS Lookup</button><button class="btn btn-s btn-xs" onclick="nRun('trace')">Traceroute</button><button class="btn btn-s btn-xs" onclick="nRun('port')">Port check</button><button class="btn btn-g btn-xs" onclick="nRun('ipconfig')">Network info</button></div>
</div>
<div class="card" style="padding:0"><div style="padding:14px 16px 0"><h3>Result</h3></div><pre class="code" id="nOut" style="margin:14px;max-height:56vh;overflow:auto">Pick a tool above.</pre></div>
<script>
function nRun(t){ document.getElementById('nOut').textContent=' running...'; api('net_run',{tool:t,host:document.getElementById('nHost').value,port:document.getElementById('nPort').value}).then(function(r){ document.getElementById('nOut').textContent=r.ok?(r.output||'(no output)'):''+r.error; }); }
</script>
<?php }

// -- Security (.htaccess / privacy / IP block) --------------
function pageSecurity(): void { $base=cfgGet('webroot_base','/var/www'); ?>
<div class="card"><div class="row"><div><label>Directory to manage</label><input id="scDir" value="<?=h($base)?>"></div><button class="btn btn-p" onclick="scLoad()">Load</button><span class="sm muted" id="scStat"></span></div></div>
<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card"><h3>Password-protect this directory</h3><div class="sm muted mb">Visitors must sign in (Apache Basic-Auth - creates .htaccess + .htpasswd).</div>
    <div class="mb"><label>Username</label><input id="scUser"></div><div class="mb"><label>Password</label><input id="scPass" type="password"></div>
    <button class="btn btn-p" onclick="act('sec_protect',{dir:scDir.value,user:scUser.value,pass:scPass.value})">Protect directory</button></div>
  <div class="card"><h3>Block an IP address</h3><div class="sm muted mb">Deny a visitor IP or range, e.g. <span class="mono">203.0.113.5</span> or <span class="mono">203.0.113.0/24</span>.</div>
    <div class="mb"><label>IP / CIDR</label><input id="scIp"></div><button class="btn btn-d" onclick="act('sec_block_ip',{dir:scDir.value,ip:scIp.value})">Block IP</button></div>
</div>
<div class="card"><div class="flex"><h3 style="margin:0">.htaccess editor</h3><button class="btn btn-p btn-xs" onclick="act('sec_save',{dir:scDir.value,content:scHt.value})" style="margin-left:auto">Save</button></div>
<textarea id="scHt" style="min-height:220px;margin-top:10px" placeholder="# .htaccess for the selected directory"></textarea></div>
<script>
function scLoad(){ api('sec_load',{dir:document.getElementById('scDir').value}).then(function(r){ if(!r.ok){toast(r.error,'e');return;} document.getElementById('scDir').value=r.dir; document.getElementById('scHt').value=r.htaccess||''; document.getElementById('scStat').textContent=(r.exists?'.htaccess loaded':'no .htaccess yet')+(r.protected?' . protected':'')+(r.writable?'':' . read-only'); }); }
scLoad();
</script>
<?php }
