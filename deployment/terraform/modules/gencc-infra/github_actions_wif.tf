resource "google_service_account" "deploy" {
  count = var.enable_github_actions_wif ? 1 : 0

  account_id   = replace("${var.name_prefix}-deploy", "_", "-")
  display_name = "GenCC Deploy (GitHub Actions)"
}

resource "google_iam_workload_identity_pool" "github" {
  count = var.enable_github_actions_wif ? 1 : 0

  workload_identity_pool_id = var.github_wif_pool_id
  display_name              = "GenCC GitHub Actions OIDC"
  description               = "Allows GitHub Actions in approved repos to impersonate GenCC deploy service account."
  disabled                  = false
}

resource "google_iam_workload_identity_pool_provider" "github" {
  count = var.enable_github_actions_wif ? 1 : 0

  workload_identity_pool_id          = google_iam_workload_identity_pool.github[0].workload_identity_pool_id
  workload_identity_pool_provider_id = var.github_wif_provider_id
  display_name                       = "GenCC GitHub OIDC Provider"
  description                        = "OIDC provider for token.actions.githubusercontent.com."

  attribute_mapping = {
    "google.subject"          = "assertion.sub"
    "attribute.actor"         = "assertion.actor"
    "attribute.repository"    = "assertion.repository"
    "attribute.ref"           = "assertion.ref"
    "attribute.workflow_ref"  = "assertion.workflow_ref"
    "attribute.repository_id" = "assertion.repository_id"
  }

  attribute_condition = "assertion.repository == '${var.github_repository}' && assertion.workflow_ref == '${var.github_repository}/.github/workflows/${var.github_deploy_workflow_file}@refs/heads/${var.github_deploy_branch}'"

  oidc {
    issuer_uri = "https://token.actions.githubusercontent.com"
  }
}

resource "google_service_account_iam_member" "deploy_wif_user" {
  count = var.enable_github_actions_wif ? 1 : 0

  service_account_id = google_service_account.deploy[0].name
  role               = "roles/iam.workloadIdentityUser"
  member             = "principalSet://iam.googleapis.com/${google_iam_workload_identity_pool.github[0].name}/attribute.repository/${var.github_repository}"
}

resource "google_iap_tunnel_instance_iam_member" "deploy_iap_tunnel_vm" {
  count = var.enable_github_actions_wif ? 1 : 0

  project  = var.project_id
  zone     = var.zone
  instance = google_compute_instance.vm.name
  role     = "roles/iap.tunnelResourceAccessor"
  member   = "serviceAccount:${google_service_account.deploy[0].email}"
}

resource "google_compute_instance_iam_member" "deploy_os_admin_login_vm" {
  count = var.enable_github_actions_wif ? 1 : 0

  project       = var.project_id
  zone          = var.zone
  instance_name = google_compute_instance.vm.name
  role          = "roles/compute.osAdminLogin"
  member        = "serviceAccount:${google_service_account.deploy[0].email}"
}

resource "google_project_iam_member" "deploy_compute_viewer" {
  count = var.enable_github_actions_wif ? 1 : 0

  project = var.project_id
  role    = "roles/compute.viewer"
  member  = "serviceAccount:${google_service_account.deploy[0].email}"
}

resource "google_service_account_iam_member" "deploy_vm_sa_user" {
  count = var.enable_github_actions_wif ? 1 : 0

  service_account_id = google_service_account.vm.name
  role               = "roles/iam.serviceAccountUser"
  member             = "serviceAccount:${google_service_account.deploy[0].email}"
}
