output "vm_external_ip" {
  value       = google_compute_address.vm_external_ip.address
  description = "External IP address of the VM."
}

output "vm_service_account" {
  value       = google_service_account.vm.email
  description = "Service account email attached to the VM."
}

output "github_deploy_service_account" {
  value       = var.enable_github_actions_wif ? google_service_account.deploy[0].email : null
  description = "Service account email for GitHub Actions deploy workflow (null when WIF is disabled)."
}

output "github_workload_identity_provider" {
  value       = var.enable_github_actions_wif ? google_iam_workload_identity_pool_provider.github[0].name : null
  description = "Full Workload Identity Provider resource name for GitHub OIDC auth action (null when WIF is disabled)."
}
