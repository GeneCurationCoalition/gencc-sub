output "vm_external_ip" {
  value       = module.gencc_infra.vm_external_ip
  description = "External IP address of the VM."
}

output "vm_service_account" {
  value       = module.gencc_infra.vm_service_account
  description = "Service account email attached to the VM."
}

output "github_deploy_service_account" {
  value       = module.gencc_infra.github_deploy_service_account
  description = "Service account email for GitHub Actions deploy workflow (null when WIF is disabled)."
}

output "github_workload_identity_provider" {
  value       = module.gencc_infra.github_workload_identity_provider
  description = "Full Workload Identity Provider resource name for GitHub OIDC auth action (null when WIF is disabled)."
}
