# Terraform (GCP) — GenCC Infrastructure

Declarative Terraform for GenCC GCP infrastructure, organized as a shared module with per-environment root configurations.

## Directory Structure

```
deployment/terraform/
├── modules/gencc-infra/          # Shared infrastructure module
│   ├── compute.tf                # VM, service account, GCS bucket, IAM
│   ├── github_actions_wif.tf     # Optional GitHub OIDC (count-gated)
│   ├── network.tf                # VPC, subnet, firewalls
│   ├── outputs.tf                # Module outputs
│   ├── variables.tf              # Module input variables
│   └── versions.tf               # required_providers
├── staging/                      # Staging environment (clingen-dev)
│   ├── main.tf                   # Provider config + module call
│   ├── variables.tf              # Variable declarations for tfvars
│   ├── outputs.tf                # Passthrough module outputs
│   └── terraform.tfvars          # Staging values
├── production/                   # Production environment (clingen-dx)
│   ├── main.tf
│   ├── variables.tf
│   ├── outputs.tf
│   └── terraform.tfvars
└── README.md
```

## What it creates (per environment)

- A dedicated VPC + subnet
- A single Ubuntu VM with a static external IP
- Ingress firewall for `80/tcp` and `443/tcp` (nginx terminates TLS on the VM)
- IAP SSH (`22/tcp`) from `35.235.240.0/20`
- A managed GCS data bucket with VM service account `roles/storage.objectAdmin`
- Optional GitHub Actions OIDC Workload Identity Federation (staging only)

## Environments

| Environment | Directory      | GCP Project  | VM Name        |
|-------------|----------------|--------------|----------------|
| Staging     | `staging/`     | clingen-dev  | gencc-vm       |
| Production  | `production/`  | clingen-dx   | gencc-prod-vm  |

## Usage

Each environment is a self-contained Terraform root module. `cd` into the environment directory and run commands directly — no `-var-file` or `-state` flags needed.

### Staging

```bash
cd deployment/terraform/staging
terraform init
terraform plan
terraform apply
```

### Production

```bash
cd deployment/terraform/production
terraform init
terraform plan
terraform apply
```

### Viewing outputs

```bash
cd deployment/terraform/staging   # or production/
terraform output
terraform output vm_external_ip
```

## OS Login

VMs are configured with OS Login enabled and project SSH keys blocked. Determine your OS Login username:

```bash
gcloud compute os-login describe-profile \
  --project <PROJECT> \
  --format='value(posixAccounts[0].username)'
```

Your account needs `roles/compute.osAdminLogin` and `roles/iap.tunnelResourceAccessor`.

## TLS termination

TLS termination is handled on the VM (nginx + certbot HTTP-01 webroot challenges) via Ansible in `deployment/ansible/`.

## Managed GenCC data bucket

Terraform creates a managed GCS bucket for GenCC data artifacts (backups, restores, etc.):

- `gencc_data_bucket_name` (staging: `gencc-dev`, production: `gencc-prod`)
- `gencc_data_bucket_location` (default: `us-east1`)

The VM service account receives `roles/storage.objectAdmin` on this bucket.

## GitHub Actions WIF

Staging has `enable_github_actions_wif = true` which creates:

- A dedicated deploy service account (`<name_prefix>-deploy`)
- A Workload Identity Pool + OIDC provider for `token.actions.githubusercontent.com`
- IAM bindings for IAP tunnel access, OS Login, and compute viewer

See staging outputs for values needed in GitHub Actions:

- `github_deploy_service_account`
- `github_workload_identity_provider`

## Remote State

Terraform state is stored in per-project GCS buckets with versioning enabled:

| Environment | Bucket              | Prefix       | GCP Project  |
|-------------|---------------------|--------------|--------------|
| Staging     | `gencc-dev-tfstate` | `staging`    | clingen-dev  |
| Production  | `gencc-prod-tfstate`| `production` | clingen-dx   |

These buckets are **not Terraform-managed** (chicken-and-egg). They were created once via `gcloud`:

```bash
gcloud storage buckets create gs://gencc-dev-tfstate \
  --project=clingen-dev --location=us-east1 \
  --uniform-bucket-level-access --public-access-prevention
gcloud storage buckets update gs://gencc-dev-tfstate --versioning
```

`terraform init` configures the backend automatically from the `backend "gcs"` block in each environment's `main.tf`. The GCS backend provides built-in state locking — concurrent `terraform apply` runs will block rather than corrupt state.

## Outputs

| Output                              | Description                                        |
|-------------------------------------|----------------------------------------------------|
| `vm_external_ip`                    | External IP for DNS A records                      |
| `vm_service_account`                | VM service account email                           |
| `github_deploy_service_account`     | Deploy SA email (null if WIF disabled)             |
| `github_workload_identity_provider` | WIF provider resource name (null if WIF disabled)  |
