locals {
  submit_host = coalesce(var.submit_hostname, "submit.${var.domain}")
  search_host = coalesce(var.search_hostname, "search.${var.domain}")
}

resource "google_compute_managed_ssl_certificate" "lb_cert" {
  name = "${var.name_prefix}-cert"

  managed {
    domains = [
      local.submit_host,
      local.search_host,
    ]
  }
}

