resource "google_compute_network" "vpc" {
  name                    = "${var.name_prefix}-vpc"
  auto_create_subnetworks = false
}

resource "google_compute_subnetwork" "subnet" {
  name          = "${var.name_prefix}-subnet"
  ip_cidr_range = var.subnet_cidr
  region        = var.region
  network       = google_compute_network.vpc.id

  private_ip_google_access = true
}

resource "google_compute_firewall" "allow_iap_ssh" {
  name    = "${var.name_prefix}-allow-iap-ssh"
  network = google_compute_network.vpc.id

  direction = "INGRESS"
  priority  = 1000

  source_ranges = ["35.235.240.0/20"]
  target_tags   = ["${var.name_prefix}-vm"]

  allow {
    protocol = "tcp"
    ports    = ["22"]
  }
}

resource "google_compute_firewall" "allow_http_https" {
  name    = "${var.name_prefix}-allow-http-https"
  network = google_compute_network.vpc.id

  direction = "INGRESS"
  priority  = 1000

  source_ranges = ["0.0.0.0/0"]
  target_tags   = ["${var.name_prefix}-vm"]

  allow {
    protocol = "tcp"
    ports    = ["80", "443"]
  }
}
