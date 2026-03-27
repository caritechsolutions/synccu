#!/bin/bash
set -euo pipefail

################################################################################
# SyncCU Platform Backup
# Core Banking Platform - Backup Script
# Version: 1.0.0
################################################################################

BACKUP_LOG="/var/log/synccu-backup.log"
BACKUP_DIR="${SYNCCU_BACKUP_DIR:-/var/backups/synccu}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DAY_OF_WEEK=$(date +"%u")

# Ensure log directory exists
mkdir -p "$(dirname "$BACKUP_LOG")"
exec > >(tee -a "$BACKUP_LOG") 2>&1

################################################################################
# Color Output Helpers
################################################################################

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

success() { echo -e "${GREEN}[✓]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1" >&2; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
info()    { echo -e "${BLUE}[i]${NC} $1"; }

################################################################################
# Load Configuration
################################################################################

load_config() {
    CONFIG_FILE="/etc/synccu/config"
    if [ -f "$CONFIG_FILE" ]; then
        source "$CONFIG_FILE"
    else
        error "Configuration file not found at $CONFIG_FILE"
        error "Run install.sh first or create the config manually."
        exit 1
    fi

    INSTALL_DIR="${INSTALL_DIR:-/var/www/synccu}"
    DB_HOST="${DB_HOST:-localhost}"
    DB_NAME="${DB_NAME:-synccu}"
    DB_USER="${DB_USER:-synccu_user}"
}

################################################################################
# Create Backup Directories
################################################################################

setup_dirs() {
    mkdir -p "$BACKUP_DIR/daily"
    mkdir -p "$BACKUP_DIR/weekly"
}

################################################################################
# Database Backup
################################################################################

backup_database() {
    info "Backing up database: $DB_NAME"

    local DB_BACKUP_FILE="$BACKUP_DIR/daily/db_${DB_NAME}_${TIMESTAMP}.sql.gz"

    mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-table \
        --complete-insert \
        2>/dev/null | gzip > "$DB_BACKUP_FILE"

    if [ -f "$DB_BACKUP_FILE" ] && [ -s "$DB_BACKUP_FILE" ]; then
        local SIZE
        SIZE=$(du -h "$DB_BACKUP_FILE" | cut -f1)
        success "Database backup created: $DB_BACKUP_FILE ($SIZE)"
    else
        error "Database backup failed or file is empty"
        rm -f "$DB_BACKUP_FILE"
        return 1
    fi
}

################################################################################
# Files Backup
################################################################################

backup_files() {
    info "Backing up application files: $INSTALL_DIR"

    local FILES_BACKUP="$BACKUP_DIR/daily/files_${TIMESTAMP}.tar.gz"

    tar czf "$FILES_BACKUP" \
        -C "$(dirname "$INSTALL_DIR")" \
        "$(basename "$INSTALL_DIR")" \
        --exclude="*/venv/*" \
        --exclude="*/vendor/*" \
        --exclude="*/node_modules/*" \
        --exclude="*/.git/*" \
        --exclude="*/storage/logs/*" \
        --exclude="*/storage/framework/cache/*" \
        2>/dev/null

    if [ -f "$FILES_BACKUP" ] && [ -s "$FILES_BACKUP" ]; then
        local SIZE
        SIZE=$(du -h "$FILES_BACKUP" | cut -f1)
        success "Files backup created: $FILES_BACKUP ($SIZE)"
    else
        error "Files backup failed or archive is empty"
        rm -f "$FILES_BACKUP"
        return 1
    fi
}

################################################################################
# Weekly Backup Copy
################################################################################

create_weekly_backup() {
    # Create weekly backup on Sundays (day 7)
    if [ "$DAY_OF_WEEK" -eq 7 ]; then
        info "Creating weekly backup copy..."

        for f in "$BACKUP_DIR/daily/"*"_${TIMESTAMP}"*; do
            [ -f "$f" ] || continue
            cp "$f" "$BACKUP_DIR/weekly/"
        done

        success "Weekly backup copy created"
    fi
}

################################################################################
# Backup Rotation
################################################################################

rotate_backups() {
    info "Rotating old backups..."

    # Keep last 7 daily backups
    local daily_count
    daily_count=$(find "$BACKUP_DIR/daily" -type f -name "*.gz" | wc -l)

    if [ "$daily_count" -gt 14 ]; then
        find "$BACKUP_DIR/daily" -type f -name "*.gz" -mtime +7 -delete 2>/dev/null
        success "Removed daily backups older than 7 days"
    else
        info "Daily backup rotation: $daily_count files (no cleanup needed)"
    fi

    # Keep last 4 weekly backups
    local weekly_count
    weekly_count=$(find "$BACKUP_DIR/weekly" -type f -name "*.gz" | wc -l)

    if [ "$weekly_count" -gt 8 ]; then
        find "$BACKUP_DIR/weekly" -type f -name "*.gz" -mtime +28 -delete 2>/dev/null
        success "Removed weekly backups older than 28 days"
    else
        info "Weekly backup rotation: $weekly_count files (no cleanup needed)"
    fi
}

################################################################################
# Optional S3 Upload
################################################################################

upload_to_s3() {
    if ! command -v aws &>/dev/null; then
        info "AWS CLI not installed, skipping S3 upload"
        return
    fi

    # Check if S3 bucket is configured
    S3_BUCKET="${SYNCCU_S3_BUCKET:-}"
    if [ -z "$S3_BUCKET" ]; then
        info "S3 bucket not configured (set SYNCCU_S3_BUCKET), skipping upload"
        return
    fi

    info "Uploading backups to S3: $S3_BUCKET"

    local S3_PREFIX="${SYNCCU_S3_PREFIX:-backups/synccu}"

    for f in "$BACKUP_DIR/daily/"*"_${TIMESTAMP}"*; do
        [ -f "$f" ] || continue
        local FILENAME
        FILENAME=$(basename "$f")

        aws s3 cp "$f" "s3://${S3_BUCKET}/${S3_PREFIX}/daily/${FILENAME}" --quiet && \
            success "Uploaded to S3: $FILENAME" || \
            warn "Failed to upload $FILENAME to S3"
    done

    # Upload weekly to separate S3 path
    if [ "$DAY_OF_WEEK" -eq 7 ]; then
        for f in "$BACKUP_DIR/weekly/"*"_${TIMESTAMP}"*; do
            [ -f "$f" ] || continue
            local FILENAME
            FILENAME=$(basename "$f")

            aws s3 cp "$f" "s3://${S3_BUCKET}/${S3_PREFIX}/weekly/${FILENAME}" --quiet && \
                success "Uploaded weekly to S3: $FILENAME" || \
                warn "Failed to upload weekly $FILENAME to S3"
        done
    fi

    success "S3 upload complete"
}

################################################################################
# Main
################################################################################

main() {
    echo ""
    info "=== SyncCU Backup Started: $(date -u +"%Y-%m-%d %H:%M:%S UTC") ==="
    echo ""

    load_config
    setup_dirs
    backup_database
    backup_files
    create_weekly_backup
    rotate_backups
    upload_to_s3

    echo ""
    info "Backup directory: $BACKUP_DIR"
    info "Total backup size: $(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)"
    success "=== SyncCU Backup Completed: $(date -u +"%Y-%m-%d %H:%M:%S UTC") ==="
    echo ""

    # Crontab hint
    # Add to crontab for daily backups at 2:00 AM:
    #   0 2 * * * /var/www/synccu/backup.sh
}

main "$@"
