resource "google_compute_global_address" "lb_ip" {
  name = "${var.name_prefix}-lb-ip"
}

resource "google_compute_health_check" "sub" {
  name               = "${var.name_prefix}-hc-sub"
  check_interval_sec = 10
  timeout_sec        = 5

  http_health_check {
    port         = 8080
    request_path = "/-/healthz"
  }
}

resource "google_compute_health_check" "search" {
  name               = "${var.name_prefix}-hc-search"
  check_interval_sec = 10
  timeout_sec        = 5

  http_health_check {
    port         = 8081
    request_path = "/-/healthz"
  }
}

resource "google_compute_backend_service" "sub" {
  name                  = "${var.name_prefix}-bs-sub"
  protocol              = "HTTP"
  load_balancing_scheme = "EXTERNAL"
  port_name             = "gencc-sub"
  health_checks         = [google_compute_health_check.sub.id]

  backend {
    group = google_compute_instance_group.ig.self_link
  }
}

resource "google_compute_backend_service" "search" {
  name                  = "${var.name_prefix}-bs-search"
  protocol              = "HTTP"
  load_balancing_scheme = "EXTERNAL"
  port_name             = "gencc-search"
  health_checks         = [google_compute_health_check.search.id]

  backend {
    group = google_compute_instance_group.ig.self_link
  }
}

resource "google_compute_url_map" "url_map" {
  name = "${var.name_prefix}-urlmap"

  default_service = google_compute_backend_service.sub.id

  host_rule {
    hosts        = [local.submit_host]
    path_matcher = "submit"
  }

  host_rule {
    hosts        = [local.search_host]
    path_matcher = "search"
  }

  path_matcher {
    name            = "submit"
    default_service = google_compute_backend_service.sub.id
  }

  path_matcher {
    name            = "search"
    default_service = google_compute_backend_service.search.id
  }
}

resource "google_compute_target_https_proxy" "https_proxy" {
  name             = "${var.name_prefix}-https-proxy"
  url_map          = google_compute_url_map.url_map.id
  ssl_certificates = [google_compute_managed_ssl_certificate.lb_cert.id]
}

resource "google_compute_global_forwarding_rule" "https" {
  name                  = "${var.name_prefix}-https-fr"
  ip_address            = google_compute_global_address.lb_ip.address
  port_range            = "443"
  load_balancing_scheme = "EXTERNAL"
  target                = google_compute_target_https_proxy.https_proxy.id
}

# Optional HTTP -> HTTPS redirect
resource "google_compute_url_map" "http_redirect" {
  name = "${var.name_prefix}-http-redirect"

  default_url_redirect {
    https_redirect = true
    strip_query    = false
  }
}

resource "google_compute_target_http_proxy" "http_proxy" {
  name    = "${var.name_prefix}-http-proxy"
  url_map = google_compute_url_map.http_redirect.id
}

resource "google_compute_global_forwarding_rule" "http" {
  name                  = "${var.name_prefix}-http-fr"
  ip_address            = google_compute_global_address.lb_ip.address
  port_range            = "80"
  load_balancing_scheme = "EXTERNAL"
  target                = google_compute_target_http_proxy.http_proxy.id
}

