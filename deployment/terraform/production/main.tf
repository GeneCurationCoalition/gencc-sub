terraform {
  required_version = ">= 1.5.0"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = ">= 7.18.0, < 8.0.0"
    }
  }

  backend "gcs" {
    bucket = "gencc-prod-tfstate"
    prefix = "production"
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
