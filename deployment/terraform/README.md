# Terraform (GCP) — gencc production VM + HTTPS Load Balancer

This directory contains **declarative Terraform** to provision the infrastructure described in `ai/2026-02-05T001502Z-synthesized-gencc-deploy-plan.GEMINI.md`.

This repo change is **safe to commit**: it does not contain secrets and does not perform any deployments by itself.

## What it creates (high level)
- A dedicated VPC + subnet
- Cloud NAT (so the VM can `apt update` / pull container images without a public IP)
- A single Ubuntu VM **without** an external IP
- An unmanaged instance group containing that VM with **named ports**:
  - `gencc-sub` → `8080`
  - `gencc-search` → `8081`
- External HTTPS Load Balancer with host-based routing:
  - `submit.<domain>` → backend `gencc-sub`
  - `search.<domain>` → backend `gencc-search`
- Firewall rules:
  - IAP SSH (`22/tcp`) from `35.235.240.0/20`
  - LB + health checks to `8080/8081` from `35.191.0.0/16` and `130.211.0.0/22`

## Usage (operator runbook)
1. Create `terraform.tfvars` (example keys are in `variables.tf`).
2. Run:
   - `terraform init`
   - `terraform plan`
   - `terraform apply`

### Managed certificate note
By default, `ssl.tf` creates a new `google_compute_managed_ssl_certificate` for the `submit.<domain>` and `search.<domain>` hostnames.

If you already have a suitable Google-managed certificate created outside Terraform (e.g. a wildcard `*.clingen.app` cert named `clingen-app`), set:
- `existing_managed_ssl_certificate_name = "clingen-app"`

In that mode, Terraform will *reference* the existing certificate (data source) and will not create a new one.

## Outputs
- `lb_ip` — external IP for DNS
- `vm_internal_ip` — internal IP used by Ansible inventory
- `ansible_inventory` — inventory snippet using IAP tunneling
- `ansible_ssh_config` — SSH config snippet using IAP tunneling
