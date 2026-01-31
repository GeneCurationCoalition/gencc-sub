#!/bin/bash

# GenCC-Sub Database Restore Script
# Restores the database from the baseline backup for development
#
# Usage:
#   ./restore-db.sh               # Interactive mode (prompts for confirmation)
#   ./restore-db.sh --no-confirm  # Non-interactive mode (skips confirmation)

# Get the directory of this script (so it works when called from other scripts)
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
BACKUP_FILE="$SCRIPT_DIR/data/backups/gencc_sub_baseline_20260103.sql.gz"

# Parse arguments
NO_CONFIRM=false
for arg in "$@"; do
    case "$arg" in
        --no-confirm) NO_CONFIRM=true ;;
    esac
done

echo "==========================================="
echo "GenCC-Sub Database Restore Script"
echo "==========================================="
echo ""

# Check if backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: Backup file not found: $BACKUP_FILE"
    exit 1
fi

# Get database configuration from .env
ENV_FILE="$SCRIPT_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
    echo "Error: .env file not found: $ENV_FILE"
    exit 1
fi

# Use environment variables if set, otherwise read from .env
DB_HOST="${DB_HOST:-$(grep "^DB_HOST=" "$ENV_FILE" | cut -d '=' -f2)}"
DB_PORT="${DB_PORT:-$(grep "^DB_PORT=" "$ENV_FILE" | cut -d '=' -f2)}"
DB_DATABASE="${DB_DATABASE:-$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d '=' -f2)}"
DB_USERNAME="${DB_USERNAME:-$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d '=' -f2)}"
DB_PASSWORD="${DB_PASSWORD:-$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d '=' -f2)}"

echo "This will restore the $DB_DATABASE database from:"
echo "  $BACKUP_FILE"
echo ""
echo "WARNING: This will overwrite all existing data in $DB_DATABASE!"
echo ""
if [ "$NO_CONFIRM" = false ]; then
    read -r -p "Press Enter to continue, or Ctrl+C to abort..."
    echo ""
fi

echo "Restoring database..."
MYSQL_ERROR=$(gunzip -c "$BACKUP_FILE" | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" 2>&1)

if [ $? -eq 0 ]; then
    echo ""
    echo "Database restored successfully!"
else
    echo ""
    echo "Error restoring database"
    echo "$MYSQL_ERROR"
    exit 1
fi

echo ""
echo "Running migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo ""
    echo "Migrations completed successfully!"
else
    echo ""
    echo "Error running migrations"
    exit 1
fi

echo ""
echo "Importing submitter logos..."
php artisan import:submitter-logos

if [ $? -eq 0 ]; then
    echo ""
    echo "Logos imported successfully!"
else
    echo ""
    echo "Warning: Some logos may have failed to import"
fi

echo ""
echo "==========================================="
echo "Database restore complete!"
echo "==========================================="
