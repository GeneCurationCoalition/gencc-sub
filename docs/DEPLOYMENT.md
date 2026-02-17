# Deployment Guide

This document covers environment configuration for deploying the GenCC Submission Portal across different environments.

## Environment Overview

| Environment | Domain | Deployment |
|-------------|--------|------------|
| Local | `localhost:8001` / `127.0.0.1:8001` | Native or Docker |
| Staging | `gencc-stage-sub.clingen.app` | Container |
| Production | `sub.thegencc.org`, `thegencc.org` | Container |

## Session & CSRF Configuration

The application uses Laravel Sanctum for SPA authentication with cookie-based sessions. Proper configuration of these environment variables is critical for CSRF protection and session handling.

### Required Environment Variables

| Variable | Purpose |
|----------|---------|
| `APP_URL` | Base URL for the application |
| `SANCTUM_STATEFUL_DOMAINS` | Domains that receive stateful (cookie-based) authentication |
| `SESSION_DOMAIN` | Cookie domain for session cookies |
| `SESSION_SECURE_COOKIE` | Whether cookies require HTTPS |

## Environment-Specific Configuration

### Local Development

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

### Staging (gencc-stage-sub.clingen.app)

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://gencc-stage-sub.clingen.app

SANCTUM_STATEFUL_DOMAINS=gencc-stage-sub.clingen.app
SESSION_DOMAIN=gencc-stage-sub.clingen.app
SESSION_SECURE_COOKIE=true
```

### Production (sub.thegencc.org + thegencc.org)

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

## Configuration Notes

### SESSION_DOMAIN with Leading Dot

When supporting multiple subdomains of the same parent domain, use a **leading dot**:

- `.thegencc.org` - Cookie works for `thegencc.org`, `sub.thegencc.org`, `www.thegencc.org`, etc.
- `thegencc.org` (no dot) - Cookie only works for exactly `thegencc.org`

### SANCTUM_STATEFUL_DOMAINS

- Comma-separated list of domains
- Include port numbers for non-standard ports (e.g., `localhost:8001`)
- Do NOT include port for standard HTTPS (443) or HTTP (80)
- These domains receive cookie-based session authentication

#### Default Behavior

If `SANCTUM_STATEFUL_DOMAINS` is NOT set, Sanctum defaults to:

```text
localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1 + {host extracted from APP_URL}
```

#### When NOT Necessary

If you use **one domain** and `APP_URL` matches it, Sanctum auto-includes it:

| Environment | APP_URL                                | Auto-included                  |
|-------------|----------------------------------------|--------------------------------|
| Staging     | `https://gencc-stage-sub.clingen.app`  | `gencc-stage-sub.clingen.app`  |
| Production  | `https://sub.thegencc.org`             | `sub.thegencc.org`             |

#### When REQUIRED

1. **Local dev on port 8001** - Default only includes `:8000`, not `:8001`
2. **Production with multiple domains** - `thegencc.org` won't be auto-included if `APP_URL` is `sub.thegencc.org`

#### Recommendation

Keep it **explicit in all environments** for clarity and to avoid debugging issues:

```env
# Local
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8001,127.0.0.1,127.0.0.1:8001

# Staging (optional if APP_URL matches)
SANCTUM_STATEFUL_DOMAINS=gencc-stage-sub.clingen.app

# Production (required for multiple domains)
SANCTUM_STATEFUL_DOMAINS=sub.thegencc.org,thegencc.org
```

### SESSION_SECURE_COOKIE

- Set to `true` for HTTPS deployments (staging/production)
- Leave unset or `false` for local HTTP development
- When `true`, cookies are only sent over HTTPS connections

## Container Deployment

Production and staging deployments use containers. The `.env` file is mounted into the container at runtime.

### Build & Deploy Workflows

- **`image-build.yml`** — Builds the Docker image with `APP_VERSION` from git tags and pushes to GitHub Container Registry. Triggered on release or manually via `workflow_dispatch`.
- **`deploy-via-ansible.yml`** — Deploys to the target server using Ansible over IAP SSH. Triggered manually with image tag inputs.

### Container Environment

The container expects:
- `.env` file mounted at `/var/www/html/.env`
- MySQL accessible via `DB_HOST` (typically `10.0.2.2` for slirp4netns networking)

Example deployment command:
```bash
podman run -d \
  --name gencc-sub \
  --restart=always \
  --network=slirp4netns:allow_host_loopback=true \
  -p 127.0.0.1:8080:80 \
  -e DB_HOST=10.0.2.2 \
  -v /var/www/gencc-sub/.env:/var/www/html/.env:ro \
  ghcr.io/thegencc/gencc-sub:latest
```

## Version Display

The application displays its version on the Help page. Version is determined by:

1. `APP_VERSION` environment variable (if set)
2. Git tag (if on a tagged commit)
3. Git commit hash (if committed but untagged)
4. Timestamp (if uncommitted changes)

See `scripts/version.sh` for the version generation logic.

### Version Output Examples

| Git State | Output |
|-----------|--------|
| Uncommitted changes | `dev-2026-02-06T14:57:23Z` |
| Committed, no tag | `commit-abc1234` |
| Tagged `v1.0.0` | `v1.0.0` |
| Tagged `2.0-beta4` | `2.0-beta4` |

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
