# Ansible — VM configuration + Podman/Quadlet deployment

This is a **local-only** automation scaffold that matches the deployment architecture in `ai/2026-02-05T001502Z-synthesized-gencc-deploy-plan.GEMINI.md`.

It is intentionally written so it can be run safely after Terraform provisions the VM (SSH via IAP is supported).

## Layout
- `playbooks/site.yml` — main entrypoint
- `inventories/gencc.ini.example` — example inventory (Terraform also outputs one)
- `inventories/group_vars/` — non-secret defaults + a vault template
- `roles/` — baseline, MySQL, quadlet units, timers

## Running (operator runbook)
1. Create an inventory file (or paste the Terraform output).
2. Create `inventories/group_vars/all/vault.yml` from the template and encrypt it:
   - `ansible-vault encrypt inventories/group_vars/all/vault.yml`
3. Run:
   - `ansible-playbook -i inventories/gencc.ini playbooks/site.yml --ask-vault-pass`

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
- Terminates TLS on the VM using **nginx + certbot** (Let’s Encrypt). Wildcard issuance (`*.clingen.app`) uses DNS-01 via **GCP Cloud DNS**.
- SSH access is expected via **IAP tunneling** + **OS Login**; ensure your operator account has `roles/iap.tunnelResourceAccessor` and `roles/compute.osAdminLogin`.
- Installs systemd units for periodic tasks as **systemd timers** (preferred over crontab).
