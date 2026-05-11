resource "google_storage_bucket_iam_member" "stage_vm_prod_backup_object_viewer" {
  count = var.stage_restore_reader_service_account_email != "" ? 1 : 0

  bucket = var.gencc_data_bucket_name
  role   = "roles/storage.objectViewer"
  member = "serviceAccount:${var.stage_restore_reader_service_account_email}"

  depends_on = [module.gencc_infra]
}
