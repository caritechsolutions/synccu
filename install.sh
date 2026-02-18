#!/usr/bin/env bash
# =============================================================
# SyncCU Installer
# Run via curl:
#   curl -sSL "https://raw.githubusercontent.com/caritechsolutions/synccu/main/install.sh?$(date +%s)" | sudo bash
#
# Or with options:
#   curl -sSL "..." | sudo bash -s -- --domain=mycu.org --db-pass=secret
# =============================================================

set -euo pipefail

# ── Colours ──────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
section() { echo -e "\n${BLUE}${BOLD}▶ $*${NC}"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

# ── Defaults (override with --flag=value args) ────────────────
REPO_URL="https://github.com/caritechsolutions/synccu.git"
BRANCH="main"
APP_DIR="/var/www/synccu"
WEB_ROOT=""                       # derived from APP_DIR below
DOMAIN="localhost"
DB_NAME="synccu"
DB_USER="synccu_app"
DB_PASS="$(openssl rand -base64 20 | tr -d '/+=' | head -c 20)"
DB_ROOT_PASS=""
INSTALL_OLLAMA=false

# ── Parse arguments ───────────────────────────────────────────
for arg in "$@"; do
    case "$arg" in
        --branch=*)       BRANCH="${arg#*=}"     ;;
        --install-dir=*)  APP_DIR="${arg#*=}"    ;;
        --domain=*)       DOMAIN="${arg#*=}"     ;;
        --db-name=*)      DB_NAME="${arg#*=}"    ;;
        --db-user=*)      DB_USER="${arg#*=}"    ;;
        --db-pass=*)      DB_PASS="${arg#*=}"    ;;
        --db-root-pass=*) DB_ROOT_PASS="${arg#*=}" ;;
        *) warn "Unknown argument: $arg" ;;
    esac
done

WEB_ROOT="${APP_DIR}/web"

# ── Root check ───────────────────────────────────────────────
[[ "$(id -u)" -eq 0 ]] || error "Please run as root:  curl ... | sudo bash"

# ── Already installed? ────────────────────────────────────────
if [[ -f "${WEB_ROOT}/.env" ]]; then
    error "SyncCU appears to be already installed at ${APP_DIR}.\nRun update.sh to update it instead."
fi

# ── Banner ────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║         SyncCU Installer                 ║${NC}"
echo -e "${BOLD}${BLUE}║  Prudential Credit Union Web App         ║${NC}"
echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════╝${NC}"
echo ""
info "Install directory : ${APP_DIR}"
info "Web root          : ${WEB_ROOT}"
info "Domain            : ${DOMAIN}"
info "Database name     : ${DB_NAME}"
info "Branch            : ${BRANCH}"
echo ""

# ── OS detection ─────────────────────────────────────────────
section "Detecting operating system"
if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS_ID="${ID}"
    OS_VERSION="${VERSION_ID:-}"
else
    error "Cannot detect OS — /etc/os-release not found."
fi
info "Detected: ${PRETTY_NAME:-${OS_ID}}"

case "$OS_ID" in
    ubuntu|debian|raspbian)
        PKG_MGR="apt-get"
        APACHE_SVC="apache2"
        APACHE_USER="www-data"
        PHP_EXTRA="libapache2-mod-php"
        ;;
    centos|rhel|rocky|almalinux|fedora)
        PKG_MGR="yum"
        APACHE_SVC="httpd"
        APACHE_USER="apache"
        PHP_EXTRA=""
        ;;
    *)
        error "Unsupported OS: ${OS_ID}. Supported: Ubuntu, Debian, CentOS, RHEL, Rocky, AlmaLinux."
        ;;
esac

# ── 1. System packages ────────────────────────────────────────
section "Installing system packages"
if [[ "$PKG_MGR" == "apt-get" ]]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq \
        git curl wget unzip \
        apache2 \
        php php-mysql php-mbstring php-json php-xml php-opcache php-curl \
        libapache2-mod-php \
        mariadb-server \
        openssl
    a2enmod rewrite headers -q
else
    yum install -y epel-release
    yum install -y \
        git curl wget unzip \
        httpd \
        php php-mysqlnd php-mbstring php-json php-xml php-opcache php-curl \
        mariadb-server \
        openssl
fi
info "Packages installed"

# ── 2. Start services ─────────────────────────────────────────
section "Starting services"
systemctl enable "$APACHE_SVC" --quiet 2>/dev/null || true
systemctl start  "$APACHE_SVC" || error "Failed to start Apache"

systemctl enable mariadb --quiet 2>/dev/null || systemctl enable mysqld --quiet 2>/dev/null || true
systemctl start  mariadb        2>/dev/null || systemctl start  mysqld        || error "Failed to start MariaDB"
info "Apache and MariaDB running"

# ── 3. Configure database ─────────────────────────────────────
section "Configuring database"

# Prompt for MySQL root password if not provided
if [[ -z "$DB_ROOT_PASS" ]]; then
    # Try passwordless root first (fresh MariaDB install)
    if mysql -u root -e "SELECT 1;" &>/dev/null; then
        MYSQL_ROOT="mysql -u root"
    else
        read -rsp "Enter MySQL root password: " DB_ROOT_PASS
        echo ""
        MYSQL_ROOT="mysql -u root -p${DB_ROOT_PASS}"
    fi
else
    MYSQL_ROOT="mysql -u root -p${DB_ROOT_PASS}"
fi

$MYSQL_ROOT -e "
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    FLUSH PRIVILEGES;
" || error "Database setup failed"
info "Database '${DB_NAME}' and user '${DB_USER}' created"

# ── 4. Clone repository ───────────────────────────────────────
section "Downloading SyncCU from GitHub"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

git clone --depth=1 --branch "$BRANCH" "$REPO_URL" "${TMP_DIR}/synccu" \
    || error "Failed to clone repo. Check network and branch name."
info "Repository cloned (branch: ${BRANCH})"

# Import DB schema
info "Importing database schema…"
$MYSQL_ROOT "${DB_NAME}" < "${TMP_DIR}/synccu/db_setup.sql" \
    || error "Schema import failed"
info "Database schema imported"

# ── 5. Deploy application files ───────────────────────────────
section "Deploying application files"
mkdir -p "$APP_DIR"
rsync -a --exclude='.git' "${TMP_DIR}/synccu/" "${APP_DIR}/"
info "Files deployed to ${APP_DIR}"

# ── 6. Create .env ────────────────────────────────────────────
section "Creating .env configuration"
cat > "${WEB_ROOT}/.env" <<EOF
DB_HOST=localhost
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
chmod 640 "${WEB_ROOT}/.env"
info ".env created"

# ── 7. Apache virtual host ────────────────────────────────────
section "Configuring Apache"
if [[ "$PKG_MGR" == "apt-get" ]]; then
    VHOST_FILE="/etc/apache2/sites-available/synccu.conf"
else
    VHOST_FILE="/etc/httpd/conf.d/synccu.conf"
fi

cat > "$VHOST_FILE" <<VHOST
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block sensitive files from web access
    <FilesMatch "^(\.env|config\.php)$">
        Require all denied
    </FilesMatch>

    ErrorLog  \${APACHE_LOG_DIR}/synccu_error.log
    CustomLog \${APACHE_LOG_DIR}/synccu_access.log combined
</VirtualHost>
VHOST

if [[ "$PKG_MGR" == "apt-get" ]]; then
    a2ensite synccu -q
    a2dissite 000-default -q 2>/dev/null || true
fi

# .htaccess for extra security
cat > "${WEB_ROOT}/.htaccess" <<'HTACCESS'
Options -Indexes

<FilesMatch "^(\.env|config\.php)$">
    Require all denied
</FilesMatch>
HTACCESS

systemctl reload "$APACHE_SVC" || error "Apache reload failed"
info "Apache virtual host configured"

# ── 8. Set permissions ────────────────────────────────────────
section "Setting file permissions"
chown -R "${APACHE_USER}:${APACHE_USER}" "$APP_DIR"
chmod -R 750 "$WEB_ROOT"
chmod 640 "${WEB_ROOT}/.env"
info "Permissions set"

# ── 9. Save credentials ───────────────────────────────────────
CREDS_FILE="${APP_DIR}/INSTALL_CREDENTIALS.txt"
cat > "$CREDS_FILE" <<CREDS
SyncCU Installation Credentials
================================
Installed at : $(date)
Branch       : ${BRANCH}
App directory: ${APP_DIR}
URL          : http://${DOMAIN}/

Database
--------
DB Host      : localhost
DB Name      : ${DB_NAME}
DB User      : ${DB_USER}
DB Password  : ${DB_PASS}

Default Login
-------------
Username     : admin
Password     : Admin1234
             (CHANGE THIS IMMEDIATELY after first login)
CREDS
chmod 600 "$CREDS_FILE"

# ── Done ─────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║        Installation Complete!            ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}URL:${NC}              http://${DOMAIN}/"
echo -e "  ${BOLD}Default login:${NC}    admin / Admin1234"
echo ""
echo -e "  ${BOLD}DB Name:${NC}          ${DB_NAME}"
echo -e "  ${BOLD}DB User:${NC}          ${DB_USER}"
echo -e "  ${BOLD}DB Password:${NC}      ${DB_PASS}"
echo ""
echo -e "  ${BOLD}Credentials file:${NC} ${CREDS_FILE}"
echo ""
echo -e "${YELLOW}${BOLD}  IMPORTANT: Change the admin password immediately after first login!${NC}"
echo ""
echo "  To update later, run:"
echo "    curl -sSL \"https://raw.githubusercontent.com/caritechsolutions/synccu/${BRANCH}/update.sh?\$(date +%s)\" | sudo bash"
echo ""
