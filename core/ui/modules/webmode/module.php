<?php
/*
 * Orizen module: Web Server Modes
 * Apache stays the default. This shows the current web server and can generate
 * an Nginx reverse-proxy config (Nginx in front of Apache) for a site. Switching
 * the live server is an advanced, opt-in step - Orizen generates the config for you.
 */
function wmApiStatus(): array {
    return ['ok'=>true, 'current'=>webSvc(),
        'nginx'=>(is_file('/usr/sbin/nginx') || is_file('/usr/bin/nginx')),
        'openlitespeed'=>is_dir('/usr/local/lsws'),
        'sites'=>array_map(fn($s)=>['domain'=>$s['domain'] ?? '','docroot'=>$s['docroot'] ?? ''], loadJson(SITES_FILE, []))];
}
function wmApiNginx(): array {
    $d = strtolower(trim((string)($_POST['domain'] ?? ''))); if (!preg_match('/^[a-z0-9.-]+$/',$d)) return ['ok'=>false,'error'=>'Enter a domain.'];
    $cfg = "# Nginx reverse proxy for {$d} (Apache stays behind on 127.0.0.1:8080)\n"
         . "server {\n    listen 80;\n    server_name {$d} www.{$d};\n\n"
         . "    location / {\n        proxy_pass http://127.0.0.1:8080;\n        proxy_set_header Host \$host;\n"
         . "        proxy_set_header X-Real-IP \$remote_addr;\n        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
         . "        proxy_set_header X-Forwarded-Proto \$scheme;\n    }\n\n"
         . "    # static assets served straight from Nginx (faster)\n"
         . "    location ~* \\.(jpg|jpeg|png|gif|css|js|ico|svg|woff2?)\$ {\n        proxy_pass http://127.0.0.1:8080;\n        expires 7d;\n    }\n}\n";
    return ['ok'=>true,'config'=>$cfg];
}

function webModePage(): void { ?>
<?=helpBox('Web server mode', 'Orizen uses <b>Apache</b> by default (well supported, works with .htaccess). You can put <b>Nginx in front of Apache</b> as a reverse proxy for faster static files and caching. This screen shows your current server and generates the Nginx config for a site. Actually switching the live server is an advanced step - the config below is ready to drop into <span class="mono">/etc/nginx/sites-available</span> once you install Nginx and move Apache to port 8080.')?>
<div class="card"><h3>Current web server</h3><div id="wmStatus" class="sm">...</div></div>
<div class="card"><h3>Generate Nginx reverse-proxy config</h3>
  <div class="row"><div style="flex:2"><label>Site</label><select id="wmSite"></select></div><button class="btn btn-p" onclick="wmGen()">Generate</button></div>
  <pre class="code" id="wmCfg" style="max-height:320px;overflow:auto;margin-top:12px;display:none"></pre>
</div>
<script>
function wmLoad(){ api('wm_status',{}).then(function(r){ if(!r.ok)return;
  document.getElementById('wmStatus').innerHTML='Running: <span class="badge bg-green">'+r.current+'</span> (default)  .  Nginx: '+(r.nginx?'<span class="badge bg-blue">installed</span>':'<span class="badge">not installed</span>')+'  .  OpenLiteSpeed: '+(r.openlitespeed?'<span class="badge bg-blue">installed</span>':'<span class="badge">not installed</span>');
  document.getElementById('wmSite').innerHTML=r.sites.map(function(s){return '<option value="'+s.domain+'">'+s.domain+'</option>';}).join('')||'<option value="">no sites</option>';
}); }
function wmGen(){ api('wm_nginx',{domain:document.getElementById('wmSite').value}).then(function(r){ if(!r.ok){toast(r.error||'Failed','e');return;} var p=document.getElementById('wmCfg'); p.style.display=''; p.textContent=r.config; }); }
wmLoad();
</script>
<?php }

if (function_exists('moduleRegister')) {
    moduleRegister([
        'meta'  => ['key'=>'webmode','name'=>'Web Server Modes','desc'=>'Apache (default) status and Nginx reverse-proxy config generation.','feature'=>'enableWebServerModes'],
        'pages' => ['webmode'=>['title'=>'Web Server','section'=>'SYSTEM','feature'=>'enableWebServerModes','render'=>'webModePage']],
        'api'   => ['wm_status'=>'wmApiStatus','wm_nginx'=>'wmApiNginx'],
    ]);
}
