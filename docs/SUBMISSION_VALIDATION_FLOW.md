# Submission validation flow

File upload and portal editing use the same record-level rules. A spreadsheet
has one additional validation phase because the application must be able to
interpret its layout and requested operations before it can create records.

## Phase 1: upload gate

`SubmissionFileValidation::validate_upload_gate()` rejects the whole file only
when it cannot be applied safely:

- the workbook or first worksheet cannot be read;
- row 6 is missing the exact, ordered set of 18 template headers, or a data row
  contains populated cells beyond those columns;
- there are no submission rows from row 13 onward, or a submission action is
  found in the instruction area above row 13;
- an action cannot be routed (`N`, `R`, or `U`), including its SGC-ID and state
  requirements;
- a republish row supplies valid relationship identifiers that do not match the
  gene, original disease, and mode of inheritance owned by its SGC ID;
- an N/R row omits the submitter or claims a submitter other than the
  authenticated upload context;
- an SGC ID is repeated within the file; or
- two rows in the file have the same gene, original disease, and mode of
  inheritance key.

If this phase fails, no records are created and the response contains only gate
errors. Content warnings are not mixed into the file-error display.

## Phase 2: record validation

Once the gate passes, every interpretable N/R row becomes an editable
submission. `Submission::load_from_json()` and the portal update endpoint both
delegate field rules to `SubmissionValueValidation`; the import path attaches
those content errors to the new record instead of rejecting the file:

- missing or unknown gene, disease, mode of inheritance, or classification;
- ambiguous or absent exact MONDO mapping;
- invalid or out-of-range report date;
- invalid public-report or assertion-criteria URL;
- no usable PMID after normalization; and
- a relationship matching a blocking existing submission.

PMID cleanup reasons are retained in `pmid_issues`. Deprecated disease and
unpublished-duplicate warnings are derived for the individual submission.
Those errors and warnings are shown with the records and summarized at the job
level; they do not make the spreadsheet uninterpretable.

`SubmissionValueValidation` is the shared boundary for gene, disease, mode of
inheritance, classification, report-date, web-URL, and PMID validation. The
portal editor and imported records may present failures differently, but they
must get the validation result from this service. Duplicate detection is also
shared after the individual relationship fields resolve. New value rules
belong in this shared layer; spreadsheet-only logic is limited to the gate.
