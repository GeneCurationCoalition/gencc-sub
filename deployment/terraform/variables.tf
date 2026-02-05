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

variable "ssh_user" {
  type        = string
  description = "OS Login Linux username Ansible will SSH as (see `gcloud compute os-login describe-profile`)."
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
