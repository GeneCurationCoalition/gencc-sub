# Ansible — VM configuration + Podman/Quadlet deployment

This automation scaffold matches the deployment architecture in `ai/2026-02-05T001502Z-synthesized-gencc-deploy-plan.GEMINI.md`.

It is intentionally written so it can be run safely after Terraform provisions the VM (SSH via IAP is supported).

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

## Notes
- Uses **rootless Podman** for the app containers via a dedicated `gencc` user and `loginctl enable-linger`.
- Uses **host MySQL** and allows container connections via `slirp4netns` (`DB_HOST=10.0.2.2`).
- Terminates TLS on the VM using **nginx + certbot** (Let's Encrypt HTTP-01 webroot challenges).
- SSH access is expected via **IAP tunneling** + **OS Login**; ensure your operator account has `roles/iap.tunnelResourceAccessor` and `roles/compute.osAdminLogin`.
- Installs systemd units for periodic tasks as **systemd timers** (preferred over crontab).
- The ansible vault passphrase is stored in a GCP secret named `gencc-ansible-vault-passphrase`.
