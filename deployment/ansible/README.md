# Ansible — VM configuration + Podman/Quadlet deployment

This is a **local-only** automation scaffold that matches the deployment architecture in `ai/2026-02-05T001502Z-synthesized-gencc-deploy-plan.GEMINI.md`.

It is intentionally written so it can be run safely after Terraform provisions the VM (SSH via IAP is supported).

## Layout
- `playbooks/site.yml` — main entrypoint
- `inventories/production.ini.example` — example inventory (Terraform also outputs one)
- `inventories/group_vars/` — non-secret defaults + a vault template
- `roles/` — baseline, MySQL, quadlet units, timers

## Running (operator runbook)
1. Create an inventory file (or paste the Terraform output).
2. Create `inventories/group_vars/all/vault.yml` from the template and encrypt it:
   - `ansible-vault encrypt inventories/group_vars/all/vault.yml`
3. Run:
   - `ansible-playbook -i inventories/production.ini playbooks/site.yml --ask-vault-pass`

## Notes
- Uses **rootless Podman** for the app containers via a dedicated `gencc` user and `loginctl enable-linger`.
- Uses **host MySQL** and allows container connections via `slirp4netns` (`DB_HOST=10.0.2.2`).
- Terminates TLS on the VM using **nginx + certbot** (Let’s Encrypt). Wildcard issuance (`*.clingen.app`) uses DNS-01 via **GCP Cloud DNS**.
- SSH access is expected via **IAP tunneling** + **OS Login**; ensure your operator account has `roles/iap.tunnelResourceAccessor` and `roles/compute.osAdminLogin`.
- Installs systemd units for periodic tasks as **systemd timers** (preferred over crontab).
