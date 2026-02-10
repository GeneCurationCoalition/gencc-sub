# Conflict Report Requirements

## Overview

The conflict report identifies gene-disease-MOI (mode of inheritance) combinations where different submitters have provided conflicting classifications. A "conflict" exists when some submitters classify the relationship as strong evidence (Definitive, Strong, Moderate) while others classify it as weak evidence (Limited, Disputed, Refuted, etc.).

## Purpose

- Help GenCC identify curations that may need review or reconciliation
- Surface disagreements between submitting organizations
- Support quality assurance and data consistency efforts

## Classification Categories

### Strong Evidence Classifications

| CURIE | Classification |
|-------|---------------|
| GENCC:100001 | Definitive |
| GENCC:100002 | Strong |
| GENCC:100003 | Moderate |

### Weak Evidence Classifications

| CURIE | Classification |
|-------|---------------|
| GENCC:100004 | Limited |
| GENCC:100005 | Disputed |
| GENCC:100006 | Refuted |
| GENCC:100007 | Animal Model Only |
| GENCC:100008 | No Known Disease Relationship |

Note: "Supportive" (if it exists) should also be considered weak evidence.

## Report Logic

### Grouping Key

Submissions are grouped by the unique combination of:

1. **Gene** (HGNC CURIE)
2. **Disease** (MONDO CURIE)
3. **Mode of Inheritance** (MOI CURIE)

### Conflict Detection

A conflict exists when a gene-disease-MOI triple has:

- At least one submission with a **strong** classification (weak_count > 0)
- AND at least one submission with a **weak** classification (strong_count > 0)

### Data to Track Per Triple

For each gene-disease-MOI combination:

- `hgnc_id` - Gene CURIE (e.g., "HGNC:1234")
- `gene_symbol` - Gene symbol (e.g., "BRCA1")
- `mondo_id` - Disease CURIE (e.g., "MONDO:0000001")
- `disease_name` - Disease name
- `moi_curie` - Mode of inheritance CURIE
- `moi_name` - Mode of inheritance name
- `strong_count` - Number of submissions with strong classifications
- `weak_count` - Number of submissions with weak classifications
- `submitters` - Array of submitter details:
  - `submitter_name` - Organization name
  - `classification` - Classification title
  - `submission_date` - Date of submission

## Input Criteria

Only include submissions that are:

- `is_live = true` (published/visible submissions)
- `status = 'published'` (confirmed published status)
- Have valid gene, disease, and MOI relationships

## Output Format

### TSV File Structure

| Column | Description |
|--------|-------------|
| Gene | Gene symbol |
| HGNC | HGNC CURIE |
| MONDO | MONDO CURIE |
| Disease | Disease name |
| MOI | Mode of inheritance name |
| Limited - | Count of weak classifications |
| Moderate + | Count of strong classifications |
| [Classification columns...] | One column per classification type, containing submitter details |

### Classification Columns

For each classification type (Definitive, Strong, Moderate, Limited, etc.), include a column showing:

```
Submitter Name, Submission Date, Classification || Submitter Name, Submission Date, Classification
```

Multiple submissions with the same classification are separated by ` || `.

## Implementation Options

### Option A: Artisan Command with Temporary Table

```php
php artisan report:conflicts
```

- Create/truncate a `conflicts` table to store intermediate results
- Process all submissions, grouping by gene-disease-MOI
- Query for rows where both weak_count > 0 AND strong_count > 0
- Export to TSV file

**Pros:** Matches original implementation, can inspect intermediate data
**Cons:** Requires database writes, additional table maintenance

### Option B: Artisan Command with In-Memory Processing

```php
php artisan report:conflicts
```

- Load all live submissions with relationships
- Group in memory using collections
- Filter for conflicts
- Export directly to TSV

**Pros:** No database writes needed, simpler
**Cons:** Higher memory usage for large datasets

### Option C: Database View + Export

- Create a database view that calculates conflicts
- Export view data to TSV on demand

**Pros:** Always up-to-date, can be queried directly
**Cons:** Complex SQL, may be slow for large datasets

## Recommended Implementation (Option B)

Given gencc-sub already has write access and the dataset is manageable (~25K submissions), in-memory processing is recommended:

```php
// Pseudocode
$submissions = Submission::where('is_live', true)
    ->where('status', 'published')
    ->with(['gene', 'disease', 'inheritance', 'classification', 'submitter'])
    ->get();

$grouped = $submissions->groupBy(function ($sub) {
    return $sub->gene->curie . '|' . $sub->disease->curie . '|' . $sub->inheritance->curie;
});

$conflicts = $grouped->filter(function ($group) {
    $hasStrong = $group->contains(fn($s) => in_array($s->classification->curie, $strongCuries));
    $hasWeak = $group->contains(fn($s) => in_array($s->classification->curie, $weakCuries));
    return $hasStrong && $hasWeak;
});
```

## Database Schema (if using Option A)

```sql
CREATE TABLE conflicts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hgnc_id VARCHAR(255) NOT NULL,
    gene_symbol VARCHAR(255) NOT NULL,
    mondo_id VARCHAR(255) NOT NULL,
    disease VARCHAR(255) NOT NULL,
    moi VARCHAR(255) NOT NULL,
    weak INT DEFAULT 0,
    strong INT DEFAULT 0,
    submitters JSON,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE INDEX conflicts_triple_unique (hgnc_id, mondo_id, moi)
);
```

## Additional Considerations

### Performance

- Eager load all relationships to avoid N+1 queries
- Consider chunking if memory becomes an issue
- Index the grouping columns if using database approach

### Output Location

- Default: `/tmp/conflictreport.tsv` or configurable path
- Consider adding option to output to storage directory

### Scheduling

- Could be run on-demand or scheduled (e.g., weekly)
- Consider adding email notification when complete

### Future Enhancements

1. **Web UI** - Display conflicts in admin dashboard
2. **Filtering** - Filter by submitter, date range, gene, disease
3. **Resolution Tracking** - Track when conflicts are reviewed/resolved
4. **Alerts** - Notify when new conflicts are detected
5. **API Endpoint** - Expose conflicts via API for external tools

## Acceptance Criteria

1. [ ] Command generates TSV file with all conflicting gene-disease-MOI triples
2. [ ] Report includes all required columns (Gene, HGNC, MONDO, Disease, MOI, counts, submitter details)
3. [ ] Only live/published submissions are included
4. [ ] Classifications are correctly categorized as strong or weak
5. [ ] Multiple submissions per classification are properly formatted with `||` separator
6. [ ] Command completes in reasonable time (<30 seconds for current dataset)
7. [ ] Output file path is configurable via command argument
