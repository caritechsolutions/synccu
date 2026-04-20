#!/bin/bash
set -euo pipefail

################################################################################
# SyncCU Platform Updater
# Usage: curl -sSL "https://raw.githubusercontent.com/caritechsolutions/synccu/claude/rebuild-platform-modern-5He4J/platform/update.sh?$(date +%s)" -o /tmp/synccu-update.sh && sudo bash /tmp/synccu-update.sh
# Version: 3.0.0
################################################################################

REPO_BRANCH="claude/rebuild-platform-modern-5He4J"
REPO_URL="https://github.com/caritechsolutions/synccu"
UPDATE_LOG="/var/log/synccu-update.log"

mkdir -p "$(dirname "$UPDATE_LOG")"
exec > >(tee -a "$UPDATE_LOG") 2>&1

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

success() { echo -e "${GREEN}[✓]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1" >&2; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
info()    { echo -e "${BLUE}[i]${NC} $1"; }
step()    { echo -e "\n${CYAN}[>]${NC} ${BOLD}$1${NC}"; }

echo -e "${CYAN}"
cat << 'BANNER'
  ____                    ____ _   _
 / ___| _   _ _ __   ___ / ___| | | |
 \___ \| | | | '_ \ / __| |   | | | |
  ___) | |_| | | | | (__| |___| |_| |
 |____/ \__, |_| |_|\___|\____|\___/
        |___/
    Platform Updater v3.0.0
BANNER
echo -e "${NC}"

if [ "$EUID" -ne 0 ]; then
    error "Run as root: sudo bash /tmp/synccu-update.sh"
    exit 1
fi

# Parse flags
RESET_DB=0
for arg in "$@"; do
    case "$arg" in
        --reset-db) RESET_DB=1 ;;
    esac
done

################################################################################
# 1. Find installation
################################################################################
step "Locating installation..."

INSTALL_DIR=""
CONFIG_FILE="/etc/synccu/config"

if [ -f "$CONFIG_FILE" ]; then
    source "$CONFIG_FILE"
fi

if [ -z "${INSTALL_DIR:-}" ] || [ ! -d "${INSTALL_DIR:-}" ]; then
    for dir in /var/www/synccu /opt/synccu; do
        if [ -d "$dir/platform" ]; then
            INSTALL_DIR="$dir"
            break
        fi
    done
fi

if [ -z "${INSTALL_DIR:-}" ] || [ ! -d "${INSTALL_DIR:-}" ]; then
    error "Installation not found. Run the installer first."
    exit 1
fi

success "Found installation at $INSTALL_DIR"

# Load config defaults
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-synccu}"
DB_USER="${DB_USER:-synccu_user}"

################################################################################
# 2. Backup database
################################################################################
step "Backing up database..."

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/var/backups/synccu"
mkdir -p "$BACKUP_DIR"

# Try socket auth first, then password-less
if mysql -u root -e "SELECT 1" &>/dev/null 2>&1; then
    MYSQL_CMD="mysql -u root"
elif mysql -u "$DB_USER" "$DB_NAME" -e "SELECT 1" &>/dev/null 2>&1; then
    MYSQL_CMD="mysql -u $DB_USER $DB_NAME"
else
    MYSQL_CMD="mysql -u $DB_USER"
fi

DUMP_CMD="${MYSQL_CMD/mysql/mysqldump}"
$DUMP_CMD "$DB_NAME" --single-transaction 2>/dev/null | gzip > "$BACKUP_DIR/db_${TIMESTAMP}.sql.gz" && \
    success "Database backed up to $BACKUP_DIR/db_${TIMESTAMP}.sql.gz" || \
    warn "Database backup failed (continuing anyway)"

################################################################################
# 3. Download latest code (no git needed)
################################################################################
step "Downloading latest code..."

DOWNLOAD_DIR=$(mktemp -d)
ARCHIVE_URL="${REPO_URL}/archive/refs/heads/${REPO_BRANCH}.tar.gz"

info "Fetching from $REPO_BRANCH branch..."
if curl -sSL "$ARCHIVE_URL" -o "$DOWNLOAD_DIR/source.tar.gz"; then
    success "Downloaded latest source"
else
    error "Failed to download. Check your internet connection."
    rm -rf "$DOWNLOAD_DIR"
    exit 1
fi

# Extract
tar xzf "$DOWNLOAD_DIR/source.tar.gz" -C "$DOWNLOAD_DIR"
SOURCE_DIR=$(find "$DOWNLOAD_DIR" -maxdepth 1 -type d -name "synccu-*" | head -1)

if [ -z "$SOURCE_DIR" ] || [ ! -d "$SOURCE_DIR/platform" ]; then
    error "Invalid archive structure. Expected platform/ directory."
    rm -rf "$DOWNLOAD_DIR"
    exit 1
fi

success "Source extracted"

################################################################################
# 4. Update files (preserve .env files)
################################################################################
step "Updating platform files..."

# Backup .env files
[ -f "$INSTALL_DIR/platform/backend/.env" ] && cp "$INSTALL_DIR/platform/backend/.env" /tmp/synccu-backend-env.bak
[ -f "$INSTALL_DIR/platform/api/.env" ] && cp "$INSTALL_DIR/platform/api/.env" /tmp/synccu-api-env.bak

# Sync new files over (preserve venv and vendor)
rsync -a --delete \
    --exclude='.env' \
    --exclude='venv/' \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='storage/logs/' \
    "$SOURCE_DIR/platform/" "$INSTALL_DIR/platform/"

# Restore .env files with correct ownership
[ -f /tmp/synccu-backend-env.bak ] && mv /tmp/synccu-backend-env.bak "$INSTALL_DIR/platform/backend/.env" && chown root:www-data "$INSTALL_DIR/platform/backend/.env" && chmod 640 "$INSTALL_DIR/platform/backend/.env"
[ -f /tmp/synccu-api-env.bak ] && mv /tmp/synccu-api-env.bak "$INSTALL_DIR/platform/api/.env" && chown root:www-data "$INSTALL_DIR/platform/api/.env" && chmod 640 "$INSTALL_DIR/platform/api/.env"

# Cleanup download
rm -rf "$DOWNLOAD_DIR"

success "Platform files updated"

################################################################################
# 5. Run database migrations / reset
################################################################################

if [ "$RESET_DB" -eq 1 ]; then
    step "Resetting database (preserving login credentials)..."

    # Save users and tenants
    RESET_TMP=$(mktemp -d)
    $DUMP_CMD "$DB_NAME" users tenants --single-transaction --no-create-info 2>/dev/null > "$RESET_TMP/users_tenants.sql" || true

    # Drop everything except users/tenants, then recreate
    $MYSQL_CMD "$DB_NAME" <<-'EOSQL'
        SET FOREIGN_KEY_CHECKS=0;
        DROP TABLE IF EXISTS loan_schedules, ledger_entries, transactions, loans, accounts,
            audit_logs, notifications, documents, journal_entry_lines, journal_entries,
            gl_accounts, chart_of_accounts, api_keys, tenant_settings, refresh_tokens;
        SET FOREIGN_KEY_CHECKS=1;
EOSQL
    success "Data tables dropped (users/tenants preserved)"

    # Import fresh schema (uses IF NOT EXISTS so users/tenants survive)
    SCHEMA_FILE="$INSTALL_DIR/platform/database/schema.sql"
    if [ -f "$SCHEMA_FILE" ]; then
        $MYSQL_CMD "$DB_NAME" < "$SCHEMA_FILE"
        success "Fresh schema imported"
    fi

    # Import seed data (uses INSERT IGNORE for tenants/users)
    SEED_FILE="$INSTALL_DIR/platform/database/seed.sql"
    if [ -f "$SEED_FILE" ]; then
        $MYSQL_CMD "$DB_NAME" < "$SEED_FILE"
        success "Seed data imported"
    fi

    # Restore any custom users not in seed
    if [ -f "$RESET_TMP/users_tenants.sql" ] && [ -s "$RESET_TMP/users_tenants.sql" ]; then
        sed 's/INSERT INTO/INSERT IGNORE INTO/g' "$RESET_TMP/users_tenants.sql" | $MYSQL_CMD "$DB_NAME"
        success "Custom login credentials restored"
    fi

    rm -rf "$RESET_TMP"
    success "Database reset complete"
else
    step "Running database migrations..."

    MIGRATION_DIR="$INSTALL_DIR/platform/database/migrations"
    MIGRATION_TRACKER="$INSTALL_DIR/platform/database/.migrations_applied"

    if [ -d "$MIGRATION_DIR" ] && ls "$MIGRATION_DIR"/*.sql &>/dev/null 2>&1; then
        touch "$MIGRATION_TRACKER"
        APPLIED=0

        for migration in "$MIGRATION_DIR"/*.sql; do
            NAME=$(basename "$migration")
            if grep -qF "$NAME" "$MIGRATION_TRACKER" 2>/dev/null; then
                continue
            fi
            info "Applying: $NAME"
            $MYSQL_CMD "$DB_NAME" < "$migration" && {
                echo "$NAME" >> "$MIGRATION_TRACKER"
                success "Applied: $NAME"
                APPLIED=$((APPLIED + 1))
            } || {
                error "Migration failed: $NAME"
                exit 1
            }
        done

        [ $APPLIED -eq 0 ] && info "No new migrations" || success "$APPLIED migration(s) applied"
    else
        info "No migrations to run"
    fi
fi

################################################################################
# 6. Update Python API dependencies
################################################################################
step "Updating Python dependencies..."

API_DIR="$INSTALL_DIR/platform/api"

if [ ! -d "$API_DIR/venv" ]; then
    python3 -m venv "$API_DIR/venv"
fi

"$API_DIR/venv/bin/pip" install --upgrade pip -q 2>/dev/null
"$API_DIR/venv/bin/pip" install -r "$API_DIR/requirements.txt" -q 2>/dev/null && \
    success "Python packages updated" || \
    warn "Some packages had warnings"

################################################################################
# 7. Update nginx config for PHP auth endpoint
################################################################################
step "Checking nginx config..."

NGINX_CONF="/etc/nginx/sites-available/synccu"

if [ -f "$NGINX_CONF" ]; then
    # Add PHP handler for frontend/api/ if not present
    if ! grep -q "auth.php" "$NGINX_CONF" 2>/dev/null; then
        info "Adding PHP auth endpoint to nginx config..."

        # Find the PHP-FPM socket
        PHP_SOCK=$(find /run/php/ -name "*.sock" 2>/dev/null | head -1 || echo "/var/run/php/php8.3-fpm.sock")

        # Insert PHP location block before "# Frontend static files"
        sed -i "/# Frontend static files/i\\
    # PHP auth API\\
    location ~ ^/api/.*\\\\.php\$ {\\
        limit_req zone=api_limit burst=10 nodelay;\\
        fastcgi_pass unix:${PHP_SOCK};\\
        fastcgi_param SCRIPT_FILENAME /var/www/synccu/platform/frontend\\\$uri;\\
        include fastcgi_params;\\
        fastcgi_param SCRIPT_NAME \\\$uri;\\
    }\\
" "$NGINX_CONF"
        success "Nginx config updated with PHP auth endpoint"
    else
        info "Nginx PHP auth endpoint already configured"
    fi
else
    warn "Nginx config not found at $NGINX_CONF"
fi

################################################################################
# 8. Fix permissions
################################################################################
step "Fixing permissions..."

chown -R www-data:www-data "$INSTALL_DIR/platform"
find "$INSTALL_DIR/platform" -type d -exec chmod 755 {} \;
find "$INSTALL_DIR/platform" -type f -exec chmod 644 {} \;
find "$INSTALL_DIR/platform" -name "*.sh" -exec chmod 755 {} \;
# .env files: readable by www-data group, not world-readable
chown root:www-data "$INSTALL_DIR/platform/backend/.env" 2>/dev/null || true
chown root:www-data "$INSTALL_DIR/platform/api/.env" 2>/dev/null || true
chmod 640 "$INSTALL_DIR/platform/backend/.env" 2>/dev/null || true
chmod 640 "$INSTALL_DIR/platform/api/.env" 2>/dev/null || true

success "Permissions fixed"

################################################################################
# 9. Restart services
################################################################################
step "Restarting services..."

# PHP-FPM
PHP_FPM=$(systemctl list-units --type=service --state=running 2>/dev/null | grep -oP 'php[\d.]+-fpm\.service' | head -1 || echo "")
if [ -n "$PHP_FPM" ]; then
    systemctl restart "$PHP_FPM" && success "$PHP_FPM restarted" || warn "Failed to restart $PHP_FPM"
fi

# SyncCU API
if systemctl is-enabled synccu-api &>/dev/null 2>&1; then
    systemctl restart synccu-api && success "synccu-api restarted" || warn "synccu-api failed to restart"
fi

# Nginx
nginx -t 2>/dev/null && systemctl reload nginx && success "nginx reloaded" || warn "nginx reload failed"

################################################################################
# 10. Verify
################################################################################
step "Verifying services..."

# Check nginx
if systemctl is-active nginx &>/dev/null; then
    success "nginx is running"
else
    error "nginx is NOT running"
fi

# Check frontend
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    success "Frontend responding (HTTP 200)"
else
    warn "Frontend returned HTTP $HTTP_CODE"
fi

# Check PHP auth endpoint
PHP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost/api/auth.php?action=login -H "Content-Type: application/json" -d '{}' 2>/dev/null || echo "000")
if [ "$PHP_CODE" = "400" ] || [ "$PHP_CODE" = "200" ]; then
    success "PHP auth endpoint responding (HTTP $PHP_CODE)"
else
    warn "PHP auth endpoint returned HTTP $PHP_CODE"
fi

################################################################################
# Done
################################################################################
echo ""
echo -e "${GREEN}══════════════════════════════════════════════════════════"
echo -e "   SyncCU Platform - Update Complete!"
echo -e "══════════════════════════════════════════════════════════${NC}"
echo ""
echo "  Platform:   http://${DOMAIN:-localhost}"
echo "  Backup:     $BACKUP_DIR/"
echo "  Log:        $UPDATE_LOG"
echo ""

success "Update completed at $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
