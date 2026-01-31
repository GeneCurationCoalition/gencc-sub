#!/bin/bash
#
# GenCC-Sub Production Database Backup Script
#
# Backs up the MySQL database and uploads to Google Cloud Storage.
# Designed to run via cron on the production GCP VM.
#
# Usage:
#   ./scripts/backup-db.sh                    # Uses defaults from environment
#   ./scripts/backup-db.sh --bucket my-bucket # Override bucket name
#   ./scripts/backup-db.sh --dry-run          # Test without uploading
#
# Environment variables (can be set in /etc/gencc/backup.env):
#   BACKUP_BUCKET     - GCS bucket name (required)
#   BACKUP_PREFIX     - GCS path prefix (default: database-backups)
#   BACKUP_RETENTION  - Days to keep local backups (default: 7)
#   DB_HOST           - Database host (default: 127.0.0.1)
#   DB_PORT           - Database port (default: 3306)
#   DB_DATABASE       - Database name (default: gencc_sub)
#   DB_USERNAME       - Database user (default: root)
#   DB_PASSWORD       - Database password (required)
#
# Exit codes:
#   0 - Success
#   1 - Configuration error
#   2 - Database dump failed
#   3 - Upload to GCS failed
#   4 - Cleanup failed

set -euo pipefail

# Script metadata
SCRIPT_NAME="gencc-backup"
SCRIPT_VERSION="1.0.0"
LOG_TAG="[${SCRIPT_NAME}]"

# Default configuration
LOCAL_BACKUP_DIR="/var/backups/gencc"
BACKUP_PREFIX="${BACKUP_PREFIX:-database-backups}"
BACKUP_RETENTION="${BACKUP_RETENTION:-7}"
DRY_RUN=false

# Database defaults
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-gencc_sub}"
DB_USERNAME="${DB_USERNAME:-root}"

# Timestamp for this backup
TIMESTAMP=$(date -u +"%Y%m%d-%H%M%S")
DATE_STAMP=$(date -u +"%Y-%m-%d")

#------------------------------------------------------------------------------
# Logging functions
#------------------------------------------------------------------------------
log_info() {
    echo "$(date -u +"%Y-%m-%dT%H:%M:%SZ") ${LOG_TAG} INFO: $*"
}

log_error() {
    echo "$(date -u +"%Y-%m-%dT%H:%M:%SZ") ${LOG_TAG} ERROR: $*" >&2
}

log_warn() {
    echo "$(date -u +"%Y-%m-%dT%H:%M:%SZ") ${LOG_TAG} WARN: $*"
}

#------------------------------------------------------------------------------
# Parse command line arguments
#------------------------------------------------------------------------------
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --bucket)
                BACKUP_BUCKET="$2"
                shift 2
                ;;
            --prefix)
                BACKUP_PREFIX="$2"
                shift 2
                ;;
            --dry-run)
                DRY_RUN=true
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
GenCC Database Backup Script v${SCRIPT_VERSION}

Usage: $(basename "$0") [OPTIONS]

Options:
    --bucket NAME     GCS bucket name (overrides BACKUP_BUCKET env var)
    --prefix PATH     GCS path prefix (default: database-backups)
    --dry-run         Create backup but don't upload to GCS
    -h, --help        Show this help message

Environment variables:
    BACKUP_BUCKET     GCS bucket name (required unless --bucket specified)
    BACKUP_PREFIX     GCS path prefix (default: database-backups)
    BACKUP_RETENTION  Days to keep local backups (default: 7)
    DB_HOST           Database host (default: 127.0.0.1)
    DB_PORT           Database port (default: 3306)
    DB_DATABASE       Database name (default: gencc_sub)
    DB_USERNAME       Database user (default: root)
    DB_PASSWORD       Database password (required)

Example:
    ./backup-db.sh --bucket gencc-backups
EOF
}

#------------------------------------------------------------------------------
# Validation
#------------------------------------------------------------------------------
validate_config() {
    local errors=0

    # Load environment file if it exists
    if [[ -f /etc/gencc/backup.env ]]; then
        log_info "Loading configuration from /etc/gencc/backup.env"
        # shellcheck source=/dev/null
        source /etc/gencc/backup.env
    fi

    # Check required variables
    if [[ -z "${BACKUP_BUCKET:-}" ]] && [[ "$DRY_RUN" == "false" ]]; then
        log_error "BACKUP_BUCKET is required (set via environment or --bucket)"
        errors=$((errors + 1))
    fi

    if [[ -z "${DB_PASSWORD:-}" ]]; then
        log_error "DB_PASSWORD is required"
        errors=$((errors + 1))
    fi

    # Check required tools
    if ! command -v mysqldump &> /dev/null; then
        log_error "mysqldump is not installed"
        errors=$((errors + 1))
    fi

    if ! command -v gzip &> /dev/null; then
        log_error "gzip is not installed"
        errors=$((errors + 1))
    fi

    if [[ "$DRY_RUN" == "false" ]] && ! command -v gsutil &> /dev/null; then
        log_error "gsutil is not installed (required for GCS upload)"
        errors=$((errors + 1))
    fi

    if [[ $errors -gt 0 ]]; then
        exit 1
    fi
}

#------------------------------------------------------------------------------
# Backup functions
#------------------------------------------------------------------------------
create_backup_dir() {
    if [[ ! -d "$LOCAL_BACKUP_DIR" ]]; then
        log_info "Creating backup directory: $LOCAL_BACKUP_DIR"
        mkdir -p "$LOCAL_BACKUP_DIR"
        chmod 700 "$LOCAL_BACKUP_DIR"
    fi
}

create_database_dump() {
    local backup_file="${LOCAL_BACKUP_DIR}/${DB_DATABASE}_${TIMESTAMP}.sql.gz"

    log_info "Starting database dump: ${DB_DATABASE}"
    log_info "  Host: ${DB_HOST}:${DB_PORT}"
    log_info "  Output: ${backup_file}"

    # mysqldump options:
    #   --single-transaction: Consistent snapshot for InnoDB without locking
    #   --routines: Include stored procedures and functions
    #   --triggers: Include triggers (default, but explicit)
    #   --quick: Don't buffer entire tables in memory
    #   --lock-tables=false: Don't lock tables (use with --single-transaction)
    mysqldump \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --password="$DB_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        --quick \
        --lock-tables=false \
        "$DB_DATABASE" 2>/dev/null | gzip -9 > "$backup_file"

    if [[ ! -f "$backup_file" ]] || [[ ! -s "$backup_file" ]]; then
        log_error "Database dump failed or produced empty file"
        exit 2
    fi

    local size
    size=$(du -h "$backup_file" | cut -f1)
    log_info "Database dump complete: ${size}"

    echo "$backup_file"
}

upload_to_gcs() {
    local backup_file="$1"
    local filename
    filename=$(basename "$backup_file")

    # Organize by date: gs://bucket/prefix/YYYY/MM/filename
    local year month gcs_path
    year=$(date -u +"%Y")
    month=$(date -u +"%m")
    gcs_path="gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/${year}/${month}/${filename}"

    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "[DRY RUN] Would upload to: ${gcs_path}"
        return 0
    fi

    log_info "Uploading to GCS: ${gcs_path}"

    if gsutil cp "$backup_file" "$gcs_path"; then
        log_info "Upload complete"

        # Verify upload
        if gsutil stat "$gcs_path" &> /dev/null; then
            log_info "Upload verified successfully"
        else
            log_error "Upload verification failed"
            exit 3
        fi
    else
        log_error "Upload to GCS failed"
        exit 3
    fi
}

cleanup_old_backups() {
    log_info "Cleaning up local backups older than ${BACKUP_RETENTION} days"

    local count
    count=$(find "$LOCAL_BACKUP_DIR" -name "*.sql.gz" -type f -mtime +"$BACKUP_RETENTION" 2>/dev/null | wc -l)

    if [[ "$count" -gt 0 ]]; then
        find "$LOCAL_BACKUP_DIR" -name "*.sql.gz" -type f -mtime +"$BACKUP_RETENTION" -delete
        log_info "Removed ${count} old backup(s)"
    else
        log_info "No old backups to remove"
    fi
}

#------------------------------------------------------------------------------
# Main
#------------------------------------------------------------------------------
main() {
    log_info "GenCC Database Backup v${SCRIPT_VERSION}"
    log_info "Timestamp: ${TIMESTAMP}"

    parse_args "$@"
    validate_config
    create_backup_dir

    # Create the backup
    local backup_file
    backup_file=$(create_database_dump)

    # Upload to GCS
    upload_to_gcs "$backup_file"

    # Cleanup old local backups
    cleanup_old_backups

    log_info "Backup completed successfully"
    log_info "  Local: ${backup_file}"
    if [[ "$DRY_RUN" == "false" ]]; then
        log_info "  GCS: gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/..."
    fi
}

main "$@"
