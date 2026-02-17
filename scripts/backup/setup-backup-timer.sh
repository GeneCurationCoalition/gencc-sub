#!/bin/bash
#
# GenCC-Sub Backup Systemd Timer Setup Script
#
# Sets up the nightly database backup systemd timer on the production VM.
# This script should be run once during initial server setup.
#
# Usage:
#   sudo ./scripts/setup-backup-timer.sh --bucket <bucket-name>
#
# What this script does:
#   1. Creates /etc/gencc/backup.env with configuration
#   2. Installs backup script to /opt/gencc/bin/
#   3. Sets up systemd service and timer for nightly backups at 2:00 AM UTC
#   4. Creates log rotation configuration
#   5. Verifies gcloud storage is configured correctly
#
# Prerequisites:
#   - Root or sudo access
#   - gcloud CLI installed and configured
#   - MySQL client installed
#   - Service account with Storage Object Creator role

set -euo pipefail

# Script metadata
SCRIPT_NAME="gencc-setup-backup"
SCRIPT_VERSION="2.0.0"

# Configuration
INSTALL_DIR="/opt/gencc/bin"
CONFIG_DIR="/etc/gencc"
LOG_DIR="/var/log/gencc"
BACKUP_DIR="/var/backups/gencc"
SYSTEMD_DIR="/etc/systemd/system"
TIMER_SCHEDULE="*-*-* 02:00:00"  # 2:00 AM UTC daily (OnCalendar syntax)

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
                TIMER_SCHEDULE="$2"
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
GenCC Backup Systemd Timer Setup Script v${SCRIPT_VERSION}

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
    --schedule ONCAL      Systemd OnCalendar schedule (default: "*-*-* 02:00:00" = 2 AM daily)
                          Examples: "daily", "weekly", "Mon 03:00", "*-*-* 06:00:00"
    --skip-verify         Skip verification tests
    -h, --help            Show this help message

Example:
    sudo ./setup-backup-timer.sh \\
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
    for cmd in mysqldump gzip gcloud systemctl; do
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
    if gcloud storage ls "gs://${BACKUP_BUCKET}/" &> /dev/null; then
        log_info "  ✓ Bucket accessible: gs://${BACKUP_BUCKET}/"
    else
        log_error "Cannot access bucket: gs://${BACKUP_BUCKET}/"
        log_error "Ensure the service account has Storage Object Creator role"
        exit 1
    fi

    # Try to write a test file
    local test_file="/tmp/gencc-backup-test-$$"
    echo "backup-test-$(date +%s)" > "$test_file"

    if gcloud storage cp "$test_file" "gs://${BACKUP_BUCKET}/backup-test-file" &> /dev/null; then
        log_info "  ✓ Write access confirmed"
        gcloud storage rm "gs://${BACKUP_BUCKET}/backup-test-file" &> /dev/null || true
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
# Generated by setup-backup-timer.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")

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

setup_systemd_timer() {
    log_info "Setting up systemd service and timer..."

    local service_file="${SYSTEMD_DIR}/gencc-backup-db.service"
    local timer_file="${SYSTEMD_DIR}/gencc-backup-db.timer"

    # Create the service unit
    cat > "$service_file" << EOF
# GenCC Database Backup Service
# Generated by setup-backup-timer.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")

[Unit]
Description=Backup GenCC MySQL database to GCS
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${INSTALL_DIR}/backup-db.sh
StandardOutput=append:${LOG_DIR}/backup.log
StandardError=append:${LOG_DIR}/backup.log
EOF

    chmod 644 "$service_file"
    log_info "  Created: $service_file"

    # Create the timer unit
    cat > "$timer_file" << EOF
# GenCC Database Backup Timer
# Generated by setup-backup-timer.sh on $(date -u +"%Y-%m-%d %H:%M:%S UTC")

[Unit]
Description=Schedule GenCC MySQL backup

[Timer]
OnCalendar=${TIMER_SCHEDULE}
Persistent=true

[Install]
WantedBy=timers.target
EOF

    chmod 644 "$timer_file"
    log_info "  Created: $timer_file"
    log_info "  Schedule: ${TIMER_SCHEDULE}"

    # Reload systemd and enable the timer
    log_info "Enabling and starting timer..."
    systemctl daemon-reload
    systemctl enable gencc-backup-db.timer
    systemctl start gencc-backup-db.timer
    log_info "  ✓ Timer enabled and started"
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
    echo "  Schedule:   ${TIMER_SCHEDULE}"
    echo "  Retention:  ${BACKUP_RETENTION} days (local)"
    echo ""
    echo "Files created:"
    echo "  ${CONFIG_DIR}/backup.env"
    echo "  ${INSTALL_DIR}/backup-db.sh"
    echo "  ${INSTALL_DIR}/restore-db-from-gcs.sh"
    echo "  ${SYSTEMD_DIR}/gencc-backup-db.service"
    echo "  ${SYSTEMD_DIR}/gencc-backup-db.timer"
    echo "  /etc/logrotate.d/gencc-backup"
    echo ""
    echo "Useful commands:"
    echo "  # Run backup manually"
    echo "  systemctl start gencc-backup-db.service"
    echo ""
    echo "  # Check timer status"
    echo "  systemctl status gencc-backup-db.timer"
    echo "  systemctl list-timers gencc-backup-db.timer"
    echo ""
    echo "  # View backup log"
    echo "  tail -f ${LOG_DIR}/backup.log"
    echo "  journalctl -u gencc-backup-db.service"
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
    echo "GenCC Backup Timer Setup v${SCRIPT_VERSION}"
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
    setup_systemd_timer
    setup_log_rotation

    run_test_backup

    print_summary
}

main "$@"
