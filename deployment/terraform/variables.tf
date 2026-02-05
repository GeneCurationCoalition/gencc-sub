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

variable "domain" {
  type        = string
  description = "Base domain for host-based routing (e.g. clingen.app)."
}

variable "submit_hostname" {
  type        = string
  description = "Hostname for gencc-sub (e.g. submit.clingen.app)."
  default     = null
}

variable "search_hostname" {
  type        = string
  description = "Hostname for gencc-search (e.g. search.clingen.app)."
  default     = null
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
  description = "Linux username Ansible will SSH as (created by Ansible)."
  default     = "gencc"
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
  description = "Existing Cloud DNS managed zone name to place records in (required if enable_dns_records=true)."
  default     = null
}

variable "existing_managed_ssl_certificate_name" {
  type        = string
  description = "Optional: name of an existing google_compute_managed_ssl_certificate to attach to the HTTPS proxy instead of creating a new one."
  default     = null
}
