variable "project_id" {
  type        = string
  description = "GCP project ID."
}

variable "region" {
  type        = string
  description = "GCP region (e.g. us-central1)."
}

variable "zone" {
  type        = string
  description = "GCP zone (e.g. us-central1-a)."
}

variable "name_prefix" {
  type        = string
  description = "Prefix for resource names."
  default     = "gencc"
}

variable "submit_hostname" {
  type        = string
  description = "Hostname for gencc-sub (e.g. gencc-sub-stage.clingen.app)."

  validation {
    condition     = !endswith(var.submit_hostname, ".")
    error_message = "submit_hostname must not include a trailing dot."
  }
}

variable "search_hostname" {
  type        = string
  description = "Hostname for gencc-search (e.g. gencc-search-stage.clingen.app)."

  validation {
    condition     = !endswith(var.search_hostname, ".")
    error_message = "search_hostname must not include a trailing dot."
  }
}

variable "machine_type" {
  type        = string
  description = "Compute instance machine type."
  default     = "e2-standard-2"
}

variable "boot_disk_gb" {
  type        = number
  description = "Boot disk size (GB)."
  default     = 200
}

variable "backup_bucket_name" {
  type        = string
  description = "Optional: existing GCS bucket name for DB backups. If set, Terraform grants the VM service account write access."
  default     = null
}

variable "private_config_bucket_name" {
  type        = string
  description = "Optional: existing GCS bucket name for private YAML configs. If set, Terraform grants the VM service account read access."
  default     = null
}

variable "subnet_cidr" {
  type        = string
  description = "CIDR for the subnet."
  default     = "10.30.0.0/24"
}

variable "enable_dns_records" {
  type        = bool
  description = "If true, create A records for submit/search hostnames."
  default     = false
}

variable "dns_managed_zone_name" {
  type        = string
  description = "Existing Cloud DNS managed zone name (used for optional A records and to grant the VM service account DNS permissions for certbot DNS-01 automation)."
  default     = null

  validation {
    condition     = var.enable_dns_records == false || var.dns_managed_zone_name != null
    error_message = "dns_managed_zone_name must be set when enable_dns_records=true."
  }
}

# -----------------------------------------------------------------------------
# GitHub Actions deployment identity (OIDC/WIF)
# -----------------------------------------------------------------------------
variable "enable_github_actions_wif" {
  type        = bool
  description = "If true, create a dedicated deploy service account and GitHub OIDC Workload Identity Federation resources."
  default     = false
}

variable "github_repository" {
  type        = string
  description = "GitHub repository allowed to impersonate deploy SA via WIF (owner/repo)."
  default     = "GeneCurationCoalition/gencc-sub"
}

variable "github_deploy_workflow_file" {
  type        = string
  description = "Workflow file under .github/workflows that is allowed to impersonate deploy SA."
  default     = "deploy-via-ansible.yml"
}

variable "github_deploy_branch" {
  type        = string
  description = "Git branch name trusted for deploy workflow impersonation."
  default     = "main"
}

variable "github_wif_pool_id" {
  type        = string
  description = "Workload Identity Pool ID for GitHub OIDC."
  default     = "gencc-sub-gha"
}

variable "github_wif_provider_id" {
  type        = string
  description = "Workload Identity Provider ID within the GitHub pool."
  default     = "gencc-sub-oidc"
}
