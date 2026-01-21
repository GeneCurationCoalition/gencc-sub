#!/bin/bash

# GenCC-Sub Database Restore Script
# Restores the database from the baseline backup for development

# Get the directory of this script
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

BACKUP_FILE="$SCRIPT_DIR/data/backups/gencc_sub_baseline_20260103.sql.gz"

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
read -r -p "Press Enter to continue, or Ctrl+C to abort..."
echo ""

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
