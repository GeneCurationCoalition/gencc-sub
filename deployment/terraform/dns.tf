data "google_dns_managed_zone" "zone" {
  count = var.dns_managed_zone_name != null ? 1 : 0
  name  = var.dns_managed_zone_name
}

resource "google_dns_record_set" "submit_a" {
  count        = var.enable_dns_records ? 1 : 0
  name         = "${var.submit_hostname}."
  managed_zone = data.google_dns_managed_zone.zone[0].name
  type         = "A"
  ttl          = 300
  rrdatas      = [google_compute_address.vm_external_ip.address]
}

resource "google_dns_record_set" "search_a" {
  count        = var.enable_dns_records ? 1 : 0
  name         = "${var.search_hostname}."
  managed_zone = data.google_dns_managed_zone.zone[0].name
  type         = "A"
  ttl          = 300
  rrdatas      = [google_compute_address.vm_external_ip.address]
}

resource "google_dns_managed_zone_iam_member" "vm_dns_admin" {
  count        = var.dns_managed_zone_name != null ? 1 : 0
  managed_zone = data.google_dns_managed_zone.zone[0].name
  role         = "roles/dns.admin"
  member       = "serviceAccount:${google_service_account.vm.email}"
}
