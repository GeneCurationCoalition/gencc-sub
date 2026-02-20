#!/bin/bash
#
# GenCC-Sub Production Database Restore Script
#
# Restores the MySQL database from a backup in Google Cloud Storage.
# Use this for disaster recovery or setting up new environments.
#
# Usage:
#   ./scripts/restore-db-from-gcs.sh --list                    # List available backups
#   ./scripts/restore-db-from-gcs.sh --latest                  # Restore most recent backup
#   ./scripts/restore-db-from-gcs.sh --file <backup_name>      # Restore specific backup
#   ./scripts/restore-db-from-gcs.sh --local <path>            # Restore from local file
#
# Environment variables (can be set in /etc/gencc/backup.env):
#   BACKUP_BUCKET     - GCS bucket name (required)
#   BACKUP_PREFIX     - GCS path prefix (default: database-backups)
#   DB_HOST           - Database host (default: 127.0.0.1)
#   DB_PORT           - Database port (default: 3306)
#   DB_DATABASE       - Database name (default: gencc_sub)
#   DB_USERNAME       - Database user (default: root)
#   DB_PASSWORD       - Database password (required)
#
# Exit codes:
#   0 - Success
#   1 - Configuration/argument error
#   2 - Backup not found
#   3 - Download failed
#   4 - Restore failed
#   5 - User aborted

set -euo pipefail

# Script metadata
SCRIPT_NAME="gencc-restore"
SCRIPT_VERSION="1.0.0"
LOG_TAG="[${SCRIPT_NAME}]"

# Default configuration
LOCAL_RESTORE_DIR="/var/backups/gencc/restore"
BACKUP_PREFIX="${BACKUP_PREFIX:-database-backups}"

# Database defaults
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-gencc_sub}"
DB_USERNAME="${DB_USERNAME:-root}"

# Operation mode
MODE=""
TARGET_FILE=""
FORCE=false
CREATE_DB=false

#------------------------------------------------------------------------------
# Logging functions
#------------------------------------------------------------------------------
log_info() {
    echo "$(date -u +"%Y-%m-%dT%H:%M:%SZ") ${LOG_TAG} INFO: $*" >&2
}

log_error() {
    echo "$(date -u +"%Y-%m-%dT%H:%M:%SZ") ${LOG_TAG} ERROR: $*" >&2
}

log_warn() {
    echo "$(date -u +"%Y-%m-%dT%H:%M:%SZ") ${LOG_TAG} WARN: $*" >&2
}

#------------------------------------------------------------------------------
# Parse command line arguments
#------------------------------------------------------------------------------
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --list|-l)
                MODE="list"
                shift
                ;;
            --latest)
                MODE="latest"
                shift
                ;;
            --file|-f)
                MODE="file"
                TARGET_FILE="$2"
                shift 2
                ;;
            --local)
                MODE="local"
                TARGET_FILE="$2"
                shift 2
                ;;
            --bucket)
                BACKUP_BUCKET="$2"
                shift 2
                ;;
            --database)
                DB_DATABASE="$2"
                shift 2
                ;;
            --create-db)
                CREATE_DB=true
                shift
                ;;
            --force|-y)
                FORCE=true
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

    if [[ -z "$MODE" ]]; then
        log_error "No operation specified. Use --list, --latest, --file, or --local"
        show_help
        exit 1
    fi
}

show_help() {
    cat << EOF
GenCC Database Restore Script v${SCRIPT_VERSION}

Usage: $(basename "$0") [OPTIONS] OPERATION

Operations:
    --list, -l            List available backups in GCS
    --latest              Restore the most recent backup
    --file, -f NAME       Restore a specific backup file
    --local PATH          Restore from a local file

Options:
    --bucket NAME         GCS bucket name (overrides BACKUP_BUCKET env var)
    --database NAME       Target database name (default: gencc_sub)
    --create-db           Create the database if it doesn't exist
    --force, -y           Skip confirmation prompt
    -h, --help            Show this help message

Environment variables:
    BACKUP_BUCKET         GCS bucket name (required for GCS operations)
    BACKUP_PREFIX         GCS path prefix (default: database-backups)
    DB_HOST               Database host (default: 127.0.0.1)
    DB_PORT               Database port (default: 3306)
    DB_DATABASE           Database name (default: gencc_sub)
    DB_USERNAME           Database user (default: root)
    DB_PASSWORD           Database password (required)

Examples:
    # List available backups
    ./restore-db-from-gcs.sh --list

    # Restore the latest backup
    ./restore-db-from-gcs.sh --latest

    # Restore a specific backup
    ./restore-db-from-gcs.sh --file gencc_sub_20260115-020000.sql.gz

    # Restore from local file
    ./restore-db-from-gcs.sh --local /path/to/backup.sql.gz

    # Restore to a different database (for testing)
    ./restore-db-from-gcs.sh --latest --database gencc_sub_test --create-db
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

    # Check required variables for GCS operations
    if [[ "$MODE" != "local" ]] && [[ -z "${BACKUP_BUCKET:-}" ]]; then
        log_error "BACKUP_BUCKET is required for GCS operations"
        errors=$((errors + 1))
    fi

    if [[ "$MODE" != "list" ]] && [[ -z "${DB_PASSWORD:-}" ]]; then
        log_error "DB_PASSWORD is required"
        errors=$((errors + 1))
    fi

    # Check required tools
    if [[ "$MODE" != "list" ]]; then
        if ! command -v mysql &> /dev/null; then
            log_error "mysql client is not installed"
            errors=$((errors + 1))
        fi

        if ! command -v gunzip &> /dev/null; then
            log_error "gunzip is not installed"
            errors=$((errors + 1))
        fi
    fi

    if [[ "$MODE" != "local" ]] && ! command -v gcloud &> /dev/null; then
        log_error "gcloud CLI is not installed (required for GCS operations)"
        errors=$((errors + 1))
    fi

    if [[ $errors -gt 0 ]]; then
        exit 1
    fi
}

#------------------------------------------------------------------------------
# GCS functions
#------------------------------------------------------------------------------
list_backups() {
    log_info "Listing backups in gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/"
    echo ""
    echo "Available backups (newest first):"
    echo "=================================="

    gcloud storage ls -l --recursive "gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/" 2>/dev/null | \
        grep '\.sql\.gz$' | \
        sort -k2 -r | \
        head -20 | \
        while read -r size date time path; do
            local filename
            filename=$(basename "$path")
            printf "  %-40s  %s  %s\n" "$filename" "$date" "$size"
        done

    echo ""
    echo "Use --file <filename> to restore a specific backup"
}

find_latest_backup() {
    log_info "Finding latest backup..."

    local latest
    latest=$(gcloud storage ls --recursive "gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/" 2>/dev/null | \
        grep '\.sql\.gz$' | \
        sort -r | \
        head -1)

    if [[ -z "$latest" ]]; then
        log_error "No backups found in gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/"
        exit 2
    fi

    echo "$latest"
}

find_backup_by_name() {
    local name="$1"
    log_info "Searching for backup: ${name}"

    local found
    found=$(gcloud storage ls --recursive "gs://${BACKUP_BUCKET}/${BACKUP_PREFIX}/" 2>/dev/null | \
        grep -F "${name}" | head -1)

    if [[ -z "$found" ]]; then
        log_error "Backup not found: ${name}"
        exit 2
    fi

    echo "$found"
}

download_backup() {
    local gcs_path="$1"
    local filename
    filename=$(basename "$gcs_path")
    local local_path="${LOCAL_RESTORE_DIR}/${filename}"

    # Create restore directory
    mkdir -p "$LOCAL_RESTORE_DIR"

    log_info "Downloading: ${gcs_path}"
    log_info "  To: ${local_path}"

    if gcloud storage cp "$gcs_path" "$local_path"; then
        log_info "Download complete"
        echo "$local_path"
    else
        log_error "Download failed"
        exit 3
    fi
}

#------------------------------------------------------------------------------
# Restore functions
#------------------------------------------------------------------------------
confirm_restore() {
    local backup_source="$1"

    if [[ "$FORCE" == "true" ]]; then
        return 0
    fi

    echo ""
    echo "============================================"
    echo "         DATABASE RESTORE WARNING"
    echo "============================================"
    echo ""
    echo "This will OVERWRITE the database: ${DB_DATABASE}"
    echo ""
    echo "  Source: ${backup_source}"
    echo "  Target: ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
    echo ""
    echo "ALL EXISTING DATA WILL BE LOST!"
    echo ""
    read -r -p "Type 'RESTORE' to confirm: " confirmation

    if [[ "$confirmation" != "RESTORE" ]]; then
        log_info "Restore cancelled by user"
        exit 5
    fi
}

create_database_if_needed() {
    if [[ "$CREATE_DB" != "true" ]]; then
        return 0
    fi

    log_info "Creating database if it doesn't exist: ${DB_DATABASE}"

    mysql \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --password="$DB_PASSWORD" \
        -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

    log_info "Database ready"
}

restore_database() {
    local backup_file="$1"

    log_info "Starting database restore"
    log_info "  Source: ${backup_file}"
    log_info "  Target: ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"

    create_database_if_needed

    # Decompress and restore
    if gunzip -c "$backup_file" | mysql \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --password="$DB_PASSWORD" \
        "$DB_DATABASE" 2>/dev/null; then
        log_info "Database restore completed successfully"
    else
        log_error "Database restore failed"
        exit 4
    fi
}

cleanup_download() {
    local file="$1"

    # Only cleanup if it's in our restore directory
    if [[ "$file" == "${LOCAL_RESTORE_DIR}/"* ]]; then
        log_info "Cleaning up downloaded file"
        rm -f "$file"
    fi
}

#------------------------------------------------------------------------------
# Main
#------------------------------------------------------------------------------
main() {
    log_info "GenCC Database Restore v${SCRIPT_VERSION}"

    parse_args "$@"
    validate_config

    case "$MODE" in
        list)
            list_backups
            ;;
        latest)
            local gcs_path
            gcs_path=$(find_latest_backup)
            log_info "Latest backup: ${gcs_path}"

            local local_file
            local_file=$(download_backup "$gcs_path")

            confirm_restore "$gcs_path"
            restore_database "$local_file"
            cleanup_download "$local_file"
            ;;
        file)
            local gcs_path
            gcs_path=$(find_backup_by_name "$TARGET_FILE")

            local local_file
            local_file=$(download_backup "$gcs_path")

            confirm_restore "$gcs_path"
            restore_database "$local_file"
            cleanup_download "$local_file"
            ;;
        local)
            if [[ ! -f "$TARGET_FILE" ]]; then
                log_error "Local file not found: ${TARGET_FILE}"
                exit 2
            fi

            confirm_restore "$TARGET_FILE"
            restore_database "$TARGET_FILE"
            ;;
    esac

    log_info "Restore operation completed"
}

main "$@"
