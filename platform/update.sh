#!/bin/bash
set -euo pipefail

################################################################################
# SyncCU Platform Updater
# Core Banking Platform - Update Script
# Version: 1.0.0
################################################################################

UPDATE_LOG="/var/log/synccu-update.log"

exec > >(tee -a "$UPDATE_LOG") 2>&1

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
# Banner
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
    Platform Updater v1.0.0
BANNER
    echo -e "${NC}"
}

################################################################################
# Detect Install Directory
################################################################################

detect_install_dir() {
    step "Detecting installation directory..."

    CONFIG_FILE="/etc/synccu/config"
    if [ -f "$CONFIG_FILE" ]; then
        source "$CONFIG_FILE"
        if [ -n "${INSTALL_DIR:-}" ] && [ -d "$INSTALL_DIR" ]; then
            success "Found installation at $INSTALL_DIR"
            return
        fi
    fi

    warn "Could not detect installation directory from config"
    read -rp "Enter SyncCU install directory [/var/www/synccu]: " INSTALL_DIR
    INSTALL_DIR="${INSTALL_DIR:-/var/www/synccu}"

    if [ ! -d "$INSTALL_DIR" ]; then
        error "Directory $INSTALL_DIR does not exist"
        exit 1
    fi

    success "Using installation directory: $INSTALL_DIR"
}

################################################################################
# Pre-Update Backup
################################################################################

create_backup() {
    step "Creating pre-update backup..."

    TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
    BACKUP_DIR="/var/backups/synccu/pre-update"
    mkdir -p "$BACKUP_DIR"

    # Database backup
    if [ -f "/etc/synccu/config" ]; then
        source /etc/synccu/config
        DB_HOST="${DB_HOST:-localhost}"
        DB_NAME="${DB_NAME:-synccu}"
        DB_USER="${DB_USER:-synccu_user}"

        info "Dumping database $DB_NAME..."
        mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
            --single-transaction --routines --triggers \
            > "$BACKUP_DIR/db_${DB_NAME}_${TIMESTAMP}.sql" 2>/dev/null && \
            success "Database backup created" || \
            warn "Database backup failed; continuing with update"
    else
        warn "No config found, skipping database backup"
    fi

    # Files backup
    info "Archiving application files..."
    tar czf "$BACKUP_DIR/files_${TIMESTAMP}.tar.gz" \
        -C "$(dirname "$INSTALL_DIR")" \
        "$(basename "$INSTALL_DIR")" \
        --exclude="*/venv/*" \
        --exclude="*/vendor/*" \
        --exclude="*/node_modules/*" 2>/dev/null && \
        success "Files backup created at $BACKUP_DIR/files_${TIMESTAMP}.tar.gz" || \
        warn "Files backup had warnings"

    success "Pre-update backup complete"
}

################################################################################
# Update Code
################################################################################

update_code() {
    step "Pulling latest code..."

    cd "$INSTALL_DIR"

    if [ -d ".git" ]; then
        BEFORE_HASH=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
        git fetch origin 2>/dev/null
        git pull origin "$(git rev-parse --abbrev-ref HEAD)" 2>/dev/null || {
            error "Git pull failed. Check for local changes."
            exit 1
        }
        AFTER_HASH=$(git rev-parse HEAD 2>/dev/null || echo "unknown")

        if [ "$BEFORE_HASH" = "$AFTER_HASH" ]; then
            info "Code is already up to date ($BEFORE_HASH)"
        else
            success "Code updated: $BEFORE_HASH -> $AFTER_HASH"
            info "Changes:"
            git log --oneline "${BEFORE_HASH}..${AFTER_HASH}" 2>/dev/null | head -20
        fi
    else
        warn "Not a git repository, skipping code pull"
    fi
}

################################################################################
# Run Migrations
################################################################################

run_migrations() {
    step "Running database migrations..."

    MIGRATION_DIR="$INSTALL_DIR/database/migrations"
    MIGRATION_TRACKER="$INSTALL_DIR/database/.migrations_applied"

    if [ ! -d "$MIGRATION_DIR" ]; then
        info "No migrations directory found, skipping"
        return
    fi

    touch "$MIGRATION_TRACKER"
    local applied=0

    if [ -f "/etc/synccu/config" ]; then
        source /etc/synccu/config
    fi

    for migration in "$MIGRATION_DIR"/*.sql; do
        [ -f "$migration" ] || continue
        MIGRATION_NAME=$(basename "$migration")

        if grep -qF "$MIGRATION_NAME" "$MIGRATION_TRACKER" 2>/dev/null; then
            continue
        fi

        info "Applying migration: $MIGRATION_NAME"
        mysql -h "${DB_HOST:-localhost}" -u "${DB_USER:-synccu_user}" "${DB_NAME:-synccu}" < "$migration" && {
            echo "$MIGRATION_NAME" >> "$MIGRATION_TRACKER"
            success "Applied: $MIGRATION_NAME"
            applied=$((applied + 1))
        } || {
            error "Failed to apply migration: $MIGRATION_NAME"
            exit 1
        }
    done

    if [ $applied -eq 0 ]; then
        info "No new migrations to apply"
    else
        success "$applied migration(s) applied"
    fi
}

################################################################################
# Update Python Dependencies
################################################################################

update_python_deps() {
    step "Updating Python API dependencies..."

    local API_DIR="$INSTALL_DIR/api"

    if [ ! -d "$API_DIR/venv" ]; then
        warn "Python venv not found, creating..."
        python3 -m venv "$API_DIR/venv"
    fi

    source "$API_DIR/venv/bin/activate"

    if [ -f "$API_DIR/requirements.txt" ]; then
        pip install --upgrade pip >/dev/null 2>&1
        pip install -r "$API_DIR/requirements.txt" --upgrade 2>/dev/null && \
            success "Python dependencies updated" || \
            warn "Some Python packages had warnings during update"
    else
        warn "requirements.txt not found"
    fi

    deactivate
}

################################################################################
# Clear Caches
################################################################################

clear_caches() {
    step "Clearing caches..."

    # PHP opcache reset
    if command -v php &>/dev/null; then
        php -r "opcache_reset();" 2>/dev/null || true
        info "PHP opcache cleared"
    fi

    # Clear application caches
    if [ -d "$INSTALL_DIR/backend/bootstrap/cache" ]; then
        find "$INSTALL_DIR/backend/bootstrap/cache" -type f -name "*.php" -delete 2>/dev/null
        info "Backend bootstrap cache cleared"
    fi

    if [ -d "$INSTALL_DIR/backend/storage/framework/cache" ]; then
        find "$INSTALL_DIR/backend/storage/framework/cache" -type f -delete 2>/dev/null
        info "Backend framework cache cleared"
    fi

    if [ -d "$INSTALL_DIR/backend/storage/framework/views" ]; then
        find "$INSTALL_DIR/backend/storage/framework/views" -type f -delete 2>/dev/null
        info "Backend view cache cleared"
    fi

    success "Caches cleared"
}

################################################################################
# Restart Services
################################################################################

restart_services() {
    step "Restarting services..."

    local services=("synccu-api" "nginx")

    # Find PHP-FPM service name
    PHP_FPM=$(systemctl list-units --type=service --state=running | grep -oP 'php[\d.]+-fpm\.service' | head -1 || echo "")
    if [ -n "$PHP_FPM" ]; then
        services+=("$PHP_FPM")
    fi

    for svc in "${services[@]}"; do
        if systemctl is-enabled "$svc" &>/dev/null; then
            info "Restarting $svc..."
            systemctl restart "$svc" && success "$svc restarted" || warn "Failed to restart $svc"
        else
            warn "$svc is not enabled, skipping"
        fi
    done
}

################################################################################
# Verify Services
################################################################################

verify_services() {
    step "Verifying services..."

    local all_ok=true
    local services=("synccu-api" "nginx")

    PHP_FPM=$(systemctl list-units --type=service --state=running | grep -oP 'php[\d.]+-fpm\.service' | head -1 || echo "")
    if [ -n "$PHP_FPM" ]; then
        services+=("$PHP_FPM")
    fi

    for svc in "${services[@]}"; do
        if systemctl is-active "$svc" &>/dev/null; then
            success "$svc is running"
        else
            error "$svc is NOT running"
            all_ok=false
        fi
    done

    if [ "$all_ok" = true ]; then
        success "All services are running"
    else
        warn "Some services are not running. Check with: journalctl -u <service-name>"
    fi
}

################################################################################
# Print Summary
################################################################################

print_summary() {
    echo ""
    echo -e "${GREEN}=========================================================="
    echo "  SyncCU Platform Update Complete!"
    echo "==========================================================${NC}"
    echo ""
    echo "  Timestamp:      $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
    echo "  Install Dir:    $INSTALL_DIR"
    echo "  Backup Dir:     /var/backups/synccu/pre-update"
    echo "  Update Log:     $UPDATE_LOG"
    echo ""
    if [ -d "$INSTALL_DIR/.git" ]; then
        echo "  Git Revision:   $(cd "$INSTALL_DIR" && git rev-parse --short HEAD 2>/dev/null || echo 'N/A')"
        echo "  Git Branch:     $(cd "$INSTALL_DIR" && git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'N/A')"
    fi
    echo ""
}

################################################################################
# Main
################################################################################

main() {
    print_banner

    if [ "$EUID" -ne 0 ]; then
        error "This script must be run as root. Use: sudo bash update.sh"
        exit 1
    fi

    info "Update started at $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
    echo ""

    detect_install_dir
    create_backup
    update_code
    run_migrations
    update_python_deps
    clear_caches
    restart_services
    verify_services
    print_summary

    success "Update completed successfully"
}

main "$@"
