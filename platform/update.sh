#!/bin/bash
set -euo pipefail

################################################################################
# SyncCU Platform Updater
# One-command: curl -sSL https://raw.githubusercontent.com/caritechsolutions/synccu/main/platform/update.sh | sudo bash
# Version: 2.0.0
################################################################################

UPDATE_LOG="/var/log/synccu-update.log"
REPO_BRANCH="claude/rebuild-platform-modern-5He4J"

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
    Platform Updater v2.0.0
BANNER
echo -e "${NC}"

# Root check
if [ "$EUID" -ne 0 ]; then
    error "Run as root:  curl -sSL <url> | sudo bash"
    exit 1
fi

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
    # Try common locations
    for dir in /var/www/synccu /opt/synccu /home/*/synccu; do
        if [ -d "$dir/platform" ]; then
            INSTALL_DIR="$dir"
            break
        fi
    done
fi

if [ -z "${INSTALL_DIR:-}" ] || [ ! -d "${INSTALL_DIR:-}" ]; then
    read -rp "Install directory not found. Enter path: " INSTALL_DIR
fi

if [ ! -d "$INSTALL_DIR/platform" ]; then
    error "Invalid install directory: $INSTALL_DIR"
    exit 1
fi

success "Found installation at $INSTALL_DIR"

# Load config
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-synccu}"
DB_USER="${DB_USER:-synccu_user}"
DOMAIN="${DOMAIN:-localhost}"

################################################################################
# 2. Backup current installation
################################################################################
step "Creating pre-update backup..."

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/var/backups/synccu"
mkdir -p "$BACKUP_DIR"

# Database backup
info "Backing up database..."
mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
    --single-transaction --routines --triggers \
    2>/dev/null | gzip > "$BACKUP_DIR/db_${TIMESTAMP}.sql.gz" && \
    success "Database backed up" || \
    warn "Database backup failed (continuing anyway)"

# Files backup
info "Backing up files..."
tar czf "$BACKUP_DIR/files_${TIMESTAMP}.tar.gz" \
    -C "$(dirname "$INSTALL_DIR")" "$(basename "$INSTALL_DIR")" \
    --exclude="*/venv/*" --exclude="*/vendor/*" --exclude="*/node_modules/*" \
    --exclude="*/.git/*" 2>/dev/null && \
    success "Files backed up to $BACKUP_DIR/files_${TIMESTAMP}.tar.gz" || \
    warn "Files backup had warnings"

################################################################################
# 3. Pull latest code
################################################################################
step "Pulling latest code..."

cd "$INSTALL_DIR"

if [ -d ".git" ]; then
    BEFORE=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")

    # Retry git pull up to 4 times with backoff
    PULL_OK=false
    for i in 1 2 3 4; do
        if git pull origin "$REPO_BRANCH" 2>/dev/null; then
            PULL_OK=true
            break
        fi
        WAIT=$((2 ** i))
        warn "Git pull failed, retrying in ${WAIT}s... (attempt $i/4)"
        sleep "$WAIT"
    done

    if [ "$PULL_OK" = false ]; then
        error "Could not pull latest code after 4 attempts"
        exit 1
    fi

    AFTER=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
    if [ "$BEFORE" = "$AFTER" ]; then
        info "Already up to date ($BEFORE)"
    else
        success "Updated: $BEFORE → $AFTER"
        git log --oneline "${BEFORE}..${AFTER}" 2>/dev/null | head -20
    fi
else
    warn "Not a git repo — re-cloning..."
    cd /tmp
    git clone -b "$REPO_BRANCH" "https://github.com/caritechsolutions/synccu.git" synccu-update
    rsync -a --delete --exclude='.env' --exclude='venv/' --exclude='vendor/' \
        /tmp/synccu-update/platform/ "$INSTALL_DIR/platform/"
    rm -rf /tmp/synccu-update
    success "Files updated via fresh clone"
fi

################################################################################
# 4. Run database migrations
################################################################################
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
        mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$migration" && {
            echo "$NAME" >> "$MIGRATION_TRACKER"
            success "Applied: $NAME"
            APPLIED=$((APPLIED + 1))
        } || {
            error "Migration failed: $NAME"
            warn "Restore from backup: $BACKUP_DIR/db_${TIMESTAMP}.sql.gz"
            exit 1
        }
    done

    [ $APPLIED -eq 0 ] && info "No new migrations" || success "$APPLIED migration(s) applied"
else
    info "No migrations to run"
fi

################################################################################
# 5. Update Python API dependencies
################################################################################
step "Updating Python dependencies..."

API_DIR="$INSTALL_DIR/platform/api"

if [ ! -d "$API_DIR/venv" ]; then
    python3 -m venv "$API_DIR/venv"
fi

source "$API_DIR/venv/bin/activate"
pip install --upgrade pip -q 2>/dev/null
pip install -r "$API_DIR/requirements.txt" -q 2>/dev/null && \
    success "Python packages updated" || \
    warn "Some packages had warnings"
deactivate

################################################################################
# 6. Update PHP dependencies
################################################################################
step "Updating PHP dependencies..."

BACKEND_DIR="$INSTALL_DIR/platform/backend"
if [ -f "$BACKEND_DIR/composer.json" ] && command -v composer &>/dev/null; then
    cd "$BACKEND_DIR"
    composer install --no-dev --optimize-autoloader --no-interaction -q 2>/dev/null && \
        success "Composer packages updated" || \
        warn "Composer had warnings"
else
    info "No composer.json or composer not installed — skipping"
fi

################################################################################
# 7. Clear caches
################################################################################
step "Clearing caches..."

# PHP opcache
php -r "if(function_exists('opcache_reset')){opcache_reset();}" 2>/dev/null || true

# App caches
find "$BACKEND_DIR" -path "*/cache/*.php" -delete 2>/dev/null || true
find "$BACKEND_DIR" -path "*/views/*.php" -delete 2>/dev/null || true

success "Caches cleared"

################################################################################
# 8. Fix permissions
################################################################################
step "Fixing permissions..."

chown -R www-data:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
find "$INSTALL_DIR" -name "*.sh" -exec chmod 755 {} \;
chmod 640 "$BACKEND_DIR/.env" "$API_DIR/.env" 2>/dev/null || true

success "Permissions fixed"

################################################################################
# 9. Restart services
################################################################################
step "Restarting services..."

# API
systemctl restart synccu-api 2>/dev/null && success "synccu-api restarted" || warn "synccu-api restart failed"

# PHP-FPM
PHP_FPM=$(systemctl list-units --type=service --state=running 2>/dev/null | grep -oP 'php[\d.]+-fpm\.service' | head -1 || echo "")
if [ -n "$PHP_FPM" ]; then
    systemctl restart "$PHP_FPM" && success "$PHP_FPM restarted" || warn "$PHP_FPM restart failed"
fi

# Nginx
systemctl reload nginx 2>/dev/null && success "nginx reloaded" || warn "nginx reload failed"

################################################################################
# 10. Verify
################################################################################
step "Verifying services..."

ALL_OK=true
for svc in synccu-api nginx; do
    if systemctl is-active "$svc" &>/dev/null; then
        success "$svc is running"
    else
        error "$svc is NOT running"
        ALL_OK=false
    fi
done

# Health check
if command -v curl &>/dev/null; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        success "Frontend responding (HTTP $HTTP_CODE)"
    else
        warn "Frontend returned HTTP $HTTP_CODE"
    fi
fi

################################################################################
# Done
################################################################################
echo ""
echo -e "${GREEN}══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}   SyncCU Platform - Update Complete!${NC}"
echo -e "${GREEN}══════════════════════════════════════════════════════════${NC}"
echo ""
echo "  Platform:   http://${DOMAIN}"
echo "  Backup:     $BACKUP_DIR/"
echo "  Log:        $UPDATE_LOG"
if [ -d "$INSTALL_DIR/.git" ]; then
    echo "  Revision:   $(cd "$INSTALL_DIR" && git rev-parse --short HEAD 2>/dev/null)"
fi
echo ""

if [ "$ALL_OK" = false ]; then
    warn "Some services need attention. Check: journalctl -u <service>"
fi
