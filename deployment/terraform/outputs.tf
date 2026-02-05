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

output "ansible_inventory" {
  value       = <<-EOT
    [gcp_vms]
    ${google_compute_instance.vm.name} ansible_host=${google_compute_address.vm_internal_ip.address} ansible_zone=${var.zone}

    [gcp_vms:vars]
    ansible_user=${var.ssh_user}
    ansible_ssh_common_args='-o ProxyCommand="gcloud compute start-iap-tunnel ${google_compute_instance.vm.name} %p --listen-on-stdin --project=${var.project_id} --zone=${var.zone}"'
  EOT
  description = "Inventory snippet for IAP-tunneled Ansible SSH."
}

output "ansible_ssh_config" {
  value       = <<-EOT
    Host ${google_compute_instance.vm.name}
      HostName ${google_compute_instance.vm.name}
      User ${var.ssh_user}
      ProxyCommand gcloud compute start-iap-tunnel ${google_compute_instance.vm.name} %p --listen-on-stdin --project ${var.project_id} --zone ${var.zone}
  EOT
  description = "SSH config snippet for IAP tunneling."
}
