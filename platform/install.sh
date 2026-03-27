#!/bin/bash
set -euo pipefail

################################################################################
# SyncCU Platform Installer
# Core Banking Platform - Installation Script
# Version: 1.0.0
################################################################################

INSTALL_LOG="/var/log/synccu-install.log"

# Ensure log directory exists
mkdir -p "$(dirname "$INSTALL_LOG")"
exec > >(tee -a "$INSTALL_LOG") 2>&1

# Error handling
cleanup() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        error "Installation failed with exit code $exit_code"
        error "Check the install log at $INSTALL_LOG for details"
    fi
}
trap cleanup EXIT

################################################################################
# Color Output Helpers
################################################################################

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

success() { echo -e "${GREEN}[✓]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1" >&2; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
info()    { echo -e "${BLUE}[i]${NC} $1"; }
step()    { echo -e "${CYAN}[>]${NC} ${BOLD}$1${NC}"; }

################################################################################
# ASCII Art Banner
################################################################################

print_banner() {
    echo -e "${CYAN}"
    cat << 'BANNER'
  ____                    ____ _   _
 / ___| _   _ _ __   ___ / ___| | | |
 \___ \| | | | '_ \ / __| |   | | | |
  ___) | |_| | | | | (__| |___| |_| |
 |____/ \__, |_| |_|\___|\____|\___/
        |___/
    Platform Installer v1.0.0
    Core Banking Platform
BANNER
    echo -e "${NC}"
    echo "=========================================================="
    echo "  This script will install the SyncCU banking platform."
    echo "  Ensure you are running as root or with sudo privileges."
    echo "=========================================================="
    echo ""
}

################################################################################
# Dependency Checks
################################################################################

check_root() {
    if [ "$EUID" -ne 0 ]; then
        error "This script must be run as root. Use: sudo bash install.sh"
        exit 1
    fi
}

check_dependencies() {
    step "Checking system dependencies..."
    local missing=0

    # PHP 8.1+
    if command -v php &>/dev/null; then
        PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
        PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
        PHP_MINOR=$(echo "$PHP_VERSION" | cut -d. -f2)
        if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; then
            error "PHP 8.1+ required, found PHP $PHP_VERSION"
            missing=1
        else
            success "PHP $PHP_VERSION detected"
        fi
    else
        error "PHP is not installed"
        missing=1
    fi

    # Python 3.9+
    if command -v python3 &>/dev/null; then
        PY_VERSION=$(python3 -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')")
        PY_MAJOR=$(echo "$PY_VERSION" | cut -d. -f1)
        PY_MINOR=$(echo "$PY_VERSION" | cut -d. -f2)
        if [ "$PY_MAJOR" -lt 3 ] || { [ "$PY_MAJOR" -eq 3 ] && [ "$PY_MINOR" -lt 9 ]; }; then
            error "Python 3.9+ required, found Python $PY_VERSION"
            missing=1
        else
            success "Python $PY_VERSION detected"
        fi
    else
        error "Python 3 is not installed"
        missing=1
    fi

    # MySQL/MariaDB client
    if command -v mysql &>/dev/null; then
        MYSQL_VERSION=$(mysql --version 2>/dev/null | head -1)
        success "MySQL client detected: $MYSQL_VERSION"
    else
        error "MySQL/MariaDB client is not installed"
        missing=1
    fi

    # pip3
    if command -v pip3 &>/dev/null; then
        PIP_VERSION=$(pip3 --version 2>/dev/null | head -1)
        success "pip3 detected: $PIP_VERSION"
    else
        error "pip3 is not installed"
        missing=1
    fi

    # git
    if command -v git &>/dev/null; then
        GIT_VERSION=$(git --version)
        success "Git detected: $GIT_VERSION"
    else
        error "Git is not installed"
        missing=1
    fi

    # Composer
    if command -v composer &>/dev/null; then
        success "Composer detected"
    else
        warn "Composer not found; will attempt to install"
    fi

    # Nginx
    if command -v nginx &>/dev/null; then
        success "Nginx detected"
    else
        warn "Nginx not found; will attempt to install"
    fi

    if [ $missing -ne 0 ]; then
        error "Missing required dependencies. Please install them and re-run."
        exit 1
    fi

    success "All required dependencies satisfied"
    echo ""
}

################################################################################
# User Prompts
################################################################################

gather_input() {
    step "Gathering installation parameters..."
    echo ""

    read -rp "Install directory [/var/www/synccu]: " INSTALL_DIR
    INSTALL_DIR="${INSTALL_DIR:-/var/www/synccu}"

    read -rp "MySQL host [localhost]: " DB_HOST
    DB_HOST="${DB_HOST:-localhost}"

    read -rsp "MySQL root password: " DB_ROOT_PASS
    echo ""

    read -rp "Application database name [synccu]: " DB_NAME
    DB_NAME="${DB_NAME:-synccu}"

    read -rp "Application database user [synccu_user]: " DB_USER
    DB_USER="${DB_USER:-synccu_user}"

    read -rsp "Application database password: " DB_PASS
    echo ""
    if [ -z "$DB_PASS" ]; then
        DB_PASS=$(openssl rand -base64 24)
        warn "Generated random database password: $DB_PASS"
    fi

    read -rp "Domain name [synccu.local]: " DOMAIN
    DOMAIN="${DOMAIN:-synccu.local}"

    read -rp "Admin email [admin@${DOMAIN}]: " ADMIN_EMAIL
    ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${DOMAIN}}"

    read -rsp "Admin password (min 12 chars): " ADMIN_PASS
    echo ""
    if [ ${#ADMIN_PASS} -lt 12 ]; then
        error "Admin password must be at least 12 characters"
        exit 1
    fi

    echo ""
    info "Installation Summary:"
    echo "  Install Dir:  $INSTALL_DIR"
    echo "  MySQL Host:   $DB_HOST"
    echo "  Database:     $DB_NAME"
    echo "  DB User:      $DB_USER"
    echo "  Domain:       $DOMAIN"
    echo "  Admin Email:  $ADMIN_EMAIL"
    echo ""

    read -rp "Proceed with installation? [Y/n]: " CONFIRM
    if [[ "${CONFIRM,,}" == "n" ]]; then
        info "Installation cancelled by user."
        exit 0
    fi
}

################################################################################
# Installation Steps
################################################################################

create_install_directory() {
    step "Creating installation directory..."

    mkdir -p "$INSTALL_DIR"
    info "Copying platform files to $INSTALL_DIR"

    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    cp -r "$SCRIPT_DIR"/* "$INSTALL_DIR/"
    cp -r "$SCRIPT_DIR"/.env.example "$INSTALL_DIR/" 2>/dev/null || true

    success "Files copied to $INSTALL_DIR"
}

setup_backend() {
    step "Setting up PHP backend..."

    local BACKEND_DIR="$INSTALL_DIR/backend"

    if [ ! -d "$BACKEND_DIR" ]; then
        error "Backend directory not found at $BACKEND_DIR"
        exit 1
    fi

    cd "$BACKEND_DIR"

    # Copy environment file
    if [ -f ".env.example" ]; then
        cp .env.example .env
        info "Copied .env.example to .env"
    else
        warn ".env.example not found in backend, creating .env"
        touch .env
    fi

    # Generate secrets
    APP_KEY=$(openssl rand -base64 32)
    JWT_SECRET=$(openssl rand -base64 64)
    ENCRYPTION_KEY=$(openssl rand -hex 32)

    info "Generated APP_KEY"
    info "Generated JWT_SECRET"
    info "Generated ENCRYPTION_KEY"

    # Set values in .env
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:${APP_KEY}|" .env
    sed -i "s|^JWT_SECRET=.*|JWT_SECRET=${JWT_SECRET}|" .env
    sed -i "s|^ENCRYPTION_KEY=.*|ENCRYPTION_KEY=${ENCRYPTION_KEY}|" .env
    sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
    sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env

    # Install Composer dependencies if composer.json exists
    if [ -f "composer.json" ]; then
        if command -v composer &>/dev/null; then
            composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || warn "Composer install had warnings"
        else
            warn "Composer not available, skipping dependency install"
        fi
    fi

    success "Backend setup complete"
}

setup_api() {
    step "Setting up Python API..."

    local API_DIR="$INSTALL_DIR/api"

    if [ ! -d "$API_DIR" ]; then
        error "API directory not found at $API_DIR"
        exit 1
    fi

    cd "$API_DIR"

    # Create virtual environment
    info "Creating Python virtual environment..."
    python3 -m venv venv
    source venv/bin/activate

    # Install dependencies
    if [ -f "requirements.txt" ]; then
        info "Installing Python dependencies..."
        pip install --upgrade pip >/dev/null 2>&1
        pip install -r requirements.txt 2>/dev/null || warn "Some Python packages had install warnings"
    else
        warn "requirements.txt not found in API directory"
    fi

    # Copy environment file
    if [ -f ".env.example" ]; then
        cp .env.example .env
        info "Copied API .env.example to .env"
    else
        warn ".env.example not found in API directory, creating .env"
        touch .env
    fi

    # Configure API environment
    DATABASE_URL="mysql+asyncmy://${DB_USER}:${DB_PASS}@${DB_HOST}:3306/${DB_NAME}"
    API_JWT_SECRET=$(openssl rand -base64 64)
    API_ENCRYPTION_KEY=$(openssl rand -hex 32)

    sed -i "s|^DATABASE_URL=.*|DATABASE_URL=${DATABASE_URL}|" .env
    sed -i "s|^JWT_SECRET=.*|JWT_SECRET=${API_JWT_SECRET}|" .env
    sed -i "s|^ENCRYPTION_KEY=.*|ENCRYPTION_KEY=${API_ENCRYPTION_KEY}|" .env
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env

    # Append if keys were not present
    grep -q "^DATABASE_URL=" .env || echo "DATABASE_URL=${DATABASE_URL}" >> .env
    grep -q "^JWT_SECRET=" .env || echo "JWT_SECRET=${API_JWT_SECRET}" >> .env
    grep -q "^ENCRYPTION_KEY=" .env || echo "ENCRYPTION_KEY=${API_ENCRYPTION_KEY}" >> .env

    deactivate

    success "API setup complete"
}

setup_database() {
    step "Setting up database..."

    # Create database and user
    info "Creating database and user..."
    mysql -h "$DB_HOST" -u root -p"$DB_ROOT_PASS" <<-EOSQL
        CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
        GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
        FLUSH PRIVILEGES;
EOSQL
    success "Database and user created"

    # Import schema
    local DB_DIR="$INSTALL_DIR/database"
    if [ -f "$DB_DIR/schema.sql" ]; then
        info "Importing database schema..."
        mysql -h "$DB_HOST" -u root -p"$DB_ROOT_PASS" "$DB_NAME" < "$DB_DIR/schema.sql"
        success "Schema imported"
    else
        warn "schema.sql not found at $DB_DIR/schema.sql"
    fi

    # Import seed data
    if [ -f "$DB_DIR/seed.sql" ]; then
        info "Importing seed data..."
        mysql -h "$DB_HOST" -u root -p"$DB_ROOT_PASS" "$DB_NAME" < "$DB_DIR/seed.sql"
        success "Seed data imported"
    else
        warn "seed.sql not found, skipping seed import"
    fi

    # Insert admin user with bcrypt-hashed password
    info "Creating admin user..."
    HASHED_PASS=$(python3 -c "
import bcrypt
password = '${ADMIN_PASS}'.encode('utf-8')
hashed = bcrypt.hashpw(password, bcrypt.gensalt(rounds=12))
print(hashed.decode('utf-8'))
" 2>/dev/null || echo "")

    if [ -z "$HASHED_PASS" ]; then
        # Fallback: use PHP for hashing
        HASHED_PASS=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT, ['cost' => 12]);")
    fi

    mysql -h "$DB_HOST" -u root -p"$DB_ROOT_PASS" "$DB_NAME" <<-EOSQL
        INSERT INTO users (email, password, first_name, last_name, role, status, created_at, updated_at)
        VALUES ('${ADMIN_EMAIL}', '${HASHED_PASS}', 'System', 'Administrator', 'admin', 'active', NOW(), NOW())
        ON DUPLICATE KEY UPDATE password='${HASHED_PASS}', updated_at=NOW();
EOSQL

    success "Admin user created: $ADMIN_EMAIL"
}

setup_nginx() {
    step "Configuring Nginx..."

    local NGINX_CONF="/etc/nginx/sites-available/${DOMAIN}"

    cat > "$NGINX_CONF" <<-NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${INSTALL_DIR}/frontend;
    index index.html index.php;

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    server_tokens off;
    client_max_body_size 10M;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;
    gzip_min_length 1000;

    # Rate limiting
    limit_req_zone \$binary_remote_addr zone=api_limit:10m rate=30r/s;
    limit_req_zone \$binary_remote_addr zone=login_limit:10m rate=5r/m;

    # Static frontend files
    location / {
        try_files \$uri \$uri/ /index.html;
        expires 1h;
        add_header Cache-Control "public, no-transform";
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # PHP API v1
    location /api/v1/ {
        limit_req zone=api_limit burst=20 nodelay;
        alias ${INSTALL_DIR}/backend/public/;
        try_files \$uri \$uri/ @phpapi;
    }

    location @phpapi {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/public/index.php;
        fastcgi_param REQUEST_URI \$request_uri;
        include fastcgi_params;
    }

    # Python API v2
    location /api/v2/ {
        limit_req zone=api_limit burst=20 nodelay;
        proxy_pass http://127.0.0.1:8000/;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300;
        proxy_connect_timeout 300;
    }

    # Login rate limiting
    location /api/v1/auth/login {
        limit_req zone=login_limit burst=3 nodelay;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/public/index.php;
        include fastcgi_params;
    }

    location /api/v2/auth/login {
        limit_req zone=login_limit burst=3 nodelay;
        proxy_pass http://127.0.0.1:8000/auth/login;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_file_not_found off;
    }

    access_log /var/log/nginx/${DOMAIN}-access.log;
    error_log /var/log/nginx/${DOMAIN}-error.log;
}
NGINX

    # Enable site
    ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/${DOMAIN}"

    # Test and reload
    nginx -t 2>/dev/null && systemctl reload nginx
    success "Nginx configured for $DOMAIN"
}

setup_systemd_service() {
    step "Creating systemd service for API..."

    cat > /etc/systemd/system/synccu-api.service <<-SERVICE
[Unit]
Description=SyncCU Banking Platform API (Uvicorn)
After=network.target mysql.service
Wants=mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${INSTALL_DIR}/api
Environment="PATH=${INSTALL_DIR}/api/venv/bin:/usr/local/bin:/usr/bin"
EnvironmentFile=${INSTALL_DIR}/api/.env
ExecStart=${INSTALL_DIR}/api/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8000 --workers 4 --access-log
ExecReload=/bin/kill -HUP \$MAINPID
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=synccu-api

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${INSTALL_DIR}/api/logs ${INSTALL_DIR}/api/storage
PrivateTmp=true

[Install]
WantedBy=multi-user.target
SERVICE

    systemctl daemon-reload
    systemctl enable synccu-api.service
    systemctl start synccu-api.service || warn "API service may not start until dependencies are ready"

    success "Systemd service created and enabled"
}

set_permissions() {
    step "Setting file permissions..."

    chown -R www-data:www-data "$INSTALL_DIR"
    find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
    find "$INSTALL_DIR" -type f -exec chmod 644 {} \;

    # Make scripts executable
    find "$INSTALL_DIR" -name "*.sh" -exec chmod 755 {} \;

    # Protect environment files
    chmod 640 "$INSTALL_DIR/backend/.env" 2>/dev/null || true
    chmod 640 "$INSTALL_DIR/api/.env" 2>/dev/null || true

    # Writable directories
    chmod -R 775 "$INSTALL_DIR/backend/storage" 2>/dev/null || true
    chmod -R 775 "$INSTALL_DIR/backend/bootstrap/cache" 2>/dev/null || true

    success "File permissions set"
}

setup_ssl() {
    echo ""
    read -rp "Would you like to set up SSL with Let's Encrypt (certbot)? [y/N]: " SETUP_SSL

    if [[ "${SETUP_SSL,,}" == "y" ]]; then
        if command -v certbot &>/dev/null; then
            step "Setting up SSL certificate..."
            certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$ADMIN_EMAIL" || {
                warn "Certbot failed. You can run it manually later:"
                info "  certbot --nginx -d $DOMAIN"
            }
            success "SSL certificate installed"
        else
            warn "certbot is not installed. Install it with:"
            info "  apt install certbot python3-certbot-nginx"
        fi
    else
        info "Skipping SSL setup. You can configure it later."
    fi
}

save_config() {
    step "Saving installation configuration..."

    mkdir -p /etc/synccu
    cat > /etc/synccu/config <<-EOF
# SyncCU Platform Configuration
# Generated by install.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")
INSTALL_DIR=${INSTALL_DIR}
DOMAIN=${DOMAIN}
DB_HOST=${DB_HOST}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
ADMIN_EMAIL=${ADMIN_EMAIL}
EOF
    chmod 600 /etc/synccu/config

    success "Configuration saved to /etc/synccu/config"
}

print_summary() {
    echo ""
    echo -e "${GREEN}=========================================================="
    echo "  SyncCU Platform Installation Complete!"
    echo "==========================================================${NC}"
    echo ""
    echo "  Frontend URL:   http://${DOMAIN}"
    echo "  PHP API (v1):   http://${DOMAIN}/api/v1/"
    echo "  Python API (v2):http://${DOMAIN}/api/v2/"
    echo ""
    echo "  Admin Email:    ${ADMIN_EMAIL}"
    echo "  Admin Password: (as entered during setup)"
    echo ""
    echo "  Install Dir:    ${INSTALL_DIR}"
    echo "  Config File:    /etc/synccu/config"
    echo "  Install Log:    ${INSTALL_LOG}"
    echo ""
    echo "  Services:"
    echo "    systemctl status synccu-api"
    echo "    systemctl status php*-fpm"
    echo "    systemctl status nginx"
    echo ""
    echo "  Management Scripts:"
    echo "    ${INSTALL_DIR}/update.sh   - Update platform"
    echo "    ${INSTALL_DIR}/backup.sh   - Backup platform"
    echo ""
    echo -e "${YELLOW}  IMPORTANT: Change the admin password after first login!${NC}"
    echo ""
}

################################################################################
# Main Execution
################################################################################

main() {
    print_banner
    check_root
    check_dependencies
    gather_input

    echo ""
    info "Installation started at $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
    echo ""

    create_install_directory
    setup_backend
    setup_api
    setup_database
    setup_nginx
    setup_systemd_service
    set_permissions
    save_config
    setup_ssl
    print_summary

    info "Installation completed at $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
    success "Full install log saved to $INSTALL_LOG"
}

main "$@"
