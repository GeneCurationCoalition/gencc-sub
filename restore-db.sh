#!/bin/bash

# GenCC-Sub Database Restore Script
# Restores the database from the baseline backup for development

BACKUP_FILE="data/backups/gencc_sub_baseline_20260103.sql.gz"

echo "==========================================="
echo "GenCC-Sub Database Restore Script"
echo "==========================================="
echo ""

# Check if backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: Backup file not found: $BACKUP_FILE"
    exit 1
fi

echo "This will restore the gencc_sub database from:"
echo "  $BACKUP_FILE"
echo ""
echo "WARNING: This will overwrite all existing data in gencc_sub!"
echo ""
read -r -p "Press Enter to continue, or Ctrl+C to abort..."
echo ""

echo "Restoring database..."
gunzip -c "$BACKUP_FILE" | mysql -u root -ppassword gencc_sub

if [ $? -eq 0 ]; then
    echo ""
    echo "Database restored successfully!"
else
    echo ""
    echo "Error restoring database"
    exit 1
fi
