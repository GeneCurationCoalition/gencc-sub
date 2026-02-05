locals {
  submit_host = coalesce(var.submit_hostname, "submit.${var.domain}")
  search_host = coalesce(var.search_hostname, "search.${var.domain}")

  lb_ssl_cert_self_links = var.existing_managed_ssl_certificate_name != null ? [
    data.google_compute_managed_ssl_certificate.existing[0].self_link
    ] : [
    google_compute_managed_ssl_certificate.lb_cert[0].self_link
  ]
}

data "google_compute_managed_ssl_certificate" "existing" {
  count = var.existing_managed_ssl_certificate_name != null ? 1 : 0
  name  = var.existing_managed_ssl_certificate_name
}

resource "google_compute_managed_ssl_certificate" "lb_cert" {
  count = var.existing_managed_ssl_certificate_name == null ? 1 : 0
  name  = "${var.name_prefix}-cert"

  managed {
    domains = [
      local.submit_host,
      local.search_host,
    ]
  }
}
