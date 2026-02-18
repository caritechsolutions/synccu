#!/usr/bin/env bash
# =============================================================
# SyncCU Updater
# Run via curl:
#   curl -sSL "https://raw.githubusercontent.com/caritechsolutions/synccu/master/update.sh?$(date +%s)" | sudo bash
#
# Or with options:
#   curl -sSL "..." | sudo bash -s -- --branch=master --install-dir=/var/www/synccu
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
BRANCH="master"
APP_DIR="/var/www/synccu"
WEB_ROOT=""                       # derived from APP_DIR below
BACKUP_DIR="/var/backups/synccu"

# ── Parse arguments ───────────────────────────────────────────
for arg in "$@"; do
    case "$arg" in
        --branch=*)      BRANCH="${arg#*=}"    ;;
        --install-dir=*) APP_DIR="${arg#*=}"   ;;
        --backup-dir=*)  BACKUP_DIR="${arg#*=}" ;;
        *) warn "Unknown argument: $arg" ;;
    esac
done

WEB_ROOT="${APP_DIR}/web"

# ── Root check ───────────────────────────────────────────────
[[ "$(id -u)" -eq 0 ]] || error "Please run as root:  curl ... | sudo bash"

# ── Check installed ───────────────────────────────────────────
[[ -f "${WEB_ROOT}/.env" ]] || error "SyncCU does not appear to be installed at ${APP_DIR}.\nRun install.sh first."

# ── Banner ────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║         SyncCU Updater                   ║${NC}"
echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════╝${NC}"
echo ""
info "Install directory : ${APP_DIR}"
info "Branch            : ${BRANCH}"
echo ""

# ── Detect web server ─────────────────────────────────────────
section "Detecting web server"
if   command -v apache2 &>/dev/null; then APACHE_SVC="apache2"; APACHE_USER="www-data"
elif command -v httpd   &>/dev/null; then APACHE_SVC="httpd";   APACHE_USER="apache"
else
    warn "Web server not detected — you may need to reload it manually."
    APACHE_SVC=""; APACHE_USER="www-data"
fi
[[ -n "$APACHE_SVC" ]] && info "Web server: ${APACHE_SVC}"

# ── Load DB credentials from .env ─────────────────────────────
section "Loading configuration"
DB_HOST="localhost"; DB_NAME="synccu"; DB_USER="synccu_app"; DB_PASS=""
while IFS='=' read -r key value; do
    [[ "$key" =~ ^[[:space:]]*# ]] && continue
    [[ -z "$key" ]] && continue
    key="${key// /}"
    value="${value// /}"
    case "$key" in
        DB_HOST) DB_HOST="$value" ;;
        DB_NAME) DB_NAME="$value" ;;
        DB_USER) DB_USER="$value" ;;
        DB_PASS) DB_PASS="$value" ;;
    esac
done < "${WEB_ROOT}/.env"
info "Database: ${DB_NAME} on ${DB_HOST}"

MYSQL_CMD="mysql -h ${DB_HOST} -u ${DB_USER}"
[[ -n "$DB_PASS" ]] && MYSQL_CMD="$MYSQL_CMD -p${DB_PASS}"
MYSQL_CMD="$MYSQL_CMD ${DB_NAME}"

MYSQLDUMP_CMD="mysqldump -h ${DB_HOST} -u ${DB_USER}"
[[ -n "$DB_PASS" ]] && MYSQLDUMP_CMD="$MYSQLDUMP_CMD -p${DB_PASS}"

# ── 1. Backup database ────────────────────────────────────────
section "Backing up database"
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="${BACKUP_DIR}/synccu_$(date +%Y%m%d_%H%M%S).sql.gz"
$MYSQLDUMP_CMD "$DB_NAME" | gzip > "$BACKUP_FILE" \
    || error "Database backup failed"
info "Backup saved to ${BACKUP_FILE}"

# Keep only the last 10 backups
ls -t "${BACKUP_DIR}"/synccu_*.sql.gz 2>/dev/null | tail -n +11 | xargs rm -f || true

# ── 2. Clone latest code ──────────────────────────────────────
section "Downloading latest SyncCU from GitHub"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

git clone --depth=1 --branch "$BRANCH" "$REPO_URL" "${TMP_DIR}/synccu" \
    || error "Failed to clone repo. Check network and branch name."
info "Repository cloned (branch: ${BRANCH})"

# ── 3. Deploy updated files ───────────────────────────────────
section "Deploying updated files"

# Preserve .env
cp "${WEB_ROOT}/.env" "${TMP_DIR}/env_backup"

# Sync files (exclude .git; .env excluded via source-side --exclude)
rsync -a --delete \
    --exclude='.git' \
    --exclude='web/.env' \
    "${TMP_DIR}/synccu/" "${APP_DIR}/"

# Restore .env
cp "${TMP_DIR}/env_backup" "${WEB_ROOT}/.env"
info "Files deployed to ${APP_DIR}"

# ── 4. Run pending migrations ─────────────────────────────────
section "Checking database migrations"
MIGRATIONS_DIR="${TMP_DIR}/synccu/migrations"
if [[ -d "$MIGRATIONS_DIR" ]]; then
    # Ensure migrations tracking table exists
    $MYSQL_CMD -e "
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename   VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    " || error "Failed to create migrations table"

    APPLIED=0
    for migration in "${MIGRATIONS_DIR}"/*.sql; do
        [[ -f "$migration" ]] || continue
        filename="$(basename "$migration")"
        already_applied=$($MYSQL_CMD -se \
            "SELECT COUNT(*) FROM schema_migrations WHERE filename='${filename}'" \
            2>/dev/null || echo 0)
        if [[ "$already_applied" -eq 0 ]]; then
            info "Applying migration: ${filename}"
            $MYSQL_CMD < "$migration" || error "Migration failed: ${filename}"
            $MYSQL_CMD -e "INSERT INTO schema_migrations (filename) VALUES ('${filename}')"
            APPLIED=$((APPLIED + 1))
        fi
    done
    [[ "$APPLIED" -eq 0 ]] \
        && info "No new migrations to apply" \
        || info "${APPLIED} migration(s) applied"
else
    warn "No migrations/ directory found — skipping migrations step"
fi

# ── 5. Set permissions ────────────────────────────────────────
section "Setting file permissions"
chown -R "${APACHE_USER}:${APACHE_USER}" "$APP_DIR"
chmod -R 750 "$WEB_ROOT"
chmod 640 "${WEB_ROOT}/.env"
info "Permissions set"

# ── 6. Reload web server ──────────────────────────────────────
if [[ -n "$APACHE_SVC" ]]; then
    section "Reloading web server"
    systemctl reload "$APACHE_SVC" || error "Web server reload failed"
    info "${APACHE_SVC} reloaded"
fi

# ── Done ──────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║        Update Complete!                  ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BOLD}Branch:${NC}      ${BRANCH}"
echo -e "  ${BOLD}Updated at:${NC}  $(date)"
echo -e "  ${BOLD}Backup:${NC}      ${BACKUP_FILE}"
echo ""
echo "  To update again later, run:"
echo "    curl -sSL \"https://raw.githubusercontent.com/caritechsolutions/synccu/${BRANCH}/update.sh?\$(date +%s)\" | sudo bash"
echo ""
