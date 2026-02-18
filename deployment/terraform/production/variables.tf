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

variable "machine_type" {
  type        = string
  description = "Compute instance machine type."
  default     = "e2-standard-2"
}

variable "boot_disk_type" {
  type        = string
  description = "Boot disk type (e.g. pd-balanced, hyperdisk-balanced)."
  default     = "pd-balanced"
}

variable "boot_disk_gb" {
  type        = number
  description = "Boot disk size (GB)."
  default     = 200
}

variable "gencc_data_bucket_name" {
  type        = string
  description = "Managed GCS bucket name for GenCC data artifacts (restore/backup sources)."
  default     = "gencc-dev"
}

variable "gencc_data_bucket_location" {
  type        = string
  description = "Location/region for the managed GenCC data bucket."
  default     = "us-east1"
}

variable "subnet_cidr" {
  type        = string
  description = "CIDR for the subnet."
  default     = "10.30.0.0/24"
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
