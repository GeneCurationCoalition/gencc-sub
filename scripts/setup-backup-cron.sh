#!/bin/bash
#
# GenCC-Sub Backup Cron Setup Script
#
# Sets up the nightly database backup cron job on the production VM.
# This script should be run once during initial server setup.
#
# Usage:
#   sudo ./scripts/setup-backup-cron.sh --bucket <bucket-name>
#
# What this script does:
#   1. Creates /etc/gencc/backup.env with configuration
#   2. Installs backup script to /opt/gencc/scripts/
#   3. Sets up cron job for nightly backups at 2:00 AM UTC
#   4. Creates log rotation configuration
#   5. Verifies gsutil is configured correctly
#
# Prerequisites:
#   - Root or sudo access
#   - gsutil installed and configured
#   - MySQL client installed
#   - Service account with Storage Object Creator role

set -euo pipefail

# Script metadata
SCRIPT_NAME="gencc-setup-backup"
SCRIPT_VERSION="1.0.0"

# Configuration
INSTALL_DIR="/opt/gencc/scripts"
CONFIG_DIR="/etc/gencc"
LOG_DIR="/var/log/gencc"
BACKUP_DIR="/var/backups/gencc"
CRON_SCHEDULE="0 2 * * *"  # 2:00 AM UTC daily

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

#------------------------------------------------------------------------------
# Helper functions
#------------------------------------------------------------------------------
log_info() {
    echo -e "${GREEN}[INFO]${NC} $*"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $*"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*" >&2
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root (use sudo)"
        exit 1
    fi
}

#------------------------------------------------------------------------------
# Parse command line arguments
#------------------------------------------------------------------------------
BACKUP_BUCKET=""
DB_PASSWORD=""
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_DATABASE="gencc_sub"
DB_USERNAME="root"
BACKUP_RETENTION="30"
SKIP_VERIFY=false

parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --bucket)
                BACKUP_BUCKET="$2"
                shift 2
                ;;
            --db-password)
                DB_PASSWORD="$2"
                shift 2
                ;;
            --db-host)
                DB_HOST="$2"
                shift 2
                ;;
            --db-port)
                DB_PORT="$2"
                shift 2
                ;;
            --db-name)
                DB_DATABASE="$2"
                shift 2
                ;;
            --db-user)
                DB_USERNAME="$2"
                shift 2
                ;;
            --retention)
                BACKUP_RETENTION="$2"
                shift 2
                ;;
            --schedule)
                CRON_SCHEDULE="$2"
                shift 2
                ;;
            --skip-verify)
                SKIP_VERIFY=true
                shift
                ;;
            --help|-h)
                show_help
                exit 0
                ;;
            *)
                log_error "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
}

show_help() {
    cat << EOF
GenCC Backup Cron Setup Script v${SCRIPT_VERSION}

Usage: sudo $(basename "$0") --bucket <bucket-name> --db-password <password> [OPTIONS]

Required:
    --bucket NAME         GCS bucket name for backups
    --db-password PASS    MySQL database password

Optional:
    --db-host HOST        Database host (default: 127.0.0.1)
    --db-port PORT        Database port (default: 3306)
    --db-name NAME        Database name (default: gencc_sub)
    --db-user USER        Database user (default: root)
    --retention DAYS      Days to keep local backups (default: 30)
    --schedule CRON       Cron schedule (default: "0 2 * * *" = 2 AM daily)
    --skip-verify         Skip verification tests
    -h, --help            Show this help message

Example:
    sudo ./setup-backup-cron.sh \\
        --bucket gencc-backups \\
        --db-password 'your-secure-password'
EOF
}

validate_args() {
    local errors=0

    if [[ -z "$BACKUP_BUCKET" ]]; then
        log_error "--bucket is required"
        errors=$((errors + 1))
    fi

    if [[ -z "$DB_PASSWORD" ]]; then
        log_error "--db-password is required"
        errors=$((errors + 1))
    fi

    if [[ $errors -gt 0 ]]; then
        echo ""
        show_help
        exit 1
    fi
}

#------------------------------------------------------------------------------
# Verification functions
#------------------------------------------------------------------------------
verify_prerequisites() {
    log_info "Checking prerequisites..."
    local errors=0

    # Check for required tools
    for cmd in mysqldump gzip gsutil; do
        if ! command -v $cmd &> /dev/null; then
            log_error "$cmd is not installed"
            errors=$((errors + 1))
        else
            log_info "  ✓ $cmd found"
        fi
    done

    if [[ $errors -gt 0 ]]; then
        exit 1
    fi
}

verify_gcs_access() {
    if [[ "$SKIP_VERIFY" == "true" ]]; then
        log_warn "Skipping GCS verification (--skip-verify)"
        return 0
    fi

    log_info "Verifying GCS bucket access..."

    # Check if bucket exists and is accessible
    if gsutil ls "gs://${BACKUP_BUCKET}/" &> /dev/null; then
        log_info "  ✓ Bucket accessible: gs://${BACKUP_BUCKET}/"
    else
        log_error "Cannot access bucket: gs://${BACKUP_BUCKET}/"
        log_error "Ensure the service account has Storage Object Creator role"
        exit 1
    fi

    # Try to write a test file
    local test_file="/tmp/gencc-backup-test-$$"
    echo "backup-test-$(date +%s)" > "$test_file"

    if gsutil cp "$test_file" "gs://${BACKUP_BUCKET}/backup-test-file" &> /dev/null; then
        log_info "  ✓ Write access confirmed"
        gsutil rm "gs://${BACKUP_BUCKET}/backup-test-file" &> /dev/null || true
    else
        log_error "Cannot write to bucket: gs://${BACKUP_BUCKET}/"
        log_error "Ensure the service account has Storage Object Creator role"
        rm -f "$test_file"
        exit 1
    fi

    rm -f "$test_file"
}

verify_database_access() {
    if [[ "$SKIP_VERIFY" == "true" ]]; then
        log_warn "Skipping database verification (--skip-verify)"
        return 0
    fi

    log_info "Verifying database access..."

    if mysqldump \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --password="$DB_PASSWORD" \
        --no-data \
        "$DB_DATABASE" &> /dev/null; then
        log_info "  ✓ Database accessible: ${DB_DATABASE}"
    else
        log_error "Cannot access database: ${DB_DATABASE}"
        log_error "Check database credentials and connectivity"
        exit 1
    fi
}

#------------------------------------------------------------------------------
# Installation functions
#------------------------------------------------------------------------------
create_directories() {
    log_info "Creating directories..."

    for dir in "$INSTALL_DIR" "$CONFIG_DIR" "$LOG_DIR" "$BACKUP_DIR"; do
        if [[ ! -d "$dir" ]]; then
            mkdir -p "$dir"
            log_info "  Created: $dir"
        else
            log_info "  Exists: $dir"
        fi
    done

    # Set permissions
    chmod 700 "$CONFIG_DIR"
    chmod 755 "$LOG_DIR"
    chmod 700 "$BACKUP_DIR"
}

create_config_file() {
    log_info "Creating configuration file..."

    local config_file="${CONFIG_DIR}/backup.env"

    cat > "$config_file" << EOF
# GenCC Backup Configuration
# Generated by setup-backup-cron.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")

# GCS Configuration
BACKUP_BUCKET="${BACKUP_BUCKET}"
BACKUP_PREFIX="database-backups"
BACKUP_RETENTION="${BACKUP_RETENTION}"

# Database Configuration
DB_HOST="${DB_HOST}"
DB_PORT="${DB_PORT}"
DB_DATABASE="${DB_DATABASE}"
DB_USERNAME="${DB_USERNAME}"
DB_PASSWORD="${DB_PASSWORD}"
EOF

    chmod 600 "$config_file"
    log_info "  Created: $config_file"
}

install_backup_script() {
    log_info "Installing backup script..."

    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local source_script="${script_dir}/backup-db.sh"
    local target_script="${INSTALL_DIR}/backup-db.sh"

    if [[ -f "$source_script" ]]; then
        cp "$source_script" "$target_script"
        chmod 755 "$target_script"
        log_info "  Installed: $target_script"
    else
        log_error "Source script not found: $source_script"
        exit 1
    fi

    # Also install restore script
    local restore_source="${script_dir}/restore-db-from-gcs.sh"
    local restore_target="${INSTALL_DIR}/restore-db-from-gcs.sh"

    if [[ -f "$restore_source" ]]; then
        cp "$restore_source" "$restore_target"
        chmod 755 "$restore_target"
        log_info "  Installed: $restore_target"
    fi
}

setup_cron_job() {
    log_info "Setting up cron job..."

    local cron_file="/etc/cron.d/gencc-backup"
    local log_file="${LOG_DIR}/backup.log"

    cat > "$cron_file" << EOF
# GenCC Database Backup
# Runs nightly at 2:00 AM UTC
# Generated by setup-backup-cron.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")

SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin

${CRON_SCHEDULE} root ${INSTALL_DIR}/backup-db.sh >> ${log_file} 2>&1
EOF

    chmod 644 "$cron_file"
    log_info "  Created: $cron_file"
    log_info "  Schedule: ${CRON_SCHEDULE} (2:00 AM UTC daily)"
}

setup_log_rotation() {
    log_info "Setting up log rotation..."

    local logrotate_file="/etc/logrotate.d/gencc-backup"

    cat > "$logrotate_file" << EOF
${LOG_DIR}/backup.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 644 root root
}
EOF

    chmod 644 "$logrotate_file"
    log_info "  Created: $logrotate_file"
}

#------------------------------------------------------------------------------
# Test backup
#------------------------------------------------------------------------------
run_test_backup() {
    if [[ "$SKIP_VERIFY" == "true" ]]; then
        log_warn "Skipping test backup (--skip-verify)"
        return 0
    fi

    log_info "Running test backup..."

    if "${INSTALL_DIR}/backup-db.sh"; then
        log_info "  ✓ Test backup completed successfully"
    else
        log_error "Test backup failed"
        log_error "Check ${LOG_DIR}/backup.log for details"
        exit 1
    fi
}

#------------------------------------------------------------------------------
# Summary
#------------------------------------------------------------------------------
print_summary() {
    echo ""
    echo "============================================"
    echo "  GenCC Backup Setup Complete"
    echo "============================================"
    echo ""
    echo "Configuration:"
    echo "  Bucket:     gs://${BACKUP_BUCKET}/"
    echo "  Database:   ${DB_DATABASE}@${DB_HOST}:${DB_PORT}"
    echo "  Schedule:   ${CRON_SCHEDULE} (2:00 AM UTC)"
    echo "  Retention:  ${BACKUP_RETENTION} days (local)"
    echo ""
    echo "Files created:"
    echo "  ${CONFIG_DIR}/backup.env"
    echo "  ${INSTALL_DIR}/backup-db.sh"
    echo "  ${INSTALL_DIR}/restore-db-from-gcs.sh"
    echo "  /etc/cron.d/gencc-backup"
    echo "  /etc/logrotate.d/gencc-backup"
    echo ""
    echo "Useful commands:"
    echo "  # Run backup manually"
    echo "  ${INSTALL_DIR}/backup-db.sh"
    echo ""
    echo "  # View backup log"
    echo "  tail -f ${LOG_DIR}/backup.log"
    echo ""
    echo "  # List available backups"
    echo "  ${INSTALL_DIR}/restore-db-from-gcs.sh --list"
    echo ""
    echo "  # Restore from latest backup"
    echo "  ${INSTALL_DIR}/restore-db-from-gcs.sh --latest"
    echo ""
}

#------------------------------------------------------------------------------
# Main
#------------------------------------------------------------------------------
main() {
    echo ""
    echo "GenCC Backup Cron Setup v${SCRIPT_VERSION}"
    echo ""

    check_root
    parse_args "$@"
    validate_args

    verify_prerequisites
    verify_gcs_access
    verify_database_access

    create_directories
    create_config_file
    install_backup_script
    setup_cron_job
    setup_log_rotation

    run_test_backup

    print_summary
}

main "$@"
