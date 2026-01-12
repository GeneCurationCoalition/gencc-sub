#!/bin/bash
set -e

# .env file should be mounted from host at /var/www/html/.env
if [ ! -f /var/www/html/.env ]; then
  echo "ERROR: .env file not found at /var/www/html/.env"
  echo "Please mount the .env file from the host using: -v /var/www/gencc-sub/.env:/var/www/html/.env"
  exit 1
fi

# Execute the main command (pm2-runtime)
exec "$@"
