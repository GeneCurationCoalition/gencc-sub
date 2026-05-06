# Ansible — VM configuration + Podman/Quadlet deployment

It is intentionally written so it can be run idempotently after Terraform provisions the VM (SSH via IAP is supported).

The easiest way to configure the connection to the VM is to add a block to your `~/.ssh/config` for each VM, with the host named the same as referred to in `production.ini` (`gencc-prod-vm`) and `staging.ini` (`gencc-vm`) which uses the `ProxyCommand` directive to tell ssh (and ansible) to use IAP tunneling to reach the VM. Public ssh connections are disabled for the VMs in our terraform config.

## Layout
- `playbooks/site.yml` — main entrypoint
- `inventories/inventory.ini.example` — example inventory (Terraform also outputs one)
- `inventories/staging.ini` — staging host inventory
- `inventories/production.ini` — production host inventory (placeholder)
- `inventories/group_vars/all/` — shared defaults
- `inventories/group_vars/staging/` — staging vars + vault
- `inventories/group_vars/production/` — production vars + vault
- `roles/` — baseline, MySQL, quadlet units, timers

## Running (operator runbook)
1. Create or update the inventory file for your environment (or paste the Terraform output).
2. Create a vault file from the template and encrypt it:
   - `cp inventories/group_vars/all/vault.yml.example inventories/group_vars/staging/vault.yml`
   - `ansible-vault encrypt inventories/group_vars/staging/vault.yml`
3. Run:
   - `ansible-playbook -i inventories/staging.ini playbooks/site.yml --ask-vault-pass`
   - `ansible-playbook -i inventories/production.ini playbooks/site.yml --ask-vault-pass`

The vault passphrases for staging and production are both stored in Google Secret Manager in their respective projects (dev/prod). You can also save each value to a local txt file and pass it to ansible-playbook to run without re-prompting for the passphrase interactively.

For example, for staging:
```
ansible-playbook \
  -i inventories/staging.ini \
  playbooks/site.yml \
  --vault-password-file gencc-staging-ansible-vault-passphrase.txt
```

## Running from GitHub Actions
Repository workflow:
- `.github/workflows/deploy-via-ansible.yml`

This workflow:
- Authenticates to GCP using Workload Identity Federation (OIDC)
- Creates an ephemeral OS Login SSH key
- Connects to the VM via IAP tunnel
- Runs this same playbook with image/db mode overrides
- Opens a PR updating pinned image tags in `inventories/group_vars/staging/vars.yml`

Required GitHub secret:
- `ANSIBLE_VAULT_PASSWORD`

GCP/instance identifiers are currently stored directly in the workflow `env`.

## Database bootstrap
By default the playbook **does not reset** the MySQL database; it only runs Laravel migrations inside the `gencc-sub` container.

To restore a database dump and then migrate:
- Set `gencc_db_bootstrap_mode: restore_and_migrate`
- Set `gencc_db_restore_force: true` (required safety switch)
- Set `gencc_db_restore_source` to an `https://` or `gs://` URL pointing to a `.sql.gz` dump

The restore is destructive: it drops and recreates `gencc_mysql_database`.

(See `./deployment/ansible/inventories/group_vars/all/vars.yml` for all config options)

## Security hardening

The `nginx_tls` role deploys several layers of abuse prevention, configured via templates in `roles/nginx_tls/templates/`:

**Bot blocking** — A user-agent map (`gencc-security.conf.j2`) blocks known SEO crawlers, AI training bots, and vulnerability scanners. Certain paths (downloads, health checks, robots.txt) are exempt so external tools can still fetch exports.

**Rate limiting** — nginx `limit_req` zones throttle requests per client IP. Different zones apply to different route types (general pages, auth endpoints, search, data exports). Limits are defined at the `http` level in `gencc-security.conf.j2` and applied per-location in `gencc-https.conf.j2`.

**Connection limiting** — `limit_conn` caps simultaneous connections per IP (30 general, 3 for exports), protecting against slowloris-style attacks and download abuse.

**IP blocklist** — `gencc-ip-blocklist.conf.j2` renders `deny` directives from the `gencc_blocked_ips` Ansible variable. Re-run the playbook to update.

**Security headers** — HSTS, X-Content-Type-Options, X-Frame-Options, and Referrer-Policy are set on the main HTTPS application responses (including error responses from that vhost). Redirect-only vhosts may still return bare `301` responses without these headers. HSTS max-age is configurable per environment (`gencc_hsts_max_age`).

**fail2ban** — Monitors nginx logs over longer windows and bans repeat offenders at the iptables level. Four jails are configured:

| Jail | Watches | Triggers on | Ban duration |
|------|---------|-------------|-------------|
| `sshd` | auth.log | SSH brute force | 1 hour |
| `nginx-req-limit` | error.log | Repeated rate-limit 429s | 10 minutes |
| `nginx-forbidden` | access.log | Repeated bot-blocked 403s | 24 hours |
| `nginx-botsearch` | access.log | Vulnerability probe paths (wp-admin, .env, .git, etc.) | 24 hours |

GCP IAP tunnel IPs (`35.235.240.0/20`) are in `ignoreip` so fail2ban never locks out SSH operators.

Useful operational commands:
```bash
fail2ban-client status                              # List all jails
fail2ban-client status nginx-req-limit              # Show banned IPs for a jail
fail2ban-client set nginx-req-limit unbanip 1.2.3.4 # Manual unban
```

## Notes
- Uses **rootless Podman** for the app containers via a dedicated `gencc` user and `loginctl enable-linger`.
- Uses **host MySQL** and allows container connections via `slirp4netns` (`DB_HOST=10.0.2.2`).
- Terminates TLS on the VM using **nginx + certbot** (Let's Encrypt HTTP-01 webroot challenges).
- SSH access is expected via **IAP tunneling** + **OS Login**; ensure your operator account has `roles/iap.tunnelResourceAccessor` and `roles/compute.osAdminLogin`.
- Installs systemd units for periodic tasks as **systemd timers** (preferred over crontab).
- The ansible vault passphrase is stored in a GCP secret named `gencc-ansible-vault-passphrase`.
