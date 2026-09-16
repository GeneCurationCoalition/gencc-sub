# Disease mapping

This document describes how the submission portal imports disease ontology data, stores upstream relationships, and resolves an input disease identifier. It describes the current implementation.

## The policy

Three rules govern every cross-ontology disease mapping in the portal:

1. **Exact only.** Only an exact upstream mapping may relate terms across ontologies. Broader, narrower, undecided and unannotated relationships are not mappings.
2. **OMIM reciprocity.** An OMIM identifier maps to a MONDO term only when that MONDO term maps back to it.
3. **MONDO preferred, Orphanet fallback, else reject.** A rejected identifier produces a blocking per-record error in the job UI, not a rejected upload.

The rules are enforced by what is stored, not by checks at the point of use. The importer writes only exact relationships, so presence in a row's `xrefs` already means exactness, and resolution never has to re-derive it. There is consequently one resolution policy for every caller — the portal, the spreadsheet upload, the programmatic API and the console commands all get the same answer for the same identifier.

## Storage model

`xrefs` is a faithful, exact-only record of **what that row's own ontology asserts** about other ontologies. Nothing stores another ontology's claim about itself.

| Row type | `xrefs` contents |
| --- | --- |
| MONDO | `omim_id`: `skos:exactMatch` OMIM ids · `orpha_id`: `skos:exactMatch` Orphanet codes · `replaced_by`: successor CURIE when the term is obsolete |
| Orphanet | `mondo_id`: exact + validated MONDO CURIEs from Orphadata · `omim_id`: exact + validated OMIM ids from Orphadata |
| OMIM | `include_titles` only — the OMIM source asserts nothing about any other ontology |

Every equivalence key is an array, even when empty, and presence under one **is** the assertion that the two terms are the same concept.

Values are bare identifiers, because the key already names the namespace — `omim_id` holds `152700`, `orpha_id` holds `536`. An Orphanet row's `mondo_id` is the one exception and holds full CURIEs: a MONDO identifier is zero-padded to 7 digits, the padding is part of its canonical form, and Orphadata writes a fifth of its references unpadded, so storing the CURIE keeps that normalization visible in the data.

Two consequences are worth stating explicitly:

- Rule 2 needs no implementation. The only OMIM↔MONDO data anywhere is MONDO's own exact-match list, so an OMIM identifier can only ever resolve through a MONDO term that names it.
- No provenance column or relation qualifier is needed. A stored cross-reference means "this ontology asserts this identifier is the same concept", and nothing else is stored.

### Rows written before this policy

`omim_id` and `orpha_id` keep the names they had under the previous importer, when MONDO's `omim_id` also merged in generic xrefs, MONDO's `orpha_id` was a single last-wins value, and Orphanet's `omim_id` was a single reference of any relation. The names were kept so that gencc-search, which reads them for its legacy friendly-URL redirect, needs no change: it matches values with `JSON_CONTAINS`, which works on a single value or an array alike, so it simply starts following only exact mappings once the rows are rewritten.

`DiseaseResolver` trusts whatever is stored under these keys, so the rows the previous importer wrote have to be rewritten when this policy is deployed. A plain `update:diseases` run would not do that until upstream next published, because it skips any source whose file headers match the ones it last recorded. The migration `expire_disease_source_file_headers` deletes those recorded headers for MONDO and Orphanet, so the next `update:diseases` run treats both sources as changed and re-imports them. OMIM rows did not change shape, so its headers are kept. The migration touches only that table and needs no network access; like any migration it runs once, so later `update:diseases` runs skip unchanged sources as usual.

The deploy starts the `update:diseases` service once, at the end of the `timers` role, rather than waiting for the nightly timer. It is not waited on: a failed run is logged to the journal and retried by the timer. Until a run succeeds, resolution reads the previous importer's values, non-exact ones included.

A few rows are never rewritten: those whose term has left its source file entirely (see [Phase outcomes and reconciliation](#phase-outcomes-and-reconciliation)). They keep the previous importer's shape, including keys like `do_id`, `gard_id` or `umls_id` that nothing reads any more.

### The dropped `diseases.mondo_id` column

`diseases.mondo_id` was a foreign key from an OMIM or Orphanet row to a MONDO row. It recorded the inverse of a MONDO assertion on the asserting row's counterpart, which this model forbids, and two post-processing passes filled it in by joining Orphanet and OMIM references that neither ontology relates. Nothing writes or reads it under this policy, and the migration `drop_mondo_id_from_diseases_table` removes it, with its index and foreign key. It is unrelated to the `mondo_id` key inside an Orphanet row's `xrefs`.

## Interpreting and storing the upstream data

`php artisan update:diseases` loads the sources in this order:

1. MONDO from `http://purl.obolibrary.org/obo/mondo/mondo-with-equivalents.json`
2. OMIM titles from `https://data.omim.org/downloads/{OMIM_API_KEY}/mimTitles.txt`
3. Orphanet from `https://www.orphadata.com/data/xml/en_product1.xml`
4. reconciliation of records no longer present in a source

Despite its name, the MONDO URL redirects to the same `mondo.json` release asset as the plain `mondo.json` PURL; there is no separate with-equivalents artifact, and the file contains no non-MONDO equivalent-class nodes.

The phases are independent. MONDO no longer has to run first for the others to map correctly, because the OMIM and Orphanet phases derive everything they store from their own source files. It still runs first so its rows exist before anything references them.

### Phase outcomes and reconciliation

Each phase reports one of three outcomes:

| Outcome | Meaning | Effect |
| --- | --- | --- |
| updated | The source was re-read and its rows upserted | Reconciliation runs |
| skipped | The source's HTTP headers (ETag, then Last-Modified, then Content-Length) matched the previous run | The namespace's identifiers are marked seen from the database, so reconciliation is a no-op for it |
| failed | Download, parse or configuration error | The namespace is excluded from reconciliation |

Reconciliation sets a previously active row absent from its current source to `DEPRECATED` and prefixes its former name with `REMOVED- ` in `deprecated_name`. `name` and `xrefs` are left alone, so historical submissions keep their relationships. Only rows that were active are touched, so a row can be reconciled at most once.

Distinguishing "failed" from "skipped" matters: a phase that failed reported nothing as seen, which is indistinguishable from "the source dropped every term it had". Reconciling on that would deprecate an entire namespace — a failed MONDO download combined with a changed Orphanet file used to be enough to deprecate every MONDO row in the table.

A source with no recorded headers is always re-read, so deleting its rows from `static_file_headers` forces the next run to import it. A downloaded MONDO file younger than one hour on disk is reused even when headers changed. OMIM and Orphanet have no such age rule and are re-downloaded whenever they are re-read.

### Database representation

The `diseases` table contains standalone rows for three namespaces: MONDO, OMIM (in several entry types), and Orphanet. DOID, GARD, UMLS, MEDGEN, MESH, NCIT and OGMS are no longer loaded at all — no `diseases` row has ever existed for any of them, none of the 38,915 submissions on record has ever used one, and nothing in either repository read the values that used to be stored on MONDO rows.

The fields that control mapping are:

| Field | Meaning |
| --- | --- |
| `curie` | The row's canonical identifier, such as `MONDO:0012345`, `OMIM:612345`, or `Orphanet:123456`. |
| `type` | Identifies whether the row came from MONDO, an OMIM entry type, or Orphanet. |
| `xrefs` | The exact relationships this row's own ontology asserts, as described under [Storage model](#storage-model). |
| `status` | `ACTIVE` or `DEPRECATED` in practice. A `REMOVED` constant exists, but nothing in the application assigns it. Soft deletion is tracked separately by `deleted_at`. |
| `deprecated_name` | The upstream label for a deprecated term, or the `REMOVED- ` prefixed former name for a row reconciliation could not find in its source. `name` is not changed. |

### MONDO

Each node whose identifier starts with `MONDO` becomes a `Disease::TYPE_MONDO` row; other nodes in the file (UBERON, HP, GO, CHEBI and so on) are skipped. The importer stores the CURIE, label, definition, exact synonyms, exact matches, successor and active/deprecated status. For a deprecated node the label goes to `deprecated_name`, the definition is cleared, and an existing row keeps its previous `name`.

Only `meta.basicPropertyValues` is read, and only three predicates from it:

| Predicate | Stored as |
| --- | --- |
| `skos:exactMatch` with a value under `omim.org/entry/` | an entry in `xrefs.omim_id` |
| `skos:exactMatch` with a value naming an Orphanet code | an entry in `xrefs.orpha_id` |
| `IAO_0100001` (term replaced by) | `xrefs.replaced_by`, as a MONDO CURIE |

The generic `meta.xrefs` list is **not** read. It carries no per-entry relation annotation in the OBO Graphs JSON, so nothing in it can be known to be exact, and under rule 1 an unannotated cross-reference is not a mapping. A few hundred OMIM and Orphanet identifiers appear only there and no longer map to anything.

Both stored fields are arrays of bare identifiers. The Orphanet field used to be a last-wins scalar, which lost data for the 43 MONDO terms that exact-match more than one Orphanet code.

The predicate filter on the OMIM branch is load-bearing for rule 2 even though, in the 2026-07-06 release, all 10,045 `omim.org/entry/` values sit under `skos:exactMatch` and nothing else. If a future release put one under another predicate, storing it would create an OMIM mapping MONDO does not assert.

`OMIMPS:` phenotypic series are neither `OMIM:` identifiers nor `/entry/` URLs, and are not stored. A `replaced_by` naming a term in another ontology (MONDO obsoletes some CHEBI and UBERON nodes in the same file) is discarded.

If two MONDO terms claim an exact match to the same OMIM or Orphanet identifier, the parser throws, which ends the MONDO phase as a failure. No release to date contains such a collision. Should one ever reach the database, resolution fails closed on it as well (see [Ambiguity](#ambiguity)).

Exact matches to deprecated MONDO terms are stored and used without a status filter. In the 2026-07-06 release 1,328 Orphanet and 272 OMIM exact matches point at a deprecated term; see [Obsolete MONDO targets](#obsolete-mondo-targets).

### OMIM

The OMIM `mimTitles.txt` file creates the portal's OMIM rows and supplies titles, alternate titles, included titles, and entry status/type. It supplies no relationships to any other ontology, and the row stores none.

| `mimTitles.txt` prefix | Stored type/status |
| --- | --- |
| `Plus` | `TYPE_OMIM_PLUS`, active |
| `Number Sign` | `TYPE_OMIM_NUMBER`, active |
| `Percent` | `TYPE_OMIM_PERCENT`, active |
| `Caret` | `TYPE_OMIM_CARET`, deprecated |
| `Asterisk` | Skipped; these are not inserted by `update:diseases`. |
| `NULL` or another value | `TYPE_OMIM`, active |

An OMIM row exists so that a submitted OMIM identifier has an `original_disease_id` to point at, and so reconciliation can track which entries still exist. It is never the thing that makes a mapping.

### Orphanet

Each disorder in Orphadata `en_product1.xml` becomes a `Disease::TYPE_ORPHANET` row. The canonical stored prefix is `Orphanet:`, although inputs may also use `ORPHA:`. Labels, synonyms, descriptions, and active/deprecated status come from the Orphadata record. `DisorderFlag id="495"` with value `8192` marks a row deprecated.

The same file is Orphadata's cross-referencing product. Each external reference carries a `DisorderMappingRelation` and a `DisorderMappingValidationStatus`, and both are now read. A reference is stored only when the relation is `E` (exact) and the status is `Validated`, matched on the numeric `id` attribute (`21527` and `21611`) rather than on the sibling `<Name>`, which is localisable English prose.

| Orphadata source | Stored on the Orphanet row |
| --- | --- |
| MONDO, exact + validated | an entry in `xrefs.mondo_id` |
| OMIM, exact + validated | an entry in `xrefs.omim_id` |
| everything else | dropped |

More than half of Orphadata's OMIM references are broader (`BTNT`), narrower (`NTBT`) or undecided (`ND`): 3,781 of 8,745 are exact and validated. UMLS, GARD, ICD-10, ICD-11, MeSH and MedDRA references are dropped entirely; the portal accepts none of those namespaces as input.

Orphadata writes MONDO references as bare digits, and 2,045 of 9,979 are unpadded (`44`, `7800`, `18887`). They are left-padded to the 7 digits a MONDO CURIE uses, after which all of them name a real MONDO node.

Both fields are arrays, and an empty result is still an object (`{"mondo_id":[],"omim_id":[]}`), so a row with no references reads back with the same shape as one that has them.

## Resolving an identifier

All resolution is implemented by `DiseaseResolver::resolve($identifier)`. The return value is a `DiseaseResolution` containing:

- `original`: the eligible row for the normalized input CURIE, or null if that namespace has no row for it;
- `mondo`: the MONDO term it normalizes to, which is never null on a successful resolution; and
- `via`: the step that produced the result.

A submission stores `original` as `original_disease_id` and `mondo` as `disease_id`. `disease_id` is now always a MONDO term: the former fallback in which an unmapped Orphanet code resolved to itself is gone.

### Input normalization

`Disease::normalizeCurie()` trims whitespace, strips a path using `basename()`, keeps the first two colon-separated tokens, uppercases the namespace, and canonicalizes both `ORPHA:` and `ORPHANET:` to `Orphanet:`. For example:

| Input | Normalized form |
| --- | --- |
| `mondo:0012345` | `MONDO:0012345` |
| `ORPHA:123456` | `Orphanet:123456` |
| `orphanet:123456` | `Orphanet:123456` |
| `OMIM:612345:extra` | `OMIM:612345` |

A bare number, an identifier without a colon, or an unknown namespace does not resolve.

### Resolution steps

A **MONDO** identifier resolves to itself. Both `original` and `mondo` contain that row and `via` is `mondo_self`.

An **Orphanet** identifier takes the first step that yields a MONDO term:

| Step | Rule | `via` |
| --- | --- | --- |
| 1 | A MONDO term `skos:exactMatch`-es the submitted code | `mondo_exact_match` |
| 2 | Orphadata asserts an exact, validated MONDO equivalent for it | `orphanet_exact_match` |
| 3 | Orphadata asserts an exact OMIM reference, and a MONDO term `skos:exactMatch`-es that OMIM id | `omim_bridge` |
| 4 | Otherwise the identifier does not resolve | — |

An **OMIM** identifier gets step 1 only. `OMIMPS` takes the same path; no OMIMPS rows are loaded and MONDO records phenotypic series under a URL form the importer does not store, so it does not succeed in practice.

There is deliberately no Orphanet bridge for OMIM — an Orphanet term that references the OMIM id and has a MONDO equivalent. It would map 9 further identifiers in current data, and it would break rule 2, because the OMIM side has nothing to reciprocate with. Adding one is a possible future step and is noted as such in the resolver.

Step 3 is the one place a mapping is composed from two assertions rather than taken from one. It is still exact in both hops and reciprocal at the MONDO end: Orphadata asserts Orphanet ≡ OMIM exactly, and MONDO asserts MONDO ≡ that OMIM id exactly.

Requiring the first hop to be exact is what does the work here. Orphanet subtypes routinely carry OMIM references annotated broader (`BTNT`) or narrower (`NTBT`) — never `E` — and a great many of them carry several. Relaxing step 3 to accept those would not mostly recover mappings; it would mostly manufacture ambiguity. Measured over the 34 rejected Orphanet codes in the issue-132 upload that have a non-exact OMIM reference, 17 would reach a single MONDO term and 17 would reach between two and seven rival terms. `Orphanet:716903` is typical: four OMIM references, all broader or narrower, reaching four different MONDO terms. The pre-policy importer picked one of the four by last-wins scalar assignment.

### Ambiguity

If any step reaches more than one distinct MONDO term, resolution fails closed and logs a warning. No choice between two exact claims to the same concept is defensible, and silently taking the first would make the answer depend on row order. Current upstream data produces no such case at any step.

### Eligibility

Resolution returns only `ACTIVE` and `DEPRECATED` rows, preferring active and then the lowest database id. `REMOVED` and soft-deleted rows are excluded. The exclusion is applied identically to the per-CURIE lookups and to the cross-reference index, which is built from the query builder and so has no model scopes of its own.

The index maps each stored identifier to every MONDO row that lists it, so an ambiguity is detected rather than resolved by row order. Lookups are memoized per resolver instance; an upload that resolves a disease per row pays for each distinct identifier once. Instances must not be held between uploads, because the nightly `update:diseases` run can change the table under a long-lived worker.

### Behaviour by namespace

| Input namespace | Standalone row loaded? | Resolves through | Typical result |
| --- | --- | --- | --- |
| `MONDO` | Yes | Exact `curie` lookup | `original = MONDO`, `mondo` = the same row |
| `OMIM` | Yes | MONDO `xrefs.omim_id` | `original = OMIM`, `mondo = MONDO`; `original` is null when no OMIM row exists for the id |
| `OMIMPS` | No | Same path as OMIM | Does not succeed in current data |
| `ORPHA` / `Orphanet` | Yes | The three steps above | `original = Orphanet`, `mondo = MONDO`, or no resolution |
| anything else | No | — | Does not resolve |

## Rejection and warnings in the portal

### A rejected identifier

`Submission::load_from_json()` records a `disease_curie_id` entry in `submission_errors` and substitutes the `MONDO:0000001` placeholder when resolution fails. The message names the code that was submitted. The row is created, and from there the existing machinery applies: the red indicator in `SubmissionsListing.vue`, the "Show Errors" filter, the field highlighting in `SubmissionItem.vue`, the `ChangeDisease` dialog, and `JobStateMachine::submit()` refusing to submit a job that still has errored records.

This is deliberate: whether a disease identifier maps to MONDO is checked per record, after the rows exist, where the submitter can fix it in place, rather than as an upload-blocking check that rejects a whole file over a handful of rows.

Spreadsheet validation still resolves every `disease_id` and still reports what it finds, but as a **warning** — one grouped result for the column, listing each unresolvable value and the rows that used it — so the outcome is visible before the rows are processed rather than only after. What the job page shows depends on the outcome. For a rejected file, the warnings are stored with the errors on the document and shown alongside them, whatever their type. For an accepted file, the job page instead summarises the errors and PMID issues recorded on the job's submissions, grouped by field and message, so the summary survives a reload and stays current as records are fixed. The `disease_id` format check (`MONDO|OMIM|ORPHA|Orphanet` followed by digits) remains a blocking error.

### Obsolete MONDO targets

A MONDO term that MONDO has obsoleted is still a valid target. 1,130 active Orphanet disorders exact-match one, and deprecated diseases are already permitted in submissions, so rejecting them would lose a working mapping for no gain.

Instead, `SubmissionController` derives a non-blocking warning at display time from the resolved disease's status and passes it to the submission page, which renders it above the record. Where `xrefs.replaced_by` names a successor the warning names it; MONDO provides one for 167 of the 1,130 (14.8%), gives the weaker `consider` hint for 326, and gives nothing for 637, so the warning also has to read sensibly without one.

Because it is derived rather than stored, the warning applies retroactively to submissions created before the term was obsoleted, and disappears on its own if the term is ever reinstated.

## Application contexts

Every context calls `resolve($id)` and gets the same answer. The contexts differ only in what they do with it.

| Context | Consequence |
| --- | --- |
| Spreadsheet column validation | Warns, grouped by value, about identifiers that will be rejected per record. |
| Spreadsheet duplicate validation | Uses the resolved `original` row for duplicate identity, falling back to the MONDO term when `original` is null. Rows that do not resolve are skipped: there is no identity to compare. |
| Spreadsheet row processing | Stores both disease references, or records the rejection. |
| Programmatic submission API (`POST /api/submit`) | Same, through `Submission::load_from_json()`. |
| Programmatic API `check` action | Does not resolve diseases at all; the controller returns `OK` before processing the packet. |
| Portal disease search (`GET /api/lookup/disease/{id}`) | Displays the resolved MONDO term, or nothing. |
| Portal manual disease edit | Requires a non-null `original`; stores `original_disease_id` and `disease_id`. |
| OMIM-generated submissions (`php artisan update:omim`) | Stores only `disease_id` on the generated submission. |
| Historical GenCC import (`php artisan import:gencc`) | Does not use the resolver; looks up `disease_curie` and `disease_original_curie` by exact, case-sensitive `curie` match. |

For a successful row, the stored pair is:

| Submitted identifier | `original_disease_id` | `disease_id` |
| --- | --- | --- |
| MONDO | Submitted MONDO row | The same MONDO row |
| Mapped OMIM | Submitted OMIM row, or the placeholder with an error if no OMIM row exists | Selected MONDO row |
| Mapped Orphanet | Submitted Orphanet row | Selected MONDO row |

## Observed data

Counts are from the MONDO 2026-07-06 release and the Orphadata file dated 2026-06-23. They will drift with each update and are included to show which code paths matter in practice.

Where an active Orphanet disorder ends up:

| Step | Active Orphanet disorders | Share |
| --- | --- | --- |
| 1 — MONDO exact match | 9,502 | 94.1% |
| 2 — Orphanet exact match | 3 | 0.0% |
| 3 — via the OMIM bridge | 85 | 0.8% |
| 4 — rejected | 511 | 5.1% |

Of the 511 rejections, roughly 272 are "Group of disorders" and roughly 210 have `DisorderType = Category` — classification buckets rather than diseases.

| Measure | Count |
| --- | --- |
| MONDO terms in the release | 36,072, of which 3,975 are deprecated |
| MONDO `skos:exactMatch` OMIM identifiers | 10,045, of which 272 are on a deprecated term |
| MONDO `skos:exactMatch` Orphanet identifiers | 9,785, of which 1,328 are on a deprecated term |
| MONDO terms with more than one Orphanet exact match | 43 |
| MONDO terms naming a successor (`IAO_0100001`) | 2,228, all deprecated |
| OMIM or Orphanet identifiers claimed by more than one MONDO term | 0 |
| Orphadata MONDO references | 9,979, all exact and validated |
| Orphadata MONDO references written unpadded | 2,045 |
| Orphadata MONDO references naming an obsolete MONDO term | 904 |
| Orphadata OMIM references that are exact and validated | 3,781 of 8,745 |
| Step-1 hits whose MONDO target is deprecated | 1,130 |
| Resolution steps that are ambiguous in current data | 0 |

## Known gaps

- **Existing submissions are not re-resolved.** Roughly 990 recent submissions would fail re-resolution under these rules. They keep their stored `disease_id` and only meet the new rules on republish or a disease-field edit. Whether to grandfather, flag or re-resolve them is a curation decision.
- **Upstream disagreements are not reported.** 904 Orphadata MONDO references point at obsolete MONDO terms, a handful of direct source conflicts exist, and MONDO exact-matches some Orphanet codes Orphadata no longer lists. Nothing surfaces these.
- **Memory.** The Orphanet phase holds the downloaded XML string and a full SimpleXML DOM at once and is the first thing likely to exhaust memory on a constrained host.

## Implementation references

- [`UpdateDiseases`](../app/Console/Commands/UpdateDiseases.php) imports and reconciles MONDO, OMIM, and Orphanet data.
- [`Disease`](../app/Models/Disease.php) defines stored types, statuses, relationships, and CURIE normalization.
- [`DiseaseResolver`](../app/Services/DiseaseResolver.php) contains the resolution policy.
- [`DiseaseResolution`](../app/Services/DiseaseResolution.php) defines the resolver result and step names.
- [`SubmissionFileValidation`](../app/Services/SubmissionFileValidation.php) resolves each spreadsheet `disease_id` and warns about the ones that will be rejected.
- [`Submission::load_from_json()`](../app/Models/Submission.php) stores the disease references, or records the rejection.
- [`SubmissionController`](../app/Http/Controllers/SubmissionController.php) derives the obsolete-term warning.
