# Synthesized GCP Deployment Plan: gencc-sub & gencc-search

## 1. Executive Summary

This document outlines the definitive plan to deploy both the `gencc-sub` and `gencc-search` applications to a single Ubuntu 24.04 LTS VM in Google Cloud Platform.

The architecture leverages **Podman** for rootless containerization and a host-level **MySQL** instance as the shared database. An external **GCP HTTPS Load Balancer** will provide a unified entrypoint, terminating TLS and routing traffic to the appropriate application based on hostname.

Infrastructure will be managed declaratively using **Terraform**. Server configuration and application deployment will be automated, idempotent, and repeatable using **Ansible**. This combined approach represents a modern, secure, and operationally mature standard for deployment.

---

## 2. Architecture

### 2.1. Architecture Diagram
This diagram from the initial `CLAUDE.md` plan remains accurate and provides an excellent visual overview of the target state.

```
                    ┌─────────────────────────────────────────────────────────────┐
                    │                     Google Cloud Platform                    │
                    │                                                              │
   Internet         │  ┌──────────────────┐                                       │
       │            │  │  Cloud DNS       │                                       │
       │            │  │  (clingen.app)   │                                       │
       ▼            │  └────────┬─────────┘                                       │
┌──────────────┐    │           │                                                 │
│ Users/API    │────┼───────────▼─────────────────────────────────────────────┐   │
└──────────────┘    │  ┌──────────────────────────────────────────────────────┤   │
                    │  │       GCP HTTPS Load Balancer                        │   │
                    │  │  • TLS termination (managed certificate)             │   │
                    │  │  • Routes: submit.clingen.app → gencc-sub            │   │
                    │  │  • Routes: search.clingen.app → gencc-search         │   │
                    │  └─────────────────────┬────────────────────────────────┘   │
                    │                        │ HTTP (private network)             │
                    │                        ▼                                    │
                    │  ┌─────────────────────────────────────────────────────────┐ │
                    │  │              Ubuntu 24.04 LTS VM                        │ │
                    │  │                                                         │ │
                    │  │   ┌─────────────────┐    ┌─────────────────┐           │ │
                    │  │   │  gencc-sub      │    │  gencc-search   │           │ │
                    │  │   │  (podman)       │    │  (podman)       │           │ │
                    │  │   │  :8080 → :80    │    │  :8081 → :80    │           │ │
                    │  │   └────────┬────────┘    └────────┬────────┘           │ │
                    │  │            │                      │                     │ │
                    │  │            └──────────┬───────────┘                     │ │
                    │  │                       ▼                                 │ │
                    │  │            ┌─────────────────────┐                      │ │
                    │  │            │  MySQL 8.0          │                      │ │
                    │  │            │  (host, :3306)      │                      │ │
                    │  │            └─────────────────────┘                      │ │
                    │  └─────────────────────────────────────────────────────────┘ │
                    └─────────────────────────────────────────────────────────────┘
```

### 2.2. Core Technologies

| Component | Technology | Rationale |
| :--- | :--- | :--- |
| **Infrastructure as Code** | **Terraform** | For repeatable, version-controlled provisioning of all GCP resources. |
| **Configuration Mgmt** | **Ansible** | For idempotent server configuration and application deployment. Superior to shell scripts. |
| **Containerization** | **Podman (rootless)**| Securely isolates application environments without requiring a Docker daemon. |
| **Service Management** | **Quadlet / systemd** | Manages containers as native systemd services for resilience and auto-restarts. |
| **Database** | **MySQL 8.0 on host** | Simplifies networking and provides a single source of truth for both apps. |
| **Admin Access** | **GCP IAP Tunneling** | The most secure method for SSH access to a VM with no public IP. |

### 2.3. Key Design Decisions

*   **Operating System: Ubuntu 24.04 LTS** — This was chosen over the initial 22.04 release specifically because its default repositories include Podman v4.9+, which provides native support for Quadlet. This allows for simpler and more robust systemd service management for containers, avoiding the need for manual `podman generate systemd` workarounds.

---

## 3. Implementation Plan

### Phase 1: `gencc-search` Production Containerization
The `gencc-search` application currently lacks a production-ready container. A new multi-stage Dockerfile must be created in its repository.

**File to Create:** `gencc-search/Dockerfile.prod`
```Dockerfile
# -- Builder Stage --
FROM composer:2 as builder
WORKDIR /app
COPY . .
# Install dependencies, ignoring platform reqs for PHP 8+ locally
RUN composer install --no-dev --no-interaction --optimize-autoloader --ignore-platform-reqs

# -- Asset Stage --
FROM node:18-slim as assets
WORKDIR /app
COPY . .
COPY --from=builder /app/vendor/ /app/vendor/
RUN npm ci && npm run production

# -- Production Stage --
FROM php:7.4-fpm-alpine

# Set up a non-root user
RUN addgroup -g 1000 www && adduser -u 1000 -G www -s /bin/sh -D www
WORKDIR /var/www/html

# Install required PHP extensions for Laravel 8
RUN apk add --no-cache libzip-dev oniguruma-dev
    && docker-php-ext-install pdo_mysql zip bcmath mbstring

# Copy application code and assets
COPY --chown=www:www . .
COPY --from=builder --chown=www:www /app/vendor/ /var/www/html/vendor/
COPY --from=assets --chown=www:www /app/public/ /var/www/html/public/

# Ensure storage is writable
RUN chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache

USER www
EXPOSE 9000
CMD ["php-fpm"]
```
*Note: A separate Nginx container or a sidecar pattern within the pod would handle web serving. For simplicity with Quadlet, we will configure an Nginx container within the same pod definition later.*

### Phase 2: Infrastructure Provisioning (Terraform)
All GCP resources will be defined in Terraform configurations within the `gencc-sub` repository.

**Location:** `gencc-sub/deployment/terraform/`

**Key Workflow:**
1.  Define resources: VPC, subnets, a `google_compute_instance` with a **static internal IP** and **no public IP**, firewall rules (allowing IAP and LB traffic), and the HTTPS Load Balancer.
2.  The VM's service account must have IAM permissions for GCS (for backups) and optionally the Ops Agent.
3.  **Crucially, Terraform will output a dynamically generated Ansible inventory file.** This ensures Ansible always targets the correct, newly created infrastructure.
4.  **SSL Certificate:** The HTTPS Load Balancer will be configured to use the **existing** Google-managed certificate named `clingen-app`. Terraform will use a `google_compute_managed_ssl_certificate` data source to reference it, rather than creating a new one.

**Example Terraform Output for Ansible:**
```hcl
# outputs.tf
output "ansible_inventory" {
  value = <<-EOT
    [gcp_vms]
    ${google_compute_instance.main.name} ansible_host=${google_compute_instance.main.network_interface[0].network_ip} ansible_zone=${google_compute_instance.main.zone}

    [gcp_vms:vars]
    ansible_user=<your-deploy-user>
    ansible_ssh_common_args='-o ProxyCommand="gcloud compute start-iap-tunnel %h %p --listen-on-stdin --project=${var.project_id} --zone=${var.zone}"'
  EOT
}
```

### Phase 3: Server Configuration & Deployment (Ansible)
Ansible will perform all configuration on the VM provisioned by Terraform.

**Location:** `gencc-sub/deployment/ansible/`

**Ansible Playbook Tasks:**
1.  **Baseline:** Update packages, install `podman`, `mysql-server`, `nginx`, and the Google Ops Agent.
2.  **MySQL Setup:** Secure the installation, create the `gencc_sub` database, and create two users (`gencc_sub_app` with write access, `gencc_search_reader` with read-only), restricted to the local host.
3.  **Directory Structure:** Create host directories for secrets (`/etc/gencc/`) and persistent storage (`/var/lib/gencc/`).
4.  **Secrets:** Copy the production `.env` files (stored securely, e.g., in Ansible Vault) to `/etc/gencc/`.
5.  **Deploy Containers with Quadlet:** Create `.container` files in `/etc/containers/systemd/`. This is the declarative way to define Podman containers that `systemd` will manage.

**Example Quadlet File:** `gencc-sub.container`
```ini
[Unit]
Description=The gencc-sub application container
After=network-online.target
Wants=network-online.target

[Container]
Image=ghcr.io/your-org/gencc-sub:latest
# Use host networking for simplicity to reach MySQL on localhost:3306
Network=host
# Map host port 8080 to the container's Nginx port 80
Port=8080:80
Volume=/etc/gencc/gencc-sub.env:/var/www/html/.env:ro,z
Volume=/var/lib/gencc/gencc-sub/storage:/var/www/html/storage:z
PodmanArgs=--tz=America/New_York

[Service]
Restart=always
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target
```
*A similar `gencc-search.container` file will be created, mapping port `8081`.*

### Phase 4: Initial System Bootstrap
After the first successful Ansible run, a series of one-time `artisan` commands must be executed to initialize the database and application state. Ansible can be used to orchestrate this.

**Command Checklist (executed via `podman exec gencc-sub ...`):**
1.  `php artisan migrate --force`
2.  `php artisan import:tables`
3.  `php artisan update:genes`
4.  `php artisan update:diseases`
5.  `php artisan gencc:import-submitters` (or other initial data imports)

### Phase 5: CI/CD Automation
GitHub Actions workflows will build and push container images, then trigger the Ansible deployment.

1.  **Image Build:** On a push/merge to `main` (or release tag), a workflow in each app's repository builds the production image (`Dockerfile.prod`) and pushes it to GHCR or Google Artifact Registry.
2.  **Deployment Trigger:** A manual dispatch workflow (or one triggered after the image build) will execute the Ansible playbook against the production environment, which will automatically pull the new image and restart the `systemd` service.

---

## 4. Operational Considerations

### Security
*   **VM Access:** Strictly limited to IAP tunneling. No public IP, no direct SSH.
*   **Secrets:** Managed by Ansible Vault and placed in a root-owned directory (`/etc/gencc`) on the host.
*   **Database:** Listens only on `localhost` (127.0.0.1). No network exposure.
*   **Containers:** Run as a non-root user using rootless Podman, managed by a non-root systemd user service if possible.

### Backup and Recovery
*   The existing `scripts/setup-backup-cron.sh` will be configured by Ansible to perform nightly dumps of the MySQL database to a GCS bucket.
*   **A restore procedure must be documented and tested quarterly.**

### Scheduled & Periodic Tasks
*   All cron jobs (`pubmed:sync`, `update:genes`, etc.) will be managed as **systemd timers** that call `podman exec ...`. This is more reliable and auditable than crontab.

### Future Improvements
1.  **Upgrade `gencc-search`:** The Laravel 8 / PHP 7.4 stack is EOL. An upgrade should be a high-priority follow-up project.
2.  **Migrate to Cloud SQL:** If database high-availability becomes a requirement, migrating from the host-level MySQL to a managed Cloud SQL instance is the logical next step.
3.  **Centralized Secret Management:** For higher security, migrate from `.env` files managed by Ansible to fetching secrets directly from **GCP Secret Manager** at runtime.

---

## 5. Local Implementation Notes / Deviations (No Cloud Deploy)

This section records issues encountered while turning this plan into local, commit-ready scaffolding (Terraform + Ansible + container builds). No cloud resources were created as part of this work.

### 5.1. `gencc-search` production image build issues

1. **Composer scripts failed in a “vendor-only” stage**
   - **Symptom:** `composer install` tried to run `@php artisan package:discover` but `artisan` was not present in the vendor stage, causing the build to fail.
   - **Decision / Fix:** Run `composer install` with `--no-scripts` in the vendor stage, and (optionally) run package discovery later when the full app code is present.

2. **Node/OpenSSL build failure with Laravel Mix (Webpack 4)**
   - **Symptom:** asset builds on Node 18+ failed with `ERR_OSSL_EVP_UNSUPPORTED`.
   - **Decision / Fix:** Use a Node 16 image for the asset build stage to avoid relying on `NODE_OPTIONS=--openssl-legacy-provider`.

3. **Composer platform mismatch (PHP version / extensions)**
   - **Symptom:** The generic `composer:2` image uses a newer PHP (currently 8.x) which conflicted with `gencc-search`’s locked dependencies (PHP 7.4-era constraints) and required extensions (notably `ext-gd`).
   - **Decision / Fix:** Perform dependency installation in a PHP 7.4-based vendor stage and ensure required extensions/libs are available for Composer’s platform checks.

### 5.2. Podman / Quadlet networking decisions

1. **Load balancer backends must be reachable on the VM’s internal IP**
   - **Issue:** If containers bind only to `127.0.0.1`, a GCP External HTTP(S) Load Balancer health check cannot reach them.
   - **Decision:** Publish container ports on all interfaces (e.g., `0.0.0.0:8080/8081`) and rely on GCP firewall rules restricting inbound traffic to LB/health-check source ranges.

2. **Rootless Podman and host MySQL connectivity**
   - **Issue:** Rootless `slirp4netns` containers reach the host MySQL via `10.0.2.2` (Podman’s host gateway).
   - **Decision:** Configure apps with `DB_HOST=10.0.2.2`, and scope MySQL users to `localhost` and `10.0.2.%`.
   - **Note:** Keep MySQL bound to `127.0.0.1` where possible; only broaden bind address if required and still firewall appropriately.

### 5.3. Terraform certificate note

The plan calls out reusing an existing Google-managed certificate (e.g., `clingen-app`) via a data source. If the certificate already exists outside Terraform, prefer importing it into state or switching the configuration to a data source reference rather than creating a new managed certificate resource.
