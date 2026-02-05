# GenCC Database Backup Setup Guide

This guide covers the complete setup of nightly database backups for the GenCC Submission Portal production environment on GCP.

## Overview

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────────┐
│   GCP VM        │     │                  │     │   Cloud Storage     │
│                 │     │   Cron Job       │     │                     │
│  MySQL Database │────▶│   (2 AM UTC)     │────▶│  gencc-backups/     │
│                 │     │                  │     │    database-backups/│
└─────────────────┘     └──────────────────┘     └─────────────────────┘
                                                          │
                                                          ▼
                                                 ┌─────────────────────┐
                                                 │  Lifecycle Policy   │
                                                 │  (30-day retention) │
                                                 └─────────────────────┘
```

## Prerequisites

- GCP project with billing enabled
- VM instance running the GenCC application
- `gcloud` CLI installed and authenticated
- MySQL client and `mysqldump` installed

---

## Step 1: Create the GCS Bucket

### Option A: Using gcloud CLI

```bash
# Set your project
export PROJECT_ID="your-gcp-project-id"
export BUCKET_NAME="gencc-backups"
export REGION="us-central1"  # Choose your preferred region

# Create the bucket with uniform bucket-level access
gcloud storage buckets create gs://${BUCKET_NAME} \
    --project=${PROJECT_ID} \
    --location=${REGION} \
    --uniform-bucket-level-access \
    --public-access-prevention

# Enable versioning (recommended for extra protection)
gcloud storage buckets update gs://${BUCKET_NAME} --versioning
```

### Option B: Using GCP Console

1. Go to **Cloud Storage** > **Buckets**
2. Click **Create Bucket**
3. Configure:
   - **Name**: `gencc-backups` (must be globally unique)
   - **Location type**: Region (e.g., `us-central1`)
   - **Storage class**: Standard
   - **Access control**: Uniform
   - **Protection**: Enable versioning
4. Click **Create**

---

## Step 2: Configure Lifecycle Policy

Set up automatic deletion of old backups to manage storage costs.

### Using gcloud CLI

Create a lifecycle configuration file:

```bash
cat > /tmp/lifecycle.json << 'EOF'
{
  "rule": [
    {
      "action": {"type": "Delete"},
      "condition": {
        "age": 30,
        "matchesPrefix": ["database-backups/"]
      }
    },
    {
      "action": {"type": "Delete"},
      "condition": {
        "age": 7,
        "isLive": false
      }
    }
  ]
}
EOF

# Apply the lifecycle policy
gcloud storage buckets update gs://${BUCKET_NAME} \
    --lifecycle-file=/tmp/lifecycle.json
```

### Lifecycle Rules Explained

| Rule | Purpose |
|------|---------|
| Delete after 30 days | Remove database backups older than 30 days |
| Delete non-current versions after 7 days | Clean up old versions from versioning |

---

## Step 3: Create Service Account

Create a dedicated service account for backups with minimal permissions.

```bash
# Create service account
gcloud iam service-accounts create gencc-backup \
    --display-name="GenCC Backup Service Account" \
    --project=${PROJECT_ID}

# Get the service account email
export SA_EMAIL="gencc-backup@${PROJECT_ID}.iam.gserviceaccount.com"

# Grant Storage Object Creator role (write-only, cannot delete)
gcloud storage buckets add-iam-policy-binding gs://${BUCKET_NAME} \
    --member="serviceAccount:${SA_EMAIL}" \
    --role="roles/storage.objectCreator"

# Grant Storage Object Viewer role (for restore operations)
gcloud storage buckets add-iam-policy-binding gs://${BUCKET_NAME} \
    --member="serviceAccount:${SA_EMAIL}" \
    --role="roles/storage.objectViewer"
```

### If Using Compute Engine Default Service Account

If your VM uses the default Compute Engine service account, grant it the necessary roles:

```bash
export VM_SA="$(gcloud compute instances describe YOUR_VM_NAME \
    --zone=YOUR_ZONE \
    --format='get(serviceAccounts[0].email)')"

gcloud storage buckets add-iam-policy-binding gs://${BUCKET_NAME} \
    --member="serviceAccount:${VM_SA}" \
    --role="roles/storage.objectCreator"

gcloud storage buckets add-iam-policy-binding gs://${BUCKET_NAME} \
    --member="serviceAccount:${VM_SA}" \
    --role="roles/storage.objectViewer"
```

---

## Step 4: Install Backup Scripts on VM

SSH into your production VM and run the setup:

```bash
# SSH into the VM
gcloud compute ssh YOUR_VM_NAME --zone=YOUR_ZONE

# Navigate to the application directory
cd /path/to/gencc-sub

# Make scripts executable
chmod +x scripts/backup-db.sh
chmod +x scripts/restore-db-from-gcs.sh
chmod +x scripts/setup-backup-cron.sh

# Run the setup script
sudo ./scripts/setup-backup-cron.sh \
    --bucket gencc-backups \
    --db-password 'YOUR_DATABASE_PASSWORD' \
    --db-host 127.0.0.1 \
    --db-name gencc_sub
```

---

## Step 5: Verify Setup

### Test Backup Manually

```bash
# Run a manual backup
sudo /opt/gencc/scripts/backup-db.sh

# Check the log
tail -20 /var/log/gencc/backup.log

# Verify in GCS
gsutil ls -l gs://gencc-backups/database-backups/
```

### Verify Cron Job

```bash
# Check cron job is installed
cat /etc/cron.d/gencc-backup

# Check cron service is running
systemctl status cron
```

### Test Restore (on non-production)

```bash
# List available backups
/opt/gencc/scripts/restore-db-from-gcs.sh --list

# Restore to a test database
/opt/gencc/scripts/restore-db-from-gcs.sh \
    --latest \
    --database gencc_sub_test \
    --create-db
```

---

## Step 6: Set Up Monitoring (Optional but Recommended)

### Create Alert for Backup Failures

Using Cloud Monitoring:

1. Go to **Monitoring** > **Alerting** > **Create Policy**
2. Configure:
   - **Condition**: Log-based metric for backup errors
   - **Filter**: `resource.type="gce_instance" AND textPayload:"[gencc-backup] ERROR"`
   - **Notification**: Email or Slack

### Log-Based Metric

```bash
gcloud logging metrics create gencc-backup-errors \
    --description="GenCC backup script errors" \
    --log-filter='resource.type="gce_instance" AND textPayload=~"^\[gencc-backup\] ERROR"'
```

---

## Step 7: Protect the Configuration YAML Bucket

The application also uses YAML files from GCS for configuration:

```
gs://gencc/application-private/
├── submitters.yaml
├── users.yaml
└── teams.yaml
```

### Enable Versioning

```bash
gcloud storage buckets update gs://gencc --versioning
```

### Set Lifecycle for Non-Current Versions

```bash
cat > /tmp/config-lifecycle.json << 'EOF'
{
  "rule": [
    {
      "action": {"type": "Delete"},
      "condition": {
        "age": 90,
        "isLive": false,
        "matchesPrefix": ["application-private/"]
      }
    }
  ]
}
EOF

gcloud storage buckets update gs://gencc \
    --lifecycle-file=/tmp/config-lifecycle.json
```

---

## Backup Schedule

| Time (UTC) | Action |
|------------|--------|
| 02:00 | Nightly database backup runs |
| 02:01-02:10 | Backup uploaded to GCS |
| 02:10 | Local backups older than 7 days deleted |

---

## Disaster Recovery Procedures

### Scenario 1: Restore Latest Backup

```bash
# List available backups
/opt/gencc/scripts/restore-db-from-gcs.sh --list

# Restore latest
/opt/gencc/scripts/restore-db-from-gcs.sh --latest --force
```

### Scenario 2: Restore Specific Point in Time

```bash
# Find the backup you need
gsutil ls -l "gs://gencc-backups/database-backups/**/*.sql.gz" | sort

# Restore specific backup
/opt/gencc/scripts/restore-db-from-gcs.sh \
    --file gencc_sub_20260115-020000.sql.gz
```

### Scenario 3: Restore to New VM

```bash
# On the new VM, first install prerequisites
apt-get update && apt-get install -y mysql-client

# Download and run restore
gsutil cp gs://gencc-backups/database-backups/2026/01/gencc_sub_20260115-020000.sql.gz /tmp/
gunzip -c /tmp/gencc_sub_20260115-020000.sql.gz | mysql -u root -p gencc_sub
```

---

## Cost Estimation

| Resource | Monthly Cost (Estimate) |
|----------|------------------------|
| Storage (30 backups × ~100MB) | ~$0.06 |
| Operations (30 writes + reads) | ~$0.01 |
| Network egress (restores) | $0 (same region) |
| **Total** | **~$0.10/month** |

---

## Checklist

- [ ] GCS bucket created with uniform access
- [ ] Versioning enabled on bucket
- [ ] Lifecycle policy configured (30-day retention)
- [ ] Service account created with appropriate roles
- [ ] VM has gsutil configured and authenticated
- [ ] Backup scripts installed to `/opt/gencc/scripts/`
- [ ] Configuration file created at `/etc/gencc/backup.env`
- [ ] Cron job installed at `/etc/cron.d/gencc-backup`
- [ ] Log rotation configured
- [ ] Manual test backup completed successfully
- [ ] Restore procedure tested on non-production database
- [ ] Monitoring/alerting configured (optional)
- [ ] Configuration YAML bucket has versioning enabled
