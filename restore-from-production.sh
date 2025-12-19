#!/bin/bash

# GenCC-Sub Database Restoration Script
# This script restores the gencc-sub database from the latest production data

echo "=========================================="
echo "GenCC-Sub Database Restoration Script"
echo "=========================================="
echo ""

# Step 1: Important notice about gencc-search dependency
echo "⚠️  IMPORTANT NOTICE:"
echo "The gencc-search database must be restored first since this depends on that."
echo "Please ensure gencc-search has been updated before proceeding."
echo ""
read -r -p "Press Enter to continue once gencc-search has been restored, or Ctrl+C to abort..."
echo ""

# Step 2: Run migration commands
echo "Step 2: Running migration commands..."
echo "--------------------------------------"
echo ""

echo "Checking migration status..."
php artisan migrate:status
echo ""

echo "Running migrations..."
php artisan migrate
echo ""

# Step 3: Update proddb command
# Note: Static file headers cache is preserved - the smart caching system will:
# - Detect empty tables and download if needed
# - Check if remote files changed and only download if changed
echo "Step 3: Updating production database..."
echo "--------------------------------------"
echo ""

echo "Running gencc:makeproddb command..."
php artisan gencc:makeproddb
echo ""

# Step 4: Test interaction between sub and search
echo "Step 4: Testing interaction between gencc-sub and gencc-search..."
echo "--------------------------------------------------------------"
echo ""

echo "Testing connection with gencc-search..."
php artisan run:publish test_init
echo ""

echo "=========================================="
echo "Database restoration process completed!"
echo "=========================================="
echo ""
echo "If all steps completed successfully, your gencc-sub database"
echo "has been restored from the latest production data."