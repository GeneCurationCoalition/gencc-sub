terraform {
  required_version = ">= 1.5.0"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = ">= 7.18.0, < 8.0.0"
    }
  }
}

provider "google" {
  project = var.project_id
  region  = var.region
  zone    = var.zone
}

module "gencc_infra" {
  source = "../modules/gencc-infra"

  project_id                  = var.project_id
  region                      = var.region
  zone                        = var.zone
  name_prefix                 = var.name_prefix
  machine_type                = var.machine_type
  boot_disk_type              = var.boot_disk_type
  boot_disk_gb                = var.boot_disk_gb
  gencc_data_bucket_name      = var.gencc_data_bucket_name
  gencc_data_bucket_location  = var.gencc_data_bucket_location
  subnet_cidr                 = var.subnet_cidr
  enable_github_actions_wif   = var.enable_github_actions_wif
  github_repository           = var.github_repository
  github_deploy_workflow_file = var.github_deploy_workflow_file
  github_deploy_branch        = var.github_deploy_branch
  github_wif_pool_id          = var.github_wif_pool_id
  github_wif_provider_id      = var.github_wif_provider_id
}

# ---------------------------------------------------------------------------
# moved blocks — map old root-module addresses to module addresses.
# Safe to remove after first successful `terraform apply` in this directory.
# ---------------------------------------------------------------------------

# compute.tf resources
moved {
  from = google_service_account.vm
  to   = module.gencc_infra.google_service_account.vm
}
moved {
  from = google_compute_address.vm_internal_ip
  to   = module.gencc_infra.google_compute_address.vm_internal_ip
}
moved {
  from = google_compute_address.vm_external_ip
  to   = module.gencc_infra.google_compute_address.vm_external_ip
}
moved {
  from = google_compute_instance.vm
  to   = module.gencc_infra.google_compute_instance.vm
}
moved {
  from = google_storage_bucket.gencc_data
  to   = module.gencc_infra.google_storage_bucket.gencc_data
}
moved {
  from = google_storage_bucket_iam_member.gencc_data_vm_object_admin
  to   = module.gencc_infra.google_storage_bucket_iam_member.gencc_data_vm_object_admin
}

# network.tf resources
moved {
  from = google_compute_network.vpc
  to   = module.gencc_infra.google_compute_network.vpc
}
moved {
  from = google_compute_subnetwork.subnet
  to   = module.gencc_infra.google_compute_subnetwork.subnet
}
moved {
  from = google_compute_firewall.allow_iap_ssh
  to   = module.gencc_infra.google_compute_firewall.allow_iap_ssh
}
moved {
  from = google_compute_firewall.allow_http_https
  to   = module.gencc_infra.google_compute_firewall.allow_http_https
}
