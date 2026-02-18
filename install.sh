#!/usr/bin/env bash
# =============================================================
# SyncCU Installer
# Installs Apache, PHP, MySQL and sets up the SyncCU web app.
# Must be run as root (or via sudo).
# Usage: sudo bash install.sh
# =============================================================

set -euo pipefail

# ── Colours ──────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# ── Config (edit these before running) ───────────────────────
APP_DIR="${APP_DIR:-/var/www/synccu}"
WEB_ROOT="${WEB_ROOT:-$APP_DIR/web}"
DOMAIN="${DOMAIN:-localhost}"
DB_NAME="${DB_NAME:-synccu}"
DB_USER="${DB_USER:-synccu_app}"
DB_PASS="${DB_PASS:-$(openssl rand -base64 20)}"
DB_ROOT_PASS="${DB_ROOT_PASS:-}"   # leave blank to prompt

# ── OS detection ─────────────────────────────────────────────
if   [ -f /etc/debian_version ]; then PKG_MGR="apt-get"; DISTRO="debian"
elif [ -f /etc/redhat-release  ]; then PKG_MGR="yum";     DISTRO="rhel"
else error "Unsupported OS. Only Debian/Ubuntu and RHEL/CentOS are supported."; fi

# ── Check root ───────────────────────────────────────────────
[ "$(id -u)" -eq 0 ] || error "Please run as root: sudo bash install.sh"

info "Starting SyncCU installation on ${DISTRO}…"
echo ""

# ── 1. Update package lists ──────────────────────────────────
info "Updating package lists…"
if [ "$DISTRO" = "debian" ]; then
    apt-get update -qq
else
    yum check-update -q || true
fi

# ── 2. Install Apache ─────────────────────────────────────────
info "Installing Apache…"
if [ "$DISTRO" = "debian" ]; then
    apt-get install -y apache2
    systemctl enable apache2 --quiet
    systemctl start  apache2
    a2enmod rewrite headers --quiet
else
    yum install -y httpd
    systemctl enable httpd --quiet
    systemctl start  httpd
fi

# ── 3. Install PHP 8.x ───────────────────────────────────────
info "Installing PHP and required extensions…"
PHP_PKGS="php php-mysql php-mbstring php-json php-opcache php-xml"
if [ "$DISTRO" = "debian" ]; then
    apt-get install -y $PHP_PKGS libapache2-mod-php
else
    yum install -y $PHP_PKGS
fi

# ── 4. Install MySQL / MariaDB ───────────────────────────────
info "Installing MariaDB…"
if [ "$DISTRO" = "debian" ]; then
    apt-get install -y mariadb-server
else
    yum install -y mariadb-server
fi
systemctl enable mariadb --quiet 2>/dev/null || systemctl enable mysqld --quiet || true
systemctl start  mariadb         2>/dev/null || systemctl start  mysqld         || true

# ── 5. Secure MySQL & create DB / user ───────────────────────
info "Configuring MySQL…"
if [ -z "$DB_ROOT_PASS" ]; then
    read -s -p "Enter MySQL root password (press Enter to set one now): " DB_ROOT_PASS
    echo ""
fi

MYSQL="mysql -u root"
[ -n "$DB_ROOT_PASS" ] && MYSQL="mysql -u root -p${DB_ROOT_PASS}"

$MYSQL -e "
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    FLUSH PRIVILEGES;
" || error "MySQL configuration failed. Check your root password and try again."

info "Running database schema…"
$MYSQL "$DB_NAME" < "$(dirname "$0")/db_setup.sql" \
    || error "Database schema import failed."

# ── 6. Deploy application ────────────────────────────────────
info "Deploying application to ${APP_DIR}…"
mkdir -p "$APP_DIR"

# Copy files (when running from the repo directory)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cp -r "${SCRIPT_DIR}/." "${APP_DIR}/"

# ── 7. Create .env ───────────────────────────────────────────
ENV_FILE="${WEB_ROOT}/.env"
if [ -f "$ENV_FILE" ]; then
    warn ".env already exists — skipping (keeping existing credentials)"
else
    info "Creating .env file…"
    cat > "$ENV_FILE" <<EOF
DB_HOST=localhost
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
    chmod 640 "$ENV_FILE"
fi

# ── 8. Set file permissions ───────────────────────────────────
info "Setting file permissions…"
APACHE_USER="www-data"
command -v httpd &>/dev/null && APACHE_USER="apache"

chown -R "${APACHE_USER}:${APACHE_USER}" "$APP_DIR"
chmod -R 750 "$WEB_ROOT"
chmod 640 "$ENV_FILE"

# ── 9. Apache virtual host ───────────────────────────────────
info "Configuring Apache virtual host…"

if [ "$DISTRO" = "debian" ]; then
    VHOST_FILE="/etc/apache2/sites-available/synccu.conf"
    cat > "$VHOST_FILE" <<VHOST
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block access to .env
    <Files ".env">
        Require all denied
    </Files>

    ErrorLog  \${APACHE_LOG_DIR}/synccu_error.log
    CustomLog \${APACHE_LOG_DIR}/synccu_access.log combined
</VirtualHost>
VHOST
    a2ensite synccu --quiet
    a2dissite 000-default --quiet 2>/dev/null || true
    systemctl reload apache2
else
    VHOST_FILE="/etc/httpd/conf.d/synccu.conf"
    cat > "$VHOST_FILE" <<VHOST
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Files ".env">
        Require all denied
    </Files>

    ErrorLog  /var/log/httpd/synccu_error.log
    CustomLog /var/log/httpd/synccu_access.log combined
</VirtualHost>
VHOST
    systemctl reload httpd
fi

# ── 10. Create .htaccess to block .env ───────────────────────
cat > "${WEB_ROOT}/.htaccess" <<'HTACCESS'
Options -Indexes

# Block sensitive files
<FilesMatch "^\.env|config\.php$">
    Require all denied
</FilesMatch>

# Pretty URLs (optional, for future routing)
# RewriteEngine On
# RewriteCond %{REQUEST_FILENAME} !-f
# RewriteCond %{REQUEST_FILENAME} !-d
# RewriteRule ^ index.php [L]
HTACCESS

# ── Done ─────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  SyncCU Installation Complete!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
echo -e "  URL:           http://${DOMAIN}/"
echo -e "  App directory: ${APP_DIR}"
echo -e "  DB Name:       ${DB_NAME}"
echo -e "  DB User:       ${DB_USER}"
echo -e "  DB Password:   ${DB_PASS}"
echo ""
echo -e "${YELLOW}  Default Login:  admin / Admin1234${NC}"
echo -e "${YELLOW}  IMPORTANT: Change the admin password immediately after first login!${NC}"
echo ""
echo -e "  The .env file is at: ${ENV_FILE}"
echo -e "  Backup this password — it cannot be recovered."
echo ""
