#!/usr/bin/env bash
#
# orizen-helper - privileged backend for Orizen Panel (cross-distro).
#
# The web panel runs unprivileged and may invoke ONLY this script as root via a
# locked sudoers rule. Every action validates its arguments so the web user can
# never run arbitrary root commands. Platform specifics (Apache service name,
# web user, vhost dir/style, firewall) come from /opt/orizen/data/platform.env
# written by the installer, so one helper works on Debian/RHEL/SUSE/Arch.
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive
umask 022

DATA_DIR="/opt/orizen/data"
# Debian defaults, overridden by platform.env if present:
OS_FAMILY=debian; PKG=apt; WEB_SVC=apache2; WEB_USER=www-data; MARIA_SVC=mariadb
VHOST_DIR=/etc/apache2/sites-available; VHOST_STYLE=debian; FW=ufw; WEBROOT_BASE=/var/www
# shellcheck disable=SC1090
[ -r "$DATA_DIR/platform.env" ] && . "$DATA_DIR/platform.env"

VMAIL_BASE="/var/mail/vhosts"; VMAIL_UID=5000; VMAIL_GID=5000
ALLOWED_SERVICES="apache2 httpd mariadb mysql mysqld postfix dovecot opendkim ufw firewalld cron cronie php-fpm"

err() { echo "ERROR: $*" >&2; exit 1; }
ok()  { echo "OK: $*"; }

# -- validators ------------------------------------------------------
is_domain() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]] && [ "${#1}" -le 253 ]; }
is_email()  { [[ "$1" =~ ^[a-zA-Z0-9._%+-]+@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }
is_port()   { [[ "$1" =~ ^[0-9]+$ ]] && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]; }
is_ip()     { [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; }
safe_docroot() { [[ "$1" == /var/www/* || "$1" == /srv/* ]] && [[ "$1" != *".."* ]] && [[ "$1" =~ ^[A-Za-z0-9_./-]+$ ]]; }
# The panel (www-data) may pass a "web user" to run work as. It must be an EXISTING,
# NON-root account - otherwise a compromised www-data could ask the helper to
# `runuser -u root -- <cmd>` and escalate to root. This guard is the core of that defence.
valid_runas() { [ -n "${1:-}" ] && [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] && id "$1" >/dev/null 2>&1 && [ "$(id -u "$1" 2>/dev/null)" -ne 0 ]; }
# Keep the Dovecot passwd-file readable by the 'dovecot' auth user. Must be re-asserted after
# every write because `sed -i` (used by mail-passwd/mail-del-box) resets ownership to root:root.
dv_users_perms() { chown dovecot:dovecot /etc/dovecot/users 2>/dev/null || true; chmod 600 /etc/dovecot/users 2>/dev/null || true; }

[ "$(id -u)" -eq 0 ] || err "helper must run as root"

ensite()  { [ "$VHOST_STYLE" = debian ] && a2ensite  "${1}.conf" >/dev/null 2>&1; return 0; }
dissite() { [ "$VHOST_STYLE" = debian ] && a2dissite "${1}.conf" >/dev/null 2>&1; return 0; }
web_test(){ apachectl configtest >/dev/null 2>&1 || apache2ctl configtest >/dev/null 2>&1 || httpd -t >/dev/null 2>&1; }
reload_web(){ systemctl reload "$WEB_SVC" 2>/dev/null || systemctl restart "$WEB_SVC" 2>/dev/null || true; }
hpkg_install(){ case "$PKG" in
    apt) DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y "$@" >/dev/null 2>&1 ;;
    dnf) dnf install -y "$@" >/dev/null 2>&1 ;; yum) yum install -y "$@" >/dev/null 2>&1 ;;
    zypper) zypper -n install -y "$@" >/dev/null 2>&1 ;; pacman) pacman -S --noconfirm --needed "$@" >/dev/null 2>&1 ;;
  esac; }

# Which compose CLI is available ("docker compose" v2 preferred, else v1 "docker-compose").
compose_bin() {
  if docker compose version >/dev/null 2>&1; then echo "docker compose"
  elif command -v docker-compose >/dev/null 2>&1; then echo "docker-compose"
  else echo "docker compose"; fi
}
# Install Docker Engine + Compose v2. Prefers Docker's official apt repo (which
# ships the compose plugin); falls back to the distro docker.io + docker-compose.
docker_ensure() {
  if command -v docker >/dev/null 2>&1 && { docker compose version >/dev/null 2>&1 || command -v docker-compose >/dev/null 2>&1; }; then
    systemctl start docker >/dev/null 2>&1 || true; return 0
  fi
  if [ "$PKG" = apt ]; then
    apt-get update -qq >/dev/null 2>&1 || true
    hpkg_install ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
      curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg 2>/dev/null || true
      chmod a+r /etc/apt/keyrings/docker.gpg 2>/dev/null || true
    fi
    ARCH="$(dpkg --print-architecture 2>/dev/null || echo amd64)"
    CN="jammy"; [ -r /etc/os-release ] && CN="$(. /etc/os-release 2>/dev/null; echo "${VERSION_CODENAME:-jammy}")"
    echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${CN} stable" > /etc/apt/sources.list.d/docker.list
    apt-get update -qq >/dev/null 2>&1 || true
    hpkg_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin \
      || hpkg_install docker.io docker-compose
  else
    hpkg_install docker || hpkg_install docker.io || return 1
  fi
  systemctl enable docker >/dev/null 2>&1 || true; systemctl start docker >/dev/null 2>&1 || true
  command -v docker >/dev/null 2>&1
}
# Enable a swapfile (default 3 GB) so image builds do not OOM on small hosts.
swap_ensure() {
  mb="${1:-3072}"; [[ "$mb" =~ ^[0-9]+$ ]] || mb=3072; [ "$mb" -gt 8192 ] && mb=8192
  [ "$(swapon --show=NAME --noheadings 2>/dev/null | wc -l)" -gt 0 ] && return 0
  SF=/swapfile
  if [ ! -f "$SF" ]; then
    fallocate -l "${mb}M" "$SF" 2>/dev/null || dd if=/dev/zero of="$SF" bs=1M count="$mb" status=none 2>/dev/null || return 1
    chmod 600 "$SF"; mkswap "$SF" >/dev/null 2>&1 || return 1
  fi
  swapon "$SF" 2>/dev/null || true
  grep -qF "$SF" /etc/fstab 2>/dev/null || echo "$SF none swap sw 0 0" >> /etc/fstab
  return 0
}

write_vhost() {  # domain docroot alias ssl_flag
  local crt="${DATA_DIR}/ssl/panel.crt" key="${DATA_DIR}/ssl/panel.key" ssl=""
  # Prefer a real Let's Encrypt cert for this domain if one exists (trusted origin
  # cert for Cloudflare Full-strict, auto-renewing); else fall back to self-signed.
  if [ -s "/etc/letsencrypt/live/${1}/fullchain.pem" ] && [ -s "/etc/letsencrypt/live/${1}/privkey.pem" ]; then
    crt="/etc/letsencrypt/live/${1}/fullchain.pem"; key="/etc/letsencrypt/live/${1}/privkey.pem"
  fi
  if [ "${4:-}" = ssl ] && [ -s "$crt" ] && [ -s "$key" ]; then
    ssl="
<VirtualHost *:443>
    ServerName ${1}
    ${3:+ServerAlias ${3}}
    DocumentRoot ${2}
    SSLEngine on
    SSLCertificateFile ${crt}
    SSLCertificateKeyFile ${key}
    <Directory ${2}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/${1}-ssl-error.log
</VirtualHost>"
  fi
  cat > "${VHOST_DIR}/${1}.conf" <<VH
<VirtualHost *:80>
    ServerName ${1}
    ${3:+ServerAlias ${3}}
    DocumentRoot ${2}
    <Directory ${2}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/${1}-error.log
    CustomLog ${APACHE_LOG_DIR:-/var/log/apache2}/${1}-access.log combined
</VirtualHost>${ssl}
VH
}

# Guarantee a neutral catch-all vhost on :80 AND :443, loaded first (000- prefix),
# so the bare server IP / any unconfigured Host is NEVER served by a real customer
# site (e.g. a payment gateway) just because its vhost sorts first. Idempotent.
ensure_default_vhost() {
  local ddir="${WEBROOT_BASE}/html"
  local crt="${DATA_DIR}/ssl/panel.crt" key="${DATA_DIR}/ssl/panel.key" sslblock=""
  mkdir -p "$ddir"
  if [ ! -e "$ddir/index.html" ]; then
    cat > "$ddir/index.html" <<'HTML'
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Server</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0b1020;color:#cdd3e6;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:20px}</style>
<div><h1 style="font-weight:800;margin:0 0 8px">Nothing to see here</h1><p style="color:#8b93ab">This server hosts websites. Please use a site's domain name to reach it.</p></div>
HTML
  fi
  chown -R "${WEB_USER}:${WEB_USER}" "$ddir" 2>/dev/null || true
  if [ -s "$crt" ] && [ -s "$key" ]; then
    sslblock="
<VirtualHost *:443>
    ServerName default.invalid
    DocumentRoot ${ddir}
    SSLEngine on
    SSLCertificateFile ${crt}
    SSLCertificateKeyFile ${key}
    <Directory ${ddir}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/default-ssl-error.log
</VirtualHost>"
  fi
  cat > "${VHOST_DIR}/000-default.conf" <<VH
<VirtualHost *:80>
    ServerName default.invalid
    DocumentRoot ${ddir}
    <Directory ${ddir}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/default-error.log
    CustomLog ${APACHE_LOG_DIR:-/var/log/apache2}/default-access.log combined
</VirtualHost>${sslblock}
VH
  ensite "000-default"
  return 0
}

ACTION="${1:-}"; shift || true

case "$ACTION" in

  # --------------------------- websites ----------------------------
  create-site|create-subdomain)
    domain="${1:-}"; docroot="${2:-}"
    is_domain "$domain" || err "invalid domain: $domain"
    [ -n "$docroot" ] || docroot="${WEBROOT_BASE}/${domain}/public"
    safe_docroot "$docroot" || err "docroot must be under /var/www or /srv"
    mkdir -p "$docroot"
    if [ ! -e "${docroot}/index.html" ] && [ ! -e "${docroot}/index.php" ]; then
      cat > "${docroot}/index.html" <<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>${domain}</title>
<style>
  :root{ --bg:#f4f5f7; --card:#ffffff; --border:#e6e8ec; --text:#15171c; --muted:#616875; }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:var(--text);
    background:var(--bg);display:grid;place-items:center;min-height:100vh}
  .wrap{position:relative;text-align:center;padding:40px 22px;max-width:560px}
  .card{position:relative;background:var(--card);border:1px solid var(--border);
    border-radius:22px;padding:44px 34px;box-shadow:0 24px 60px -24px rgba(16,24,40,.18);
    animation:rise .7s cubic-bezier(.2,.7,.2,1) both}
  .badge{display:inline-flex;align-items:center;gap:8px;font-size:12px;letter-spacing:.14em;text-transform:uppercase;
    color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:7px 14px;margin-bottom:22px}
  .dot{width:9px;height:9px;border-radius:50%;background:#0b7a37;box-shadow:0 0 0 0 rgba(11,122,55,.5);animation:pulse 2s infinite}
  h1{margin:0 0 10px;font-size:clamp(26px,5vw,40px);font-weight:800;letter-spacing:-.02em;color:var(--text);word-break:break-word}
  p{margin:0;color:var(--muted);font-size:15.5px;line-height:1.6}
  .foot{margin-top:26px;font-size:12px;color:var(--muted)}
  @keyframes rise{from{opacity:0;transform:translateY(18px) scale(.98)}to{opacity:1;transform:none}}
  @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(11,122,55,.5)}70%{box-shadow:0 0 0 12px rgba(11,122,55,0)}100%{box-shadow:0 0 0 0 rgba(11,122,55,0)}}
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <span class="badge"><span class="dot"></span> Online</span>
      <h1>${domain}</h1>
      <p>This site is set up and ready. Your content will appear here soon.</p>
      <div class="foot">Secured &amp; powered by Orizen Panel</div>
    </div>
  </div>
</body>
</html>
HTML
    fi
    chown -R "${WEB_USER}:${WEB_USER}" "$(dirname "$docroot")" 2>/dev/null || chown -R "${WEB_USER}:${WEB_USER}" "$docroot"
    al=""; [ "$ACTION" = create-site ] && al="www.${domain}"
    # Always add the self-signed :443 vhost too, so Cloudflare "Full" (orange-cloud)
    # can reach the origin over HTTPS. Public certs still come from Let's Encrypt or
    # Cloudflare's edge; this is just so an HTTPS listener always exists.
    write_vhost "$domain" "$docroot" "$al" "ssl"
    ensure_default_vhost
    ensite "$domain"
    [ "$VHOST_STYLE" = debian ] && a2enmod ssl >/dev/null 2>&1 || true
    web_test || err "apache config test failed"
    reload_web
    ok "site ${domain} -> ${docroot}"
    ;;

  delete-site)
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    dissite "$domain"
    rm -f "${VHOST_DIR}/${domain}.conf" "${VHOST_DIR}/${domain}-le-ssl.conf"
    reload_web
    ok "site ${domain} removed (files kept)"
    ;;

  purge-site)   # purge-site <domain> [docroot] - full cleanup: vhost, SSL cert, logs, and the site's own folder
    domain="${1:-}"; docroot="${2:-}"
    is_domain "$domain" || err "invalid domain"
    dissite "$domain"
    rm -f "${VHOST_DIR}/${domain}.conf" "${VHOST_DIR}/${domain}-le-ssl.conf"
    # remove the Let's Encrypt certificate for this domain (non-interactive, best-effort)
    if command -v certbot >/dev/null 2>&1; then certbot delete --cert-name "$domain" -n >/dev/null 2>&1 || true; fi
    # remove this domain's apache logs
    rm -f "${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-error.log" \
          "${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-access.log" \
          "${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-ssl-error.log" 2>/dev/null || true
    # delete the site's own folder ONLY - never a shared/alias docroot. We only remove
    # <base>/<domain>[/public] when the folder basename matches the domain exactly.
    if [ -n "$docroot" ] && safe_docroot "$docroot"; then
      target="$docroot"; case "$docroot" in */public) target="$(dirname "$docroot")";; esac
      if [ "$(basename "$target")" = "$domain" ] && [ -d "$target" ]; then rm -rf "$target"; fi
    fi
    reload_web
    ok "site ${domain} fully removed"
    ;;

  fs-delete)   # fs-delete <path> - File Manager root fallback: delete a path under a web root when the web user cannot
    p="${1:-}"
    [ -n "$p" ] || err "no path"
    case "$p" in *".."*) err "bad path";; esac
    case "$p" in /var/www/*|/srv/*) ;; *) err "path not under a web root";; esac
    [ -e "$p" ] || { ok "already gone"; exit 0; }
    rm -rf "$p"
    ok "deleted"
    ;;

  create-redirect)
    domain="${1:-}"; target="${2:-}"; rtype="${3:-301}"
    is_domain "$domain" || err "invalid domain: $domain"
    case "$rtype" in 301|302) ;; *) rtype=301;; esac
    case "$target" in http://*|https://*) ;; *) target="https://${target}";; esac
    printf '%s' "$target" | grep -Eq "^https?://[A-Za-z0-9._~:/?#@!\$&'()*+,;=%-]+$" || err "invalid target URL"
    # keep the bare IP neutral, never a redirect
    ensure_default_vhost
    # give the redirect a :443 origin cert too, so Cloudflare (SSL: Full) can reach the
    # origin over HTTPS without an "invalid SSL certificate" error. Prefer LE, else self-signed.
    rcrt="${DATA_DIR}/ssl/panel.crt"; rkey="${DATA_DIR}/ssl/panel.key"; rssl=""
    if [ -s "/etc/letsencrypt/live/${domain}/fullchain.pem" ] && [ -s "/etc/letsencrypt/live/${domain}/privkey.pem" ]; then
      rcrt="/etc/letsencrypt/live/${domain}/fullchain.pem"; rkey="/etc/letsencrypt/live/${domain}/privkey.pem"
    fi
    if [ -s "$rcrt" ] && [ -s "$rkey" ]; then
      rssl="
<VirtualHost *:443>
    ServerName ${domain}
    ServerAlias www.${domain}
    SSLEngine on
    SSLCertificateFile ${rcrt}
    SSLCertificateKeyFile ${rkey}
    Redirect ${rtype} / ${target}
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-ssl-error.log
</VirtualHost>"
    fi
    cat > "${VHOST_DIR}/${domain}.conf" <<VH
<VirtualHost *:80>
    ServerName ${domain}
    ServerAlias www.${domain}
    Redirect ${rtype} / ${target}
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-error.log
</VirtualHost>${rssl}
VH
    ensite "$domain"
    web_test || err "apache config test failed"
    reload_web
    ok "redirect ${domain} -> ${target} (${rtype})"
    ;;

  set-docroot-perms)
    docroot="${1:-}"; safe_docroot "$docroot" || err "bad docroot"
    chown -R "${WEB_USER}:${WEB_USER}" "$docroot"; ok "perms reset"
    ;;

  # --------------------------- SSL ---------------------------------
  issue-ssl)
    domain="${1:-}"; email="${2:-}"
    is_domain "$domain" || err "invalid domain"
    extra=""; grep -qs "www.${domain}" "${VHOST_DIR}/${domain}.conf" 2>/dev/null && extra="-d www.${domain}"
    if [ -n "$email" ] && is_email "$email"; then reg=(-m "$email" --agree-tos); else reg=(--register-unsafely-without-email --agree-tos); fi
    command -v certbot >/dev/null || err "certbot not installed on this server"
    certbot --apache --non-interactive "${reg[@]}" -d "$domain" $extra --redirect
    ok "ssl issued for ${domain}"
    ;;

  renew-ssl) certbot renew --quiet 2>/dev/null; ok "renew attempted" ;;
  cert-list) certbot certificates 2>/dev/null || echo "certbot not installed"; ;;

  auto-ssl-scan)
    email="${1:-}"; sites="${DATA_DIR}/sites.json"; cfgf="${DATA_DIR}/config.json"
    [ -f "$sites" ] && [ -f "$cfgf" ] || exit 0
    command -v jq >/dev/null && command -v dig >/dev/null && command -v certbot >/dev/null || exit 0
    ip="$(jq -r '.server_ip // empty' "$cfgf")"; [ -n "$ip" ] || exit 0
    if [ -n "$email" ] && is_email "$email"; then reg=(-m "$email" --agree-tos); else reg=(--register-unsafely-without-email --agree-tos); fi
    while IFS= read -r domain; do
      [ -n "$domain" ] && is_domain "$domain" || continue
      resolved="$(dig +short A "$domain" @1.1.1.1 2>/dev/null | grep -E '^[0-9.]+$' | tail -n1)"
      [ "$resolved" = "$ip" ] || continue
      if certbot --apache --non-interactive "${reg[@]}" -d "$domain" --redirect >/dev/null 2>&1; then
        tmp="$(mktemp)"; jq --arg d "$domain" 'map(if .domain==$d then .ssl=true else . end)' "$sites" > "$tmp" 2>/dev/null && mv "$tmp" "$sites" && chown "${WEB_USER}:${WEB_USER}" "$sites"
      fi
    done < <(jq -r '.[] | select(.ssl != true) | .domain' "$sites" 2>/dev/null)
    ok "auto-ssl scan complete"
    ;;

  # --------------------------- services ---------------------------
  service)
    svc="${1:-}"; act="${2:-}"
    [[ " $ALLOWED_SERVICES " == *" $svc "* ]] || err "service not allowed: $svc"
    case "$act" in start|stop|restart|reload|status|enable|disable) ;; *) err "bad action";; esac
    systemctl "$act" "$svc"
    ;;
  reload-web) reload_web; ok "web reloaded" ;;

  # --------------------------- processes --------------------------
  kill-pid)
    pid="${1:-}"; [[ "$pid" =~ ^[0-9]+$ ]] || err "bad pid"
    [ "$pid" -gt 1 ] || err "refusing"
    kill -TERM "$pid" 2>/dev/null || kill -KILL "$pid" 2>/dev/null || err "could not kill $pid"
    ok "killed $pid"
    ;;

  # --------------------------- firewall ---------------------------
  firewall)
    op="${1:-}"; port="${2:-}"; proto="${3:-tcp}"
    case "$proto" in tcp|udp) ;; *) proto=tcp ;; esac
    # Ports that must ALWAYS stay open so enabling the firewall never locks the operator out.
    PPORT="$(grep -oE '"panel_port"[^0-9]*[0-9]+' "$DATA_DIR/config.json" 2>/dev/null | grep -oE '[0-9]+$')"
    KEEP="22 80 443 ${PPORT:-1337}"
    case "$FW" in
      ufw)
        command -v ufw >/dev/null || err "ufw not installed"
        case "$op" in
          allow) is_port "$port" || err "bad port"; ufw allow "${port}/${proto}"; ufw reload >/dev/null 2>&1 || true ;;
          deny)  is_port "$port" || err "bad port"; ufw deny "${port}/${proto}"; ufw reload >/dev/null 2>&1 || true ;;
          delete-allow) is_port "$port" || err "bad port"
            for pr in tcp udp; do ufw delete allow "${port}/${pr}" >/dev/null 2>&1 || true; ufw delete deny "${port}/${pr}" >/dev/null 2>&1 || true; done
            ufw delete allow "${port}" >/dev/null 2>&1 || true ;;
          enable) for p in $KEEP; do ufw allow "${p}/tcp" >/dev/null 2>&1 || true; done; ufw --force enable ;;
          disable) ufw disable ;;
          status) ufw status verbose ;;
          list) ufw status 2>/dev/null | awk '/ALLOW/{print $1}' | sort -u -t/ -k1 -n | tr '\n' ' ' ;;
          active) ufw status 2>/dev/null | grep -qi 'Status: active' && echo active || echo inactive ;;
          *) err "bad op";;
        esac ;;
      firewalld)
        command -v firewall-cmd >/dev/null || err "firewalld not installed"
        case "$op" in
          allow) is_port "$port" || err "bad port"; firewall-cmd --permanent --add-port="${port}/${proto}"; firewall-cmd --reload ;;
          deny|delete-allow) is_port "$port" || err "bad port"
            for pr in tcp udp; do firewall-cmd --permanent --remove-port="${port}/${pr}" >/dev/null 2>&1 || true; done; firewall-cmd --reload ;;
          enable) for p in $KEEP; do firewall-cmd --permanent --add-port="${p}/tcp" >/dev/null 2>&1 || true; done
            systemctl enable firewalld >/dev/null 2>&1 || true; systemctl start firewalld >/dev/null 2>&1 || true; firewall-cmd --reload ;;
          disable) systemctl stop firewalld >/dev/null 2>&1 || true; systemctl disable firewalld >/dev/null 2>&1 || true ;;
          status) firewall-cmd --list-all ;;
          list) firewall-cmd --list-ports 2>/dev/null | tr ' ' '\n' | sort -u -t/ -k1 -n | tr '\n' ' ' ;;
          active) firewall-cmd --state >/dev/null 2>&1 && echo active || echo inactive ;;
          *) err "bad op";;
        esac ;;
      *) err "no managed firewall on this system" ;;
    esac
    case "$op" in status|list|active) ;; *) ok "firewall ${op} ${port}" ;; esac
    ;;

  get-dkim)
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    f="/etc/opendkim/keys/${domain}/mail.txt"
    [ -f "$f" ] && cat "$f" || echo "NO_DKIM_KEY"
    ;;

  # --------------------------- mail -------------------------------
  mail-install)   # install the mail server packages (Postfix + Dovecot [+ OpenDKIM]) cross-distro
    mailname="${1:-localhost}"
    case "$PKG" in
      apt)
        echo "postfix postfix/mailname string ${mailname}" | debconf-set-selections 2>/dev/null || true
        echo "postfix postfix/main_mailer_type string 'Internet Site'" | debconf-set-selections 2>/dev/null || true
        apt-get update -qq >/dev/null 2>&1 || true
        hpkg_install postfix dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd opendkim opendkim-tools ;;
      dnf|yum) hpkg_install postfix dovecot opendkim opendkim-tools ;;
      *) hpkg_install postfix dovecot ;;
    esac
    command -v postfix >/dev/null 2>&1 || err "mail packages failed to install"
    ok "mail packages installed"
    ;;

  mail-bootstrap)
    mailname="${1:-localhost}"; ip="${2:-127.0.0.1}"; is_ip "$ip" || ip="127.0.0.1"
    getent group vmail >/dev/null || groupadd -g "$VMAIL_GID" vmail
    getent passwd vmail >/dev/null || useradd -g vmail -u "$VMAIL_UID" -d "$VMAIL_BASE" -s /usr/sbin/nologin vmail
    mkdir -p "$VMAIL_BASE"; chown -R vmail:vmail "$VMAIL_BASE"
    : > /etc/postfix/vmail_domains; : > /etc/postfix/vmail_mailbox; : > /etc/postfix/vmail_alias
    postmap /etc/postfix/vmail_mailbox; postmap /etc/postfix/vmail_alias
    postconf -e "myhostname = ${mailname}" "virtual_mailbox_domains = /etc/postfix/vmail_domains" \
      "virtual_mailbox_base = ${VMAIL_BASE}" "virtual_mailbox_maps = hash:/etc/postfix/vmail_mailbox" \
      "virtual_alias_maps = hash:/etc/postfix/vmail_alias" "virtual_minimum_uid = ${VMAIL_UID}" \
      "virtual_uid_maps = static:${VMAIL_UID}" "virtual_gid_maps = static:${VMAIL_GID}" \
      "virtual_transport = lmtp:unix:private/dovecot-lmtp" "smtpd_sasl_type = dovecot" \
      "smtpd_sasl_path = private/auth" "smtpd_sasl_auth_enable = yes" "inet_interfaces = all" \
      "smtpd_recipient_restrictions = permit_sasl_authenticated, permit_mynetworks, reject_unauth_destination"
    grep -qE '^submission[[:space:]]+inet' /etc/postfix/master.cf || cat >> /etc/postfix/master.cf <<'MCF'

submission inet n - y - - smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=may
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
MCF
    DCD=/etc/dovecot/conf.d
    # Dovecot config uses NEWLINE-separated settings inside blocks - never semicolons or
    # multiple blocks on one line (that fails with "Garbage after '{'").
    cat > "$DCD/10-mail.conf" <<'DC'
mail_location = maildir:/var/mail/vhosts/%d/%n
namespace inbox {
  inbox = yes
}
mail_privileged_group = vmail
DC
    cat > "$DCD/10-auth.conf" <<'DC'
disable_plaintext_auth = no
auth_mechanisms = plain login
!include auth-passwdfile.conf.ext
DC
    cat > "$DCD/auth-passwdfile.conf.ext" <<'DC'
passdb {
  driver = passwd-file
  args = scheme=CRYPT username_format=%u /etc/dovecot/users
}
userdb {
  driver = static
  args = uid=5000 gid=5000 home=/var/mail/vhosts/%d/%n
}
DC
    cat > "$DCD/10-master.conf" <<'DC'
service imap-login {
  inet_listener imap {
    port = 143
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}
service pop3-login {
  inet_listener pop3 {
    port = 110
  }
  inet_listener pop3s {
    port = 995
    ssl = yes
  }
}
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
  unix_listener auth-userdb {
    mode = 0600
    user = vmail
  }
}
service auth-worker {
  user = vmail
}
DC
    # The Dovecot AUTH process runs as the 'dovecot' user - the passwd-file must be readable by
    # it (NOT vmail). Otherwise every login fails with "[UNAVAILABLE] Temporary authentication failure".
    touch /etc/dovecot/users; dv_users_perms
    systemctl enable postfix dovecot >/dev/null 2>&1 || true
    systemctl restart postfix dovecot 2>/dev/null || true
    ok "mail bootstrapped for ${mailname}"
    ;;

  mail-add-domain)
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    grep -qxF "$domain" /etc/postfix/vmail_domains 2>/dev/null || echo "$domain" >> /etc/postfix/vmail_domains
    mkdir -p "${VMAIL_BASE}/${domain}"; chown -R vmail:vmail "${VMAIL_BASE}/${domain}"
    if command -v opendkim-genkey >/dev/null && [ ! -f "/etc/opendkim/keys/${domain}/mail.private" ]; then
      mkdir -p "/etc/opendkim/keys/${domain}"
      ( cd "/etc/opendkim/keys/${domain}" && opendkim-genkey -b 2048 -s mail -d "$domain" )
      chown -R opendkim:opendkim "/etc/opendkim/keys/${domain}" 2>/dev/null || true
      grep -q "mail._domainkey.${domain}" /etc/opendkim/key.table 2>/dev/null || echo "mail._domainkey.${domain} ${domain}:mail:/etc/opendkim/keys/${domain}/mail.private" >> /etc/opendkim/key.table
      grep -q "@${domain}" /etc/opendkim/signing.table 2>/dev/null || echo "*@${domain} mail._domainkey.${domain}" >> /etc/opendkim/signing.table
      systemctl restart opendkim 2>/dev/null || true
    fi
    systemctl reload postfix 2>/dev/null || systemctl restart postfix 2>/dev/null || true
    ok "mail domain ${domain} added"
    ;;

  mail-del-domain)
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    sed -i "\|^${domain}$|d" /etc/postfix/vmail_domains 2>/dev/null || true
    systemctl reload postfix 2>/dev/null || true; ok "mail domain ${domain} removed"
    ;;

  mail-add-box)
    email="${1:-}"; crypt="${2:-}"; is_email "$email" || err "invalid email"
    [[ "$crypt" =~ ^\$6\$ ]] || [[ "$crypt" =~ ^\{ ]] || err "expected a crypt hash"
    lp="${email%@*}"; dp="${email#*@}"
    # self-heal: ensure the mail domain exists so account creation is one-step and never
    # trips over a JSON/postfix desync. (If the mail server isn't installed at all, bail clearly.)
    if ! grep -qxF "$dp" /etc/postfix/vmail_domains 2>/dev/null; then
      [ -d /etc/postfix ] || err "mail server is not installed"
      echo "$dp" >> /etc/postfix/vmail_domains
      mkdir -p "${VMAIL_BASE}/${dp}"; chown -R vmail:vmail "${VMAIL_BASE}/${dp}" 2>/dev/null || true
      systemctl reload postfix 2>/dev/null || true
    fi
    grep -q "^${email}[[:space:]]" /etc/postfix/vmail_mailbox 2>/dev/null && err "mailbox exists"
    echo "${email}    ${dp}/${lp}/" >> /etc/postfix/vmail_mailbox; postmap /etc/postfix/vmail_mailbox
    echo "${email}:${crypt}::::::" >> /etc/dovecot/users; dv_users_perms
    mkdir -p "${VMAIL_BASE}/${dp}/${lp}"; chown -R vmail:vmail "${VMAIL_BASE}/${dp}"
    systemctl reload postfix dovecot 2>/dev/null || systemctl restart postfix dovecot 2>/dev/null || true
    ok "mailbox ${email} created"
    ;;

  mail-passwd)
    email="${1:-}"; crypt="${2:-}"; is_email "$email" || err "invalid email"
    [[ "$crypt" =~ ^\$6\$ ]] || [[ "$crypt" =~ ^\{ ]] || err "expected crypt hash"
    sed -i "\|^${email}:|d" /etc/dovecot/users; echo "${email}:${crypt}::::::" >> /etc/dovecot/users; dv_users_perms
    systemctl reload dovecot 2>/dev/null || true; ok "password updated for ${email}"
    ;;

  mail-del-box)
    email="${1:-}"; is_email "$email" || err "invalid email"
    sed -i "\|^${email}[[:space:]]|d" /etc/postfix/vmail_mailbox 2>/dev/null || true
    sed -i "\|^${email}:|d" /etc/dovecot/users 2>/dev/null || true; dv_users_perms
    postmap /etc/postfix/vmail_mailbox 2>/dev/null || true
    systemctl reload postfix dovecot 2>/dev/null || true; ok "mailbox ${email} removed"
    ;;

  # --------------------------- logs -------------------------------
  log-list)
    ae=/var/log/apache2/error.log; aa=/var/log/apache2/access.log
    [ "$OS_FAMILY" = rhel ] && { ae=/var/log/httpd/error_log; aa=/var/log/httpd/access_log; }
    [ -f "$ae" ] && echo "ae|$ae|Apache - Error Log"
    [ -f "$aa" ] && echo "aa|$aa|Apache - Access Log"
    [ -f /var/log/mysql/error.log ] && echo "my|/var/log/mysql/error.log|MySQL - Error Log"
    [ -f /var/log/mariadb/mariadb.log ] && echo "mdb|/var/log/mariadb/mariadb.log|MariaDB - Error Log"
    [ -f /var/log/maillog ] && echo "ml|/var/log/maillog|Mail Log"
    [ -f /var/log/mail.log ] && echo "ml|/var/log/mail.log|Mail Log"
    [ -f /var/log/orizen-install.log ] && echo "op|/var/log/orizen-install.log|Orizen Panel - Install Log"
    for f in /var/log/php*.log; do [ -f "$f" ] && echo "php|$f|PHP - $(basename "$f")"; done
    exit 0
    ;;
  log-tail)
    path="${1:-}"; lines="${2:-200}"
    [[ "$path" == /var/log/* ]] && [[ "$path" != *".."* ]] || err "path not allowed"
    [[ "$lines" =~ ^[0-9]+$ ]] || lines=200
    [ "$lines" -gt 5000 ] && lines=5000
    [ -f "$path" ] || err "log not found"
    tail -n "$lines" "$path"
    ;;
  log-clear)
    path="${1:-}"; [[ "$path" == /var/log/* ]] && [[ "$path" != *".."* ]] || err "path not allowed"
    [ -f "$path" ] && : > "$path"; ok "cleared"
    ;;

  # -- monitoring module: install/remove the once-a-minute collector cron --
  monitor-setup)
    WU="${1:-$WEB_USER}"
    valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    COL="/opt/orizen/panel/modules/monitoring/collector.php"
    [ -f "$COL" ] || err "collector not found"
    mkdir -p /opt/orizen/data/monitor
    chown "$WU":"$WU" /opt/orizen/data/monitor
    chmod 750 /opt/orizen/data/monitor
    mkdir -p /etc/cron.d
    cat > /etc/cron.d/orizen-monitor <<CRON
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * ${WU} php ${COL} >/dev/null 2>&1
CRON
    chmod 644 /etc/cron.d/orizen-monitor
    ok "monitor collector installed"
    ;;
  monitor-teardown)
    rm -f /etc/cron.d/orizen-monitor; ok "monitor collector removed"
    ;;

  # -- git deploy: clone/pull, run build, rollback - all as the unprivileged web user --
  git-deploy)   # git-deploy <https-repo> <branch> <dir> <webuser>
    REPO="${1:-}"; BR="${2:-}"; DIR="${3:-}"; WU="${4:-$WEB_USER}"
    [[ "$REPO" =~ ^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/~-]+$ ]] || err "repo must be an https git URL"
    [[ "$BR"   =~ ^[A-Za-z0-9._/-]+$ ]] || err "invalid branch"
    case "$DIR" in /var/www/*|/opt/orizen/deploys/*) : ;; *) err "target dir must be under /var/www or /opt/orizen/deploys" ;; esac
    [[ "$DIR" != *".."* && "$DIR" =~ ^[A-Za-z0-9._/-]+$ ]] || err "invalid dir"
    valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    command -v git >/dev/null 2>&1 || err "git is not installed"
    mkdir -p "$DIR"; chown "$WU":"$WU" "$DIR"
    export GIT_TERMINAL_PROMPT=0
    if [ -d "$DIR/.git" ]; then
      runuser -u "$WU" -- env HOME="$DIR" GIT_TERMINAL_PROMPT=0 git -C "$DIR" fetch --depth=50 origin "$BR" 2>&1 || err "git fetch failed"
      runuser -u "$WU" -- env HOME="$DIR" git -C "$DIR" reset --hard "origin/$BR" 2>&1 || err "git reset failed"
    else
      [ -z "$(ls -A "$DIR" 2>/dev/null)" ] || err "target dir is not empty and is not a git checkout"
      runuser -u "$WU" -- env HOME="$DIR" GIT_TERMINAL_PROMPT=0 git clone --depth=50 -b "$BR" "$REPO" "$DIR" 2>&1 \
        || runuser -u "$WU" -- env HOME="$DIR" GIT_TERMINAL_PROMPT=0 git clone --depth=50 "$REPO" "$DIR" 2>&1 \
        || err "git clone failed"
    fi
    REF="$(runuser -u "$WU" -- git -C "$DIR" rev-parse --short HEAD 2>/dev/null)"
    echo "OK: deployed ${REF}"
    ;;
  git-run)      # git-run <dir> <webuser> <build command...>
    DIR="${1:-}"; WU="${2:-$WEB_USER}"; shift 2 || true; CMD="$*"
    case "$DIR" in /var/www/*|/opt/orizen/deploys/*) : ;; *) err "dir must be under /var/www or /opt/orizen/deploys" ;; esac
    [[ "$DIR" != *".."* ]] || err "invalid dir"
    [ -d "$DIR" ] || err "dir not found"
    valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    [ -n "$CMD" ] || { ok "no build command"; exit 0; }
    runuser -u "$WU" -- env HOME="$DIR" bash -lc "cd $(printf '%q' "$DIR") && $CMD" 2>&1
    ok "build finished"
    ;;
  git-checkout) # git-checkout <dir> <webuser> <ref>
    DIR="${1:-}"; WU="${2:-$WEB_USER}"; REF="${3:-}"
    case "$DIR" in /var/www/*|/opt/orizen/deploys/*) : ;; *) err "dir must be under /var/www or /opt/orizen/deploys" ;; esac
    [[ "$DIR" != *".."* ]] || err "invalid dir"
    [[ "$REF" =~ ^[A-Za-z0-9._/-]+$ ]] || err "invalid ref"
    [ -d "$DIR/.git" ] || err "not a git checkout"
    valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    runuser -u "$WU" -- env HOME="$DIR" git -C "$DIR" checkout -f "$REF" 2>&1 || err "checkout failed"
    echo "OK: checked out ${REF}"
    ;;

  # -- website tools --
  perm-repair)  # perm-repair <docroot> <webuser>
    DIR="${1:-}"; WU="${2:-$WEB_USER}"
    safe_docroot "$DIR" || err "invalid docroot"; [ -d "$DIR" ] || err "docroot not found"
    valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    chown -R "$WU":"$WU" "$DIR"
    find "$DIR" -type d -exec chmod 755 {} + 2>/dev/null
    find "$DIR" -type f -exec chmod 644 {} + 2>/dev/null
    ok "permissions repaired on $DIR"
    ;;
  site-clone)   # site-clone <srcdoc> <dstdoc> <webuser>
    SRC="${1:-}"; DST="${2:-}"; WU="${3:-$WEB_USER}"
    safe_docroot "$SRC" || err "invalid source"; safe_docroot "$DST" || err "invalid target"
    [ -d "$SRC" ] || err "source not found"; [ -e "$DST" ] && [ -n "$(ls -A "$DST" 2>/dev/null)" ] && err "target exists and is not empty"
    valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    mkdir -p "$DST"; cp -a "$SRC/." "$DST/" 2>/dev/null || err "copy failed"
    chown -R "$WU":"$WU" "$DST"; ok "cloned $SRC -> $DST"
    ;;
  pkg-ensure)   # pkg-ensure <name> - install a whitelisted optional package
    P="${1:-}"
    case "$P" in
      redis|redis-server|memcached|php-redis|php-memcached|fail2ban|clamav|clamav-daemon|clamav-freshclam|docker.io) : ;;
      *) err "package not allowed: $P" ;;
    esac
    hpkg_install "$P" && ok "installed $P" || err "install failed for $P"
    ;;
  cache-ext)    # cache-ext <redis|memcached> - install the PHP extension for the running PHP
    E="${1:-}"; PV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
    case "$E" in
      redis) hpkg_install "php-redis" || hpkg_install "php${PV}-redis" ;;
      memcached) hpkg_install "php-memcached" || hpkg_install "php${PV}-memcached" ;;
      *) err "bad ext" ;;
    esac
    reload_web; ok "php $E extension step done"
    ;;

  # -- security+ --
  fail2ban-setup)
    command -v fail2ban-client >/dev/null 2>&1 || err "fail2ban not installed"
    if [ ! -f /etc/fail2ban/jail.local ]; then
      printf '[DEFAULT]\nbantime = 1h\nfindtime = 10m\nmaxretry = 5\n\n[sshd]\nenabled = true\n' > /etc/fail2ban/jail.local
    fi
    systemctl enable fail2ban >/dev/null 2>&1 || true
    systemctl restart fail2ban >/dev/null 2>&1 || systemctl start fail2ban >/dev/null 2>&1 || true
    ok "fail2ban configured"
    ;;
  fail2ban-status)
    command -v fail2ban-client >/dev/null 2>&1 || { echo "NOT_INSTALLED"; exit 0; }
    systemctl is-active fail2ban 2>/dev/null
    fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:\s*//p'
    for j in $(fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:\s*//p' | tr ',' ' '); do
      B="$(fail2ban-client status "$j" 2>/dev/null | sed -n 's/.*Banned IP list:\s*//p')"
      echo "JAIL ${j} | ${B}"
    done
    ;;
  fail2ban-unban)  # fail2ban-unban <jail> <ip>
    J="${1:-}"; IP="${2:-}"
    [[ "$J" =~ ^[A-Za-z0-9._-]+$ ]] || err "bad jail"; is_ip "$IP" || err "bad ip"
    fail2ban-client set "$J" unbanip "$IP" 2>&1 && ok "unbanned $IP" || err "unban failed"
    ;;
  clamav-scan)   # clamav-scan <path>
    P="${1:-}"
    case "$P" in /var/www/*|/opt/orizen/*) : ;; *) err "path must be under /var/www or /opt/orizen" ;; esac
    [[ "$P" != *".."* ]] || err "bad path"; [ -e "$P" ] || err "path not found"
    command -v clamscan >/dev/null 2>&1 || err "clamav not installed"
    clamscan -r --infected --no-summary "$P" 2>/dev/null; echo "SCAN_DONE"
    ;;

  # -- cron installers for optional background jobs (fixed commands) --
  notify-setup)   # notify-setup <webuser>
    WU="${1:-$WEB_USER}"; valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    S="/opt/orizen/panel/modules/notify/checker.php"; [ -f "$S" ] || err "checker not found"
    mkdir -p /etc/cron.d
    printf 'SHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n*/5 * * * * %s php %s >/dev/null 2>&1\n' "$WU" "$S" > /etc/cron.d/orizen-notify
    chmod 644 /etc/cron.d/orizen-notify; ok "notify checker installed"
    ;;
  notify-teardown) rm -f /etc/cron.d/orizen-notify; ok "notify checker removed" ;;
  backupx-setup)  # backupx-setup <webuser>
    WU="${1:-$WEB_USER}"; valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    S="/opt/orizen/panel/modules/backupx/runner.php"; [ -f "$S" ] || err "runner not found"
    mkdir -p /etc/cron.d
    printf 'SHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n17 * * * * %s php %s >/dev/null 2>&1\n' "$WU" "$S" > /etc/cron.d/orizen-backupx
    chmod 644 /etc/cron.d/orizen-backupx; ok "scheduled backups installed"
    ;;
  backupx-teardown) rm -f /etc/cron.d/orizen-backupx; ok "scheduled backups removed" ;;

  # -- runtime manager: multiple PHP versions + per-site selection (Debian/Ubuntu) --
  php-list)
    echo "sockets:"; ls /run/php/ 2>/dev/null | grep -oE 'php[0-9.]+-fpm\.sock' | sed 's/-fpm.sock//;s/php//' | sort -u
    for v in 7.4 8.0 8.1 8.2 8.3; do command -v "php${v}" >/dev/null 2>&1 && echo "have ${v}"; done
    ;;
  php-install)   # php-install <x.y>
    V="${1:-}"; [[ "$V" =~ ^[0-9]\.[0-9]$ ]] || err "bad php version"
    [ "$PKG" = apt ] || err "multi-PHP auto-install currently supports Debian/Ubuntu"
    command -v add-apt-repository >/dev/null 2>&1 || hpkg_install software-properties-common
    add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
    DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 update -qq >/dev/null 2>&1
    hpkg_install "php${V}-fpm" "php${V}-cli" "php${V}-common" "php${V}-mysql" "php${V}-curl" "php${V}-mbstring" "php${V}-xml" "php${V}-zip" "php${V}-gd" || err "php ${V} install failed"
    a2enmod proxy_fcgi setenvif >/dev/null 2>&1 || true
    systemctl enable "php${V}-fpm" >/dev/null 2>&1 || true; systemctl start "php${V}-fpm" >/dev/null 2>&1 || true
    systemctl reload "$WEB_SVC" 2>/dev/null || true
    ok "php ${V} installed"
    ;;
  site-runtime)  # site-runtime <domain> <docroot> <x.y|default>
    D="${1:-}"; DOC="${2:-}"; V="${3:-default}"
    is_domain "$D" || err "bad domain"; safe_docroot "$DOC" || err "bad docroot"
    CF="${VHOST_DIR}/${D}.conf"; [ -f "$CF" ] || err "vhost not found for $D"
    cp -f "$CF" "${CF}.ozbak"
    sed -i '/# ORIZEN-PHP-START/,/# ORIZEN-PHP-END/d' "$CF"
    if [ "$V" != "default" ]; then
      [[ "$V" =~ ^[0-9]\.[0-9]$ ]] || err "bad version"
      SOCK="/run/php/php${V}-fpm.sock"; [ -S "$SOCK" ] || err "php ${V}-fpm not installed"
      BLK=$(mktemp)
      printf '    # ORIZEN-PHP-START\n    <FilesMatch \\.php$>\n        SetHandler "proxy:unix:%s|fcgi://localhost"\n    </FilesMatch>\n    # ORIZEN-PHP-END\n' "$SOCK" > "$BLK"
      awk -v blk="$BLK" 'BEGIN{while((getline l < blk)>0) b=b l"\n"} /<\/VirtualHost>/{printf "%s", b} {print}' "$CF" > "${CF}.tmp" && mv "${CF}.tmp" "$CF"
      rm -f "$BLK"
    fi
    if web_test; then systemctl reload "$WEB_SVC" 2>/dev/null || true; rm -f "${CF}.ozbak"; ok "site ${D} PHP -> ${V}"; else mv -f "${CF}.ozbak" "$CF"; err "apache config test failed - reverted"; fi
    ;;

  # -- site isolation: dedicated user + PHP-FPM pool per site --
  isolate-site)  # isolate-site <domain> <docroot> <x.y> [maxchildren]
    D="${1:-}"; DOC="${2:-}"; V="${3:-}"; MC="${4:-5}"
    is_domain "$D" || err "bad domain"; safe_docroot "$DOC" || err "bad docroot"; [[ "$V" =~ ^[0-9]\.[0-9]$ ]] || err "bad php version"
    [[ "$MC" =~ ^[0-9]+$ ]] || MC=5
    POOLDIR="/etc/php/${V}/fpm/pool.d"; [ -d "$POOLDIR" ] || err "php ${V}-fpm not installed"
    USR="oz_$(echo -n "$D" | md5sum | cut -c1-10)"
    id "$USR" >/dev/null 2>&1 || useradd -M -s /usr/sbin/nologin -d "$DOC" "$USR"
    chown -R "$USR":"$USR" "$DOC"
    SOCK="/run/php/oz-${USR}.sock"
    cat > "${POOLDIR}/oz-${D}.conf" <<POOL
[oz-${D}]
user = ${USR}
group = ${USR}
listen = ${SOCK}
listen.owner = ${WEB_USER}
listen.group = ${WEB_USER}
pm = ondemand
pm.max_children = ${MC}
pm.process_idle_timeout = 30s
php_admin_value[open_basedir] = ${DOC}:/tmp
POOL
    systemctl restart "php${V}-fpm" 2>/dev/null || err "fpm restart failed"
    CF="${VHOST_DIR}/${D}.conf"; [ -f "$CF" ] || err "vhost not found"
    cp -f "$CF" "${CF}.ozbak"; sed -i '/# ORIZEN-PHP-START/,/# ORIZEN-PHP-END/d' "$CF"
    BLK=$(mktemp); printf '    # ORIZEN-PHP-START\n    <FilesMatch \\.php$>\n        SetHandler "proxy:unix:%s|fcgi://localhost"\n    </FilesMatch>\n    # ORIZEN-PHP-END\n' "$SOCK" > "$BLK"
    awk -v blk="$BLK" 'BEGIN{while((getline l < blk)>0) b=b l"\n"} /<\/VirtualHost>/{printf "%s", b} {print}' "$CF" > "${CF}.tmp" && mv "${CF}.tmp" "$CF"; rm -f "$BLK"
    if web_test; then systemctl reload "$WEB_SVC" 2>/dev/null || true; rm -f "${CF}.ozbak"; ok "isolated ${D} as user ${USR} (php ${V}, max ${MC} procs)"; else mv -f "${CF}.ozbak" "$CF"; err "apache test failed - reverted"; fi
    ;;
  unisolate-site) # unisolate-site <domain> <docroot> <x.y>
    D="${1:-}"; DOC="${2:-}"; V="${3:-}"
    is_domain "$D" || err "bad domain"; safe_docroot "$DOC" || err "bad docroot"
    rm -f "/etc/php/${V}/fpm/pool.d/oz-${D}.conf" 2>/dev/null; systemctl reload "php${V}-fpm" 2>/dev/null || true
    CF="${VHOST_DIR}/${D}.conf"; [ -f "$CF" ] && { sed -i '/# ORIZEN-PHP-START/,/# ORIZEN-PHP-END/d' "$CF"; systemctl reload "$WEB_SVC" 2>/dev/null || true; }
    chown -R "$WEB_USER":"$WEB_USER" "$DOC"
    ok "isolation removed for ${D}"
    ;;
  # -- mail queue (postfix) --
  mailq-list)   command -v postqueue >/dev/null 2>&1 || { echo "NO_POSTFIX"; exit 0; }; postqueue -p 2>/dev/null ;;
  mailq-flush)  command -v postqueue >/dev/null 2>&1 && postqueue -f 2>&1; ok "queue flushed" ;;
  mailq-delete) command -v postsuper >/dev/null 2>&1 || err "no postfix"; ID="${1:-}"; case "$ID" in ALL) postsuper -d ALL 2>&1 ;; *) [[ "$ID" =~ ^[A-Za-z0-9]+$ ]] || err "bad id"; postsuper -d "$ID" 2>&1 ;; esac; ok "deleted" ;;

  # -- staging: mirror files between two site docroots --
  site-sync)   # site-sync <srcdoc> <dstdoc> <webuser>
    SRC="${1:-}"; DST="${2:-}"; WU="${3:-$WEB_USER}"
    safe_docroot "$SRC" || err "invalid source"; safe_docroot "$DST" || err "invalid target"
    [ -d "$SRC" ] || err "source not found"; valid_runas "$WU" || err "invalid web user (must be a non-root account)"
    mkdir -p "$DST"
    if command -v rsync >/dev/null 2>&1; then rsync -a --delete "$SRC/" "$DST/" 2>&1; else find "$DST" -mindepth 1 -delete 2>/dev/null; cp -a "$SRC/." "$DST/" 2>&1; fi
    chown -R "$WU":"$WU" "$DST"; ok "synced $SRC -> $DST"
    ;;

  # -- docker management --
  docker-install)
    docker_ensure && ok "docker installed" || err "docker install failed"
    ;;
  docker-ps)     command -v docker >/dev/null 2>&1 || { echo "NOT_INSTALLED"; exit 0; }; docker ps -a --format '{{json .}}' 2>/dev/null ;;
  docker-images) command -v docker >/dev/null 2>&1 || { echo "NOT_INSTALLED"; exit 0; }; docker images --format '{{json .}}' 2>/dev/null ;;
  docker-ctl)    # docker-ctl <start|stop|restart|rm> <id>
    A="${1:-}"; ID="${2:-}"
    case "$A" in start|stop|restart|rm) : ;; *) err "bad action" ;; esac
    [[ "$ID" =~ ^[A-Za-z0-9_.-]+$ ]] || err "bad container id"
    command -v docker >/dev/null 2>&1 || err "docker not installed"
    if [ "$A" = rm ]; then docker rm -f "$ID" 2>&1; else docker "$A" "$ID" 2>&1; fi
    ok "docker $A done"
    ;;
  docker-logs)   # docker-logs <id>
    ID="${1:-}"; [[ "$ID" =~ ^[A-Za-z0-9_.-]+$ ]] || err "bad container id"
    command -v docker >/dev/null 2>&1 || err "docker not installed"
    docker logs --tail 200 "$ID" 2>&1
    ;;
  docker-compose) # docker-compose <up|down> <dir>
    A="${1:-}"; D="${2:-}"
    case "$A" in up|down) : ;; *) err "bad action" ;; esac
    case "$D" in /var/www/*|/opt/orizen/*) : ;; *) err "dir must be under /var/www or /opt/orizen" ;; esac
    [[ "$D" != *".."* ]] || err "bad dir"
    [ -f "$D/docker-compose.yml" ] || [ -f "$D/compose.yml" ] || err "no compose file in $D"
    if [ "$A" = up ]; then ( cd "$D" && docker compose up -d ) 2>&1; else ( cd "$D" && docker compose down ) 2>&1; fi
    ok "compose $A done"
    ;;

  # -- hosting accounts: optional dedicated Linux user per account --
  acct-user-create)  # <username>
    U="${1:-}"; [[ "$U" =~ ^[a-z][a-z0-9_-]{1,30}$ ]] || err "invalid username"
    id "$U" >/dev/null 2>&1 && { ok "user already exists"; exit 0; }
    useradd -m -d "/home/$U" -s /usr/sbin/nologin "$U" 2>&1 || err "useradd failed"
    ok "user $U created"
    ;;
  acct-user-delete)  # <username>
    U="${1:-}"; [[ "$U" =~ ^[a-z][a-z0-9_-]{1,30}$ ]] || err "invalid username"
    id "$U" >/dev/null 2>&1 || { ok "no such user"; exit 0; }
    userdel "$U" 2>&1 || true; ok "user $U removed"
    ;;
  acct-user-lock)    # <username>
    U="${1:-}"; [[ "$U" =~ ^[a-z][a-z0-9_-]{1,30}$ ]] || err "invalid username"
    id "$U" >/dev/null 2>&1 && usermod -L "$U" 2>&1; ok "locked $U"
    ;;
  acct-user-unlock)  # <username>
    U="${1:-}"; [[ "$U" =~ ^[a-z][a-z0-9_-]{1,30}$ ]] || err "invalid username"
    id "$U" >/dev/null 2>&1 && usermod -U "$U" 2>&1; ok "unlocked $U"
    ;;

  # -- payment gateway (Navixo Pay) ---------------------------------
  # A swapfile so the api/worker/dashboard image build does not OOM on small hosts.
  ensure-swap)   # ensure-swap [size_mb]
    swap_ensure "${1:-3072}" && ok "swap enabled" || err "swap setup failed"
    ;;

  # Reverse-proxy vhost: <domain> (80 + self-signed 443) -> http://127.0.0.1:<port>.
  # Public HTTPS is provided by Cloudflare (orange-cloud); CF connects to origin
  # over 443 (Full mode) using the panel's self-signed cert, which it accepts.
  create-proxy-site)   # create-proxy-site <domain> <docroot> <port>
    domain="${1:-}"; docroot="${2:-}"; port="${3:-}"
    is_domain "$domain" || err "invalid domain: $domain"
    is_port "$port" || err "invalid port: $port"
    [ -n "$docroot" ] || docroot="${WEBROOT_BASE}/${domain}/public"
    safe_docroot "$docroot" || err "docroot must be under /var/www or /srv"
    mkdir -p "$docroot/.well-known/acme-challenge"
    chown -R "${WEB_USER}:${WEB_USER}" "$(dirname "$docroot")" 2>/dev/null || chown -R "${WEB_USER}:${WEB_USER}" "$docroot" 2>/dev/null || true
    [ "$VHOST_STYLE" = debian ] && a2enmod proxy proxy_http headers ssl >/dev/null 2>&1 || true
    crt="${DATA_DIR}/ssl/panel.crt"; key="${DATA_DIR}/ssl/panel.key"; sslblock=""
    # Prefer a real Let's Encrypt cert if one exists for this domain: Cloudflare
    # "Full (strict)" requires a trusted origin cert, and LE auto-renews (lifetime).
    if [ -s "/etc/letsencrypt/live/${domain}/fullchain.pem" ] && [ -s "/etc/letsencrypt/live/${domain}/privkey.pem" ]; then
      crt="/etc/letsencrypt/live/${domain}/fullchain.pem"; key="/etc/letsencrypt/live/${domain}/privkey.pem"
    fi
    if [ -s "$crt" ] && [ -s "$key" ]; then
      sslblock="
<VirtualHost *:443>
    ServerName ${domain}
    DocumentRoot ${docroot}
    SSLEngine on
    SSLCertificateFile ${crt}
    SSLCertificateKeyFile ${key}
    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass /.well-known/acme-challenge/ !
    Alias /.well-known/acme-challenge/ ${docroot}/.well-known/acme-challenge/
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/
    RequestHeader set X-Forwarded-Proto \"https\"
    ProxyTimeout 3600
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-ssl-error.log
</VirtualHost>"
    fi
    cat > "${VHOST_DIR}/${domain}.conf" <<VH
<VirtualHost *:80>
    ServerName ${domain}
    DocumentRoot ${docroot}
    ProxyPreserveHost On
    ProxyRequests Off
    ProxyPass /.well-known/acme-challenge/ !
    Alias /.well-known/acme-challenge/ ${docroot}/.well-known/acme-challenge/
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/
    RequestHeader set X-Forwarded-Proto "https"
    ProxyTimeout 3600
    ErrorLog ${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-error.log
    CustomLog ${APACHE_LOG_DIR:-/var/log/apache2}/${domain}-access.log combined
</VirtualHost>${sslblock}
VH
    ensure_default_vhost
    ensite "$domain"
    web_test || err "apache config test failed"
    reload_web
    ok "proxy site ${domain} -> 127.0.0.1:${port}"
    ;;

  # Deploy the Navixo Pay lean stack for a domain: copy the shipped payload, install
  # the panel-generated .env (secrets), then launch the heavy work (docker install,
  # swap, image build, container start) DETACHED so the browser request returns fast.
  paygw-deploy)   # paygw-deploy <domain> <port> <envfile>
    domain="${1:-}"; port="${2:-}"; envfile="${3:-}"
    is_domain "$domain" || err "invalid domain"
    is_port "$port" || err "invalid port"
    case "$envfile" in /opt/orizen/data/*) : ;; *) err "envfile must be under /opt/orizen/data" ;; esac
    [[ "$envfile" != *".."* ]] || err "bad envfile path"
    [ -f "$envfile" ] || err "envfile not found"
    SRC="/opt/orizen/apps/navixo-src"
    [ -d "$SRC" ] || err "navixo payload missing at $SRC"
    DEST="/opt/orizen/apps/navixo/${domain}"
    mkdir -p "$DEST"
    cp -a "$SRC/." "$DEST/"
    install -m 600 "$envfile" "$DEST/.env"
    shred -u "$envfile" 2>/dev/null || rm -f "$envfile"
    LOG="$DEST/build.log"; : > "$LOG"
    # Re-invoke this same helper as root, detached, to do docker+swap+build.
    setsid "$0" _paygw-build "$domain" "$port" </dev/null >/dev/null 2>&1 &
    ok "navixo build started for ${domain} on 127.0.0.1:${port}"
    ;;

  # Internal: the long-running deploy step (runs detached; logs to build.log).
  _paygw-build)   # _paygw-build <domain> <port>
    domain="${1:-}"; port="${2:-}"
    is_domain "$domain" || err "invalid domain"
    DEST="/opt/orizen/apps/navixo/${domain}"
    [ -f "$DEST/docker-compose.panel.yml" ] || err "no deployment for ${domain}"
    LOG="$DEST/build.log"
    {
      echo "== $(date -u) ensuring docker =="
      docker_ensure || echo "WARN: docker_ensure reported a problem"
      echo "== ensuring swap =="
      swap_ensure 3072 || echo "WARN: swap setup skipped"
      DC="$(compose_bin)"
      echo "== building + starting stack with: $DC =="
      ( cd "$DEST" && $DC -f docker-compose.panel.yml up -d --build )
      echo "PAYGW_EXIT=$?"
    } >> "$LOG" 2>&1
    ;;

  paygw-ctl)   # paygw-ctl <up|down|restart|build|status|logs|buildlog> <domain>
    act="${1:-}"; domain="${2:-}"
    case "$act" in up|down|restart|build|status|logs|buildlog) : ;; *) err "bad action" ;; esac
    is_domain "$domain" || err "invalid domain"
    DEST="/opt/orizen/apps/navixo/${domain}"
    [ -d "$DEST" ] || err "no deployment for ${domain}"
    if [ "$act" = buildlog ]; then tail -n 60 "$DEST/build.log" 2>/dev/null; exit 0; fi
    [ -f "$DEST/docker-compose.panel.yml" ] || err "no deployment for ${domain}"
    cd "$DEST" || err "cd failed"
    DCP="$(compose_bin) -f docker-compose.panel.yml"
    case "$act" in
      up)      $DCP up -d 2>&1 ;;
      down)    $DCP down 2>&1 ;;
      restart) $DCP restart 2>&1 ;;
      build)   setsid "$0" _paygw-build "$domain" "0" </dev/null >/dev/null 2>&1 & ok "rebuild started" ;;
      status)  $DCP ps --format '{{.Service}}|{{.State}}|{{.Status}}' 2>&1 ;;
      logs)    $DCP logs --tail 150 2>&1 ;;
    esac
    ;;

  paygw-remove)   # paygw-remove <domain>
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    DEST="/opt/orizen/apps/navixo/${domain}"
    [ -d "$DEST" ] || { ok "nothing to remove"; exit 0; }
    DC="$(compose_bin)"
    ( cd "$DEST" && $DC -f docker-compose.panel.yml down -v 2>&1 ) || true
    rm -rf "$DEST"
    ok "removed navixo deployment ${domain}"
    ;;

  # Issue a real Let's Encrypt cert for a reverse-proxied domain (webroot HTTP-01),
  # then rewrite its proxy vhost to use it. Makes Cloudflare "Full (strict)" work and
  # gives a valid, auto-renewing origin certificate. Best-effort - safe to retry.
  paygw-ssl)   # paygw-ssl <domain> <port> [email]
    domain="${1:-}"; port="${2:-}"; email="${3:-}"
    is_domain "$domain" || err "invalid domain"
    is_port "$port" || err "invalid port"
    command -v certbot >/dev/null 2>&1 || err "certbot not installed"
    docroot="${WEBROOT_BASE}/${domain}/public"
    mkdir -p "$docroot/.well-known/acme-challenge"
    chown -R "${WEB_USER}:${WEB_USER}" "$docroot" 2>/dev/null || true
    if [ -n "$email" ] && is_email "$email"; then reg="-m $email --agree-tos"; else reg="--register-unsafely-without-email --agree-tos"; fi
    certbot certonly --webroot -w "$docroot" -d "$domain" --non-interactive $reg >/dev/null 2>&1 || err "certbot failed for $domain (does the domain resolve to this server yet?)"
    # Re-run the proxy vhost writer, which now picks up the LE cert automatically.
    "$0" create-proxy-site "$domain" "$docroot" "$port" >/dev/null 2>&1 || err "vhost rewrite failed"
    ok "ssl issued for ${domain} (Let's Encrypt)"
    ;;

  # Issue a real Let's Encrypt cert for a normal (non-proxied) website via webroot,
  # then rewrite its vhost to use it. Tries domain + www, falls back to domain only.
  site-ssl)   # site-ssl <domain> [email]
    domain="${1:-}"; email="${2:-}"
    is_domain "$domain" || err "invalid domain"
    command -v certbot >/dev/null 2>&1 || err "certbot not installed"
    docroot="${WEBROOT_BASE}/${domain}/public"
    [ -d "$docroot" ] || err "no docroot for ${domain}"
    mkdir -p "$docroot/.well-known/acme-challenge"
    chown -R "${WEB_USER}:${WEB_USER}" "$docroot" 2>/dev/null || true
    if [ -n "$email" ] && is_email "$email"; then reg="-m $email --agree-tos"; else reg="--register-unsafely-without-email --agree-tos"; fi
    # Only add www if it actually resolves - otherwise the whole (all-or-nothing) request fails slowly.
    names="-d ${domain}"
    if getent hosts "www.${domain}" >/dev/null 2>&1 || dig +short "www.${domain}" 2>/dev/null | grep -qE '[0-9a-f]'; then names="${names} -d www.${domain}"; fi
    # --cert-name pins ONE lineage (no orionlivetest.xyz-0001 duplicates); --expand adopts a changed name set.
    if certbot certonly --webroot -w "$docroot" $names --cert-name "$domain" --expand --non-interactive $reg >/dev/null 2>&1; then :
    elif certbot certonly --webroot -w "$docroot" -d "$domain" --cert-name "$domain" --expand --non-interactive $reg >/dev/null 2>&1; then :
    else err "certbot failed for ${domain} (does it resolve to this server yet?)"; fi
    write_vhost "$domain" "$docroot" "www.${domain}" ssl
    ensite "$domain"
    web_test || err "apache config test failed"
    reload_web
    ok "ssl issued for ${domain} (Let's Encrypt)"
    ;;

  cert-status)   # cert-status <domain> - EXISTS if a live LE cert is present (this dir is root-only, so the panel asks us)
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    if [ -s "/etc/letsencrypt/live/${domain}/fullchain.pem" ]; then echo "EXISTS"; else echo "NONE"; fi
    ;;

  site-ssl-remove)   # site-ssl-remove <domain> - delete the LE cert + revert the vhost to HTTP-only
    domain="${1:-}"; is_domain "$domain" || err "invalid domain"
    docroot="${WEBROOT_BASE}/${domain}/public"
    if command -v certbot >/dev/null 2>&1; then certbot delete --cert-name "$domain" -n >/dev/null 2>&1 || true; fi
    if [ -d "$docroot" ]; then write_vhost "$domain" "$docroot" "www.${domain}"; ensite "$domain"; web_test && reload_web; fi
    ok "ssl removed for ${domain}"
    ;;

  site-force-https)   # site-force-https <domain> <on|off> - redirect http->https at the origin (works in any mode)
    domain="${1:-}"; mode="${2:-on}"
    is_domain "$domain" || err "invalid domain"
    docroot="${WEBROOT_BASE}/${domain}/public"
    [ -d "$docroot" ] || err "no docroot for ${domain}"
    ht="${docroot}/.htaccess"
    # remove any existing Orizen block first (idempotent)
    [ -f "$ht" ] && sed -i '/# ORIZEN-FORCE-HTTPS-START/,/# ORIZEN-FORCE-HTTPS-END/d' "$ht"
    if [ "$mode" = on ]; then
      tmp="$(mktemp)"
      {
        echo "# ORIZEN-FORCE-HTTPS-START"
        echo "<IfModule mod_rewrite.c>"
        echo "RewriteEngine On"
        # Do not loop when the visitor is already on HTTPS (X-Forwarded-Proto is set by Cloudflare / proxies)
        echo "RewriteCond %{HTTPS} off"
        echo "RewriteCond %{HTTP:X-Forwarded-Proto} !https"
        echo "RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/"
        echo "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]"
        echo "</IfModule>"
        echo "# ORIZEN-FORCE-HTTPS-END"
        [ -f "$ht" ] && cat "$ht"
      } > "$tmp"
      mv "$tmp" "$ht"
      chown "${WEB_USER}:${WEB_USER}" "$ht" 2>/dev/null || true
    fi
    ok "force-https ${mode} for ${domain}"
    ;;

  script-run)   # script-run <start|stop|status|log> <id> [interval_sec|lines] [script] [webuser]
    op="${1:-}"; id="${2:-}"
    [[ "$id" =~ ^[A-Za-z0-9_-]{1,32}$ ]] || err "bad id"
    SD=/opt/orizen/data/scripts; mkdir -p "$SD"
    pidf="$SD/${id}.pid"; logf="$SD/${id}.log"; runner="$SD/${id}.runner.sh"
    case "$op" in
      start)
        interval="${3:-60}"; script="${4:-}"; wu="${5:-${WEB_USER}}"
        valid_runas "$wu" || err "invalid web user (must be a non-root account)"
        [[ "$interval" =~ ^[0-9]{1,6}$ ]] && [ "$interval" -ge 1 ] || err "bad interval"
        [ -f "$script" ] || err "script not found"
        case "$script" in /var/www/*|/srv/*|"$SD"/*) ;; *) err "script must be under /var/www, /srv or the scripts dir" ;; esac
        case "$script" in *..*) err "bad path" ;; esac
        if [ -f "$pidf" ] && kill -0 "$(cat "$pidf" 2>/dev/null)" 2>/dev/null; then ok "already running ($(cat "$pidf"))"; exit 0; fi
        # choose interpreter by extension
        case "$script" in *.php) run="php ${script}";; *.py) run="python3 ${script}";; *.js) run="node ${script}";; *) run="bash ${script}";; esac
        cat > "$runner" <<RUN
#!/bin/bash
while true; do
  echo "[\$(date '+%F %T')] --- run ---" >> "${logf}"
  runuser -u "${wu}" -- ${run} >> "${logf}" 2>&1 || echo "[\$(date '+%F %T')] exited \$?" >> "${logf}"
  tail -n 2000 "${logf}" > "${logf}.tmp" 2>/dev/null && mv "${logf}.tmp" "${logf}"
  sleep ${interval}
done
RUN
        chmod +x "$runner"
        setsid bash "$runner" >/dev/null 2>&1 &
        echo $! > "$pidf"
        ok "started (pid $(cat "$pidf"), every ${interval}s)"
        ;;
      stop)
        if [ -f "$pidf" ]; then pid="$(cat "$pidf")"; pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; rm -f "$pidf"; ok "stopped"; else ok "not running"; fi
        ;;
      status)
        if [ -f "$pidf" ] && kill -0 "$(cat "$pidf" 2>/dev/null)" 2>/dev/null; then echo "RUNNING $(cat "$pidf")"; else echo "STOPPED"; fi
        ;;
      log)
        lines="${3:-120}"; [[ "$lines" =~ ^[0-9]{1,4}$ ]] || lines=120
        tail -n "$lines" "$logf" 2>/dev/null || echo "(no output yet)"
        ;;
      *) err "bad op" ;;
    esac
    ;;

  qr)   # qr <text> - render a QR code as SVG (for TOTP enrolment; the text never leaves the server)
    txt="${1:-}"
    [ -n "$txt" ] && [ "${#txt}" -le 512 ] || err "bad qr text"
    command -v qrencode >/dev/null 2>&1 || hpkg_install qrencode >/dev/null 2>&1 || true
    command -v qrencode >/dev/null 2>&1 || err "qrencode not installed"
    qrencode -t SVG -m 1 -o - "$txt"
    ;;

  version) echo "orizen-helper 1.6 (${OS_FAMILY})" ;;
  *) err "unknown action: ${ACTION}" ;;
esac
