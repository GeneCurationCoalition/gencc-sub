# Deployment Guide

This document provides instructions for deploying the GenCC Submission Portal to production environments.

## Automated GCP VM deployment (Terraform + Ansible + Podman)

This repo includes a **local-only** infrastructure + configuration scaffold matching the deployment plans in `ai/`:
- Terraform: `deployment/terraform/`
- Ansible: `deployment/ansible/`

These directories are safe to commit (no secrets). They target a single GCP VM with:
- External HTTPS Load Balancer (TLS termination + host-based routing)
- Rootless Podman containers for `gencc-sub` and `gencc-search`
- Host MySQL as the shared database

Health check endpoint (used by load balancer checks): `GET /-/healthz` (public, returns `200 ok`).

## Table of Contents

- [Prerequisites](#prerequisites)
- [Environment Configuration](#environment-configuration)
- [External API Keys](#external-api-keys)
- [Initial Setup](#initial-setup)
- [Data Import](#data-import)
- [Production Deployment](#production-deployment)
- [Security Considerations](#security-considerations)

## Prerequisites

- PHP 8.1 or higher
- Composer
- Node.js 22 or higher
- MySQL/MariaDB database
- Web server (Apache/Nginx)
- SSL certificate (for production)

## Environment Configuration

### 1. Create Environment File

Copy the example environment file and configure it for your environment:

```bash
cp .env.example .env
```

### 2. Generate Application Key

```bash
php artisan key:generate
```

### 3. Configure Database

Edit `.env` and set your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gencc_sub
DB_USERNAME=your_username
DB_PASSWORD=your_secure_password
```

### 4. Configure Application URL

Set the application URL to your production domain:

```env
APP_URL=https://submit.thegencc.org
APP_ENV=production
APP_DEBUG=false
```

### 5. Session & CSRF Configuration

The application uses Laravel Sanctum for SPA authentication with cookie-based sessions. Proper configuration is critical for CSRF protection.

| Variable | Purpose |
|----------|---------|
| `APP_URL` | Base URL for the application |
| `SANCTUM_STATEFUL_DOMAINS` | Domains that receive stateful (cookie-based) authentication |
| `SESSION_DOMAIN` | Cookie domain for session cookies |
| `SESSION_SECURE_COOKIE` | Whether cookies require HTTPS |

#### Local Development

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8001

# Multiple domains/ports for local dev flexibility
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8001,127.0.0.1,127.0.0.1:8001

# Empty = use request host (allows both localhost and 127.0.0.1)
SESSION_DOMAIN=

# Not set or false for HTTP
# SESSION_SECURE_COOKIE=false
```

#### Staging

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://gencc-stage-sub.clingen.app

SANCTUM_STATEFUL_DOMAINS=gencc-stage-sub.clingen.app
SESSION_DOMAIN=gencc-stage-sub.clingen.app
SESSION_SECURE_COOKIE=true
```

#### Production (Multiple Domains)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sub.thegencc.org

# Both domains supported
SANCTUM_STATEFUL_DOMAINS=sub.thegencc.org,thegencc.org

# Leading dot allows cookie sharing across subdomains
SESSION_DOMAIN=.thegencc.org
SESSION_SECURE_COOKIE=true
```

#### SESSION_DOMAIN Notes

When supporting multiple subdomains of the same parent domain, use a **leading dot**:
- `.thegencc.org` - Cookie works for `thegencc.org`, `sub.thegencc.org`, `www.thegencc.org`, etc.
- `thegencc.org` (no dot) - Cookie only works for exactly `thegencc.org`

## External API Keys

The application stores API keys in the database via the `anlutro/l4-settings` package (table: `settings`). This allows runtime updates without redeployment.

### Setting API Keys

```bash
php artisan tinker
>>> Setting::set('omim', 'your-omim-api-key');      // Required for disease imports
>>> Setting::set('pubmed', 'your-ncbi-api-key');    // Optional, for higher rate limits
>>> Setting::set('publish', 'your-publish-token');  // Internal publish authentication
>>> exit
```

### OMIM API Key (Required)

Required for disease data imports from OMIM.

1. Register at https://www.omim.org/api
2. Request an API key
3. Store with `Setting::set('omim', 'your-key')`

### PubMed/NCBI API Key (Optional)

Increases rate limit from 3 to 10 requests/second.

1. Create account at https://www.ncbi.nlm.nih.gov/account/
2. Navigate to Settings > API Key Management
3. Store with `Setting::set('pubmed', 'your-key')`

### Email Configuration (Optional)

Configure SMTP for email notifications in `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@thegencc.org
MAIL_FROM_NAME="GenCC Submission Portal"
```

### Google Cloud Storage Configuration (Optional)

Release files (CSV exports and release notes) can be stored in Google Cloud Storage instead of the local filesystem. This prevents file accumulation and enables centralized storage.

Configure GCS in `.env`:

```env
GOOGLE_CLOUD_PROJECT_ID=your-gcp-project-id
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name
GOOGLE_CLOUD_STORAGE_PATH_PREFIX=releases
```

### Build & Deploy Workflows

- **`image-build.yml`** — Builds the Docker image with `APP_VERSION` from git tags and pushes to GitHub Container Registry. Triggered on release or manually via `workflow_dispatch`.
- **`deploy-via-ansible.yml`** — Deploys to the target server using Ansible over IAP SSH. Triggered manually with image tag inputs.

**GCP Infrastructure (Terraform):**

The `deployment/terraform/` configuration includes a managed GCS bucket with the VM service account granted `roles/storage.objectAdmin`:

```hcl
# Variables (terraform.tfvars)
gencc_data_bucket_name     = "gencc-prod"
gencc_data_bucket_location = "us-east1"
```

**Authentication:**

- **On GCP VMs**: Uses Application Default Credentials automatically via the VM's service account
- **Local development**: Set `GOOGLE_APPLICATION_CREDENTIALS` to a service account key file, or run `gcloud auth application-default login`

**Fallback behavior:**

If GCS is not configured or upload fails, files are stored locally in `storage/app/releases/` with a warning logged. Downloads similarly fall back to local storage.

## Initial Setup

### 1. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 2. Run Migrations

```bash
php artisan migrate --force
```

### 3. Create Storage Link

```bash
php artisan storage:link
```

### 4. Set Permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

## Data Import

### Reference Data

Import required reference data:

```bash
# Import lookup tables for classifications, MOI, etc. (required first)
php artisan import:tables

# Import gene data from HGNC (required)
php artisan update:genes

# Import disease data from MONDO, OMIM, Orphanet (required)
php artisan update:diseases

# Optional: Import existing GenCC curated data
php artisan import:gencc

# Optional: Import ClinGen data
php artisan import:clingen
```

### User & Organization Data (YAML Import)

Import submitters, teams, and users from YAML files. Files can be local or stored in Google Cloud Storage.

```bash
# Import submitters (organizations)
php artisan gencc:import-submitters [file]

# Import teams
php artisan gencc:import-teams [file]

# Import users
php artisan gencc:import-users [file]
```

**File locations** (in order of precedence):

1. Command argument: `php artisan gencc:import-users /path/to/users.yaml`
2. Environment variable: `GENCC_USERS_FILE`, `GENCC_TEAMS_FILE`, `GENCC_SUBMITTERS_FILE`
3. Default: `data/users.yaml`, `data/teams.yaml`, `data/submitters.yaml`

**Google Cloud Storage support:**

Files can be loaded directly from GCS using `gs://` URLs:

```bash
# Via environment variable
GENCC_USERS_FILE=gs://my-bucket/config/users.yaml php artisan gencc:import-users

# Or command argument
php artisan gencc:import-users gs://my-bucket/config/users.yaml
```

For GCS access, configure authentication via `GOOGLE_APPLICATION_CREDENTIALS` environment variable or use default service account credentials (on GCE/Cloud Run).

**Environment variable substitution:**

YAML files support `${ENV_VAR}` syntax for secrets:

```yaml
users:
  - name: Admin User
    email: admin@example.com
    password: ${ADMIN_PASSWORD}
```

## Production Deployment

### Web Server Configuration

#### Nginx

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name submit.thegencc.org;
    root /var/www/gencc-sub/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### Apache

```apache
<VirtualHost *:80>
    ServerName submit.thegencc.org
    DocumentRoot /var/www/gencc-sub/public

    <Directory /var/www/gencc-sub/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/gencc-sub-error.log
    CustomLog ${APACHE_LOG_DIR}/gencc-sub-access.log combined
</VirtualHost>
```

### Scheduled Tasks

Add to crontab for scheduled commands:

```cron
# Release pending submissions to gencc-search every Friday at midnight
0 0 * * 5 cd /var/www/gencc-sub && php artisan gencc:release >> /var/log/gencc-release.log 2>&1

# Update PubMed data every 6 hours
0 */6 * * * cd /var/www/gencc-sub && php artisan pubmed:sync >> /dev/null 2>&1

# Update disease ontology data daily at 2 AM
0 2 * * * cd /var/www/gencc-sub && php artisan update:diseases >> /dev/null 2>&1

# Update gene data daily at 3 AM
0 3 * * * cd /var/www/gencc-sub && php artisan update:genes >> /dev/null 2>&1
```

#### Container Deployments

For containerized deployments on a VM, run cron on the host to exec into the container:

```cron
# Release pending submissions every Friday at midnight
0 0 * * 5 docker exec gencc-sub php artisan gencc:release >> /var/log/gencc-release.log 2>&1
```

#### Log Rotation

To prevent log files from growing unbounded, configure logrotate:

```bash
# /etc/logrotate.d/gencc
/var/log/gencc-release.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
```

### Queue Workers

The application uses database queues for background job processing (file uploads, etc.).

**Important:** Queue workers cache application code. After deploying code changes, you must restart the queue workers:

```bash
pm2 restart gencc-queue-worker
```

#### Using PM2 (Recommended)

PM2 is recommended for both development and production. Node.js is already required for the build process, and using the same tool in both environments reduces complexity.

```bash
npm install -g pm2

# Start queue worker for production
pm2 start ecosystem.config.cjs --only gencc-queue-worker --env production

# Save PM2 configuration
pm2 save

# Setup PM2 to start on boot
pm2 startup
```

The `ecosystem.config.cjs` includes:

- `gencc-queue-worker` - Background job processing (required)
- `gencc-dev-server` - Laravel development server (development only)
- `gencc-vite` - Vite dev server for hot reload (development only)

#### Using Supervisor (Alternative)

For traditional Linux server deployments without Node.js, Supervisor can be used instead:

```bash
sudo apt-get install supervisor
```

Create `/etc/supervisor/conf.d/gencc-sub-worker.conf`:

```ini
[program:gencc-sub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/gencc-sub/artisan queue:work database --sleep=3 --tries=3 --timeout=3600 --memory=2048 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/gencc-sub/storage/logs/worker.log
stopwaitsecs=3600
```

Start supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start gencc-sub-worker:*
```

## Security Considerations

### 1. Environment Variables

- Never commit `.env` files to version control
- Use strong, unique values for `APP_KEY` and database passwords
- Restrict `.env` file permissions: `chmod 600 .env`

### 2. Application Security

```env
APP_DEBUG=false
APP_ENV=production
```

### 3. Database Security

- Use a dedicated database user with minimal privileges
- Enable SSL for database connections if possible
- Regularly backup your database

### 4. File Permissions

```bash
# Application files (read-only for web server)
chown -R deploy:www-data /var/www/gencc-sub
chmod -R 755 /var/www/gencc-sub

# Writable directories
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 5. SSL/TLS

Always use HTTPS in production. Use Let's Encrypt for free SSL certificates:

```bash
sudo apt-get install certbot python3-certbot-nginx
sudo certbot --nginx -d submit.thegencc.org
```

### 6. CORS Configuration

Configure allowed origins in `config/cors.php` or via `.env`:

```env
SANCTUM_STATEFUL_DOMAINS=submit.thegencc.org
SESSION_DOMAIN=.thegencc.org
```

## Database Restore

When restoring a database from backup, you must run the bootstrap release command to create an initial release record (GCC-00000) that captures the current state of all live submissions.

```bash
# After restoring the database, run the bootstrap release
php artisan gencc:release bootstrap
```

This command:
- Creates a release record with slug `GCC-00000`
- Generates a CSV file with all live submissions
- Generates release notes capturing the current state
- Uploads files to GCS (with local fallback)

**Important:** This command should only be run **once** after a database restore. It will fail if `GCC-00000` already exists.

### Why Bootstrap is Required

After a database restore:
1. The `releases` table may be empty or out of sync with the restored data
2. Live submissions exist but have no corresponding release record
3. The admin dashboard needs a release record to display release history
4. The release files (CSV, notes) in GCS need to match the database state

The bootstrap creates a baseline that all future releases build upon.

## Updating the Application

```bash
# Pull latest changes
git pull origin master

# Update dependencies
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# Run migrations
php artisan migrate --force

# Clear caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue workers (IMPORTANT - required after code changes)
sudo supervisorctl restart gencc-sub-worker:*
# Or if using PM2:
pm2 restart gencc-queue-worker
```

## Monitoring and Logs

### Application Logs

Logs are stored in `storage/logs/laravel.log`

```bash
tail -f storage/logs/laravel.log
```

### Queue Monitoring

```bash
# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### PM2 Monitoring & Logs

```bash
# Real-time monitoring (CPU, memory, logs)
pm2 monit

# View queue worker logs
pm2 logs gencc-queue-worker

# View all logs
pm2 logs

# Check process status
pm2 status

# View detailed info
pm2 show gencc-queue-worker
```

### Local Development Queue Monitoring

Check if worker is running:

```bash
pgrep -la "queue:work"
```

Check pending jobs:

```bash
php artisan tinker --execute="echo DB::table('jobs')->count() . ' pending jobs';"
```

Clear all queued jobs (use cautiously):

```bash
php artisan queue:clear database
```

Watch queue worker output in real-time:

```bash
php artisan queue:work database --timeout=3600 --sleep=3 --tries=1 --verbose
```

## Troubleshooting

### 419 CSRF Token Mismatch

If you get 419 errors on form submissions:

1. **Check SANCTUM_STATEFUL_DOMAINS** - Ensure your domain is listed
2. **Check SESSION_DOMAIN** - Should match or be a parent of your domain
3. **Check SESSION_SECURE_COOKIE** - Should be `true` for HTTPS, unset for HTTP
4. **Clear caches**:

   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

### Session Not Persisting

1. Verify `SESSION_DRIVER` is set (typically `database` for production)
2. Check database connectivity if using database sessions
3. Verify cookie domain matches the request domain

### Cookies Not Being Sent

1. Check browser dev tools > Application > Cookies
2. Verify `SESSION_SECURE_COOKIE=true` for HTTPS deployments
3. Ensure `supports_credentials` is `true` in `config/cors.php`

### Clear All Caches

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Permission Issues

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Database Connection Issues

```bash
# Test database connection
php artisan tinker
>>> DB::connection()->getPdo();
```

### Queue Worker Not Processing Jobs

```bash
# Check if workers are running
pm2 list
# or
sudo supervisorctl status

# Restart workers
pm2 restart gencc-queue-worker
# or
sudo supervisorctl restart gencc-sub-worker:*
```

### Worker Keeps Dying

- With PM2: Worker automatically restarts with memory limits (2048MB configured)
- Check PM2 logs: `pm2 logs gencc-queue-worker --err`
- Check Laravel logs: `tail -f storage/logs/laravel.log`
- Look for PHP errors or memory issues
- Ensure database connection is stable

### Jobs Stuck in Queue

- Verify worker is running: `pm2 status` or `pgrep -f "queue:work"`
- Check for errors: `php artisan queue:failed`
- Restart worker: `pm2 restart gencc-queue-worker`

### Code Changes Not Applying

Workers cache code at startup. After deploying code changes:

```bash
pm2 restart gencc-queue-worker
# or
php artisan queue:restart
```

### Multiple Workers Accumulating

- With PM2: PM2 manages only one worker process automatically
- Manual: Kill all workers then restart:

```bash
pkill -f "queue:work"
pm2 start ecosystem.config.cjs --only gencc-queue-worker
```

### File Upload Shows "Partial Upload"

If file uploads show incorrect row counts or "partial upload" status:

1. Restart the queue worker (it may have cached old code):
   ```bash
   pm2 restart gencc-queue-worker
   ```

2. Check the document's stored values:
   ```bash
   php artisan tinker
   >>> $doc = App\Models\Document::find(ID);
   >>> echo $doc->total_submissions . ' / ' . $doc->processed_submissions;
   ```

## Support

For issues or questions:
- GitHub Issues: https://github.com/GeneCurationCoalition/gencc-sub/issues
- Email: support@thegencc.org
