data "google_compute_image" "ubuntu" {
  family  = "ubuntu-2404-lts-amd64"
  project = "ubuntu-os-cloud"
}

resource "google_service_account" "vm" {
  account_id   = replace("${var.name_prefix}-vm", "_", "-")
  display_name = "GenCC VM"
}

resource "google_compute_address" "vm_internal_ip" {
  name         = "${var.name_prefix}-vm-internal-ip"
  address_type = "INTERNAL"
  subnetwork   = google_compute_subnetwork.subnet.id
  region       = var.region
}

resource "google_compute_address" "vm_external_ip" {
  name         = "${var.name_prefix}-vm-external-ip"
  address_type = "EXTERNAL"
  region       = var.region
}

resource "google_compute_instance" "vm" {
  name         = "${var.name_prefix}-vm"
  machine_type = var.machine_type
  zone         = var.zone

  tags = ["${var.name_prefix}-vm"]

  boot_disk {
    initialize_params {
      image = data.google_compute_image.ubuntu.self_link
      size  = var.boot_disk_gb
      type  = "pd-balanced"
    }
  }

  network_interface {
    subnetwork = google_compute_subnetwork.subnet.id
    network_ip = google_compute_address.vm_internal_ip.address
    access_config {
      nat_ip = google_compute_address.vm_external_ip.address
    }
  }

  service_account {
    email  = google_service_account.vm.email
    scopes = ["https://www.googleapis.com/auth/cloud-platform"]
  }

  metadata = {
    enable-oslogin         = "TRUE"
    block-project-ssh-keys = "TRUE"
  }
}

resource "google_storage_bucket" "gencc_data" {
  name                        = var.gencc_data_bucket_name
  location                    = var.gencc_data_bucket_location
  storage_class               = "STANDARD"
  uniform_bucket_level_access = true
  public_access_prevention    = "enforced"
}

resource "google_storage_bucket_iam_member" "gencc_data_vm_object_admin" {
  bucket = google_storage_bucket.gencc_data.name
  role   = "roles/storage.objectAdmin"
  member = "serviceAccount:${google_service_account.vm.email}"
}

resource "google_storage_bucket_iam_member" "backup_writer" {
  count  = var.backup_bucket_name != null ? 1 : 0
  bucket = var.backup_bucket_name
  role   = "roles/storage.objectCreator"
  member = "serviceAccount:${google_service_account.vm.email}"
}

resource "google_storage_bucket_iam_member" "backup_reader" {
  count  = var.backup_bucket_name != null ? 1 : 0
  bucket = var.backup_bucket_name
  role   = "roles/storage.objectViewer"
  member = "serviceAccount:${google_service_account.vm.email}"
}

resource "google_storage_bucket_iam_member" "private_reader" {
  count  = var.private_config_bucket_name != null ? 1 : 0
  bucket = var.private_config_bucket_name
  role   = "roles/storage.objectViewer"
  member = "serviceAccount:${google_service_account.vm.email}"
}
