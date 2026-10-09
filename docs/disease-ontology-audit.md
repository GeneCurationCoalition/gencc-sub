# Disease ontology audit

The admin page at `/admin/disease-ontology-audit` reports disease findings on
non-deleted live or most recent submission versions. It includes published,
unpublished, and pending records. A live published version and a newer draft
can both appear. Counts distinguish versions, SGC IDs, submitted identifiers,
and submitters; finding categories overlap.

## Generate a report

A scan runs:

- after each successful scheduled `update:diseases`
  (`gencc-sub-audit-diseases.service`, started by the disease update
  service's `OnSuccess=`);
- during deployment, after the disease rebuild, when
  `gencc_refresh_diseases_on_deploy` is enabled (the default);
- from **Audit Diseases** in the Dashboard's Admin Actions, which queues a
  background scan, or by hand:

```sh
php artisan audit:disease-ontologies
```

Setting `gencc_refresh_diseases_on_deploy: false` skips both the disease update
and deployment audit. Keep it enabled for the first deployment of the disease
mapping migrations, which clear legacy mappings for rebuilding. A failed audit
is reported without failing the deployment; verify the latest successful report
afterward.

`update:diseases` and the audit share a process lock
(`storage/framework/disease-ontology.lock`, `DiseaseOntologyLock`). An audit
refuses to start while a disease update or another audit is running, and a
disease update started during an audit waits for it to finish. The lock is a
local file, so deployments using multiple independent application hosts will
need a shared lock. An interrupted scan is marked failed when the next scan
starts. After each successful scan, runs older than the last eight successful
ones are deleted with their findings.

The page warns when disease source files were imported after the displayed
scan, and when the scan is more than eight days old.

The scanner writes only audit tables. It calls the existing disease validator
and resolver and does not apply blocking duplicate rules or edit submissions. It
uses the submitted identifier from `submission_data.disease.id`; original
upload data and foreign keys provide context, never a silent fallback.

Only a completely successful run becomes the default report. Failed scans
leave the last successful report available. The page and export are pinned to
the displayed run so a later scan cannot change a download mid-review.

## Reading findings

The table compares the stored MONDO with the result of current resolution.
Current validation can still reject an identifier with a unique MONDO result
if the submitted source term is missing. Deprecated terms remain eligible for
resolution, and deprecation appears as a separate finding.

Expand a row for disease names, status qualifiers, mapping route, candidates,
current validation explanation, and any previously recorded disease error.
Existing disease errors are reported separately from changes to stored
mappings. This also exposes legacy inconsistent/placeholder references without
assuming they were originally accepted disease mappings.

“Deprecated” means marked deprecated in the local disease database. The current
importer uses that status for both explicit upstream deprecation and some terms
absent from a later import. This report does not yet distinguish those reasons.
Stored/current differences alone cannot establish whether code, ontology data,
or an earlier correction caused the historical mapping.

Results are observations at scan time. Changes to submissions or diseases after
the scan require a new scan. The page flags reports older than eight days and
shows cached source HTTP metadata as context, not proof of import completion.
Snapshot evidence is retained even if disease labels change later.

Filter by finding type, submitter, submitted namespace, submission/job status,
or SGC/job/disease identifier. CSV exports contain the same filtered results,
including evidence. All routes require GenCC admin membership and work without
a selected submitter. Spreadsheet formula-like text is escaped in CSV exports.

## Replacement information and relationship overlap

The implemented replacement-information milestone adds these cases:

| Case | Meaning |
|---|---|
| `replacement_available` | A deprecated term has exactly one explicit, active, locally available disease successor that passes validation and resolves to an active MONDO. This does **not** make a published record editable. |
| `replacement_not_recorded` | A deprecated term has no recorded replacement assertion. This is distinct from an asserted replacement that needs review. |
| `replacement_needs_review` | A deprecated term has unparsed metadata, multiple successors, an unsupported gene/phenotype successor, or a missing, deleted, deprecated, self-referencing, or unresolvable successor. An active successor mapping to a deprecated MONDO also needs review. Expanded evidence explains which. |
| `shared_stored_mondo_relationship` | Distinct SGC assertions from the same submitter store the same gene, MONDO and inheritance combination. |
| `shared_current_mondo_relationship` | Current validation of distinct SGC assertions' submitted identifiers accepts the same gene, MONDO and inheritance combination. Missing-source or ambiguous candidates do not qualify. |

Overlap is advisory, not a declaration that assertions are scientifically
interchangeable. Same-SGC versions are excluded as peers; peer groups retain
version, submitted-ID, job and status context. Archived-only and deleted
records do not contribute. Already-unpublished peers are labeled accordingly.
The displayed/exported peer list is capped at 20 assertions; the total peer
count includes any omitted assertions. A pair can meet both stored/current
definitions. Existing submitted-ID duplicate errors remain unchanged.

Public API check/create and gene/disease/inheritance edits warn using the
incoming accepted MONDO versus existing **stored** targets, plus intra-batch
matches. The file upload gate reports blocking file errors only, so uploaded
rows get this advice on their submission pages, which recompute it. Only the
audit checks current-only convergence across the database. Warnings neither
enter `submission_errors` nor block upload/publication. Duplicates are still
keyed on the submitted disease: public API check/create rejects a batch with
submitted-ID duplicates before writing.

Replacements are immediate explicit source assertions, separate from exact
equivalence. The importer records all MONDO `IAO_0100001` destinations, OMIM
Caret `MOVED TO` entries (including gene destinations as unsupported metadata),
and forward Orphanet type-21471 associations verified by XML root identity.
No chains are followed and no submitted disease is automatically changed.
See `/help/disease-mapping` for the public policy.

The replacement parsers use versioned source cache identifiers ending in
`:replacements-v1`. The next ordinary disease update reimports each source
once; old header history is retained. Audit metadata labels legacy fallback
headers and never treats headers alone as proof of a completed import. No
replacement-specific migration or Ansible task is required.

Older scans cannot answer newly introduced cases. Missing `case_counts` keys
mean **not captured**, whereas a present zero means evaluated without findings.
The page disables uncaptured filters and direct filter/CSV requests return 422.
Replacement and overlap evidence in previous scans is not rewritten.
Scans predating `replacement_not_recorded` included absent replacements under
`replacement_needs_review`; rerun the scan to separate these categories.
Successor and mapped-MONDO statuses are explicit in the expanded advice. If an
older snapshot omitted the mapped status and usability does not establish an
active MONDO, the display says its status was not captured rather than assuming
that it was active.

## Deferred increments

More precise source-status metadata, review history, and approved submitter
notifications remain deferred. There is no automatic remediation or notification
in this delivery. A submitter action to accept a replacement or refresh a stored
MONDO mapping is deferred to a future revision.

Focused verification:

```sh
direnv exec . php artisan test tests/Feature/DiseaseOntologyAuditTest.php
direnv exec . npm run build
```
