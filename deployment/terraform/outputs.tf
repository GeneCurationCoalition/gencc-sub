output "vm_internal_ip" {
  value       = google_compute_address.vm_internal_ip.address
  description = "Internal IP address of the VM."
}

output "vm_external_ip" {
  value       = google_compute_address.vm_external_ip.address
  description = "External IP address of the VM."
}

output "vm_name" {
  value       = google_compute_instance.vm.name
  description = "Compute instance name."
}

output "vm_service_account" {
  value       = google_service_account.vm.email
  description = "Service account email attached to the VM."
}

output "gencc_data_bucket_name" {
  value       = google_storage_bucket.gencc_data.name
  description = "Managed GCS bucket name for GenCC data artifacts."
}

output "ansible_inventory" {
  value       = <<-EOT
    [gcp_vms]
    ${google_compute_instance.vm.name} ansible_host=${google_compute_address.vm_internal_ip.address} ansible_zone=${var.zone}

    [gcp_vms:vars]
    # Set to your OS Login username (from `gcloud compute os-login describe-profile`).
    ansible_user=<OS_LOGIN_USERNAME>
    ansible_ssh_common_args='-o ProxyCommand="gcloud compute start-iap-tunnel ${google_compute_instance.vm.name} %p --listen-on-stdin --project=${var.project_id} --zone=${var.zone}"'
  EOT
  description = "Inventory snippet for IAP-tunneled Ansible SSH."
}

output "ansible_ssh_config" {
  value       = <<-EOT
    Host ${google_compute_instance.vm.name}
      HostName ${google_compute_instance.vm.name}
      User <OS_LOGIN_USERNAME>
      ProxyCommand gcloud compute start-iap-tunnel ${google_compute_instance.vm.name} %p --listen-on-stdin --project ${var.project_id} --zone ${var.zone}
  EOT
  description = "SSH config snippet for IAP tunneling."
}

output "github_deploy_service_account" {
  value       = var.enable_github_actions_wif ? google_service_account.deploy[0].email : null
  description = "Service account email for GitHub Actions deploy workflow (null when WIF is disabled)."
}

output "github_workload_identity_provider" {
  value       = var.enable_github_actions_wif ? google_iam_workload_identity_pool_provider.github[0].name : null
  description = "Full Workload Identity Provider resource name for GitHub OIDC auth action (null when WIF is disabled)."
}
