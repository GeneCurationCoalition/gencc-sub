# GenCC Submission Portal API Guide

**Last Updated: February 2026**

---

## Table of Contents

1. [Overview](#overview)
2. [Authentication](#authentication)
3. [Base URL](#base-url)
4. [Submission API](#submission-api)
   - [Create Submissions](#create-submissions)
   - [Validate Submissions (Dry Run)](#validate-submissions-dry-run)
   - [Query Job Details](#query-job-details)
   - [Query Submission Details](#query-submission-details)
   - [Check Job Status](#check-job-status)
   - [Check Submission Status](#check-submission-status)
   - [Remove Job](#remove-job)
   - [Remove Submission](#remove-submission)
5. [Data Schema](#data-schema)
   - [Submission Object](#submission-object)
   - [Required Fields](#required-fields)
   - [Reference IDs](#reference-ids)
6. [Error Codes](#error-codes)
7. [Examples](#examples)
8. [Support](#support)

---

## Overview

The GenCC Submission Portal API enables programmatic submission and management of gene-disease curation records. This RESTful API allows authorized GenCC member organizations to:

- Submit new gene-disease curations
- Update existing submissions
- Query submission and job status
- Remove submissions

All submissions go through the same validation and release process as portal-submitted records.

**Note:** Spreadsheet file uploads are only available through the web portal interface. This API supports JSON-based submissions only.

---

## Authentication

All API requests require authentication via an API key.

### API Key Header

Include your API key in the request header:

```
GEN-API-KEY: your-api-token-here
```

### Obtaining an API Key

API keys are automatically generated for each registered user. To view or regenerate your API key:

1. Log in to the GenCC Submission Portal
2. Navigate to your Profile settings
3. Your API key is displayed in the API Access section

**Important:** Keep your API key secure. Do not share it or commit it to version control.

---

## Base URL

**Production:** `https://portal.thegencc.org/api`

---

## Submission API

### Create Submissions

Submit one or more gene-disease curation records.

**Endpoint:** `POST /api/submit`

**Headers:**
```
Content-Type: application/json
GEN-API-KEY: your-api-token
```

**Request Body:**
```json
{
  "action": "create",
  "submitter": {
    "id": "GENCC:000102"
  },
  "data": [
    {
      "action": "new",
      "gene": {
        "id": "HGNC:1234"
      },
      "disease": {
        "id": "MONDO:0012345"
      },
      "moi": {
        "id": "HP:0000006"
      },
      "classification": {
        "id": "GENCC:100001"
      },
      "report": {
        "display_date": "2026-02-15",
        "ext_url": "https://example.org/report/123"
      },
      "criteria": {
        "name": "ClinGen Gene-Disease Validity SOP",
        "url": "https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-disease-clinical-validity-framework/"
      },
      "evidence": [
        { "pmid": "12345678" },
        { "pmid": "23456789" }
      ],
      "notes": {
        "display": "Public notes visible on GenCC website",
        "private": "Internal notes not published"
      },
      "contributors": {
        "primary": {
          "id": "contributor-id",
          "name": "Primary Contributor Name"
        }
      },
      "local_key": "your-internal-id-123"
    }
  ]
}
```

**Success Response (200):**
```json
{
  "date": "2026-02-15T10:30:00Z",
  "message": "Job accepted",
  "jobs": [
    {
      "id": "J-100045",
      "message": "Job accepted",
      "status_code": 200
    }
  ]
}
```

---

### Validate Submissions (Dry Run)

Validate submission data without creating records. Use this to check for errors before actual submission.

**Endpoint:** `POST /api/submit/check`

**Headers:**
```
Content-Type: application/json
GEN-API-KEY: your-api-token
```

**Request Body:** Same as Create Submissions

**Success Response (200):**
```json
{
  "success": "true",
  "status_code": 200,
  "message": "OK"
}
```

---

### Query Job Details

Retrieve full details of a job including all submissions.

**Endpoint:** `GET /api/submit/job/{job_id}`

Alternative: `GET /api/query/job/{job_id}`

**Headers:**
```
GEN-API-KEY: your-api-token
```

**Response (200):**
```json
{
  "action": "status",
  "type": "Job",
  "jid": "J-100045",
  "submitted": "2026-02-15T10:30:00Z",
  "submitter": "CUID:300001",
  "last_update": "2026-02-15T10:35:00Z",
  "message": {
    "errorCode": null,
    "severity": "info",
    "text": "Your GenCC submission job processing status is: Staged"
  },
  "data": [
    {
      "type": "Submission",
      "submission_id": "SGC-100123",
      "submission_label": "BRCA1 / Breast cancer",
      "local_key": "your-internal-id-123",
      "submitted": "2026-02-15T10:30:00Z",
      "last_update": "2026-02-15T10:35:00Z",
      "status": "Staged",
      "gene": {
        "id": "HGNC:1100",
        "symbol": "BRCA1"
      },
      "disease": {
        "id": "MONDO:0007254",
        "name": "breast cancer"
      },
      "moi": {
        "id": "HP:0000006",
        "name": "Autosomal dominant"
      },
      "classification": {
        "id": "GENCC:100001",
        "name": "Definitive"
      }
    }
  ]
}
```

---

### Query Submission Details

Retrieve details of a specific submission.

**Endpoint:** `GET /api/submit/{submission_id}`

Alternative: `GET /api/query/{submission_id}`

**Headers:**
```
GEN-API-KEY: your-api-token
```

**Response:** Similar to job query but for a single submission.

---

### Check Job Status

Get the current processing status of a job.

**Endpoint:** `GET /api/status/job/{job_id}`

Alternative: `GET /api/submit/job/{job_id}/status`

**Headers:**
```
GEN-API-KEY: your-api-token
```

**Response (200):**
```json
{
  "date": "2026-02-15T10:40:00Z",
  "message": "Status Response",
  "jobs": [
    {
      "id": "J-100045",
      "message": "Staged",
      "status_code": 200
    }
  ]
}
```

---

### Check Submission Status

Get the current status of a specific submission.

**Endpoint:** `GET /api/status/{submission_id}`

Alternative: `GET /api/submit/{submission_id}/status`

**Headers:**
```
GEN-API-KEY: your-api-token
```

**Response (200):**
```json
{
  "date": "2026-02-15T10:40:00Z",
  "message": "Status Response",
  "jobs": [
    {
      "id": "J-100045",
      "message": "Staged",
      "status_code": 200,
      "data": [
        {
          "sid": "SGC-100123",
          "message": "Published",
          "status_code": 200
        }
      ]
    }
  ]
}
```

---

### Remove Job

Remove a job and all its associated submissions.

**Endpoint:** `GET /api/remove/job/{job_id}`

Alternative: `GET /api/submit/job/{job_id}/remove`

**Headers:**
```
GEN-API-KEY: your-api-token
```

**Response (200):**
```json
{
  "success": "true",
  "status_code": 200,
  "message": "Job Removed"
}
```

---

### Remove Submission

Remove a specific submission.

**Endpoint:** `GET /api/remove/{submission_id}`

Alternative: `GET /api/submit/{submission_id}/remove`

**Headers:**
```
GEN-API-KEY: your-api-token
```

**Response (200):**
```json
{
  "success": "true",
  "status_code": 200,
  "message": "Submission Removed"
}
```

---

## Data Schema

### Submission Object

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | `"new"` for new submissions, `"update"` for updates |
| `gene.id` | string | Yes | HGNC gene identifier (e.g., `"HGNC:1234"` or `"1234"`) |
| `disease.id` | string | Yes | Disease identifier (MONDO, OMIM, or ORPHA) |
| `moi.id` | string | Yes | Mode of inheritance HPO term (e.g., `"HP:0000006"`) |
| `classification.id` | string | Yes | GenCC classification ID (e.g., `"GENCC:100001"`) |
| `report.display_date` | string | Yes | Evaluation date (YYYY-MM-DD format) |
| `criteria.url` | string | Yes | URL to assertion criteria documentation |
| `criteria.name` | string | No | Name of assertion criteria |
| `report.ext_url` | string | No | URL to external report |
| `evidence` | array | No | Array of PubMed references |
| `evidence[].pmid` | string | No | PubMed ID |
| `notes.display` | string | No | Public notes (visible on GenCC website) |
| `notes.private` | string | No | Private notes (not published) |
| `contributors.primary.name` | string | No | Primary contributor name |
| `contributors.primary.id` | string | No | Primary contributor identifier |
| `local_key` | string | No | Your internal tracking identifier |
| `mechanism.id` | string | No | Mechanism of disease (e.g., `"GENCC:200001"`) |
| `mechanism.comments` | string | No | Comment on mechanism |

### Required Fields

For new submissions, the following fields are **required**:
- `gene.id` - Valid HGNC gene identifier
- `disease.id` - Valid disease identifier (MONDO preferred, OMIM/ORPHA accepted)
- `moi.id` - Valid HPO mode of inheritance term
- `classification.id` - Valid GenCC classification
- `report.display_date` - Evaluation date
- `criteria.url` - Assertion criteria URL

### Reference IDs

#### Submitter IDs
Use your organization's GenCC submitter ID. Contact GenCC to obtain your submitter ID if you don't have one.

#### Classification IDs
| ID | Classification |
|----|---------------|
| `GENCC:100001` | Definitive |
| `GENCC:100002` | Strong |
| `GENCC:100003` | Moderate |
| `GENCC:100004` | Limited |
| `GENCC:100005` | Disputed Evidence |
| `GENCC:100006` | Refuted Evidence |
| `GENCC:100007` | Animal Model Only |
| `GENCC:100008` | No Known Disease Relationship |
| `GENCC:100009` | Supportive |

#### Mode of Inheritance IDs (Common)
| ID | Mode of Inheritance |
|----|---------------------|
| `HP:0000006` | Autosomal dominant |
| `HP:0000007` | Autosomal recessive |
| `HP:0001417` | X-linked |
| `HP:0001419` | X-linked recessive |
| `HP:0001423` | X-linked dominant |
| `HP:0001427` | Mitochondrial |
| `HP:0032113` | Semidominant |
| `HP:0000005` | Unknown |

*See the GenCC Submission Spreadsheet for the complete list of accepted values.*

---

## Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| 9000 | 401 | Missing API key |
| 9001 | 401 | Unauthorized - invalid API key |
| 9002 | 401 | Unauthorized - inactive user |
| 9003 | 401 | Submitter mismatch - user not authorized for submitter |
| 9005 | 401 | Unauthorized - user not found |
| 9006 | 401 | Job not found or unauthorized |
| 9007 | 401 | Submission not found or unauthorized |
| 1001 | 501 | Invalid JSON format |
| 1002 | 501 | JSON schema error |
| 1003 | 501 | Unknown action |
| 1004 | 501 | Submission validation error |

---

## Examples

### cURL: Create a New Submission

```bash
curl -X POST https://portal.thegencc.org/api/submit \
  -H "Content-Type: application/json" \
  -H "GEN-API-KEY: your-api-token-here" \
  -d '{
    "action": "create",
    "submitter": { "id": "GENCC:000102" },
    "data": [{
      "action": "new",
      "gene": { "id": "HGNC:1100" },
      "disease": { "id": "MONDO:0007254" },
      "moi": { "id": "HP:0000006" },
      "classification": { "id": "GENCC:100001" },
      "report": {
        "display_date": "2026-02-15",
        "ext_url": "https://example.org/report"
      },
      "criteria": {
        "name": "ClinGen SOP",
        "url": "https://clinicalgenome.org/sop"
      },
      "evidence": [{ "pmid": "12345678" }],
      "local_key": "internal-123"
    }]
  }'
```

### cURL: Check Job Status

```bash
curl -X GET https://portal.thegencc.org/api/status/job/J-100045 \
  -H "GEN-API-KEY: your-api-token-here"
```

### Python Example

```python
import requests

API_BASE = "https://portal.thegencc.org/api"
API_KEY = "your-api-token-here"

headers = {
    "Content-Type": "application/json",
    "GEN-API-KEY": API_KEY
}

# Create submission
payload = {
    "action": "create",
    "submitter": {"id": "GENCC:000102"},
    "data": [{
        "action": "new",
        "gene": {"id": "HGNC:1100"},
        "disease": {"id": "MONDO:0007254"},
        "moi": {"id": "HP:0000006"},
        "classification": {"id": "GENCC:100001"},
        "report": {
            "display_date": "2026-02-15",
            "ext_url": "https://example.org/report"
        },
        "criteria": {
            "url": "https://clinicalgenome.org/sop"
        }
    }]
}

response = requests.post(f"{API_BASE}/submit", json=payload, headers=headers)
print(response.json())

# Check status
job_id = "J-100045"
status = requests.get(f"{API_BASE}/status/job/{job_id}", headers=headers)
print(status.json())
```

---

## Support

### Technical Support
For API issues, authentication problems, or error troubleshooting:
- **Email:** gencc-tech@broadinstitute.org

### General Inquiries
For scientific questions, data inquiries, or GenCC participation:
- **Email:** gencc@thegencc.org

### Additional Resources
- [GenCC Website](https://thegencc.org)
- [GenCC Submission Portal](https://portal.thegencc.org)
- [User Guide](https://portal.thegencc.org/documents/UserGuide.pdf)
