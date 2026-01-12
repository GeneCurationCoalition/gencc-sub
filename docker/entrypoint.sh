#!/bin/bash
set -e

# Fetch .env from Google Secret Manager if GCP_SECRET_NAME is set
if [ -n "$GCP_SECRET_NAME" ]; then
  echo "Fetching .env from Google Secret Manager: ${GCP_SECRET_NAME}..."
  gcloud secrets versions access latest --secret="$GCP_SECRET_NAME" > /var/www/html/.env
  chown www-data:www-data /var/www/html/.env
  chmod 600 /var/www/html/.env
  echo "Successfully wrote .env file"
fi

# Execute the main command (pm2-runtime)
exec "$@"
