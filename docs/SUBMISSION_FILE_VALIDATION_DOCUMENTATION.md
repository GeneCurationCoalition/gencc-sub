# SubmissionFileValidation Documentation

**Last Updated**: November 13, 2025
**State Model Version**: 2.0 (String-Based States)

## Overview

The `SubmissionFileValidation` service validates GenCC submission spreadsheets before they are processed into the system. 
It performs comprehensive validation on spreadsheet structure, headers, and data rows to ensure data integrity and compliance
with GenCC standards.

## Spreadsheet Structure Requirements

### Header Row
- **Location:** Row 6 
- **Requirement:** Must contain all required column headers in exact order as the Spreadsheet Template Version 2
- **Validation:** Case-insensitive matching with whitespace normalization

### Data Rows
- **Start Row:** Row 13
- **Minimum Rows:** Spreadsheet must have at least 13 rows total (including header)
- **Blank Rows:** Automatically skipped during processing

### Validation Limits
- **Maximum Errors Reported:** 25
  - When 25 errors are reached, validation stops and returns accumulated errors

## Error Severity Levels

### Fatal Errors
Fatal errors prevent all processing and require file re-upload after correction:
- Missing or invalid header row
- Insufficient minimum rows
- Missing required header columns

### Standard Errors
Standard errors are reported per row and allow partial processing:
- Missing required field values
- Invalid field formats
- Invalid field values (not in allowed list or database)
- Duplicate values in unique fields

---

## Field Validation Specifications

### 1. SGC ID (`sgc_id`)

**Description:** System-generated GenCC submission identifier

**Required:** No (Optional), when provided, the submission with given SGC ID will be updated. When omitted, indicates this is a new submission.

**Unique:** Yes - Must be unique across all submissions in the spreadsheet when populated

**Format Validation:**
- **Pattern:** `SGC-#####` (SGC- followed by one or more digits)
- **Empty Values:** Allowed (field is optional)

**Valid Examples:**
- `SGC-1`
- `SGC-12345`
- `` (empty/blank)

**Invalid Examples:**
- `SGC1` (missing hyphen)
- `123` (missing SGC- prefix)
- `sgc-123` (lowercase)

**Error Messages:**
```
"Invalid format for column 'sgc_id': 'SGC1'."
```

```
"Unique column requirement on column 'sgc_id' not met."
```

---

### 2. Local Key (`local_key`)

**Description:** Submitter's internal identifier for the submission

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `INTERNAL-001`
- `My Gene 123`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 3. HGNC ID (`hgnc_id`)

**Description:** HUGO Gene Nomenclature Committee identifier for the gene

**Required:** Yes

**Format Validation:**
- **Pattern:** Numeric ID or `HGNC:#####`

**Database Validation:**
- **Lookup Method:** `Gene::lookup_hgnc_id()`
- **Requirement:** Must exist in the genes database table

**Valid Examples:**
- `1234`
- `HGNC:5`
- `HGNC:11892`

**Invalid Examples:**
- `HGNC-1234` (hyphen instead of colon)
- `hgnc:1234` (lowercase)
- `` (empty - field is required)

**Error Messages:**
```
"Required field 'hgnc_id' is missing."
```

```
"Invalid format for column 'hgnc_id': 'HGNC-1234'."
```

```
"Invalid value for column 'hgnc_id': '99999999'. Invalid Gene ID."
```

---

### 4. HGNC Symbol (`hgnc_symbol`)

**Description:** Human-readable gene symbol (e.g., BRCA1)

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `BRCA1`
- `TP53`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 5. Disease ID (`disease_id`)

**Description:** Disease ontology identifier (MONDO, OMIM, or ORPHA)

**Required:** Yes

**Format Validation:**
- **Pattern:** `MONDO:#####`, `OMIM:#####`, `ORPHA:#####`, or numeric ID

**Database Validation:**
- **Requirement:** Must exist in the diseases database table

**Valid Examples:**
- `MONDO:0007254`
- `OMIM:114480`
- `ORPHA:166024`
- `123456` (numeric ID)

**Invalid Examples:**
- `MONDO-0007254` (hyphen instead of colon)
- `mondo:0007254` (lowercase)
- `MONDO 0007254` (space instead of colon)
- `` (empty - field is required)

**Error Messages:**
```
"Required field 'disease_id' is missing."
```

```
"Invalid format for column 'disease_id': 'MONDO-0007254'."
```

```
"Invalid value for column 'disease_id': 'MONDO:9999999'. Invalid Disease ID."
```

---

### 6. Disease Name (`disease_name`)

**Description:** Human-readable disease name

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `Breast cancer`
- `Hereditary breast and ovarian cancer syndrome`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 7. Mode of Inheritance ID (`moi_id`)

**Description:** Human Phenotype Ontology (HP) identifier for mode of inheritance

**Required:** Yes

**Database Validation:**
- **Lookup Method:** `get_valid_inheritances()`
- **Source:** Active inheritance CURIEs from `inheritances` database table
- **Requirement:** Must match one of the active inheritance CURIEs exactly

**Valid Examples:**
- `HP:0000006` (Autosomal dominant)
- `HP:0000007` (Autosomal recessive)
- `HP:0001417` (X-linked inheritance)
- `HP:0001419` (X-linked recessive)
- `HP:0001423` (X-linked dominant)

**Invalid Examples:**
- `HP:9999999` (not in database)
- `Autosomal dominant` (text instead of ID)
- `` (empty - field is required)

**Error Messages:**
```
"Required field 'moi_id' is missing."
```

```
"Invalid value for column 'moi_id': 'HP:9999999'. Must be one of: 'HP:0000006, HP:0000007, HP:0001417, HP:0001419, HP:0001423'. Refer to 'https://thegencc.org/submission-directions' for details."
```

---

### 8. Mode of Inheritance Name (`moi_name`)

**Description:** Human-readable mode of inheritance description

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `Autosomal dominant`
- `Autosomal recessive`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 9. Submitter ID (`submitter_id`)

**Description:** GenCC submitter organization identifier (CURIE)

**Required:** Yes

**Database Validation:**
- **Lookup Method:** `get_valid_submitters()`
- **Source:** Submitter CURIE from `submitters` database table
- **Context:** Must match the authenticated user's submitter organization
- **Requirement:** Only the submitter's own CURIE is valid

**Valid Examples:**
- `CGC:123456` (if this is the user's organization)
- `CLINGEN:40001` (if this is the user's organization)

**Invalid Examples:**
- `INVALID:123` (not the user's organization)
- `CGC-123` (invalid format)
- `` (empty - field is required)

**Error Messages:**
```
"Required field 'submitter_id' is missing."
```

```
"Invalid value for column 'submitter_id': 'INVALID:123'. Must be one of: 'CGC:123456'. Refer to 'https://thegencc.org/submission-directions' for details."
```

---

### 10. Submitter Name (`submitter_name`)

**Description:** Human-readable submitter organization name

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `Clinical Genome Resource`
- `Gene Curation Coalition`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 11. Classification ID (`classification_id`)

**Description:** GenCC classification CURIE for gene-disease relationship strength

**Required:** Yes

**Database Validation:**
- **Lookup Method:** `get_valid_classifications()`
- **Source:** Active classification CURIEs from `classifications` database table
- **Requirement:** Must match one of the active classification CURIEs exactly

**Valid Examples:**
- `GENCC:100001` (Definitive)
- `GENCC:100002` (Strong)
- `GENCC:100003` (Moderate)
- `GENCC:100004` (Limited)
- `GENCC:100005` (Disputed)
- `GENCC:100006` (Refuted)
- `GENCC:100009` (No Known Disease Relationship)

**Invalid Examples:**
- `Definitive` (text instead of CURIE)
- `gencc:100001` (lowercase)
- `GENCC-100001` (hyphen instead of colon)
- `` (empty - field is required)

**Error Messages:**
```
"Required field 'classification_id' is missing."
```

```
"Invalid value for column 'classification_id': 'Definitive'. Must be one of: 'GENCC:100001, GENCC:100002, GENCC:100003, GENCC:100004, GENCC:100005, GENCC:100006, GENCC:100009'. Refer to 'https://thegencc.org/submission-directions' for details."
```

---

### 12. Classification Name (`classification_name`)

**Description:** Human-readable classification label

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `Definitive`
- `Strong`
- `Moderate`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 13. Report Date (`date`)

**Description:** Date of the curation report or assertion

**Required:** Yes

**Format Validation:**
- **Pattern:** `YYYY-MM-DD` or `YYYY/MM/DD`

**Special Handling:**
- Excel numeric date formats are automatically converted
- Both slash and hyphen separators are accepted

**Valid Examples:**
- `2024-01-15`
- `2024/01/15`
- Excel numeric date value (e.g., 45015)

**Invalid Examples:**
- `01-15-2024` (wrong order)
- `2024.01.15` (wrong separator)
- `15/01/2024` (day-month-year format)
- `` (empty - field is required)

**Error Messages:**
```
"Required field 'date' is missing."
```

```
"Invalid format for column 'date': '01-15-2024'."
```

---

### 14. Public Report URL (`public_report_url`)

**Description:** URL to the publicly accessible curation report

**Required:** No (Optional)

**Format Validation:**
- **Pattern:** Must start with `http://` or `https://`, or be empty

**Valid Examples:**
- `https://www.example.com/report/123`
- `http://example.org/curations/gene-disease`
- `` (empty/blank)

**Invalid Examples:**
- `www.example.com` (missing protocol)
- `ftp://example.com` (FTP not allowed)
- `example.com/report` (missing protocol)

**Error Messages:**
```
"Invalid format for column 'public_report_url': 'www.example.com'."
```

---

### 15. Notes (`notes`)

**Description:** Additional notes or comments about the submission

**Required:** No (Optional)

**Format Validation:** None - accepts any string value

**Valid Examples:**
- `Updated classification based on new evidence`
- `See supplementary materials`
- `` (empty/blank)

**Error Messages:**
None - this field accepts any value or can be empty

---

### 16. PubMed IDs (`pmids`)

**Description:** Comma or semicolon-separated list of PubMed identifiers

**Required:** No (Optional)

**Format Validation:**
- **Pattern:** Numeric IDs separated by commas or semicolons, with optional whitespace

**Valid Examples:**
- `12345678`
- `12345678, 23456789, 34567890`
- `12345678; 23456789; 34567890`
- `12345678,23456789,34567890` (no spaces)
- `` (empty/blank)

**Invalid Examples:**
- `PMID:12345678` (includes prefix)
- `12345678 23456789` (space separated without comma)
- `12345678.0` (decimal point)

**Error Messages:**
```
"Invalid format for column 'pmids': 'PMID:12345678'."
```

---

### 17. Assertion Criteria URL (`assertion_criteria_url`)

**Description:** URL to the criteria or framework used for the classification

**Required:** Yes

**Format Validation:**
- **Pattern:** Must start with `http://` or `https://`
- **Note:** Unlike `public_report_url`, this field cannot be empty

**Valid Examples:**
- `https://www.clinicalgenome.org/site/assets/files/2210/clingen_gene_curation_sop_v7.pdf`
- `http://example.org/criteria/gene-disease`

**Invalid Examples:**
- `` (empty - field is required)
- `www.example.com` (missing protocol)
- `ftp://example.com` (FTP not allowed)

**Error Messages:**
```
"Required field 'assertion_criteria_url' is missing."
```

```
"Invalid format for column 'assertion_criteria_url': 'www.example.com'."
```

---

## Spreadsheet-Level Validation Errors

### Minimum Row Requirement

**Error Condition:** Spreadsheet has fewer than 13 rows

**Severity:** FATAL

**Error Message Example:**
```
"The spreadsheet contains 10 rows, but there must be a minimum of 13. This a fatal error that must be fixed and the file re-uploaded."
```

---

### Header Row Validation

#### Missing Header Row

**Error Condition:** No header row found in row 6

**Severity:** FATAL

**Error Message Example:**
```
"Could not find header row in row 6 of the spreadsheet. Headers must be in row 6. This a fatal error that must be fixed and the file re-uploaded."
```

#### Invalid Header Columns

**Error Condition:** Missing required columns or extra/unknown columns

**Severity:** FATAL

**Error Message Example:**
```
"Header validation failed in row 6. Found 15 fields, missing 2 required fields, 1 extra fields. Required: 17 fields total. Missing fields: hgnc_id, disease_id. Extra fields: unknown_column. This a fatal error that must be fixed and the file re-uploaded."
```

---

### Maximum Validation Errors Reached

**Error Condition:** 25 validation errors have been accumulated during processing

**Severity:** ERROR (Processing stops)

**Behavior:**
- Validation stops at 25 errors
- Returns the first 25 errors found
- User must fix these errors and re-upload to see any additional errors

**UI Display:**
```
"25 Processing Error(s) Maximum errors reached"
```

---

## Validation Process Flow

1. **Spreadsheet Structure Check**
   - Verify minimum row count (≥13 rows)
   - Stop if insufficient rows (FATAL)

2. **Header Validation** (Row 6)
   - Verify all required columns are present
   - Header matching is case-insensitive
   - Stop if headers are invalid (FATAL)

3. **Data Row Validation** (Starting at Row 13)
   - Process each data row sequentially
   - Skip blank rows automatically
   - For each row:
     - Check all required fields are populated
     - Validate field formats against regex patterns
     - Validate field values against database lookups
     - Accumulate errors (max 25)
   - Stop when 25 errors are reached

4. **Uniqueness Validation**
   - After processing all rows, check unique column constraints
   - Currently applies to: `sgc_id`

5. **Return Results**
   - Empty array = validation passed
   - Array of error objects = validation failed
   - Each error includes: row number, sgc_id, local_key, error message

---


## Tips for Valid Submissions

1. **Use the Template:** Always start with the official GenCC submission template
2. **Header Row:** Ensure headers are exactly in row 6
3. **Data Rows:** Begin data in row 13 or later
4. **Required Fields:** Double-check all 7 required fields are populated:
   - hgnc_id
   - disease_id
   - moi_id
   - submitter_id
   - classification_id
   - date
   - assertion_criteria_url
5. **ID Formats:** Use colons not hyphens in CURIEs (e.g., `MONDO:123` not `MONDO-123`)
6. **Dates:** Use YYYY-MM-DD or YYYY/MM/DD format
7. **URLs:** Always include `http://` or `https://`
8. **PubMed IDs:** Use numbers only, separated by commas or semicolons
9. **SGC IDs:** For updates, use existing SGC ID; for new submissions, leave blank
10. **Submitter ID:** Use your organization's official CURIE


---

## Related Documentation

- [SUBMISSION_PROCESSING_GUIDE.md](SUBMISSION_PROCESSING_GUIDE.md) - Complete submission lifecycle and state workflows
- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly state model guide

---

**Version**: 2.0
**Last Updated**: November 13, 2025
