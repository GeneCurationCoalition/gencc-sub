data "google_dns_managed_zone" "zone" {
  count = var.enable_dns_records ? 1 : 0
  name  = var.dns_managed_zone_name
}

resource "google_dns_record_set" "submit_a" {
  count        = var.enable_dns_records ? 1 : 0
  name         = "${local.submit_host}."
  managed_zone = data.google_dns_managed_zone.zone[0].name
  type         = "A"
  ttl          = 300
  rrdatas      = [google_compute_global_address.lb_ip.address]
}

resource "google_dns_record_set" "search_a" {
  count        = var.enable_dns_records ? 1 : 0
  name         = "${local.search_host}."
  managed_zone = data.google_dns_managed_zone.zone[0].name
  type         = "A"
  ttl          = 300
  rrdatas      = [google_compute_global_address.lb_ip.address]
}

