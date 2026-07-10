<?php
/*
 * Orizen module: Payment Gateway (Orizen Payment Gateway)
 *
 * Installs a self-hosted, multi-chain crypto payment gateway onto a chosen
 * domain/subdomain in one click: builds the lean Docker stack, reverse-proxies
 * it through Apache, gives it lifetime HTTPS via Cloudflare (orange-cloud), and
 * hands back a fresh, RANDOM admin login. All secrets are generated per install
 * and never stored in the panel - the password is shown once, at install time.
 */

const PAYGW_FILE = DATA_DIR . '/paygateway.json';
const PAYGW_SRC  = '/opt/orizen/apps/navixo-src';

// -- secret / identifier generation (never predictable) -----------------------
function pgHex(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function pgPass(int $len = 22): string {
    // Unambiguous base-58-ish alphabet (no 0/O/1/l/I) - strong and easy to copy.
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = ''; $max = strlen($a) - 1;
    for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, $max)];
    return $s;
}
function pgSlug(string $d): string { return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($d)), '_'); }
/** A random, non-obvious admin login local-part - never literally "admin"/"orizen". */
function pgAdminEmail(string $domain): string {
    $local = 'owner_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $realDomain = false;
    if (strpos($domain, '.') !== false) {
        $last = substr($domain, strrpos($domain, '.') + 1);
        if ($last !== '' && !ctype_digit($last)) $realDomain = true;
    }
    return $local . '@' . ($realDomain ? $domain : 'orizen.local');
}

// -- deployment registry (no secrets stored - only the login id + metadata) ---
function pgDeployments(): array { return loadJson(PAYGW_FILE, []); }
function pgSaveDeployments(array $d): void { saveJson(PAYGW_FILE, array_values($d)); }
function pgNextPort(): int {
    $used = [];
    foreach (pgDeployments() as $d) if (!empty($d['port'])) $used[(int)$d['port']] = true;
    $p = 8081; while (isset($used[$p])) $p++;
    return $p;
}
function pgDockerPresent(): bool { return trim(sh('command -v docker 2>/dev/null')) !== ''; }

/** Generate the full .env for one gateway (all secrets fresh + zero-storage RPC). */
function pgBuildEnv(string $domain, int $port, string $slug, string $adminEmail, string $adminPass, string $tronKey, string $ssoSecret): string {
    $lines = [
        'COMPOSE_PROJECT_NAME=navixo_' . $slug,
        'APP_DOMAIN=' . $domain,
        'NAVIXO_PORT=' . $port,
        '',
        'POSTGRES_USER=navixo',
        'POSTGRES_PASSWORD=' . pgHex(),
        'POSTGRES_DB=navixo',
        'POSTGRES_MAX_CONNECTIONS=100',
        'POSTGRES_SHARED_BUFFERS=128MB',
        '',
        'REDIS_PASSWORD=' . pgHex(),
        '',
        'JWT_ACCESS_SECRET=' . pgHex(),
        'JWT_REFRESH_SECRET=' . pgHex(),
        'APP_ENCRYPTION_KEY=' . pgHex(),
        '',
        'ADMIN_EMAIL=' . $adminEmail,
        'ADMIN_INITIAL_PASSWORD=' . $adminPass,
        '',
        '# Shared secret so the Orizen panel can SSO-login + reset the admin password.',
        'PANEL_SSO_SECRET=' . $ssoSecret,
        '',
        'SIGNER_HMAC_KEY=' . pgHex(),
        'SIGNER_KEYSTORE_PASSPHRASE=' . pgHex(),
        '',
        '# Zero-storage: every chain is read from an external endpoint (no local node).',
        'BITCOIN_ESPLORA_URL=https://mempool.space/api',
        'LITECOIN_ESPLORA_URL=https://litecoinspace.org/api',
        'ETH_RPC_URL=https://ethereum-rpc.publicnode.com',
        'XRPL_WS_URL=wss://xrplcluster.com',
        'TRON_HTTP_URL=https://api.trongrid.io',
        'TRON_API_KEY=' . $tronKey,
        '',
        '# RPC URLs are required by config validation but never called while the',
        '# matching *_ESPLORA_URL is set (BTC/LTC are detected via Esplora, zero storage).',
        'BITCOIN_RPC_URL=http://bitcoind:8332',
        'BITCOIN_RPC_USER=navixo',
        'BITCOIN_RPC_PASSWORD=' . pgHex(16),
        'LITECOIN_RPC_URL=http://litecoind:9332',
        'LITECOIN_RPC_USER=navixo',
        'LITECOIN_RPC_PASSWORD=' . pgHex(16),
        '',
    ];
    return implode("\n", $lines) . "\n";
}

// -- API: list deployments + live status --------------------------------------
function pgApiList(): array {
    $items = [];
    foreach (pgDeployments() as $d) {
        $dom = (string)($d['domain'] ?? ''); if ($dom === '') continue;
        $running = 0; $total = 0; $built = null;
        $r = helper('paygw-ctl', ['status', $dom]);
        if ($r['code'] === 0) {
            foreach (preg_split('/\r?\n/', trim((string)$r['out'])) as $line) {
                if (strpos($line, '|') === false) continue;
                $total++;
                if (stripos($line, 'running') !== false || stripos($line, ' up ') !== false || stripos($line, 'up ') === 0) $running++;
            }
        }
        $bl = helper('paygw-ctl', ['buildlog', $dom]);
        if ($bl['code'] === 0 && preg_match('/PAYGW_EXIT=(\d+)/', (string)$bl['out'], $m)) {
            $built = ((int)$m[1] === 0) ? 'ok' : 'failed';
        }
        if ($running >= 6) $status = 'running';
        elseif ($built === 'failed') $status = 'build failed';
        elseif ($built === null) $status = 'building';
        elseif ($running > 0) $status = 'starting';
        else $status = 'stopped';
        $items[] = [
            'domain' => $dom, 'port' => (int)($d['port'] ?? 0),
            'admin_email' => (string)($d['admin_email'] ?? ''),
            'url' => 'https://' . $dom, 'cf' => $d['cf'] ?? false,
            'created' => (string)($d['created'] ?? ''),
            'running' => $running, 'total' => $total, 'status' => $status,
        ];
    }
    $siteList = [];
    foreach (loadJson(SITES_FILE, []) as $s) { $dom = (string)($s['domain'] ?? ''); if ($dom !== '') $siteList[] = $dom; }
    return ['ok' => true, 'items' => $items, 'docker' => pgDockerPresent(),
            'cf' => cfConnected(), 'ip' => cfgGet('server_ip'), 'sites' => $siteList];
}

// -- API: system readiness (can this box run a gateway alongside the panel?) --
function pgApiSysinfo(): array {
    $memLine  = trim(sh("free -m 2>/dev/null | awk 'NR==2{print \$2\" \"\$7\" \"\$3}'"));
    $mp = preg_split('/\s+/', $memLine); $memTotal=(int)($mp[0]??0); $memAvail=(int)($mp[1]??0); $memUsed=(int)($mp[2]??0);
    $swap = (int)trim(sh("free -m 2>/dev/null | awk 'NR==3{print \$2}'"));
    $diskLine = trim(sh("df -m / 2>/dev/null | awk 'NR==2{print \$2\" \"\$4}'"));
    $dp = preg_split('/\s+/', $diskLine); $diskTotal=(int)($dp[0]??0); $diskAvail=(int)($dp[1]??0);
    $cpu = max(1, (int)trim(sh('nproc 2>/dev/null')));
    $docker = pgDockerPresent();
    $running = 0; foreach (pgDeployments() as $d) if (!empty($d['domain'])) $running++;
    // A lean gateway needs ~700MB RAM to run; the build peaks higher but uses swap.
    $headroom = $memAvail + $swap;
    $canRun = ($headroom >= 900) && ($diskAvail >= 3000);
    $suggest = [];
    if ($memTotal < 2048) $suggest[] = 'RAM is '.$memTotal.'MB. Enough for 1 gateway with swap; add RAM (2GB+) to run several busy gateways smoothly.';
    if ($swap < 1024) $suggest[] = 'Swap is '.$swap.'MB - the installer adds a 3GB swapfile automatically before building so the build won\'t run out of memory.';
    if ($diskAvail < 5000) $suggest[] = 'Free disk is '.round($diskAvail/1024,1).'GB. Docker images use ~2-3GB per gateway; keep 5GB+ free.';
    if (!$docker) $suggest[] = 'Docker is not installed yet - it will be installed automatically on your first gateway install.';
    if ($cpu < 2) $suggest[] = 'Single CPU core - fine for a low-traffic store; more cores help under load.';
    return ['ok'=>true,'mem_total'=>$memTotal,'mem_avail'=>$memAvail,'mem_used'=>$memUsed,'swap'=>$swap,
            'disk_total'=>$diskTotal,'disk_avail'=>$diskAvail,'cpu'=>$cpu,'docker'=>$docker,
            'running'=>$running,'can_run'=>$canRun,'headroom'=>$headroom,'suggest'=>$suggest];
}

// -- API: install a gateway on a domain ---------------------------------------
function pgApiInstall(): array {
    if (!is_file(HELPER)) return ['ok' => false, 'error' => 'This runs on the server via the privileged helper.'];
    @set_time_limit(0);
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!validDomain($domain)) return ['ok' => false, 'error' => 'Enter a valid domain or subdomain, e.g. pay.yoursite.com.'];
    $ip = (string)cfgGet('server_ip');
    if ($ip === '') return ['ok' => false, 'error' => 'This server has no public IP set (Settings). Cannot continue.'];
    if (!is_dir(PAYGW_SRC)) return ['ok' => false, 'error' => 'The Orizen Payment Gateway payload is missing on the server (' . PAYGW_SRC . ').'];
    foreach (pgDeployments() as $d) {
        if (($d['domain'] ?? '') === $domain) return ['ok' => false, 'error' => 'A payment gateway is already installed on ' . $domain . '.'];
    }
    $tronKey = trim($_POST['tron_key'] ?? '');
    if ($tronKey !== '' && !preg_match('/^[A-Za-z0-9-]{10,80}$/', $tronKey)) {
        return ['ok' => false, 'error' => 'That TronGrid API key looks invalid.'];
    }

    // Generate the fresh, random credentials + secrets and stage the .env.
    $port = pgNextPort();
    $slug = pgSlug($domain);
    $adminEmail = pgAdminEmail($domain);
    $adminPass = pgPass(22);
    $ssoSecret = pgHex(24);   // shared with the panel for SSO-login + password reset
    $env = pgBuildEnv($domain, $port, $slug, $adminEmail, $adminPass, $tronKey, $ssoSecret);
    $envfile = DATA_DIR . '/paygw-' . $slug . '.env';
    if (@file_put_contents($envfile, $env) === false) return ['ok' => false, 'error' => 'Could not stage the configuration.'];
    @chmod($envfile, 0600);

    // Kick off the deploy (docker install + swap + build all run detached).
    $r = helper('paygw-deploy', [$domain, (string)$port, $envfile]);
    if ($r['code'] !== 0) { @unlink($envfile); return ['ok' => false, 'error' => 'Deploy failed: ' . $r['out']]; }

    // Reverse-proxy the domain to the gateway's loopback port (works once built).
    $docroot = cfgGet('webroot_base', '/var/www') . '/' . $domain . '/public';
    $pr = helper('create-proxy-site', [$domain, $docroot, (string)$port]);
    if ($pr['code'] !== 0) return ['ok' => false, 'error' => 'Reverse proxy setup failed: ' . $pr['out']];

    // Cloudflare: orange-cloud + full HTTPS for the life of the site.
    $cfInfo = null; $cfErr = null;
    if (cfConnected()) {
        $cf = cfOnboardSite($domain, $ip);
        if ($cf['ok']) $cfInfo = $cf; else $cfErr = $cf['error'];
    }
    // Best-effort: issue a real Let's Encrypt origin cert so Cloudflare "Full (strict)"
    // is accepted and HTTPS is valid + auto-renewing. Runs once DNS points here (via CF
    // above); harmless if it can't validate yet - the self-signed cert stays in place.
    if ($cfInfo) {
        $email = (string)cfgGet('admin_email', 'admin@' . $domain);
        helper('paygw-ssl', [$domain, (string)$port, $email]);
    }

    // Register the site + deployment (no secrets stored).
    $sites = loadJson(SITES_FILE, []);
    $isSub = substr_count($domain, '.') >= 2;
    if (!array_filter($sites, fn($s) => ($s['domain'] ?? '') === $domain)) {
        $sites[] = ['domain' => $domain, 'docroot' => $docroot, 'ssl' => (bool)$cfInfo,
                    'sub' => $isSub, 'paygw' => true, 'created' => date('c')];
        saveJson(SITES_FILE, $sites);
    }
    $deps = pgDeployments();
    $deps[] = ['domain' => $domain, 'port' => $port, 'project' => 'navixo_' . $slug,
               'admin_email' => $adminEmail, 'docroot' => $docroot, 'sso_secret' => $ssoSecret,
               'cf' => $cfInfo ? ($cfInfo['zone'] ?? true) : false, 'created' => date('c')];
    pgSaveDeployments($deps);
    auditLog('paygw_install', $domain . ' :' . $port);

    return ['ok' => true, 'domain' => $domain, 'url' => 'https://' . $domain,
            'admin_email' => $adminEmail, 'admin_password' => $adminPass,
            'cf' => $cfInfo, 'cf_error' => $cfErr, 'ip' => $ip];
}

// -- API: start / stop / restart ----------------------------------------------
function pgApiCtl(): array {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    $op = $_POST['op'] ?? '';
    if (!validDomain($domain)) return ['ok' => false, 'error' => 'invalid domain'];
    if (!in_array($op, ['up', 'down', 'restart', 'build'], true)) return ['ok' => false, 'error' => 'bad action'];
    $r = helper('paygw-ctl', [$op, $domain]);
    if ($r['code'] !== 0) return ['ok' => false, 'error' => $r['out']];
    auditLog('paygw_' . $op, $domain);
    return ['ok' => true, 'msg' => 'Gateway on ' . $domain . ': ' . $op . '.'];
}

// -- API: remove --------------------------------------------------------------
function pgApiRemove(): array {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!validDomain($domain)) return ['ok' => false, 'error' => 'invalid domain'];
    // Only ever act on a domain we actually deployed a gateway to.
    $isDeployed = (bool)array_filter(pgDeployments(), fn($d) => strtolower((string)($d['domain'] ?? '')) === $domain);
    if (!$isDeployed) return ['ok' => false, 'error' => 'No payment gateway is installed on ' . $domain . '.'];

    // 1) Stop + delete the gateway's containers, database and keys.
    helper('paygw-remove', [$domain]);
    // 2) Forget the deployment record.
    pgSaveDeployments(array_values(array_filter(pgDeployments(), fn($d) => strtolower((string)($d['domain'] ?? '')) !== $domain)));

    // 3) Revert the domain to a NORMAL website - keep it listed in Websites, never delete
    //    it or its DNS. Rebuild the static vhost (replacing the reverse-proxy config) so it
    //    serves the default index page like a freshly created site, and re-assert HTTPS if
    //    it had it. Mirrors how removing a redirect reverts a domain cleanly.
    $sites = loadJson(SITES_FILE, []);
    $idx = -1; $site = null;
    foreach ($sites as $i => $s) {
        if (strtolower((string)($s['domain'] ?? '')) === $domain) { $idx = $i; $site = $s; break; }
    }
    $docroot = (string)($site['docroot'] ?? (cfgGet('webroot_base', '/var/www') . '/' . $domain . '/public'));
    helper('create-site', [$domain, $docroot]);                       // normal vhost + default index.html
    if (!empty($site['ssl'])) helper('site-ssl', [$domain, (string)cfgGet('admin_email', 'admin@' . $domain)]);
    if ($idx >= 0) {
        unset($sites[$idx]['paygw']);                                 // no longer a gateway, still a website
        $sites[$idx]['docroot'] = $docroot;
    } else {
        $sites[] = ['domain' => $domain, 'docroot' => $docroot, 'ssl' => false,
                    'sub' => substr_count($domain, '.') >= 2, 'created' => date('c')];
    }
    saveJson(SITES_FILE, array_values($sites));
    auditLog('paygw_remove', $domain);
    return ['ok' => true, 'msg' => 'Payment gateway removed from ' . $domain . '. It is a normal website again (default page) - manage it under Websites.'];
}

/** Server-to-server JSON POST to a gateway over its loopback port (no cert / Cloudflare in the way). */
function pgPostJson(int $port, string $path, array $body): ?array {
    if (!function_exists('curl_init') || $port <= 0) return null;
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
    $out = curl_exec($ch); curl_close($ch);
    return $out ? (json_decode($out, true) ?: null) : null;
}
function pgFindDeployment(string $domain): ?array {
    foreach (pgDeployments() as $d) if (($d['domain'] ?? '') === $domain) return $d;
    return null;
}
/** Panel SSO: exchange a signed request for a real gateway session so "Open" logs the admin in. */
function pgApiSso(): array {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!validDomain($domain)) return ['ok' => false, 'error' => 'invalid domain'];
    $rec = pgFindDeployment($domain);
    if (!$rec) return ['ok' => false, 'error' => 'Gateway not found.'];
    $secret = (string)($rec['sso_secret'] ?? '');
    if ($secret === '') return ['ok' => false, 'error' => 'Auto-login isn\'t set up for this gateway (it predates the feature). Reset the password below, then sign in once.'];
    $email = (string)($rec['admin_email'] ?? '');
    $ts = (string)round(microtime(true) * 1000);
    $sig = hash_hmac('sha256', $email . '.' . $ts, $secret);
    $resp = pgPostJson((int)($rec['port'] ?? 0), '/api/v1/auth/panel-token', ['email' => $email, 'ts' => $ts, 'sig' => $sig]);
    if (!$resp || empty($resp['accessToken'])) return ['ok' => false, 'error' => 'Auto-login failed - the gateway may still be starting. Try again in a moment.'];
    $b64 = rtrim(strtr(base64_encode((string)json_encode($resp)), '+/', '-_'), '=');
    return ['ok' => true, 'url' => 'https://' . $domain . '/#sso=' . $b64];
}
/** Panel-driven admin password reset - generates a fresh password and shows it once. */
function pgApiResetPw(): array {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!validDomain($domain)) return ['ok' => false, 'error' => 'invalid domain'];
    $rec = pgFindDeployment($domain);
    if (!$rec) return ['ok' => false, 'error' => 'Gateway not found.'];
    $secret = (string)($rec['sso_secret'] ?? '');
    if ($secret === '') return ['ok' => false, 'error' => 'Password reset isn\'t available for this gateway (it was installed before the feature).'];
    $email = (string)($rec['admin_email'] ?? '');
    $newPass = pgPass(22);
    $ts = (string)round(microtime(true) * 1000);
    $sig = hash_hmac('sha256', $email . '.' . $ts, $secret);
    $resp = pgPostJson((int)($rec['port'] ?? 0), '/api/v1/auth/panel-reset', ['email' => $email, 'ts' => $ts, 'sig' => $sig, 'newPassword' => $newPass]);
    if (!$resp || empty($resp['ok'])) return ['ok' => false, 'error' => 'Password reset failed - the gateway may still be starting. Try again shortly.'];
    auditLog('paygw_reset_pw', $domain);
    return ['ok' => true, 'email' => $email, 'password' => $newPass];
}

// -- Page ---------------------------------------------------------------------
function payGatewayPage(): void { ?>
<?= helpBox('Crypto Gateway', 'Install <b>Orizen Payment Gateway</b> - your own multi-chain crypto payment gateway (BTC, LTC, ETH, XRP, USDT-TRC20, USDC) - onto any domain or subdomain in one click. Orizen builds it, reverse-proxies it, secures it with <b>lifetime HTTPS via Cloudflare</b>, and gives you a fresh, random admin login. No blockchain nodes to run: every chain is read from an external endpoint (zero local storage).') ?>
<style>
.alert-w{background:#fef3c7;color:#92400e;border-color:#fde68a}
.pg-tbl{width:100%;border-collapse:collapse}
.pg-tbl th,.pg-tbl td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.pg-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text2)}
.pg-tbl td .btn{margin:2px 1px}
.spinner{display:inline-block;width:12px;height:12px;border:2px solid var(--border2,#ccc);border-top-color:var(--accent,#6d28d9);border-radius:50%;animation:pgspin .7s linear infinite;vertical-align:-2px}
@keyframes pgspin{to{transform:rotate(360deg)}}
.pg-steps{margin:0;padding-left:20px} .pg-steps li{margin:7px 0;line-height:1.55}
.pg-ready{display:flex;gap:10px;flex-wrap:wrap} .pg-stat{flex:1;min-width:130px;background:var(--surface2,rgba(125,125,160,.06));border:1px solid var(--border);border-radius:10px;padding:11px 13px}
.pg-stat .v{font-size:19px;font-weight:800} .pg-stat .k{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text2)}
.pg-ovl{position:fixed;inset:0;background:rgba(10,12,20,.55);display:flex;align-items:center;justify-content:center;z-index:200;padding:16px}
.pg-ovl-box{background:var(--card);border:1px solid var(--border);border-radius:14px;max-width:680px;width:100%;max-height:88vh;overflow:auto;padding:20px 22px;box-shadow:0 30px 80px rgba(0,0,0,.4)}
.pg-code{background:#0f1524;color:#e6edf6;border-radius:10px;padding:12px 14px;font-family:'JetBrains Mono',Consolas,monospace;font-size:12px;white-space:pre;overflow:auto;max-height:280px}
</style>

<div class="card">
  <h3>How it works - step by step</h3>
  <ol class="pg-steps">
    <li><b>Check server readiness</b> (below). Until you install a gateway, it runs <b>nothing</b> - zero CPU, zero RAM.</li>
    <li><b>Add the domain</b> in <a href="?page=websites">Websites</a> or <a href="?page=subdomains">Subdomains</a> (e.g. <span class="mono">pay.yoursite.com</span>). With Cloudflare connected it gets DNS + lifetime HTTPS automatically.</li>
    <li><b>Install the gateway</b> on that domain (the form below). Orizen builds the stack, reverse-proxies it, secures it, and shows you a fresh <b>random admin login once</b> - save it.</li>
    <li><b>Connect your store</b>: on the installed gateway click <b>Connect store</b> for ready-to-paste code (no-code button or full API), then create an API key inside the gateway.</li>
  </ol>
</div>

<div class="card">
  <h3>Server readiness</h3>
  <div id="pgReadyBody"><div class="sm muted"><span class="spinner"></span> Analyzing your server...</div></div>
</div>

<div class="card">
  <h3>Install a payment gateway</h3>
  <div id="pgStatus" class="xs muted mb"></div>
  <div class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap">
    <div style="flex:2;min-width:240px">
      <label>Domain or subdomain</label>
      <select id="pgDomain"><option value="">- loading your domains -</option></select>
    </div>
    <div style="flex:1;min-width:180px">
      <label>TronGrid API key <span class="muted">(optional)</span> <a href="#" onclick="pgTronGuide();return false;" class="xs">how to get one &rarr;</a></label>
      <input id="pgTron" placeholder="for USDT-TRC20 detection" autocomplete="off">
    </div>
    <button class="btn btn-p" id="pgGo" onclick="pgInstall()">Install gateway</button>
  </div>
  <div class="xs muted mt">Pick one of your added domains/subdomains. To use a new one, add it in <b>Websites</b> or <b>Subdomains</b> first (so Cloudflare knows about it), then it appears here. The build takes a few minutes on first install.</div>
</div>

<div id="pgCreds"></div>

<div class="card">
  <h3>Installed gateways</h3>
  <div id="pgList"><div class="sm muted">Loading...</div></div>
</div>

<div class="pg-ovl" id="pgConnOvl" onclick="if(event.target===this)pgConnClose()" style="display:none">
  <div class="pg-ovl-box">
    <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:8px"><h3 id="pgConnTitle" style="margin:0">Connect your store</h3><button class="btn btn-g btn-xs" onclick="pgConnClose()">Close</button></div>
    <div id="pgConnBody"></div>
  </div>
</div>

<script>
var PG_TIMER = null;
function pgEsc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

function pgRenderStatus(r){
  var bits = [];
  bits.push('Server IP: <b>'+pgEsc(r.ip||'not set')+'</b>');
  bits.push('Docker: '+(r.docker?'<b style="color:var(--ok,#16a34a)">installed</b>':'<b>will be installed on first deploy</b>'));
  bits.push('Cloudflare: '+(r.cf?'<b style="color:var(--ok,#16a34a)">connected</b> (automatic HTTPS)':'<b>not connected</b> - add a token in Settings for automatic HTTPS'));
  document.getElementById('pgStatus').innerHTML = bits.join(' &nbsp;&middot;&nbsp; ');
}

function pgBadge(st){
  var color = st==='running' ? 'var(--ok,#16a34a)' : (st==='build failed' ? 'var(--red,#dc2626)' : 'var(--muted)');
  var extra = (st==='building'||st==='starting') ? ' <span class="spinner"></span>' : '';
  return '<span style="font-weight:700;color:'+color+'">'+pgEsc(st)+'</span>'+extra;
}

function pgRenderList(items){
  if(!items.length){ document.getElementById('pgList').innerHTML='<div class="empty">No payment gateways yet. Install one above.</div>'; return; }
  var h = '<table class="pg-tbl"><thead><tr><th>Domain</th><th>Status</th><th>Admin login</th><th>Actions</th></tr></thead><tbody>';
  items.forEach(function(it){
    h += '<tr>';
    h += '<td><a href="'+pgEsc(it.url)+'" target="_blank" rel="noopener">'+pgEsc(it.domain)+'</a>'+(it.cf?' <span class="xs muted">CF</span>':'')+'</td>';
    h += '<td>'+pgBadge(it.status)+' <span class="xs muted">'+it.running+'/'+ (it.total||7) +'</span></td>';
    h += '<td class="mono xs">'+pgEsc(it.admin_email)+'</td>';
    h += '<td class="xs">'
       + '<button class="btn btn-p" onclick="pgOpen(\''+pgEsc(it.domain)+'\')" title="Sign in automatically">Open &#8599;</button> '
       + '<button class="btn btn-s" onclick="pgResetPw(\''+pgEsc(it.domain)+'\')">Change password</button> '
       + '<button class="btn btn-s" onclick="pgConnect(\''+pgEsc(it.domain)+'\',\''+pgEsc(it.url)+'\')">Connect store</button> '
       + '<button class="btn btn-s" onclick="pgCtl(\'restart\',\''+pgEsc(it.domain)+'\')">Restart</button> '
       + '<button class="btn btn-s" onclick="pgCtl(\'down\',\''+pgEsc(it.domain)+'\')">Stop</button> '
       + '<button class="btn btn-s" onclick="pgCtl(\'up\',\''+pgEsc(it.domain)+'\')">Start</button> '
       + '<button class="btn btn-g" onclick="pgRemove(\''+pgEsc(it.domain)+'\')">Remove</button>'
       + '</td>';
    h += '</tr>';
  });
  h += '</tbody></table>';
  document.getElementById('pgList').innerHTML = h;
}

function pgLoad(){
  return api('pg_list',{}).then(function(r){
    if(!r.ok) return;
    pgRenderStatus(r);
    pgRenderList(r.items);
    var pgSel=document.getElementById('pgDomain'), pgCur=pgSel.value, pgSites=r.sites||[];
    pgSel.innerHTML = pgSites.length ? pgSites.map(function(s){ return '<option value="'+pgEsc(s)+'">'+pgEsc(s)+'</option>'; }).join('') : '<option value="">- add a website or subdomain first -</option>';
    if(pgCur && pgSites.indexOf(pgCur)>=0) pgSel.value=pgCur;
    var building = r.items.some(function(it){ return it.status==='building'||it.status==='starting'; });
    if(building && !PG_TIMER){ PG_TIMER = setInterval(pgLoad, 8000); }
    if(!building && PG_TIMER){ clearInterval(PG_TIMER); PG_TIMER=null; }
  });
}

function pgTronGuide(){ uiHtml(
  '<h3 style="margin-bottom:8px">Get a free TronGrid API key</h3>'
 +'<div class="sm muted mb">Only needed to accept <b>USDT (TRC-20)</b>. It lifts TronGrid\'s anonymous rate limit so payment detection stays reliable. BTC/LTC/ETH/XRP work without it.</div>'
 +'<ol class="pg-steps" style="line-height:1.7">'
 +'<li>Go to <b>trongrid.io</b> and click <b>Get Started</b> (or open <span class="mono">dashboard.trongrid.io</span>).</li>'
 +'<li><b>Sign up</b> with your email and verify it, then sign in to the dashboard.</li>'
 +'<li>Open <b>API Keys</b> in the left menu &rarr; <b>Create API Key</b>. Give it a name (e.g. <span class="mono">Orizen</span>).</li>'
 +'<li>Leave the default <b>Mainnet</b> settings. Click <b>Create</b>.</li>'
 +'<li>Copy the <b>API Key</b> value (a UUID like <span class="mono">7b3a0f3-5dc2-...</span>).</li>'
 +'<li>Paste it into the <b>TronGrid API key</b> field here before installing the gateway.</li>'
 +'</ol>'
 +'<div class="alert alert-i" style="margin-top:10px">The free tier is plenty for most stores. Your key is stored only in this gateway\'s own <span class="mono">.env</span> and is never shared - Orizen never ships a hardcoded key.</div>'
 , true); }
function pgOpen(domain){
  // Open the popup synchronously (avoids blockers), then point it at the SSO url.
  var w = window.open('about:blank', '_blank');
  toast('Signing you in...');
  api('pg_sso',{domain:domain}).then(function(r){
    if(r.ok && r.url){ if(w){ w.location = r.url; } else { location.href = r.url; } }
    else { if(w) w.close(); toast(r.error||'Auto-login failed','e'); }
  }).catch(function(){ if(w)w.close(); toast('Auto-login failed','e'); });
}
function pgResetPw(domain){
  uiConfirm('Reset the admin password for '+domain+'? A brand-new password is generated and shown once.',function(){
    toast('Resetting password...');
    api('pg_reset_pw',{domain:domain}).then(function(r){
      if(!r.ok){ toast(r.error||'Reset failed','e'); return; }
      uiHtml('<h3 style="margin-bottom:8px">New admin password for '+pgEsc(domain)+'</h3>'
        +'<div class="alert alert-w">Save this now - it is shown only once and is never stored by Orizen.</div>'
        +'<div class="mt"><div class="xs muted">Login</div><div class="mono" style="margin-bottom:10px">'+pgEsc(r.email)+'</div>'
        +'<div class="xs muted">Password</div><div class="mono">'+pgEsc(r.password)+'</div></div>'
        +'<div class="mt"><button class="btn btn-s" onclick="pgCopyText(\''+pgEsc(r.password)+'\')">Copy password</button></div>', true);
    });
  },{title:'Reset admin password',okText:'Reset password'});
}
function pgInstall(){
  var dom = document.getElementById('pgDomain').value.trim().toLowerCase();
  var tron = document.getElementById('pgTron').value.trim();
  if(!dom){ toast('Enter a domain or subdomain','e'); return; }
  uiConfirm('Install a crypto payment gateway on '+dom+'? This builds the Docker stack (a few minutes) and generates a fresh admin login.',function(){
    var btn = document.getElementById('pgGo'); btn.disabled=true; btn.textContent='Starting...';
    document.getElementById('pgCreds').innerHTML='<div class="card"><div class="sm"><span class="spinner"></span> Setting up '+pgEsc(dom)+' - generating credentials, starting the build, configuring HTTPS...</div></div>';
    api('pg_install',{domain:dom, tron_key:tron}).then(function(r){
      btn.disabled=false; btn.textContent='Install gateway';
      if(!r.ok){ document.getElementById('pgCreds').innerHTML='<div class="card"><div class="alert alert-e">'+pgEsc(r.error||'Failed')+'</div></div>'; toast(r.error||'Failed','e'); return; }
      pgShowCreds(r);
      document.getElementById('pgDomain').value=''; document.getElementById('pgTron').value='';
      toast('Gateway installing on '+r.domain,'');
      pgLoad();
      if(!PG_TIMER) PG_TIMER = setInterval(pgLoad, 8000);
    }).catch(function(e){ btn.disabled=false; btn.textContent='Install gateway'; toast(String(e),'e'); });
  },{title:'Install payment gateway',okText:'Install',danger:false});
}

function pgShowCreds(r){
  var cf = r.cf ? ('HTTPS is being provisioned by Cloudflare (zone <b>'+pgEsc(r.cf.zone||'')+'</b>, orange-cloud). It goes live within a minute or two.')
                : (r.cf_error ? ('<b>Cloudflare:</b> '+pgEsc(r.cf_error)+' - point '+pgEsc(r.domain)+"'s DNS A record to <b>"+pgEsc(r.ip)+'</b> and enable proxy manually, or use Let\'s Encrypt in SSL.')
                              : ('Point '+pgEsc(r.domain)+"'s DNS A record to <b>"+pgEsc(r.ip)+'</b>. Connect Cloudflare in Settings for automatic lifetime HTTPS.'));
  var html = ''
    + '<div class="card" style="border:2px solid var(--accent,#6d28d9)">'
    + '<h3>Save these credentials now</h3>'
    + '<div class="alert alert-w">This admin password is shown <b>once</b> and is never stored. Copy it now.</div>'
    + '<div class="row" style="gap:24px;flex-wrap:wrap">'
    + '<div><div class="xs muted">Gateway URL</div><div class="mono"><a href="'+pgEsc(r.url)+'" target="_blank" rel="noopener">'+pgEsc(r.url)+'</a></div></div>'
    + '<div><div class="xs muted">Admin login</div><div class="mono" id="pgCE">'+pgEsc(r.admin_email)+'</div></div>'
    + '<div><div class="xs muted">Admin password</div><div class="mono" id="pgCP">'+pgEsc(r.admin_password)+'</div></div>'
    + '</div>'
    + '<div class="mt"><button class="btn btn-s" onclick="pgCopy(\''+pgEsc(r.admin_email)+'\')">Copy login</button> '
    + '<button class="btn btn-s" onclick="pgCopy(\''+pgEsc(r.admin_password)+'\')">Copy password</button></div>'
    + '<div class="xs muted mt">'+cf+' The gateway may show <i>502</i> for a few minutes while the containers build - that is normal on first install.</div>'
    + '</div>';
  document.getElementById('pgCreds').innerHTML = html;
}

function pgCopy(v){ if(navigator.clipboard){ navigator.clipboard.writeText(v); toast('Copied',''); } }

function pgCtl(op,domain){
  function go(){ api('pg_ctl',{op:op, domain:domain}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); pgLoad(); }); }
  if(op==='down'){ uiConfirm('Stop the gateway on '+domain+'? Payments will not be detected while it is down.',go,{title:'Stop gateway',okText:'Stop'}); }
  else { go(); }
}
function pgRemove(domain){
  uiConfirm('Remove the payment gateway from '+domain+'? This DELETES its containers, database and wallet keys - any crypto still held there is lost. The website itself stays and reverts to a normal page.',function(){
    uiConfirm('Are you absolutely sure? All wallet keys and balances for '+domain+' will be destroyed (withdraw any funds first). '+domain+' will remain as a normal website.',function(){
      api('pg_remove',{domain:domain}).then(function(r){ toast(r.ok?r.msg:(r.error||'Failed'), r.ok?'':'e'); document.getElementById('pgCreds').innerHTML=''; pgLoad(); });
    },{title:'Confirm destruction',okText:'Destroy everything'});
  },{title:'Remove gateway',okText:'Remove'});
}

function pgSysinfo(){
  api('pg_sysinfo',{}).then(function(r){
    var b=document.getElementById('pgReadyBody'); if(!b) return;
    if(!r.ok){ b.innerHTML='<div class="sm muted">Could not read server stats.</div>'; return; }
    var gb=function(mb){ return (mb/1024).toFixed(1)+' GB'; };
    var verdict = r.can_run
      ? '<div class="alert alert-ok" style="margin-bottom:12px"><b>Ready.</b> This server can run a payment gateway alongside Orizen Panel'+(r.running?(' - '+r.running+' already installed'):'')+'. Nothing runs until you install one.</div>'
      : '<div class="alert alert-e" style="margin-bottom:12px"><b>Resources are tight.</b> A gateway may be unstable here - see the notes below.</div>';
    var stats='<div class="pg-ready">'
      +'<div class="pg-stat"><div class="k">RAM</div><div class="v">'+gb(r.mem_total)+'</div><div class="xs muted">'+gb(r.mem_avail)+' free now</div></div>'
      +'<div class="pg-stat"><div class="k">Swap</div><div class="v">'+gb(r.swap)+'</div></div>'
      +'<div class="pg-stat"><div class="k">Disk free</div><div class="v">'+gb(r.disk_avail)+'</div></div>'
      +'<div class="pg-stat"><div class="k">CPU cores</div><div class="v">'+r.cpu+'</div></div>'
      +'<div class="pg-stat"><div class="k">Docker</div><div class="v">'+(r.docker?'Installed':'Auto')+'</div></div>'
      +'<div class="pg-stat"><div class="k">Gateways</div><div class="v">'+r.running+'</div></div>'
      +'</div>';
    var sug=(r.suggest&&r.suggest.length)?'<div class="xs muted" style="margin-top:12px"><b>Notes &amp; suggestions:</b><ul style="margin:6px 0 0;padding-left:18px">'+r.suggest.map(function(s){return '<li style="margin:3px 0">'+pgEsc(s)+'</li>';}).join('')+'</ul></div>':'';
    b.innerHTML=verdict+stats+sug;
  }).catch(function(){ var b=document.getElementById('pgReadyBody'); if(b) b.innerHTML='<div class="sm muted">Could not read server stats.</div>'; });
}
function pgStoreDomains(){ var o=[]; document.querySelectorAll('#pgDomain option').forEach(function(x){ if(x.value) o.push(x.value); }); return o; }
function pgConnClose(){ document.getElementById('pgConnOvl').style.display='none'; }
function pgCopyText(t){ if(navigator.clipboard){ navigator.clipboard.writeText(t); toast('Copied',''); } }
function pgConnect(domain, url){
  document.getElementById('pgConnTitle').textContent='Connect your store - '+domain;
  var others=pgStoreDomains().filter(function(d){ return d!==domain; });
  var php='<?php\n'
    +'// Orizen Payment Gateway - create an invoice and send the buyer to hosted checkout.\n'
    +'// 1) Open '+url+' -> API Keys -> create a key (copy the key AND secret).\n'
    +'// 2) Download orizen.php (gateway docs -> docs/integration) next to this file.\n'
    +'require __DIR__."/orizen.php";\n'
    +'$orizen = new Orizen("'+url+'", "YOUR_API_KEY", "YOUR_API_SECRET");\n\n'
    +'// In your checkout / payment section:\n'
    +'$invoice = $orizen->createInvoice([\n'
    +'  "fiatAmount"   => "19.99",\n'
    +'  "fiatCurrency" => "USD",\n'
    +'  "assetCode"    => "BTC",   // BTC, LTC, ETH, XRP, USDT_TRC20, USDC_ERC20\n'
    +'  "orderId"      => "ORDER-123",\n'
    +']);\n'
    +'header("Location: ".$invoice["checkoutUrl"]);   // hosted, mobile-friendly checkout\n';
  var btn='<a href="'+url+'/l/YOUR_LINK_ID" style="display:inline-block;padding:12px 22px;border-radius:10px;background:#111;color:#fff;font-weight:700;text-decoration:none">Pay with crypto</a>';
  var h='<div class="alert alert-i" style="margin-bottom:12px">Your gateway: <b><a href="'+url+'" target="_blank" rel="noopener">'+url+'</a></b>. The checkout is hosted here, so it works from <b>any</b> store or domain.</div>';
  if(others.length) h+='<div class="xs muted" style="margin-bottom:10px">Other sites you\'ve added that can use this gateway: <b>'+others.map(pgEsc).join(', ')+'</b>.</div>';
  h+='<h4 style="margin:14px 0 6px">Option A - No-code payment button (easiest)</h4>'
    +'<ol class="pg-steps"><li>In the gateway open <b>Payment Links</b> and create a link (fixed price, or let the buyer enter the amount).</li><li>Copy its URL, then paste this button into your store\'s payment section (replace YOUR_LINK_ID):</li></ol>'
    +'<div class="pg-code" id="pgBtnCode">'+pgEsc(btn)+'</div>'
    +'<div class="mt"><button class="btn btn-s btn-xs" onclick="pgCopyText(document.getElementById(\'pgBtnCode\').textContent)">Copy button HTML</button></div>'
    +'<h4 style="margin:18px 0 6px">Option B - Full API (dynamic cart totals)</h4>'
    +'<ol class="pg-steps"><li>In the gateway open <b>API Keys</b> -> create a key (copy key + secret, shown once).</li><li>Drop <span class="mono">orizen.php</span> (from the gateway docs) beside your code, then:</li></ol>'
    +'<div class="pg-code" id="pgPhpCode">'+pgEsc(php)+'</div>'
    +'<div class="mt"><button class="btn btn-p btn-xs" onclick="pgCopyText(document.getElementById(\'pgPhpCode\').textContent)">Copy PHP</button> '
    +'<a class="btn btn-s btn-xs" href="'+url+'" target="_blank" rel="noopener">Open gateway dashboard</a></div>';
  document.getElementById('pgConnBody').innerHTML=h;
  document.getElementById('pgConnOvl').style.display='flex';
}
pgSysinfo();
pgLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key' => 'paygateway', 'name' => 'Crypto Gateway',
                    'desc' => 'Install a self-hosted multi-chain crypto payment gateway (Orizen Payment Gateway) onto a domain in one click, with lifetime HTTPS via Cloudflare.'],
        'pages' => ['paygateway' => ['title' => 'Crypto Gateway', 'section' => 'PAYMENT', 'render' => 'payGatewayPage']],
        'api'   => ['pg_list' => 'pgApiList', 'pg_sysinfo' => 'pgApiSysinfo', 'pg_install' => 'pgApiInstall', 'pg_ctl' => 'pgApiCtl', 'pg_remove' => 'pgApiRemove', 'pg_sso' => 'pgApiSso', 'pg_reset_pw' => 'pgApiResetPw'],
    ]);
}
