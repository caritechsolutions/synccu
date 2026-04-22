#!/bin/bash
set -euo pipefail

################################################################################
# SyncCU Platform Installer
# One-command installer: curl -sSL https://raw.githubusercontent.com/caritechsolutions/synccu/main/platform/install.sh | sudo bash
# Version: 2.0.0
################################################################################

INSTALL_LOG="/var/log/synccu-install.log"
REPO_URL="https://github.com/caritechsolutions/synccu.git"
REPO_BRANCH="claude/rebuild-platform-modern-5He4J"

mkdir -p "$(dirname "$INSTALL_LOG")"
exec > >(tee -a "$INSTALL_LOG") 2>&1

cleanup() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        error "Installation failed (exit code $exit_code). See $INSTALL_LOG"
    fi
}
trap cleanup EXIT

################################################################################
# Color Helpers
################################################################################
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

success() { echo -e "${GREEN}[✓]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1" >&2; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
info()    { echo -e "${BLUE}[i]${NC} $1"; }
step()    { echo -e "\n${CYAN}[>]${NC} ${BOLD}$1${NC}"; }

################################################################################
# Banner
################################################################################
echo -e "${CYAN}"
cat << 'BANNER'
  ____                    ____ _   _
 / ___| _   _ _ __   ___ / ___| | | |
 \___ \| | | | '_ \ / __| |   | | | |
  ___) | |_| | | | | (__| |___| |_| |
 |____/ \__, |_| |_|\___|\____|\___/
        |___/
    Platform Installer v2.0.0
    Core Banking Platform
BANNER
echo -e "${NC}"

################################################################################
# Root check
################################################################################
if [ "$EUID" -ne 0 ]; then
    error "Run as root:  curl -sSL <url> | sudo bash"
    exit 1
fi

################################################################################
# Detect OS
################################################################################
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS_ID="${ID}"
        OS_VERSION="${VERSION_ID:-}"
    else
        OS_ID="unknown"
        OS_VERSION=""
    fi
    info "Detected OS: $OS_ID $OS_VERSION"
}
detect_os

################################################################################
# Auto-install ALL prerequisites
################################################################################
install_prerequisites() {
    step "Installing system prerequisites..."

    case "$OS_ID" in
        ubuntu|debian)
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -qq

            # Add PHP repo for latest PHP on older distros
            if ! command -v php8.2 &>/dev/null && ! dpkg -l php8.2-fpm &>/dev/null 2>&1; then
                if command -v add-apt-repository &>/dev/null; then
                    add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
                    apt-get update -qq
                fi
            fi

            # Core packages
            apt-get install -y -qq \
                git curl wget unzip software-properties-common \
                nginx \
                mysql-server mysql-client \
                python3 python3-pip python3-venv \
                certbot python3-certbot-nginx \
                2>/dev/null

            # PHP packages — try 8.2, fall back to 8.1, fall back to default
            PHP_VER=""
            for v in 8.3 8.2 8.1; do
                if apt-cache show "php${v}-fpm" &>/dev/null; then
                    PHP_VER="$v"
                    break
                fi
            done

            if [ -z "$PHP_VER" ]; then
                # Install whatever php-fpm is available
                apt-get install -y -qq php-fpm php-mysql php-mbstring php-xml php-bcmath php-zip php-gd php-curl php-intl 2>/dev/null
            else
                apt-get install -y -qq \
                    "php${PHP_VER}-fpm" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" \
                    "php${PHP_VER}-xml" "php${PHP_VER}-bcmath" "php${PHP_VER}-zip" \
                    "php${PHP_VER}-gd" "php${PHP_VER}-curl" "php${PHP_VER}-intl" \
                    2>/dev/null
            fi

            # Composer
            if ! command -v composer &>/dev/null; then
                info "Installing Composer..."
                curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>/dev/null
            fi

            # Start & enable services
            systemctl enable --now mysql 2>/dev/null || true
            systemctl enable --now nginx 2>/dev/null || true
            PHP_FPM_SVC=$(systemctl list-unit-files | grep -oP 'php[\d.]+-fpm\.service' | head -1 || echo "")
            if [ -n "$PHP_FPM_SVC" ]; then
                systemctl enable --now "$PHP_FPM_SVC" 2>/dev/null || true
            fi
            ;;

        centos|rhel|rocky|alma|fedora)
            if command -v dnf &>/dev/null; then
                PKG="dnf"
            else
                PKG="yum"
            fi

            $PKG install -y epel-release 2>/dev/null || true

            # Remi repo for PHP
            if [ "$OS_ID" != "fedora" ]; then
                $PKG install -y "https://rpms.remirepo.net/enterprise/remi-release-${OS_VERSION%%.*}.rpm" 2>/dev/null || true
                $PKG module enable -y php:remi-8.2 2>/dev/null || true
            fi

            $PKG install -y \
                git curl wget unzip \
                nginx \
                mysql-server mysql \
                python3 python3-pip \
                php-fpm php-mysqlnd php-mbstring php-xml php-bcmath php-zip php-gd php-curl php-intl \
                certbot python3-certbot-nginx \
                2>/dev/null

            # Composer
            if ! command -v composer &>/dev/null; then
                curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>/dev/null
            fi

            systemctl enable --now mysqld 2>/dev/null || systemctl enable --now mariadb 2>/dev/null || true
            systemctl enable --now nginx 2>/dev/null || true
            systemctl enable --now php-fpm 2>/dev/null || true
            ;;

        *)
            warn "Unsupported OS ($OS_ID). Attempting generic install..."
            warn "You may need to install manually: git, nginx, mysql, php8.1+, python3, pip3"
            ;;
    esac

    # Verify critical dependencies
    local fail=0
    for cmd in git php python3 pip3 mysql nginx; do
        if command -v "$cmd" &>/dev/null; then
            success "$cmd: $(command -v $cmd)"
        else
            error "$cmd: NOT FOUND"
            fail=1
        fi
    done

    if [ $fail -ne 0 ]; then
        error "Some dependencies could not be installed. Check output above."
        exit 1
    fi

    success "All prerequisites installed"
}

install_prerequisites

################################################################################
# Gather configuration (with sane defaults)
# Temporarily talk directly to the terminal so prompts display and reads work
# even when piped via curl | bash (stdin=pipe, stdout=tee buffer).
################################################################################

# Save tee-wrapped descriptors, switch to raw terminal for interactive prompts
exec 3>&1 4>&2
exec 0</dev/tty 1>/dev/tty 2>/dev/tty

step "Configuration"
echo ""

read -rp "Install directory [/var/www/synccu]: " INSTALL_DIR
INSTALL_DIR="${INSTALL_DIR:-/var/www/synccu}"

read -rp "Domain name [$(hostname -f 2>/dev/null || echo 'synccu.local')]: " DOMAIN
DOMAIN="${DOMAIN:-$(hostname -f 2>/dev/null || echo 'synccu.local')}"

read -rp "MySQL host [localhost]: " DB_HOST
DB_HOST="${DB_HOST:-localhost}"

# Check if MySQL needs root password setup
MYSQL_CMD=""
if mysql -u root -e "SELECT 1" &>/dev/null 2>&1; then
    info "MySQL root access OK (socket auth, no password needed)"
    MYSQL_CMD="mysql -u root"
elif mysql -h "$DB_HOST" -u root -e "SELECT 1" &>/dev/null 2>&1; then
    info "MySQL root access OK (no password)"
    MYSQL_CMD="mysql -h $DB_HOST -u root"
else
    read -rsp "MySQL root password: " DB_ROOT_PASS
    echo ""
    if [ -n "$DB_ROOT_PASS" ]; then
        MYSQL_CMD="mysql -h $DB_HOST -u root -p${DB_ROOT_PASS}"
    else
        MYSQL_CMD="mysql -h $DB_HOST -u root"
    fi
fi

# Test MySQL connection
if ! $MYSQL_CMD -e "SELECT 1" &>/dev/null 2>&1; then
    error "Cannot connect to MySQL. Check your root password."
    error "Try: sudo mysql -u root   (Ubuntu uses socket auth by default)"
    exit 1
fi
success "MySQL connection verified"

read -rp "Application database name [synccu]: " DB_NAME
DB_NAME="${DB_NAME:-synccu}"

read -rp "Application database user [synccu_user]: " DB_USER
DB_USER="${DB_USER:-synccu_user}"

DB_PASS=$(openssl rand -hex 18)
info "Generated database password (saved to .env files)"

read -rp "Admin email [admin@${DOMAIN}]: " ADMIN_EMAIL
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${DOMAIN}}"

while true; do
    read -rsp "Admin password (min 8 chars): " ADMIN_PASS
    echo ""
    if [ ${#ADMIN_PASS} -ge 8 ]; then
        break
    fi
    warn "Password too short. Minimum 8 characters."
done

echo ""
info "Summary:"
echo "  Directory:  $INSTALL_DIR"
echo "  Domain:     $DOMAIN"
echo "  Database:   $DB_NAME"
echo "  Admin:      $ADMIN_EMAIL"
echo ""
read -rp "Proceed? [Y/n]: " CONFIRM
if [[ "${CONFIRM,,}" == "n" ]]; then
    info "Cancelled."
    exit 0
fi

# Restore tee-logged output for the rest of the install
exec 1>&3 2>&4 3>&- 4>&-

################################################################################
# 1. Clone repository
################################################################################
step "Downloading SyncCU platform..."

if [ -d "$INSTALL_DIR/.git" ]; then
    info "Existing installation found, pulling latest..."
    cd "$INSTALL_DIR"
    git fetch origin 2>/dev/null
    git checkout "$REPO_BRANCH" 2>/dev/null || git checkout -b "$REPO_BRANCH" "origin/$REPO_BRANCH" 2>/dev/null
    git pull origin "$REPO_BRANCH" 2>/dev/null
else
    rm -rf "$INSTALL_DIR" 2>/dev/null || true
    git clone -b "$REPO_BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR/platform"
success "Source code ready at $INSTALL_DIR"

################################################################################
# 2. Setup database
################################################################################
step "Setting up database..."

$MYSQL_CMD <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
success "Database '$DB_NAME' and user '$DB_USER' created"

# Import schema and seed
if [ -f "database/schema.sql" ]; then
    $MYSQL_CMD "$DB_NAME" < database/schema.sql
    success "Schema imported"
fi

if [ -f "database/seed.sql" ]; then
    $MYSQL_CMD "$DB_NAME" < database/seed.sql
    success "Seed data imported"
fi

# Post-install migrations & data cleanup
step "Running database migrations..."

$MYSQL_CMD "$DB_NAME" <<-'EOSQL'
    -- Add late_fee to transactions type ENUM if not present
    ALTER TABLE `transactions`
        MODIFY COLUMN `type` ENUM('deposit','withdrawal','transfer','payment','fee','late_fee','interest','adjustment','loan_disbursement','loan_payment','expense') NOT NULL,
        MODIFY COLUMN `account_id` CHAR(36) DEFAULT NULL,
        MODIFY COLUMN `balance_after` DECIMAL(15,2) DEFAULT NULL;

    -- Make loans.account_id nullable (loan account created at approval, not application)
    ALTER TABLE `loans` MODIFY COLUMN `account_id` CHAR(36) DEFAULT NULL;
EOSQL
success "Migrations applied"

# Create admin user (use PHP for bcrypt since it's guaranteed installed)
HASHED_PASS=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 12]);' -- "$ADMIN_PASS")
ADMIN_UUID=$(python3 -c "import uuid; print(str(uuid.uuid4()))")
TENANT_ID="00000000-0000-0000-0000-000000000001"

SAFE_EMAIL=${ADMIN_EMAIL//\'/\'\'}
SAFE_HASH=${HASHED_PASS//\'/\'\'}
$MYSQL_CMD "$DB_NAME" <<-EOSQL
    INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, role, status, email_verified_at, created_at, updated_at)
    VALUES ('${ADMIN_UUID}', '${TENANT_ID}', '${SAFE_EMAIL}', '${SAFE_HASH}', 'System', 'Administrator', 'super_admin', 'active', NOW(), NOW(), NOW())
    ON DUPLICATE KEY UPDATE password_hash='${SAFE_HASH}', role='super_admin', status='active', updated_at=NOW();
EOSQL
success "Admin user created: $ADMIN_EMAIL"

################################################################################
# 3. Setup PHP backend
################################################################################
step "Configuring PHP backend..."

BACKEND_DIR="$INSTALL_DIR/platform/backend"
cd "$BACKEND_DIR"

cp -f .env.example .env 2>/dev/null || touch .env

APP_KEY=$(openssl rand -base64 32)
JWT_SECRET=$(openssl rand -base64 64)
ENCRYPTION_KEY=$(openssl rand -hex 32)

# Write .env values
cat > .env <<-ENV
APP_NAME=SyncCU
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${DOMAIN}
APP_KEY=base64:${APP_KEY}

DB_HOST=${DB_HOST}
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

JWT_SECRET=${JWT_SECRET}
JWT_EXPIRY=3600
JWT_REFRESH_EXPIRY=604800

ENCRYPTION_KEY=${ENCRYPTION_KEY}

RATE_LIMIT_PER_MINUTE=60

MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@${DOMAIN}
MAIL_FROM_NAME=SyncCU
ENV

# Install Composer deps if composer.json exists
if [ -f "composer.json" ] && command -v composer &>/dev/null; then
    composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || true
fi

success "PHP backend configured"

################################################################################
# 4. Setup Python API
################################################################################
step "Configuring Python API..."

API_DIR="$INSTALL_DIR/platform/api"
cd "$API_DIR"

python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip -q 2>/dev/null
pip install -r requirements.txt -q 2>/dev/null
deactivate

API_JWT=$(openssl rand -base64 64)
API_ENC=$(openssl rand -hex 32)

cat > .env <<-ENV
DATABASE_URL=mysql+pymysql://${DB_USER}:${DB_PASS}@${DB_HOST}:3306/${DB_NAME}
JWT_SECRET=${API_JWT}
JWT_ALGORITHM=HS256
JWT_EXPIRY_MINUTES=60
ENCRYPTION_KEY=${API_ENC}
REDIS_URL=redis://localhost:6379/0
CORS_ORIGINS=https://${DOMAIN},http://${DOMAIN},http://localhost
RATE_LIMIT=60/minute
ENV

success "Python API configured"

################################################################################
# 5. Setup Nginx
################################################################################
step "Configuring Nginx..."

# Find PHP-FPM socket
PHP_SOCK=$(find /var/run/php/ -name "*.sock" 2>/dev/null | head -1 || echo "/var/run/php/php-fpm.sock")
if [ ! -S "$PHP_SOCK" ]; then
    PHP_SOCK=$(find /run/php/ -name "*.sock" 2>/dev/null | head -1 || echo "/var/run/php/php8.2-fpm.sock")
fi
info "PHP-FPM socket: $PHP_SOCK"

FRONTEND_DIR="$INSTALL_DIR/platform/frontend"

cat > "/etc/nginx/sites-available/synccu" <<-NGINX
# SyncCU Platform - Nginx Configuration
# Generated by install.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")

limit_req_zone \$binary_remote_addr zone=api_limit:10m rate=30r/s;
limit_req_zone \$binary_remote_addr zone=login_limit:10m rate=5r/m;

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${FRONTEND_DIR};
    index index.html;

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    server_tokens off;
    client_max_body_size 10M;

    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;
    gzip_min_length 1000;

    # Frontend static files
    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # PHP files in frontend (auth API etc)
    location ~ ^/api/.*\.php$ {
        limit_req zone=api_limit burst=10 nodelay;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME ${FRONTEND_DIR}/\$uri;
        include fastcgi_params;
        fastcgi_param SCRIPT_NAME \$uri;
    }

    # PHP API
    location /api/v1/ {
        limit_req zone=api_limit burst=20 nodelay;
        try_files \$uri @phpapi;
    }

    location @phpapi {
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME ${BACKEND_DIR}/public/index.php;
        fastcgi_param REQUEST_URI \$request_uri;
        include fastcgi_params;
    }

    # Python API
    location /api/v2/ {
        limit_req zone=api_limit burst=20 nodelay;
        proxy_pass http://127.0.0.1:8000/;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Login rate limiting
    location = /api/v1/auth/login {
        limit_req zone=login_limit burst=3 nodelay;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME ${BACKEND_DIR}/public/index.php;
        include fastcgi_params;
    }

    # Block hidden files
    location ~ /\. { deny all; }

    access_log /var/log/nginx/synccu-access.log;
    error_log /var/log/nginx/synccu-error.log;
}
NGINX

# Enable site, disable default
ln -sf /etc/nginx/sites-available/synccu /etc/nginx/sites-enabled/synccu
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

nginx -t 2>/dev/null && (systemctl is-active nginx &>/dev/null && systemctl reload nginx || systemctl start nginx)
systemctl enable nginx 2>/dev/null || true
success "Nginx configured"

################################################################################
# 6. Setup systemd service for Python API
################################################################################
step "Creating API service..."

cat > /etc/systemd/system/synccu-api.service <<-SERVICE
[Unit]
Description=SyncCU Banking Platform API
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${API_DIR}
Environment="PATH=${API_DIR}/venv/bin:/usr/local/bin:/usr/bin"
ExecStart=${API_DIR}/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8000 --workers 4
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable synccu-api
systemctl start synccu-api 2>/dev/null || warn "API service will start after reboot"
success "API systemd service created"

################################################################################
# 7. File permissions
################################################################################
step "Setting permissions..."

chown -R www-data:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
find "$INSTALL_DIR" -name "*.sh" -exec chmod 755 {} \;
chmod 640 "$BACKEND_DIR/.env" "$API_DIR/.env" 2>/dev/null || true

success "Permissions set"

################################################################################
# 8. Save config for update/backup scripts
################################################################################
mkdir -p /etc/synccu
cat > /etc/synccu/config <<-EOF
INSTALL_DIR=${INSTALL_DIR}
DOMAIN=${DOMAIN}
DB_HOST=${DB_HOST}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
ADMIN_EMAIL=${ADMIN_EMAIL}
INSTALLED_AT="$(date -u +"%Y-%m-%d %H:%M:%S UTC")"
EOF
chmod 600 /etc/synccu/config

################################################################################
# 9. Optional SSL
################################################################################
exec 3>&1 4>&2; exec 0</dev/tty 1>/dev/tty 2>/dev/tty
echo ""
read -rp "Setup free SSL certificate with Let's Encrypt? [y/N]: " SETUP_SSL
exec 1>&3 2>&4 3>&- 4>&-
if [[ "${SETUP_SSL,,}" == "y" ]]; then
    if command -v certbot &>/dev/null; then
        certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$ADMIN_EMAIL" 2>/dev/null && \
            success "SSL certificate installed" || \
            warn "Certbot failed. Run manually: certbot --nginx -d $DOMAIN"
    fi
fi

################################################################################
# Done!
################################################################################
echo ""
echo -e "${GREEN}══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}   SyncCU Platform - Installation Complete!${NC}"
echo -e "${GREEN}══════════════════════════════════════════════════════════${NC}"
echo ""
echo "  Your platform is live at:"
echo ""
echo "    Login:          http://${DOMAIN}"
echo "    Admin Panel:    http://${DOMAIN}/admin/dashboard.html"
echo "    Client Portal:  http://${DOMAIN}/portal/dashboard.html"
echo "    PHP API:        http://${DOMAIN}/api/v1/"
echo "    Python API:     http://${DOMAIN}/api/v2/"
echo ""
echo "  Admin Login:"
echo "    Email:    ${ADMIN_EMAIL}"
echo "    Password: (as entered during setup)"
echo ""
echo "  Management:"
echo "    Update:   curl -sSL \"https://raw.githubusercontent.com/caritechsolutions/synccu/${REPO_BRANCH}/platform/update.sh?\$(date +%s)\" | sudo bash"
echo "    Backup:   sudo bash ${INSTALL_DIR}/platform/backup.sh"
echo ""
echo "  Config:     /etc/synccu/config"
echo "  Log:        ${INSTALL_LOG}"
echo ""
echo -e "${YELLOW}  Change your admin password after first login!${NC}"
echo ""
