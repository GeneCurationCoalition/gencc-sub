project_id  = "clingen-dev"
region      = "us-east1"
zone        = "us-east1-b"
name_prefix = "gencc"


# Needed for certbot DNS-01 automation (TXT updates)
dns_managed_zone_name = "clingen-app"

# Optional: have Terraform create the A records too
enable_dns_records = true

submit_hostname = "gencc-sub-stage.clingen.app"
search_hostname = "gencc-search-stage.clingen.app"

# Optional: enable GitHub Actions OIDC deploy identity.
enable_github_actions_wif   = true
github_repository           = "GeneCurationCoalition/gencc-sub"
github_deploy_workflow_file = "deploy-via-ansible.yml"
github_deploy_branch        = "kf/fresh-deployment" # TODO change back to main after testing
github_wif_pool_id          = "gencc-sub-gha"
github_wif_provider_id      = "gencc-sub-oidc"
