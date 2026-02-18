#!/usr/bin/env bash
# =============================================================
# SyncCU Updater
# Pulls the latest code from git, runs any new DB migrations,
# and reloads the web server.
# Usage: sudo bash update.sh          (from the repo directory)
# =============================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# ── Config ────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/synccu}"
WEB_ROOT="${WEB_ROOT:-$APP_DIR/web}"
REPO_DIR="$(cd "$(dirname "$0")" && pwd)"   # directory of this script = git repo
BACKUP_DIR="${BACKUP_DIR:-/var/backups/synccu}"
BRANCH="${BRANCH:-master}"

# ── Check root ───────────────────────────────────────────────
[ "$(id -u)" -eq 0 ] || error "Please run as root: sudo bash update.sh"

# ── Detect web server ────────────────────────────────────────
if   command -v apache2    &>/dev/null; then WEB_SVC="apache2"
elif command -v httpd      &>/dev/null; then WEB_SVC="httpd"
else warn "Web server not detected — you may need to reload manually."; WEB_SVC=""; fi

APACHE_USER="www-data"
[ "$WEB_SVC" = "httpd" ] && APACHE_USER="apache"

# ── Load DB credentials from .env ────────────────────────────
ENV_FILE="${WEB_ROOT}/.env"
[ -f "$ENV_FILE" ] || error ".env not found at ${ENV_FILE}. Run install.sh first."

DB_HOST="localhost"; DB_NAME="synccu"; DB_USER="root"; DB_PASS=""
while IFS='=' read -r key value; do
    [[ "$key" =~ ^# ]] && continue
    case "$key" in
        DB_HOST) DB_HOST="$value" ;;
        DB_NAME) DB_NAME="$value" ;;
        DB_USER) DB_USER="$value" ;;
        DB_PASS) DB_PASS="$value" ;;
    esac
done < "$ENV_FILE"

MYSQL_CMD="mysql -h ${DB_HOST} -u ${DB_USER}"
[ -n "$DB_PASS" ] && MYSQL_CMD="$MYSQL_CMD -p${DB_PASS}"
MYSQL_CMD="$MYSQL_CMD ${DB_NAME}"

echo ""
info "Starting SyncCU update…"
echo -e "  App dir:  ${APP_DIR}"
echo -e "  Branch:   ${BRANCH}"
echo ""

# ── 1. Backup database ────────────────────────────────────────
info "Backing up database…"
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="${BACKUP_DIR}/synccu_$(date +%Y%m%d_%H%M%S).sql.gz"
MYSQLDUMP_CMD="mysqldump -h ${DB_HOST} -u ${DB_USER}"
[ -n "$DB_PASS" ] && MYSQLDUMP_CMD="$MYSQLDUMP_CMD -p${DB_PASS}"
$MYSQLDUMP_CMD "$DB_NAME" | gzip > "$BACKUP_FILE"
info "Database backup saved to ${BACKUP_FILE}"

# Keep only the last 10 backups
ls -t "${BACKUP_DIR}"/synccu_*.sql.gz 2>/dev/null | tail -n +11 | xargs rm -f || true

# ── 2. Pull latest code ───────────────────────────────────────
info "Pulling latest code from git…"
cd "$REPO_DIR"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull origin "$BRANCH"

# ── 3. Copy updated files to app dir ─────────────────────────
info "Deploying updated files…"
# Preserve the .env file
cp "${WEB_ROOT}/.env" /tmp/synccu_env_backup

# Sync files (exclude .git and sensitive files)
rsync -a --delete \
    --exclude='.git' \
    --exclude='web/.env' \
    "${REPO_DIR}/" "${APP_DIR}/"

# Restore .env
cp /tmp/synccu_env_backup "${WEB_ROOT}/.env"
rm -f /tmp/synccu_env_backup

# ── 4. Run any pending migrations ────────────────────────────
MIGRATIONS_DIR="${REPO_DIR}/migrations"
if [ -d "$MIGRATIONS_DIR" ]; then
    info "Checking for database migrations…"

    # Ensure migrations tracking table exists
    $MYSQL_CMD -e "
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename   VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    "

    APPLIED=0
    for migration in "$MIGRATIONS_DIR"/*.sql; do
        [ -f "$migration" ] || continue
        filename="$(basename "$migration")"
        already_applied=$($MYSQL_CMD -se "SELECT COUNT(*) FROM schema_migrations WHERE filename='${filename}'" 2>/dev/null || echo 0)
        if [ "$already_applied" -eq 0 ]; then
            info "Applying migration: ${filename}"
            $MYSQL_CMD < "$migration"
            $MYSQL_CMD -e "INSERT INTO schema_migrations (filename) VALUES ('${filename}')"
            APPLIED=$((APPLIED + 1))
        fi
    done
    [ "$APPLIED" -eq 0 ] && info "No new migrations to apply" || info "${APPLIED} migration(s) applied"
else
    warn "No migrations/ directory found — skipping migrations step"
fi

# ── 5. Set permissions ────────────────────────────────────────
info "Setting file permissions…"
chown -R "${APACHE_USER}:${APACHE_USER}" "$APP_DIR"
chmod -R 750 "$WEB_ROOT"
chmod 640 "${WEB_ROOT}/.env"

# ── 6. Reload web server ─────────────────────────────────────
if [ -n "$WEB_SVC" ]; then
    info "Reloading ${WEB_SVC}…"
    systemctl reload "$WEB_SVC"
fi

# ── Done ─────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  SyncCU update complete!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
echo -e "  Backup file: ${BACKUP_FILE}"
echo -e "  Branch:      ${BRANCH}"
echo -e "  Updated at:  $(date)"
echo ""
