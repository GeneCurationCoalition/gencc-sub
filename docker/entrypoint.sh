#!/bin/bash
set -e

# .env file should be mounted from host at /var/www/html/.env
if [ ! -f /var/www/html/.env ]; then
  echo "ERROR: .env file not found at /var/www/html/.env"
  echo "Please mount the .env file from the host using: -v /var/www/gencc-sub/.env:/var/www/html/.env"
  exit 1
fi

# Create storage symlink if it doesn't exist or is broken
# This is required for serving files from storage/app/public via /storage URL
# Note: We check with -e (target exists) not -L (symlink exists) to handle broken symlinks
# that may have been copied from a host machine with a different path structure
if [ ! -e /var/www/html/public/storage ]; then
  echo "Creating storage symlink..."
  # Remove broken symlink if it exists
  rm -f /var/www/html/public/storage 2>/dev/null || true
  php /var/www/html/artisan storage:link
fi

# Ensure storage directories exist and are writable
mkdir -p /var/www/html/storage/app/public
chown -R www-data:www-data /var/www/html/storage/app/public

# Copy GCS credentials to www-data accessible location if mounted
# The credentials may be mounted from the host with root-only permissions
GCS_SOURCE="/var/www/.config/gcloud/application_default_credentials.json"
GCS_DEST="/var/www/html/storage/.gcloud-credentials.json"
if [ -f "$GCS_SOURCE" ]; then
  echo "Copying GCS credentials for www-data access..."
  cp "$GCS_SOURCE" "$GCS_DEST"
  chown www-data:www-data "$GCS_DEST"
  chmod 600 "$GCS_DEST"
  export GOOGLE_APPLICATION_CREDENTIALS="$GCS_DEST"
fi

# Execute the main command (pm2-runtime)
exec "$@"
