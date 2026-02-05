project_id  = "clingen-dev"
region      = "us-east1"
zone        = "us-east1-b"
name_prefix = "gencc"


# Needed for certbot DNS-01 automation (TXT updates)
dns_managed_zone_name = "clingen-app"

# Optional: have Terraform create the A records too
enable_dns_records = true

# First Ansible run should SSH as an existing image user
ssh_user = "kferrite"


submit_hostname = "gencc-sub-stage.clingen.app"
search_hostname = "gencc-search-stage.clingen.app"
