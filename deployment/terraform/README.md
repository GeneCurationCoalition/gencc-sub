# Terraform (GCP) — GenCC VM (nginx + certbot TLS termination)

This directory contains **declarative Terraform** to provision the infrastructure described in `ai/2026-02-05T001502Z-synthesized-gencc-deploy-plan.GEMINI.md`.

This repo change is **safe to commit**: it does not contain secrets and does not perform any deployments by itself.

## What it creates (high level)
- A dedicated VPC + subnet
- A single Ubuntu VM with a **static external IP**
- Ingress firewall for `80/tcp` and `443/tcp` (nginx terminates TLS on the VM)
- Firewall rules:
  - IAP SSH (`22/tcp`) from `35.235.240.0/20`
- A managed GCS data bucket (default: `gencc-dev`, location `us-east1`)
- IAM: grants the VM service account `roles/storage.objectAdmin` on the managed data bucket
- Optional GitHub Actions OIDC Workload Identity Federation resources for CI deploys

## Environments

Each environment has its own `.tfvars` file and separate Terraform state:

| Environment | tfvars file | GCP project | VM name |
|-------------|-------------|-------------|---------|
| Staging | `staging.tfvars` | `clingen-dev` | `gencc-vm` |
| Production | `production.tfvars` | `clingen-dx` | `gencc-prod-vm` |

## Usage (operator runbook)
1. Select the appropriate `.tfvars` file for your environment.
2. Run:
   ```bash
   terraform init
   terraform plan  -var-file=staging.tfvars      # or production.tfvars
   terraform apply -var-file=staging.tfvars      # or production.tfvars
   ```

### OS Login
This VM is configured with OS Login enabled (`enable-oslogin=TRUE`) and project SSH keys blocked (`block-project-ssh-keys=TRUE`). Before running Ansible, determine your OS Login username:

- `gcloud compute os-login describe-profile --project <PROJECT> --format='value(posixAccounts[0].username)'`

Use this username as `ansible_user` in your inventory (or `User` in SSH config).

Your account will also need `roles/compute.osAdminLogin` (for sudo) and `roles/iap.tunnelResourceAccessor` (for IAP SSH).

### TLS termination
TLS termination is handled on the VM (nginx + certbot HTTP-01 webroot challenges) via Ansible in `deployment/ansible/`.

### Managed GenCC data bucket
Terraform creates a managed GCS bucket for GenCC data artifacts:

- `gencc_data_bucket_name` (default `gencc-dev`)
- `gencc_data_bucket_location` (default `us-east1`)

The VM service account receives object-level read/write/delete permissions (`roles/storage.objectAdmin`) on this bucket. This allows Ansible `restore_and_migrate` runs to read dumps from `gs://` URLs using VM identity.

Because GCS bucket names are globally unique, update `gencc_data_bucket_name` in the `.tfvars` file if `gencc-dev` is unavailable.

### Optional: GitHub Actions Workload Identity Federation
To allow GitHub Actions to deploy through IAP + OS Login without static GCP keys:

1. Enable in the `.tfvars` file:
   - `enable_github_actions_wif = true`
2. Set repository identity:
   - `github_repository = "GeneCurationCoalition/gencc-sub"`
3. Set trusted workflow + branch:
   - `github_deploy_workflow_file = "deploy-via-ansible.yml"`
   - `github_deploy_branch = "main"`
4. Apply Terraform.

Terraform will create:
- A dedicated deploy service account (`<name_prefix>-deploy`)
- A Workload Identity Pool + OIDC provider for `token.actions.githubusercontent.com`
- WIF trust condition that allows only:
  - repository: `github_repository`
  - workflow_ref: `${github_repository}/.github/workflows/${github_deploy_workflow_file}@refs/heads/${github_deploy_branch}`
- IAM bindings:
  - `roles/iam.workloadIdentityUser` (GitHub principal set -> deploy SA)
  - `roles/iap.tunnelResourceAccessor` on the configured VM only (deploy SA)
  - `roles/compute.osAdminLogin` on the configured VM only (deploy SA)
  - `roles/compute.viewer` at project scope (deploy SA, retained for operational lookup/read)
  - `roles/iam.serviceAccountUser` on VM service account (deploy SA)

Use these outputs in GitHub Actions auth setup:
- `github_deploy_service_account`
- `github_workload_identity_provider`

## Outputs
- `vm_internal_ip` — internal IP used by Ansible inventory
- `vm_external_ip` — external IP (use for DNS A records at your registrar)
- `gencc_data_bucket_name` — managed data bucket name used for GenCC artifacts
- `ansible_inventory` — inventory snippet using IAP tunneling
- `ansible_ssh_config` — SSH config snippet using IAP tunneling
- `github_deploy_service_account` — deploy SA email for CI auth (when WIF enabled)
- `github_workload_identity_provider` — WIF provider resource name for `google-github-actions/auth` (when WIF enabled)
