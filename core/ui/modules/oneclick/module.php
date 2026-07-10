<?php
/*
 * Orizen module: One-Click Apps
 * WordPress installs fully automatically (download core, create DB + credentials,
 * write wp-config, set permissions). Other stacks get a ready site + database +
 * exact next-step command (they need the Runtime/Docker module to finish).
 */
function ocApps(): array {
    return [
        'wordpress'  =>['WordPress','Blog / CMS - fully automatic (PHP + MySQL).','full'],
        'woocommerce'=>['WooCommerce','WordPress + the WooCommerce store plugin - fully automatic.','full'],
        'drupal'   =>['Drupal','CMS - site + database created, finish setup in the browser.','php'],
        'joomla'   =>['Joomla','CMS - site + database created, finish setup in the browser.','php'],
        'laravel'  =>['Laravel','PHP framework - needs Composer (Runtime module).','composer'],
        'nextjs'   =>['Next.js','Node app - needs Node (Runtime/Docker module).','node'],
        'ghost'    =>['Ghost','Node blog - needs Node (Runtime/Docker module).','node'],
        'nodeapp'  =>['Node app','Blank Node project - needs Node runtime.','node'],
        'pythonapp'=>['Python app','Blank Python/WSGI project - needs Python runtime.','python'],
        'dockerapp'=>['Docker app','Deploy from a Compose file - needs the Docker module.','docker'],
    ];
}
function ocSlug(string $s): string { $s = strtolower(preg_replace('/[^a-z0-9.-]+/','-', $s)); return trim($s,'-'); }
function ocCreateDb(string $prefix): array {
    $pdo = db(); if (!$pdo) throw new Exception('Database is not available.');
    $n = $prefix.'_'.substr(bin2hex(random_bytes(4)),0,8); $u = $n; $p = bin2hex(random_bytes(9));
    $pdo->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE USER '$u'@'localhost' IDENTIFIED BY '".str_replace("'","''",$p)."'");
    $pdo->exec("GRANT ALL PRIVILEGES ON `$n`.* TO '$u'@'localhost'"); $pdo->exec("FLUSH PRIVILEGES");
    return ['name'=>$n,'user'=>$u,'pass'=>$p,'host'=>'localhost'];
}
function ocMakeSite(string $domain): array {
    $base = cfgGet('webroot_base','/var/www'); $docroot = "$base/$domain/public";
    if (is_dir($docroot) && count(@scandir($docroot) ?: []) > 2) throw new Exception('A site folder already exists for that domain.');
    $r = helper('create-site', [$domain, $docroot]); if (($r['code'] ?? 1) !== 0) throw new Exception('Could not create site: '.trim((string)$r['out']));
    if (cfgGet('cf_token')) { $cf = function_exists('cfSetA') ? cfSetA($domain, cfgGet('server_ip')) : ['ok'=>true]; }
    $sites = loadJson(SITES_FILE, []); if (!array_filter($sites, fn($s)=>($s['domain']??'')===$domain)) { $sites[] = ['domain'=>$domain,'docroot'=>$docroot,'ssl'=>false,'created'=>date('c')]; saveJson(SITES_FILE,$sites); }
    return ['docroot'=>$docroot];
}
function ocFetchExtract(string $url, string $docroot, string $stripDir = ''): void {
    $tmp = sys_get_temp_dir().'/ozapp-'.bin2hex(random_bytes(4));
    @mkdir($tmp, 0775, true);
    @exec('curl -fsSL --max-time 300 -o '.escapeshellarg($tmp.'/pkg.tar.gz').' '.escapeshellarg($url).' 2>&1', $o, $rc);
    if ($rc !== 0) { throw new Exception('Download failed.'); }
    @exec('tar -xzf '.escapeshellarg($tmp.'/pkg.tar.gz').' -C '.escapeshellarg($tmp).' 2>&1', $o2, $rc2);
    if ($rc2 !== 0) { throw new Exception('Extract failed.'); }
    $src = $stripDir !== '' ? $tmp.'/'.$stripDir : $tmp;
    @exec('cp -a '.escapeshellarg($src.'/.').' '.escapeshellarg($docroot.'/').' 2>&1');
    @exec('rm -rf '.escapeshellarg($tmp));
}
function ocFetchZip(string $url, string $destDir): void {
    $tmp = sys_get_temp_dir().'/ozzip-'.bin2hex(random_bytes(4)).'.zip';
    @exec('curl -fsSL --max-time 300 -o '.escapeshellarg($tmp).' '.escapeshellarg($url).' 2>&1', $o, $rc);
    if ($rc !== 0) throw new Exception('Download failed.');
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) { @unlink($tmp); throw new Exception('Bad archive.'); }
        @mkdir($destDir, 0775, true); $z->extractTo($destDir); $z->close();
    } else {
        @exec('cd '.escapeshellarg($destDir).' && unzip -o '.escapeshellarg($tmp).' 2>&1');
    }
    @unlink($tmp);
}
function ocWpConfig(string $docroot, array $db): void {
    $sample = $docroot.'/wp-config-sample.php'; $cfgf = $docroot.'/wp-config.php';
    $c = is_file($sample) ? file_get_contents($sample) : "<?php\n";
    $c = str_replace(['database_name_here','username_here','password_here','localhost'], [$db['name'],$db['user'],$db['pass'],$db['host']], $c);
    $salt = httpGet('https://api.wordpress.org/secret-key/1.1/salt/', 8);
    if ($salt && strpos($salt,'AUTH_KEY') !== false) {
        $c = preg_replace('/define\(\s*\'AUTH_KEY\'.*?\'NONCE_SALT\'[^\n]*\n/s', $salt."\n", $c);
    }
    @file_put_contents($cfgf, $c);
}
function ocApiList(): array { $a = []; foreach (ocApps() as $k=>$v) $a[] = ['key'=>$k,'name'=>$v[0],'desc'=>$v[1],'kind'=>$v[2]]; return ['ok'=>true,'apps'=>$a]; }
function ocApiInstall(): array {
    if (!is_file(HELPER)) return ['ok'=>false,'error'=>'Runs on the server via the helper.'];
    @set_time_limit(0);
    $app = (string)($_POST['app'] ?? ''); $apps = ocApps(); if (!isset($apps[$app])) return ['ok'=>false,'error'=>'Unknown app.'];
    $mode = (($_POST['target'] ?? 'new') === 'existing') ? 'existing' : 'new';
    try {
        $kind = $apps[$app][2];
        if ($mode === 'existing') {
            // Select Website -> Select App -> Install : deploy straight into an existing site's folder.
            $domain = strtolower(trim((string)($_POST['site'] ?? '')));
            $rec = null; foreach (loadJson(SITES_FILE, []) as $s) if (($s['domain'] ?? '') === $domain) { $rec = $s; break; }
            if (!$rec) return ['ok'=>false,'error'=>'Pick an existing website to install into.'];
            $docroot = (string)$rec['docroot'];
            if (!is_dir($docroot)) return ['ok'=>false,'error'=>'That website has no folder on disk.'];
            // App stacks that own the whole site (WordPress/CMS) need an otherwise-empty root.
            $extra = count(array_diff(@scandir($docroot) ?: [], ['.','..','index.html','index.php','.htaccess']));
            if ($extra > 0 && in_array($kind, ['full','php'], true)) return ['ok'=>false,'error'=>'That website already has files. Pick an empty site (just the default page) or create a new one.'];
        } else {
            $domain = ocSlug((string)($_POST['domain'] ?? '')); if (!preg_match('/^[a-z0-9.-]{2,80}$/',$domain)) return ['ok'=>false,'error'=>'Enter a domain or name (letters, numbers, dashes, dots).'];
            $site = ocMakeSite($domain); $docroot = $site['docroot'];
        }
        if ($app === 'wordpress' || $app === 'woocommerce') {
            @array_map('unlink', glob($docroot.'/index.html') ?: []);   // clear the default landing page
            $db = ocCreateDb('wp'); ocFetchExtract('https://wordpress.org/latest.tar.gz', $docroot, 'wordpress'); ocWpConfig($docroot, $db);
            $woo = '';
            if ($app === 'woocommerce') {
                try { @mkdir($docroot.'/wp-content/plugins', 0775, true); ocFetchZip('https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip', $docroot.'/wp-content/plugins'); $woo = ' WooCommerce is pre-installed - just activate it in Plugins after setup.'; }
                catch (Exception $e) { $woo = ' (Add WooCommerce from Plugins - the auto-download was blocked.)'; }
            }
            helper('perm-repair', [$docroot, cfgGet('web_user','www-data')]);
            auditLog('oneclick_install',$app.' '.$domain);
            return ['ok'=>true,'msg'=>ucfirst($app==='woocommerce'?'WooCommerce (WordPress)':'WordPress').' installed for '.$domain.'. Open the site to finish the 5-minute setup.'.$woo,'db'=>['name'=>$db['name'],'user'=>$db['user']]];
        }
        if ($kind === 'php') {
            $db = ocCreateDb(substr($app,0,3));
            $url = $app === 'joomla' ? 'https://downloads.joomla.org/cms/joomla5/5-2-1/Joomla_5-2-1-Stable-Full_Package.tar.gz' : '';
            if ($app === 'drupal') { @exec('curl -fsSL --max-time 60 https://www.drupal.org/download-latest/tar.gz -o /dev/null 2>&1'); }
            $note = 'Site and database created. Database: '.$db['name'].' / user '.$db['user'].' / pass '.$db['pass'].' (host localhost). ';
            if ($url) { try { ocFetchExtract($url, $docroot); helper('perm-repair',[$docroot,cfgGet('web_user','www-data')]); $note .= 'Files downloaded - open the site in a browser to finish setup.'; } catch (Exception $e) { $note .= 'Download the '.$apps[$app][0].' package into '.$docroot.' and finish in the browser.'; } }
            else { $note .= 'Install '.$apps[$app][0].' into '.$docroot.' (or via Composer) and use these DB credentials.'; }
            return ['ok'=>true,'msg'=>$note];
        }
        // runtime-dependent stacks: prepare the site + starter + db, show the command
        $db = ($kind==='node'||$kind==='python') ? null : ocCreateDb(substr($app,0,3));
        @file_put_contents($docroot.'/index.html', '<html><body style="font-family:system-ui"><h1>'.$apps[$app][0].' site ready</h1><p>Finish installing this app on the server, then replace this page.</p></body></html>');
        $cmds = [
            'laravel'=>'composer create-project laravel/laravel '.$docroot,
            'nextjs'=>'npx create-next-app@latest '.$docroot,
            'ghost'=>'ghost install --dir '.$docroot,
            'nodeapp'=>'cd '.$docroot.' && npm init -y',
            'pythonapp'=>'cd '.$docroot.' && python3 -m venv venv',
            'dockerapp'=>'put a docker-compose.yml in '.$docroot.' then use the Docker module',
        ];
        $note = 'Site created at '.$docroot.'. '.($db?('Database '.$db['name'].' / '.$db['user'].' / '.$db['pass'].'. '):'').'Next step: '.($cmds[$app] ?? '').' (enable the Runtime/Docker module to run this from the panel).';
        return ['ok'=>true,'msg'=>$note];
    } catch (Exception $e) {
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}

function oneClickPage(): void {
    $sites = array_values(array_filter(loadJson(SITES_FILE, []), fn($s)=>empty($s['staging'])));
    ?>
<?=helpBox('One-click apps', 'Install a ready-to-use app in three steps: <b>pick a website</b> (an existing one, or create a new one), <b>pick an app</b>, and click Install. <b>WordPress &amp; WooCommerce install completely automatically</b> - Orizen downloads them, creates the database + credentials, writes the config and sets permissions.')?>
<div class="card">
  <h3>Install an app</h3>
  <div class="row" style="align-items:flex-end">
    <div style="max-width:220px"><label>Install into</label>
      <select id="ocTarget" onchange="ocTargetChange()">
        <option value="existing">An existing website</option>
        <option value="new">A new website</option>
      </select>
    </div>
    <div id="ocSiteWrap" style="flex:2;min-width:220px"><label>Website</label>
      <select id="ocSite">
        <?php foreach($sites as $s): ?><option value="<?=h($s['domain'])?>"><?=h($s['domain'])?></option><?php endforeach; ?>
        <?php if(!$sites): ?><option value="">(no websites yet - choose "A new website")</option><?php endif; ?>
      </select>
    </div>
    <div id="ocDomainWrap" style="flex:2;min-width:200px;display:none"><label>New domain / site name</label><input id="ocDomain" placeholder="blog.example.com"></div>
    <div style="min-width:170px"><label>App</label><select id="ocApp"></select></div>
    <button class="btn btn-p" onclick="ocGo()">Install</button>
  </div>
  <div class="xs muted mt" id="ocDesc"></div>
</div>
<div id="ocOut"></div>
<script>
var OCAPPS=[];
function ocTargetChange(){ var ex=document.getElementById('ocTarget').value==='existing'; document.getElementById('ocSiteWrap').style.display=ex?'':'none'; document.getElementById('ocDomainWrap').style.display=ex?'none':''; }
function ocFillDesc(){ var a=OCAPPS.filter(function(x){return x.key===document.getElementById('ocApp').value;})[0]; document.getElementById('ocDesc').textContent=a?a.desc:''; }
function ocGo(){ var app=document.getElementById('ocApp').value, mode=document.getElementById('ocTarget').value;
  var data={app:app,target:mode};
  if(mode==='existing'){ var site=document.getElementById('ocSite').value; if(!site){toast('Pick a website (or choose "A new website")','e');return;} data.site=site; }
  else { var dom=document.getElementById('ocDomain').value.trim(); if(!dom){toast('Enter a domain/name','e');return;} data.domain=dom; }
  document.getElementById('ocOut').innerHTML='<div class="card"><div class="sm"><span class="spinner"></span> Installing '+app+'... this can take a minute (downloading files).</div></div>';
  api('oc_install',data).then(function(r){ toast(r.ok?'Done':(r.error||'Failed'), r.ok?'':'e'); document.getElementById('ocOut').innerHTML='<div class="card"><div class="alert '+(r.ok?'alert-ok':'alert-e')+'">'+(r.ok?r.msg:(r.error||'Failed'))+'</div></div>'; }); }
api('oc_list',{}).then(function(r){ if(!r.ok)return; OCAPPS=r.apps; document.getElementById('ocApp').innerHTML=r.apps.map(function(a){return '<option value="'+a.key+'">'+a.name+'</option>';}).join(''); ocFillDesc(); });
document.getElementById('ocApp').addEventListener('change',ocFillDesc);
ocTargetChange();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'oneclick','name'=>'One-Click Apps','desc'=>'Install WordPress automatically; prepare site + database for Laravel, Next.js, Ghost, Drupal, Joomla and more.','feature'=>'enableOneClick'],
        'pages' => ['oneclick'=>['title'=>'One-Click Apps','section'=>'DOMAINS','feature'=>'enableOneClick','render'=>'oneClickPage']],
        'api'   => ['oc_list'=>'ocApiList','oc_install'=>'ocApiInstall'],
    ]);
}
