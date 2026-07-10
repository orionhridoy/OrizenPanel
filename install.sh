#!/usr/bin/env bash
#
# +------------------------------------------------------------------+
# |  Orizen Panel - web hosting control panel bootstrap installer      |
# |  Cross-distro: Debian/Ubuntu . RHEL/Fedora/Rocky/Alma . openSUSE   |
# |                . Arch (best-effort).                               |
# |  Installs Apache + PHP + MariaDB + Certbot (+ optional mail) and   |
# |  deploys a PHP control panel at  https://SERVER_IP:PORT            |
# |                                                                    |
# |  Fully automatic:   sudo bash install.sh --auto                    |
# |  Interactive:       sudo bash install.sh                           |
# +------------------------------------------------------------------+
set -uo pipefail

# -- pretty output ---------------------------------------------------
C_RESET=$'\e[0m'; C_DIM=$'\e[2m'; C_B=$'\e[1m'
C_GRN=$'\e[32m'; C_YEL=$'\e[33m'; C_RED=$'\e[31m'; C_CYN=$'\e[36m'; C_MAG=$'\e[35m'
say()  { printf '%s\n' "${C_CYN}▸ ${1}${C_RESET}"; }
ok()   { printf '%s\n' "${C_GRN}✔ ${1}${C_RESET}"; }
warn() { printf '%s\n' "${C_YEL}⚠ ${1}${C_RESET}"; }
die()  { printf '%s\n' "${C_RED}✘ ${1}${C_RESET}" >&2; exit 1; }
hr()   { printf '%s\n' "${C_DIM}------------------------------------------------------------${C_RESET}"; }

# -- constants -------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="/opt/orizen"; PANEL_DIR="$APP_DIR/panel"; DATA_DIR="$APP_DIR/data"
HELPER="/usr/local/bin/orizen-helper"; WEBROOT_BASE="/var/www"
LOG="/var/log/orizen-install.log"

[ "$(id -u)" -eq 0 ] || die "Please run as root:  sudo bash install.sh --auto"

# --------------------------------------------------------------------
#  PLATFORM DETECTION  - makes everything below distro-agnostic
# --------------------------------------------------------------------
detect_platform() {
  [ -r /etc/os-release ] && . /etc/os-release
  PRETTY_NAME="${PRETTY_NAME:-$(uname -s)}"
  if   command -v apt-get >/dev/null 2>&1; then PKG=apt
  elif command -v dnf     >/dev/null 2>&1; then PKG=dnf
  elif command -v yum     >/dev/null 2>&1; then PKG=yum
  elif command -v zypper  >/dev/null 2>&1; then PKG=zypper
  elif command -v pacman  >/dev/null 2>&1; then PKG=pacman
  else die "No supported package manager found (need apt, dnf, yum, zypper or pacman)."
  fi

  case "$PKG" in
    apt)
      OS_FAMILY=debian; WEB_SVC=apache2; WEB_USER=www-data; MARIA_SVC=mariadb
      VHOST_DIR=/etc/apache2/sites-available; VHOST_STYLE=debian; FW=ufw
      PKGS_BASE="curl wget unzip zip tar rsync git ca-certificates jq cron openssl qrencode dnsutils apache2-utils"
      PKGS_WEB="apache2 libapache2-mod-php"
      PKGS_PHP="php php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-intl php-bcmath php-imap"
      PKGS_DB="mariadb-server mariadb-client"
      PKGS_SSL="certbot python3-certbot-apache"
      PKGS_MAIL="postfix dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd opendkim opendkim-tools"
      PKGS_SEC="clamav clamav-freshclam fail2ban"; PKGS_CACHE="redis-server php-redis" ;;
    dnf|yum)
      OS_FAMILY=rhel; WEB_SVC=httpd; WEB_USER=apache; MARIA_SVC=mariadb
      VHOST_DIR=/etc/httpd/conf.d; VHOST_STYLE=dropin; FW=firewalld
      PKGS_BASE="curl wget unzip zip tar rsync git ca-certificates jq cronie openssl qrencode bind-utils httpd-tools"
      PKGS_WEB="httpd php mod_ssl"
      PKGS_PHP="php-cli php-mysqlnd php-curl php-mbstring php-xml php-gd php-intl php-bcmath php-imap"
      PKGS_DB="mariadb-server mariadb"
      PKGS_SSL="certbot python3-certbot-apache"
      PKGS_MAIL="postfix dovecot opendkim opendkim-tools"
      PKGS_SEC="clamav clamav-update fail2ban"; PKGS_CACHE="redis php-pecl-redis" ;;
    zypper)
      OS_FAMILY=suse; WEB_SVC=apache2; WEB_USER=wwwrun; MARIA_SVC=mariadb
      VHOST_DIR=/etc/apache2/vhosts.d; VHOST_STYLE=dropin; FW=firewalld
      PKGS_BASE="curl wget unzip zip tar rsync git ca-certificates jq cron openssl qrencode bind-utils apache2-utils"
      PKGS_WEB="apache2 apache2-mod_php8"
      PKGS_PHP="php8 php8-cli php8-mysql php8-curl php8-mbstring php8-xml php8-zip php8-gd php8-intl php8-bcmath php8-imap"
      PKGS_DB="mariadb mariadb-client"
      PKGS_SSL="certbot python3-certbot-apache"
      PKGS_MAIL="postfix dovecot"
      PKGS_SEC="clamav fail2ban"; PKGS_CACHE="redis php8-redis" ;;
    pacman)
      OS_FAMILY=arch; WEB_SVC=httpd; WEB_USER=http; MARIA_SVC=mariadb
      VHOST_DIR=/etc/httpd/conf/extra; VHOST_STYLE=arch; FW=none
      PKGS_BASE="curl wget unzip zip tar rsync git ca-certificates jq cronie openssl qrencode bind"
      PKGS_WEB="apache php php-apache"
      PKGS_PHP="php-gd php-intl"
      PKGS_DB="mariadb"
      PKGS_SSL="certbot certbot-apache"
      PKGS_MAIL="postfix dovecot"
      PKGS_SEC="clamav fail2ban"; PKGS_CACHE="redis php-redis" ;;
  esac
}

pkg_update() {
  case "$PKG" in
    apt) DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 update -qq ;;
    dnf) dnf -y -q makecache 2>/dev/null || true ;;
    yum) yum -y -q makecache 2>/dev/null || true ;;
    zypper) zypper -n --gpg-auto-import-keys refresh >/dev/null 2>&1 || true ;;
    pacman) pacman -Sy --noconfirm >/dev/null 2>&1 || true ;;
  esac
}
pkg_install() {  # pkg_install <packages...>
  case "$PKG" in
    apt) DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq $* >/dev/null ;;
    dnf) dnf install -y -q $* >/dev/null ;;
    yum) yum install -y -q $* >/dev/null ;;
    zypper) zypper -n install --no-recommends -y $* >/dev/null ;;
    pacman) pacman -S --noconfirm --needed $* >/dev/null ;;
  esac
}
svc()   { systemctl "$1" "$2" >/dev/null 2>&1 || true; }
reload_web() { systemctl reload "$WEB_SVC" 2>/dev/null || systemctl restart "$WEB_SVC" 2>/dev/null || true; }

enable_apache_modules() {
  case "$OS_FAMILY" in
    debian) a2enmod rewrite ssl headers setenvif >/dev/null 2>&1 || true ;;
    suse)   a2enmod rewrite ssl headers php8 >/dev/null 2>&1 || true; a2enflag SSL >/dev/null 2>&1 || true ;;
    rhel)   : ;;  # rewrite/headers loaded by default; mod_ssl pkg provides ssl
    arch)
      local hc=/etc/httpd/conf/httpd.conf
      sed -i 's|^#\(LoadModule rewrite_module\)|\1|' "$hc" 2>/dev/null || true
      sed -i 's|^#\(LoadModule ssl_module\)|\1|'     "$hc" 2>/dev/null || true
      grep -q 'libphp' "$hc" 2>/dev/null || printf '\nLoadModule php_module modules/libphp.so\nAddHandler php-script .php\nInclude conf/extra/php_module.conf\n' >> "$hc"
      grep -q 'orizen' "$hc" 2>/dev/null || echo 'IncludeOptional conf/extra/orizen*.conf' >> "$hc" ;;
  esac
}

fw_allow() {  # fw_allow <port>
  case "$FW" in
    ufw) ufw allow "${1}/tcp" >/dev/null 2>&1 || true ;;
    firewalld) firewall-cmd --permanent --add-port="${1}/tcp" >/dev/null 2>&1 || true ;;
  esac
}

detect_platform
export DEBIAN_FRONTEND=noninteractive

# -- automatic (no-questions) mode detection -------------------------
AUTO=0
for a in "$@"; do case "$a" in --auto|-y|--yes|--unattended) AUTO=1;; esac; done
[ "${ORIZEN_AUTO:-0}" = "1" ] && AUTO=1
[ -t 0 ] || AUTO=1

# -- intro banner ----------------------------------------------------
clear 2>/dev/null || true
cat <<BANNER
${C_MAG}${C_B}  ___        _                   ${C_RESET}
${C_MAG}${C_B} / _ \ _ __ (_) ____  ___  _ __  ${C_RESET}${C_DIM} Panel${C_RESET}
${C_MAG}${C_B}| | | | '__|| ||_  / / _ \| '_ \ ${C_RESET}
${C_MAG}${C_B}| |_| | |   | | / / |  __/| | | |${C_RESET}
${C_MAG}${C_B} \___/|_|   |_|/___| \___||_| |_|${C_RESET}
       self-hosted web hosting control panel
BANNER
echo
say "Detected: ${C_B}${PRETTY_NAME}${C_RESET}  .  family ${C_B}${OS_FAMILY}${C_RESET}  .  pkg ${C_B}${PKG}${C_RESET}  .  $(uname -m)"
echo

# -- auto-detect public IP -------------------------------------------
detect_ip() {
  local ip=""
  for u in "https://api.ipify.org" "https://ifconfig.me/ip" "https://icanhazip.com"; do
    ip="$(curl -fsS --max-time 6 "$u" 2>/dev/null | tr -d '[:space:]')" && [ -n "$ip" ] && { echo "$ip"; return; }
  done
  hostname -I 2>/dev/null | awk '{print $1}'
}
PUBLIC_IP="$(detect_ip)"

# -- prompt helpers --------------------------------------------------
ask() { local p="$1" d="${2:-}" a=""; if [ -n "$d" ]; then read -r -p "  ${C_B}${p}${C_RESET} [${C_GRN}${d}${C_RESET}]: " a; else read -r -p "  ${C_B}${p}${C_RESET}: " a; fi; echo "${a:-$d}"; }
ask_secret() { local p="$1" a=""; read -r -s -p "  ${C_B}${p}${C_RESET}: " a; echo >&2; echo "$a"; }
ask_yn() { local p="$1" d="${2:-y}" a=""; read -r -p "  ${C_B}${p}${C_RESET} ($([ "$d" = y ] && echo 'Y/n' || echo 'y/N')): " a; a="${a:-$d}"; [[ "$a" =~ ^[Yy] ]]; }
valid_domain() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }

# --------------------------------------------------------------------
#  SETTINGS - automatic or short questionnaire
# --------------------------------------------------------------------
if [ "$AUTO" -eq 1 ]; then
  # random login (username + password) - no predictable defaults
  PANEL_USER="orizen_$( (openssl rand -hex 3 2>/dev/null || head -c 3 /dev/urandom | xxd -p 2>/dev/null || echo "${RANDOM}") | tr -dc 'a-z0-9' | head -c 6)"
  [ "$PANEL_USER" = "orizen_" ] && PANEL_USER="orizen_${RANDOM}"
  PANEL_PASS="$( (openssl rand -base64 16 2>/dev/null || head -c 16 /dev/urandom | base64) | tr -dc 'A-Za-z0-9' | head -c 16)"
  [ -n "$PANEL_PASS" ] || PANEL_PASS="op${RANDOM}${RANDOM}"
  AUTO_GEN_PASS=1
  PANEL_PORT="1337"; SERVER_IP="${PUBLIC_IP}"; PRIMARY_DOMAIN=""; ADMIN_EMAIL=""
  INSTALL_SSL=no; INSTALL_MAIL=yes; ENABLE_FW=yes   # --auto installs EVERYTHING, mail included
  SERVER_TZ="$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || echo UTC)"
  hr; say "${C_B}Fully automatic mode${C_RESET} - no questions. Installing everything (web, DB, HTTPS, Docker, mail, security)..."; hr; echo
else
  hr; say "I'll ask a few simple things. Press Enter to accept the [default]."; hr; echo
  PANEL_USER="$(ask 'Control-panel login username' 'admin')"
  while :; do
    PANEL_PASS="$(ask_secret 'Control-panel login password (min 6 chars)')"
    PANEL_PASS2="$(ask_secret 'Repeat password')"
    [ "$PANEL_PASS" = "$PANEL_PASS2" ] || { warn "Passwords don't match."; continue; }
    [ "${#PANEL_PASS}" -ge 6 ] || { warn "Too short (min 6)."; continue; }; break
  done
  PANEL_PORT="$(ask 'Port to reach the panel on (e.g. 1337)' '1337')"
  [[ "$PANEL_PORT" =~ ^[0-9]+$ ]] && [ "$PANEL_PORT" -ge 1 ] && [ "$PANEL_PORT" -le 65535 ] || die "Invalid port."
  echo; say "Your server's public IP looks like: ${C_B}${PUBLIC_IP:-unknown}${C_RESET}"
  SERVER_IP="$(ask 'Confirm public IP' "${PUBLIC_IP}")"
  echo; say "Primary domain (optional). Leave blank to start IP-only and add sites later."
  PRIMARY_DOMAIN="$(ask 'Primary domain (e.g. example.com)' '')"
  [ -n "$PRIMARY_DOMAIN" ] && ! valid_domain "$PRIMARY_DOMAIN" && { warn "Not a valid domain; skipping primary site."; PRIMARY_DOMAIN=""; }
  ADMIN_EMAIL="$(ask 'Admin email (for Lets Encrypt)' "")"
  echo; INSTALL_SSL=no
  [ -n "$PRIMARY_DOMAIN" ] && ask_yn "Get HTTPS for ${PRIMARY_DOMAIN} now? (its DNS must already point to ${SERVER_IP})" n && INSTALL_SSL=yes
  INSTALL_MAIL=yes; ask_yn "Install a mail server (Postfix + Dovecot + OpenDKIM)?" y || INSTALL_MAIL=no
  ENABLE_FW=yes; ask_yn "Configure the firewall (recommended)?" y || ENABLE_FW=no
  SERVER_TZ="$(ask 'Server timezone' "$(cat /etc/timezone 2>/dev/null || echo UTC)")"
  echo; hr
  printf '%b\n' "${C_B}Install summary:${C_RESET}\n   Panel : ${C_GRN}${PANEL_USER}${C_RESET} @ https://${SERVER_IP}:${PANEL_PORT}\n   Domain: ${PRIMARY_DOMAIN:-<IP only>}   Mail: ${INSTALL_MAIL}   Firewall: ${ENABLE_FW}"
  hr; ask_yn "Proceed?" y || die "Aborted."; echo
fi

exec > >(tee -a "$LOG") 2>&1
say "Full log: $LOG"
timedatectl set-timezone "$SERVER_TZ" >/dev/null 2>&1 || true

# -- 1. base packages ------------------------------------------------
say "Refreshing package lists..."; pkg_update
[ "$OS_FAMILY" = rhel ] && { pkg_install epel-release || true; }
say "Installing base tools..."; pkg_install $PKGS_BASE || warn "Some base tools failed (continuing)."
ok "Base tools ready."

# -- 2. Apache + PHP -------------------------------------------------
say "Installing web server (Apache) + PHP..."
pkg_install $PKGS_WEB || warn "Web server install reported a problem."
pkg_install $PKGS_PHP || warn "Some PHP extensions failed (continuing)."
enable_apache_modules
svc enable "$WEB_SVC"; svc start "$WEB_SVC"
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '8')"
mkdir -p "$WEBROOT_BASE"
ok "Apache + PHP ${PHP_VER} ready (service: ${WEB_SVC}, user: ${WEB_USER})."

# -- 3. MariaDB ------------------------------------------------------
say "Installing MariaDB database server..."
pkg_install $PKGS_DB || warn "MariaDB install reported a problem."
svc enable "$MARIA_SVC"; svc start "$MARIA_SVC"
sleep 2
DB_ADMIN_USER="panel_admin"
DB_ADMIN_PASS="$( (openssl rand -base64 18 2>/dev/null || head -c 18 /dev/urandom | base64) | tr -dc 'A-Za-z0-9' | head -c 24)"
say "Creating database admin account..."
mysql --protocol=socket -uroot <<SQL 2>/dev/null || warn "MariaDB bootstrap warning (continuing)."
CREATE USER IF NOT EXISTS '${DB_ADMIN_USER}'@'localhost' IDENTIFIED BY '${DB_ADMIN_PASS}';
CREATE USER IF NOT EXISTS '${DB_ADMIN_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_ADMIN_PASS}';
GRANT ALL PRIVILEGES ON *.* TO '${DB_ADMIN_USER}'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON *.* TO '${DB_ADMIN_USER}'@'127.0.0.1' WITH GRANT OPTION;
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
SQL
# sanity-check the panel can actually authenticate
if mysql -u"${DB_ADMIN_USER}" -p"${DB_ADMIN_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
  ok "MariaDB ready (panel can connect)."
else
  warn "MariaDB installed but the panel account check failed - the panel will retry socket+TCP at runtime."
fi

# -- 4. Certbot ------------------------------------------------------
say "Installing Certbot (Lets Encrypt)..."
pkg_install $PKGS_SSL 2>/dev/null || warn "Certbot package not available here - install it later if you want HTTPS."
ok "SSL tooling step done."

# -- 4b. Security + object cache (ClamAV, Fail2Ban, Redis) -----------
say "Installing security + cache (ClamAV, Fail2Ban, Redis object cache)..."
pkg_install $PKGS_SEC   2>/dev/null || warn "Some security packages were unavailable (continuing)."
pkg_install $PKGS_CACHE 2>/dev/null || warn "Redis/object-cache package unavailable here (continuing)."
# Redis object cache
svc enable redis-server; svc start redis-server; svc enable redis; svc start redis
# ClamAV signatures (first run downloads the DB in the background; never blocks install)
svc enable clamav-freshclam; svc start clamav-freshclam
command -v freshclam >/dev/null 2>&1 && (setsid freshclam >/dev/null 2>&1 &) || true
# Fail2Ban - protect SSH out of the box; enable + start
if command -v fail2ban-server >/dev/null 2>&1; then
  if [ ! -f /etc/fail2ban/jail.local ]; then
    cat > /etc/fail2ban/jail.local <<'F2B'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true
F2B
  fi
  svc enable fail2ban; svc restart fail2ban
fi
ok "Security + cache step done."

# -- 5. deploy panel + helper + platform.env -------------------------
say "Deploying control panel to ${APP_DIR}..."
mkdir -p "$PANEL_DIR" "$DATA_DIR" "$DATA_DIR/ssl" "$DATA_DIR/backups" "$DATA_DIR/backups/update"
cp -r "$SCRIPT_DIR/core/ui/." "$PANEL_DIR/"
install -m 0755 -o root -g root "$SCRIPT_DIR/core/bin/orizen-helper.sh" "$HELPER"
[ -f "$SCRIPT_DIR/core/bin/orizen-cli.sh" ] && install -m 0755 -o root -g root "$SCRIPT_DIR/core/bin/orizen-cli.sh" /usr/local/bin/orizen   # REST/automation CLI (optional)
# Stage the payment engine so the Payment Gateway module can deploy gateways per domain.
if [ -d "$SCRIPT_DIR/core/svc" ]; then
  mkdir -p /opt/orizen/apps
  cp -a "$SCRIPT_DIR/core/svc" /opt/orizen/apps/navixo-src
fi

# Stable installation ID - generated ONCE, reused forever, preserved across updates/migrations.
if [ ! -f "$DATA_DIR/install_id" ]; then
  ( command -v uuidgen >/dev/null 2>&1 && uuidgen \
    || cat /proc/sys/kernel/random/uuid 2>/dev/null \
    || php -r 'printf("%s-%s-%s-%s-%s\n",bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));' ) \
    > "$DATA_DIR/install_id" 2>/dev/null
fi

# platform.env - read by the helper AND the panel so everything is distro-aware
cat > "$DATA_DIR/platform.env" <<ENV
OS_FAMILY=${OS_FAMILY}
PKG=${PKG}
WEB_SVC=${WEB_SVC}
WEB_USER=${WEB_USER}
MARIA_SVC=${MARIA_SVC}
VHOST_DIR=${VHOST_DIR}
VHOST_STYLE=${VHOST_STYLE}
FW=${FW}
WEBROOT_BASE=${WEBROOT_BASE}
ENV

# -- 5b. Docker Engine + Compose (the Payment Gateway module deploys each gateway as containers) --
# Uses the helper's cross-distro docker_ensure (Docker's official repo on Debian/Ubuntu, distro
# packages elsewhere). Best-effort: if it can't finish now, the panel installs Docker the first
# time you deploy a gateway.
say "Installing Docker Engine + Compose (crypto payment gateway engine)..."
if "$HELPER" docker-install >/dev/null 2>&1 && command -v docker >/dev/null 2>&1; then
  ok "Docker ready."
else
  warn "Docker isn't ready yet - the panel installs it automatically the first time you deploy a gateway."
fi

if [ ! -s "$DATA_DIR/ssl/panel.crt" ]; then
  say "Generating a self-signed certificate for the panel..."
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "$DATA_DIR/ssl/panel.key" -out "$DATA_DIR/ssl/panel.crt" -subj "/CN=${SERVER_IP}" >/dev/null 2>&1
fi

cat > "$DATA_DIR/config.json" <<JSON
{
  "admin_user": "${PANEL_USER}",
  "admin_hash": "$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PANEL_PASS")",
  "db_host": "localhost", "db_user": "${DB_ADMIN_USER}", "db_pass": "${DB_ADMIN_PASS}",
  "server_ip": "${SERVER_IP}", "primary_domain": "${PRIMARY_DOMAIN}", "admin_email": "${ADMIN_EMAIL}",
  "php_ver": "${PHP_VER}", "mail_enabled": $([ "$INSTALL_MAIL" = yes ] && echo true || echo false),
  "panel_port": "${PANEL_PORT}", "webroot_base": "${WEBROOT_BASE}",
  "web_svc": "${WEB_SVC}", "web_user": "${WEB_USER}", "os_family": "${OS_FAMILY}",
  "maria_svc": "${MARIA_SVC}", "dev_name": "Orion Hridoy", "dev_fb": "",
  "schema_version": 2,
  "features": { "enableDocker": false, "enableMultiPHP": false, "enableMultiServer": false, "enableSiteIsolation": false, "enableMonitoring": true, "enableGitDeploy": true, "enableRemoteBackup": true },
  "stats_enabled": true, "stats_url": "https://worker-throbbing-morning-7bb5.dokalura.workers.dev/",
  "created": "$(date -Iseconds)"
}
JSON
[ -f "$DATA_DIR/sites.json" ] || echo '[]' > "$DATA_DIR/sites.json"
[ -f "$DATA_DIR/mail.json" ]  || echo '{"domains":[],"mailboxes":[]}' > "$DATA_DIR/mail.json"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
chmod 750 "$DATA_DIR"; chmod 640 "$DATA_DIR/config.json"
ok "Panel files deployed."

# -- 6. sudoers ------------------------------------------------------
say "Granting the panel permission to run the helper as root (locked)..."
SUDOERS=/etc/sudoers.d/orizen
echo "${WEB_USER} ALL=(root) NOPASSWD: ${HELPER}" > "$SUDOERS"; chmod 440 "$SUDOERS"
visudo -cf "$SUDOERS" >/dev/null 2>&1 || { rm -f "$SUDOERS"; warn "sudoers validation failed - privileged actions disabled."; }
ok "Privilege separation configured."

# -- 7. panel vhost on the chosen port (HTTPS, self-signed) ----------
say "Publishing the panel on port ${PANEL_PORT}..."
PANEL_VHOST="<VirtualHost *:${PANEL_PORT}>
    ServerName ${PRIMARY_DOMAIN:-$SERVER_IP}
    ServerAlias *
    DocumentRoot ${PANEL_DIR}
    DirectoryIndex index.php
    SSLEngine on
    SSLCertificateFile ${DATA_DIR}/ssl/panel.crt
    SSLCertificateKeyFile ${DATA_DIR}/ssl/panel.key
    SetEnvIf Authorization \"(.+)\" HTTP_AUTHORIZATION=\$1
    <Directory ${PANEL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>"
case "$OS_FAMILY" in
  debian)
    grep -qE "^\s*Listen\s+${PANEL_PORT}\b" /etc/apache2/ports.conf || echo "Listen ${PANEL_PORT}" >> /etc/apache2/ports.conf
    printf '%s\n' "$PANEL_VHOST" > "${VHOST_DIR}/orizen.conf"
    a2ensite orizen.conf >/dev/null 2>&1 || true ;;
  *)
    printf 'Listen %s\n%s\n' "$PANEL_PORT" "$PANEL_VHOST" > "${VHOST_DIR}/orizen.conf" ;;
esac

# auto-SSL: secure pointed domains automatically every 10 min (zero clicks)
mkdir -p /etc/cron.d
cat > /etc/cron.d/orizen-autossl <<CRON
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/10 * * * * root ${HELPER} auto-ssl-scan '${ADMIN_EMAIL}' >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/orizen-autossl
svc enable cron; svc enable cronie; svc start cron; svc start cronie

# friendly landing page on :80
DEFAULT_DOC="${WEBROOT_BASE}/html"; mkdir -p "$DEFAULT_DOC"
cat > "$DEFAULT_DOC/index.html" <<HTML
<!doctype html><meta charset="utf-8"><title>Server ready</title>
<style>body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f4f5f7;color:#15171c;display:grid;place-items:center;height:100vh;margin:0;text-align:center}h1{font-weight:800;letter-spacing:-.02em}</style>
<div><h1>Your server is live</h1></div>
HTML
chown -R "$WEB_USER":"$WEB_USER" "$DEFAULT_DOC"
reload_web
ok "Panel published on https://${SERVER_IP}:${PANEL_PORT}"

# -- 8. primary site + optional SSL ----------------------------------
if [ -n "$PRIMARY_DOMAIN" ]; then
  say "Creating the primary website ${PRIMARY_DOMAIN}..."
  "$HELPER" create-site "$PRIMARY_DOMAIN" "${WEBROOT_BASE}/${PRIMARY_DOMAIN}/public" || warn "Primary site problem."
  TMP=$(mktemp); jq --arg d "$PRIMARY_DOMAIN" --arg r "${WEBROOT_BASE}/${PRIMARY_DOMAIN}/public" \
     '. += [{"domain":$d,"docroot":$r,"ssl":false}]' "$DATA_DIR/sites.json" > "$TMP" && mv "$TMP" "$DATA_DIR/sites.json"
  chown "$WEB_USER":"$WEB_USER" "$DATA_DIR/sites.json"
  if [ "$INSTALL_SSL" = yes ]; then
    say "Requesting HTTPS certificate..."
    "$HELPER" issue-ssl "$PRIMARY_DOMAIN" "$ADMIN_EMAIL" && ok "HTTPS active." || warn "Couldn't get a cert yet (DNS not pointed?). Retry later from the panel."
  fi
fi

# -- 9. mail server (Postfix + Dovecot + OpenDKIM) - cross-distro ----
if [ "$INSTALL_MAIL" = yes ]; then
  say "Installing mail server (Postfix + Dovecot + OpenDKIM)..."
  MAILNAME="${PRIMARY_DOMAIN:-$(hostname -f 2>/dev/null || hostname)}"
  # The helper installs the right packages for THIS distro, then configures them.
  if "$HELPER" mail-install "${MAILNAME}" && "$HELPER" mail-bootstrap "${MAILNAME}" "${SERVER_IP}"; then
    ok "Mail server installed. Add email accounts in the panel -> Email."
  else
    warn "Mail server install had a problem (see $LOG) - retry anytime from the panel: Email -> Install mail server."
    # reflect reality so the panel shows the one-click installer instead of a broken form
    TMP=$(mktemp); jq '.mail_enabled=false' "$DATA_DIR/config.json" > "$TMP" && mv "$TMP" "$DATA_DIR/config.json"
    chown "$WEB_USER":"$WEB_USER" "$DATA_DIR/config.json"
  fi
fi

# -- 10. firewall ----------------------------------------------------
if [ "$ENABLE_FW" = yes ]; then
  say "Configuring firewall..."
  case "$FW" in
    ufw) pkg_install ufw || true; ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null 2>&1 || true
         for p in 80 443 "$PANEL_PORT"; do fw_allow "$p"; done
         [ "$INSTALL_MAIL" = yes ] && for p in 25 587 465 143 993 110 995; do fw_allow "$p"; done
         ufw --force enable >/dev/null 2>&1 || true ;;
    firewalld) pkg_install firewalld || true; svc enable firewalld; svc start firewalld
         firewall-cmd --permanent --add-service=ssh >/dev/null 2>&1 || true
         for p in 80 443 "$PANEL_PORT"; do fw_allow "$p"; done
         [ "$INSTALL_MAIL" = yes ] && for p in 25 587 465 143 993 110 995; do fw_allow "$p"; done
         firewall-cmd --reload >/dev/null 2>&1 || true ;;
    *) warn "No managed firewall on ${OS_FAMILY} - configure iptables manually if needed." ;;
  esac
  ok "Firewall configured."
fi

# -- 11. installation counter (silent, one-shot, developer stats only) --
# Sends ONLY the anonymous install_id, once, in the background. Never blocks the
# install, ignores all failures, no cron/heartbeat. Fully opt-out: set
# "stats_enabled": false in /opt/orizen/data/config.json.
mkdir -p "$DATA_DIR"
if [ ! -f "$DATA_DIR/install_id" ]; then
  ( command -v uuidgen >/dev/null 2>&1 && uuidgen || cat /proc/sys/kernel/random/uuid 2>/dev/null ) > "$DATA_DIR/install_id" 2>/dev/null
fi
STATS_ENABLED=$(jq -r '.stats_enabled // true' "$DATA_DIR/config.json" 2>/dev/null || echo true)
if [ "$STATS_ENABLED" = "true" ]; then
  INSTALL_ID=$(cat "$DATA_DIR/install_id" 2>/dev/null)
  STATS_URL=$(jq -r '.stats_url // ""' "$DATA_DIR/config.json" 2>/dev/null)
  # fallback parse if jq is unavailable for any reason
  [ -z "$STATS_URL" ] && STATS_URL=$(sed -n 's/.*"stats_url"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$DATA_DIR/config.json" 2>/dev/null | head -n1)
  if [ -n "$INSTALL_ID" ] && [ -n "$STATS_URL" ]; then
    ( curl -fsS --max-time 8 -X POST -H "Content-Type: application/json" \
        -d "{\"install_id\":\"${INSTALL_ID}\"}" "$STATS_URL" >/dev/null 2>&1 || true ) &
  fi
fi

# -- done ------------------------------------------------------------
LOGIN_NOTE="(the password you chose)"
if [ "${AUTO_GEN_PASS:-0}" = 1 ]; then
  printf 'Orizen Panel login\n  URL : https://%s:%s\n  User: %s\n  Pass: %s\n' "$SERVER_IP" "$PANEL_PORT" "$PANEL_USER" "$PANEL_PASS" > /root/orizen-login.txt 2>/dev/null || true
  chmod 600 /root/orizen-login.txt 2>/dev/null || true
  LOGIN_NOTE="${C_GRN}${C_B}${PANEL_PASS}${C_RESET}"
fi
echo; hr
cat <<DONE
${C_GRN}${C_B}  Installation complete!${C_RESET}  ${C_DIM}(${PRETTY_NAME})${C_RESET}

  ${C_B}Open your control panel:${C_RESET}
     ${C_CYN}https://${SERVER_IP}:${PANEL_PORT}${C_RESET}
     (Your browser will warn about the self-signed certificate - this is
      expected. Click "Advanced", then "Proceed".)

  ${C_B}Username:${C_RESET}  ${PANEL_USER}
  ${C_B}Password:${C_RESET}  ${LOGIN_NOTE}
$([ "${AUTO_GEN_PASS:-0}" = 1 ] && echo "  ${C_DIM}(also saved to /root/orizen-login.txt - keep it safe!)${C_RESET}")

  ${C_B}Put a domain online:${C_RESET}
     1) At your registrar, A record:  @ -> ${SERVER_IP}  and  www -> ${SERVER_IP}
     2) Panel -> Websites -> Add the domain.
     3) HTTPS turns on by itself within ~10 minutes.

  ${C_DIM}Panel: ${PANEL_DIR}  .  Data: ${DATA_DIR}  .  Log: ${LOG}${C_RESET}
DONE
hr
