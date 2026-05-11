project_id  = "clingen-dx"
region      = "us-east1"
zone        = "us-east1-b"
name_prefix = "gencc-prod"

machine_type   = "c4-highcpu-4"
boot_disk_type = "hyperdisk-balanced"

gencc_data_bucket_name     = "gencc-prod"
gencc_data_bucket_location = "us-east1"

stage_restore_reader_service_account_email = "gencc-vm@clingen-dev.iam.gserviceaccount.com"
