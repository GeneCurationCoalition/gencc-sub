# Disease mapping

This document describes how the submission portal imports disease ontology data, turns upstream relationships into database records, and resolves an input disease identifier in each application context. It describes the current implementation; where the database loses source provenance or callers use different policies, those differences are called out explicitly.

In this document, an **upstream assertion** is a relationship stated in a downloaded source file. A **materialized mapping** is a `diseases.mondo_id` foreign key created by the application. These are not interchangeable: some foreign keys directly reflect a MONDO assertion, while others are inferred by joining through another namespace.

## Interpreting and storing the upstream data

`php artisan update:diseases` loads the sources in this order:

1. MONDO from `http://purl.obolibrary.org/obo/mondo/mondo-with-equivalents.json`
2. OMIM titles from `https://data.omim.org/downloads/{OMIM_API_KEY}/mimTitles.txt`
3. Orphanet from `https://www.orphadata.com/data/xml/en_product1.xml`
4. post-processing that propagates MONDO mappings across Orphanet–OMIM references
5. reconciliation of records no longer present in a source

MONDO is loaded first because its database IDs are the targets stored in `diseases.mondo_id` on OMIM and Orphanet rows.

Despite its name, the MONDO URL redirects to the same `mondo.json` release asset as the plain `mondo.json` PURL; there is no separate with-equivalents artifact, and the file contains no non-MONDO equivalent-class nodes.

Each source is skipped when its HTTP headers (ETag, then Last-Modified, then Content-Length) match the previous run. Steps 4 and 5 run only if at least one source changed; when all three are unchanged the command exits after step 3. A downloaded MONDO file younger than one hour on disk is reused even when headers changed.

A failure inside the MONDO phase, including a download failure or the ambiguity exception described below, is caught and logged, and the phase returns as if unchanged. The OMIM and Orphanet phases still run against whatever MONDO maps were built before the failure. If either of those sources changed, reconciliation also runs with a partial set of seen MONDO identifiers and would deprecate active MONDO rows that were never reached. This is fail-open behavior, not an abort.

### Database representation

The `diseases` table contains standalone rows for three namespaces:

- MONDO
- OMIM, with several OMIM entry types
- Orphanet

Other namespaces such as DOID, GARD, UMLS, and NCIT do not normally get their own rows. Their identifiers are stored as values in a MONDO row's `xrefs` JSON object.

The fields that control mapping are:

| Field | Meaning |
| --- | --- |
| `curie` | The row's canonical identifier, such as `MONDO:0012345`, `OMIM:612345`, or `Orphanet:123456`. |
| `type` | Identifies whether the row came from MONDO, an OMIM entry type, or Orphanet. |
| `xrefs` | A reduced JSON representation of selected upstream cross-references. Its shape depends on the row's source. |
| `mondo_id` | A nullable foreign key from an OMIM or Orphanet row to a MONDO row. MONDO rows themselves have `mondo_id = null`. |
| `status` | `ACTIVE` or `DEPRECATED` in practice. A `REMOVED` constant exists, but nothing in the application assigns it; reconciliation uses `DEPRECATED`. Soft deletion is tracked separately by `deleted_at`. |
| `deprecated_name` | The upstream label for a deprecated term, or the `REMOVED- ` prefixed former name for a row reconciliation could not find in its source. `name` is not changed. |

A `mondo_id` records the selected target efficiently, but it does not record why that target was selected. From the column alone, a caller cannot tell whether the mapping came from a MONDO exact match, a generic MONDO xref, or a transitive Orphanet–OMIM join.

### MONDO

Each node whose identifier starts with `MONDO` becomes a `Disease::TYPE_MONDO` row; other nodes in the file (UBERON, HP, GO, and so on) are skipped. The importer stores the CURIE, label, definition, exact synonyms, selected cross-references, and active/deprecated status. For a deprecated node the label goes to `deprecated_name`, the definition is cleared, and an existing row keeps its previous `name`. A MONDO row always has `mondo_id = null` because it is already the normalization target.

MONDO supplies two relevant forms of outgoing relationship:

- `skos:exactMatch` values in `meta.basicPropertyValues`
- identifiers in `meta.xrefs`

The generic xrefs carry no per-entry annotation in the OBO Graphs JSON, so `skos:exactMatch` is the only equivalence signal available. In current releases every OMIM and Orphanet exact match is also repeated as a generic xref, so an exact-matched identifier is always present in `meta.xrefs`; the reverse does not hold, and a few hundred OMIM and Orphanet identifiers appear only as generic xrefs.

During a run, the importer keeps exact matches and generic xrefs in separate in-memory maps. For OMIM and Orphanet, exact match has priority; otherwise the first MONDO xref encountered is selected. If two different MONDO terms claim an exact match to the same OMIM or Orphanet identifier, the parser throws; because of the fail-open handling described above this ends the MONDO phase rather than the command. The check does not exclude deprecated MONDO terms. Current MONDO releases contain no such collision, and no OMIM or Orphanet identifier is a generic xref on more than one term, so neither the abort nor the first-wins rule has been exercised by real data. Exact matches to deprecated MONDO terms are used without a status filter; in the 2026-07 release about 1,300 Orphanet and 270 OMIM exact matches point at deprecated terms.

The stored `xrefs` object is more lossy than those in-memory maps:

| Upstream MONDO namespace | Stored key | Stored shape | Notes |
| --- | --- | --- | --- |
| OMIM | `omim_id` | array | Combines OMIM entry URLs from `basicPropertyValues` with generic xrefs and removes duplicates. The storage parser does not check the property predicate; currently harmless, because OMIM entry URLs appear only under `skos:exactMatch`. |
| Orphanet | `orpha_id` | scalar | Built from generic `meta.xrefs` only; the `skos:exactMatch` value itself is not copied. Because every exact match is also a generic xref, the identifier is normally persisted anyway. It is lost only when a term has two or more Orphanet xrefs and a later value overwrites the earlier one, which affects about 100 terms. |
| DOID | `do_id` | scalar | Later values overwrite earlier ones. |
| GARD | `gard_id` | scalar | Later values overwrite earlier ones. |
| UMLS | `umls_id` | scalar | Later values overwrite earlier ones. |
| MESH | `mesh` | scalar | Stored, but `DiseaseResolver` does not accept MESH input. |
| NCIT | `ncit` | scalar | Stored, but `DiseaseResolver` does not accept NCIT input. |
| OGMS | `ogms` | scalar | Stored, but `DiseaseResolver` does not accept OGMS input. |
| MEDGEN | `medgen_id` | always null | The key is initialized, but the parser has no `MEDGEN` case. MONDO supplies about 21,500 `MEDGEN:` xrefs, so adding the case is all that is missing for MEDGEN resolution to work. |
| (none) | `omim_label`, `orpha_label` | always null | Initialized placeholders that nothing populates. |

Prefix matching is a case-sensitive string comparison on the text before the colon. MONDO spells these `OMIM:`, `Orphanet:`, and `MEDGEN:`, so the parser's spellings match the source. `OMIMPS:` xrefs are not `OMIM:` and are dropped, as are `skos:exactMatch` values pointing at OMIM phenotypic series URLs.

If the MONDO download is unchanged, the importer reconstructs its in-memory OMIM and Orphanet maps from the stored `xrefs` JSON. This path differs from a fresh parse in three ways: it reads only `ACTIVE` MONDO rows, it loads every stored `omim_id` and `orpha_id` into the exact-match maps so generic-only xrefs are promoted to exact, and where the same identifier is stored on more than one row the last row read wins rather than the first. Over identical data the two paths can therefore assign different `mondo_id` values.

The raw direction is:

```text
MONDO term -> OMIM / Orphanet / DOID / GARD / UMLS / ... identifier
```

For OMIM and Orphanet rows, the application reverses the selected relationship into:

```text
OMIM or Orphanet row -> diseases.mondo_id -> MONDO row
```

### OMIM

The OMIM `mimTitles.txt` file creates the portal's OMIM rows and supplies titles, alternate titles, included titles, and entry status/type. It does not supply OMIM-to-MONDO relationships.

The importer handles the OMIM title prefixes as follows:

| `mimTitles.txt` prefix | Stored type/status |
| --- | --- |
| `Plus` | `TYPE_OMIM_PLUS`, active |
| `Number Sign` | `TYPE_OMIM_NUMBER`, active |
| `Percent` | `TYPE_OMIM_PERCENT`, active |
| `Caret` | `TYPE_OMIM_CARET`, deprecated |
| `Asterisk` | Skipped; these are not inserted by `update:diseases`. |
| `NULL` or another value | `TYPE_OMIM`, active |

For each imported OMIM row, `mondo_id` is assigned from the MONDO maps built in the previous phase:

1. use a MONDO `skos:exactMatch`, if present;
2. otherwise use the first MONDO generic xref;
3. otherwise leave `mondo_id` null until post-processing.

The OMIM row's own `xrefs` contains only `include_titles`; MONDO relationships are represented by `mondo_id` and by `omim_id` on the MONDO row.

### Orphanet

Each disorder in Orphadata `en_product1.xml` becomes a `Disease::TYPE_ORPHANET` row. The canonical stored prefix is `Orphanet:`, although inputs may also use `ORPHA:`. Labels, synonyms, descriptions, and active/deprecated status come from the Orphadata record. `DisorderFlag id="495"` with value `8192` marks a row deprecated.

The same file is Orphadata's cross-referencing product. Each disorder carries outgoing external references with metadata describing their relationship and validation status. The current parser retains only:

| Orphadata source | Stored on the Orphanet row |
| --- | --- |
| OMIM | `xrefs.omim_id`, as a scalar |
| UMLS | `xrefs.umls_id`, as a scalar |

If a record has multiple retained references from the same namespace, the last one overwrites the earlier ones. In the 2026-06 Orphadata file roughly 1,000 disorders list more than one OMIM reference, and some of those combine one exact reference with several broader ones, so last-wins can retain a broader OMIM mapping in place of the exact one. No disorder lists more than one UMLS reference.

The parser discards Orphadata references to MONDO, ICD-10, ICD-11, GARD, MeSH, and MedDRA. It also discards `DisorderMappingRelation` and `DisorderMappingValidationStatus`, including whether an Orphanet–OMIM relationship is exact (`E`), broader (`BTNT`), narrower (`NTBT`), or undecided (`ND`). More than half of Orphadata's OMIM references are non-exact.

The discarded MONDO references are the largest untapped source of Orphanet mappings. Orphadata asserts about 10,000 Orphanet → MONDO references, all marked exact and validated. Roughly 2,100 of them have no counterpart in MONDO's own exact matches or xrefs, and about 600 active disorders have no MONDO → Orphanet assertion at all. Those disorders are the ones that currently resolve to themselves.

The retained Orphanet `umls_id` is descriptive data only in the current implementation. Resolving a submitted UMLS identifier searches `umls_id` on MONDO rows; it does not traverse UMLS references stored on Orphanet rows.

An Orphanet row's initial `mondo_id` is derived from MONDO's outgoing assertions, rather than from Orphadata's own MONDO reference:

1. use a MONDO `skos:exactMatch` to this Orphanet ID, if present;
2. otherwise use the first MONDO generic xref to this Orphanet ID;
3. otherwise leave `mondo_id` null until post-processing.

Consequently, the two raw sources are not treated symmetrically. A relationship stated only as Orphanet → MONDO is not directly consumed, while MONDO → Orphanet can create the foreign key.

### Transitive mappings created during post-processing

After all three sources are loaded, and only if at least one of them changed, the command fills remaining null foreign keys in both directions across retained Orphanet–OMIM references.

For an OMIM row without a MONDO target:

```text
OMIM row
  <- Orphanet row.xrefs.omim_id
  <- Orphanet row.mondo_id
  -> assign the same MONDO target to the OMIM row
```

For an Orphanet row without a MONDO target:

```text
Orphanet row.xrefs.omim_id
  -> OMIM row.mondo_id
  -> assign the same MONDO target to the Orphanet row
```

These are application inferences. The OMIM file does not assert an OMIM → MONDO relationship, and the selected MONDO term need not contain the submitted identifier in its own xrefs. Because the importer discarded Orphadata's mapping relation, a broader, narrower, or undecided Orphanet–OMIM relationship can participate in the same propagation as an exact relationship.

The implementation also chooses the first matching Orphanet or OMIM row when more than one route is available. Neither direction filters on status, so a deprecated Orphanet row can supply an OMIM row's target. The two directions also differ in type filtering: the OMIM-via-Orphanet pass includes Caret rows, while the Orphanet-via-OMIM pass excludes them. No provenance or ambiguity information is stored with the resulting `mondo_id`.

Nearly every foreign key whose target lacks a reciprocal xref traces back to this bridge (see [Observed data](#observed-data)). One example of the semantic risk: `OMIM:190351` (TRPS3) maps to the MONDO term for trichorhinophalangeal syndrome type I, whose only OMIM xref is `190350`.

### Status and reconciliation

MONDO's `deprecated` flag and Orphanet/OMIM source markers set a row active or deprecated during import. After an update, a previously active MONDO, OMIM, or Orphanet row absent from its current source is set to `DEPRECATED`, and `deprecated_name` receives its former name with a `REMOVED- ` prefix. Its existing `mondo_id` is preserved so historical submissions retain their relationship. Only rows that were active are touched, so a row can be reconciled at most once.

Resolution permits active and deprecated rows, preferring active rows and then the lowest database ID. Rows with the `REMOVED` status or a `deleted_at` value are excluded; no such rows exist in current data, so this exclusion is code-only. An active Orphanet row whose `mondo_id` points to an excluded MONDO target can still fall back to itself.

## Resolving an identifier

All current resolution logic is implemented by `DiseaseResolver::resolve($identifier, $forSubmission = false)`. The return value is a `DiseaseResolution` containing:

- `original`: the eligible row for the normalized input CURIE, if that namespace has standalone rows;
- `mondo`: the selected normalized target; and
- `via`: the strategy that produced the result.

The property is named `mondo`, but it can contain an Orphanet row when the active Orphanet term has no MONDO mapping.

### Input normalization

`Disease::normalizeCurie()` trims whitespace, strips a path using `basename()`, keeps the first two colon-separated tokens, uppercases the namespace, and canonicalizes both `ORPHA:` and `ORPHANET:` to `Orphanet:`. For example:

| Input | Normalized form |
| --- | --- |
| `mondo:0012345` | `MONDO:0012345` |
| `ORPHA:123456` | `Orphanet:123456` |
| `orphanet:123456` | `Orphanet:123456` |
| `OMIM:612345:extra` | `OMIM:612345` |

A bare number, an identifier without a colon, or an unknown namespace does not resolve. The normalizer itself does not require the identifier suffix to be numeric; individual entry points may enforce a narrower syntax before calling the resolver.

### Resolution order

For a MONDO input, the eligible MONDO row resolves to itself. Both `original` and `mondo` contain that row and `via` is `mondo_self`.

For OMIM or Orphanet, resolution uses this order:

1. Find the eligible row for the submitted CURIE. If it has a `mondo_id` whose target is eligible, return that target with `via = equivalence_fk`.
2. Search eligible MONDO rows for the submitted number in the namespace's stored xref field. If found, return that MONDO row with `via = equivalence_xref`. The `original` value may be null if no standalone row exists.
3. For Orphanet only, if the original row is active, return the Orphanet row as both `original` and `mondo`, with `via = orphanet_self`.
4. Otherwise return null.

`OMIMPS` takes the same path as `OMIM`, including the standalone-row lookup. No OMIMPS rows are loaded, so in practice it only ever reaches step 2, but it would honor a `mondo_id` if such a row existed.

For DOID, GARD, MEDGEN, and UMLS, the resolver only searches MONDO `xrefs`. These namespaces have no standalone row in the normal import, so a successful result has `original = null` and `via = equivalence_xref`.

The xref index is memoized per resolver instance. If more than one eligible MONDO row contains the same xref, an active row wins over a deprecated row; the lowest database ID breaks a tie. Current data has no OMIM or Orphanet number stored on more than one MONDO row, so this tie-break is not exercised.

## Behavior by namespace

| Input namespace | Standalone row normally loaded? | Resolver source | Can fall back to itself? | Typical result |
| --- | --- | --- | --- | --- |
| `MONDO` | Yes | Exact `curie` lookup | It already is the target | `original = MONDO`, `mondo = MONDO` |
| `OMIM` | Yes | OMIM row's `mondo_id`, then MONDO `xrefs.omim_id` | No | `original = OMIM`, `mondo = MONDO`; `original` can be null for an xref-only hit |
| `OMIMPS` | No | Same path as OMIM; in practice MONDO `xrefs.omim_id` | No | `original = null`, `mondo = MONDO` if the number appears as an OMIM xref. MONDO records phenotypic series under `OMIMPS:`, which the importer drops, so this rarely succeeds. |
| `ORPHA` / `Orphanet` | Yes | Orphanet row's `mondo_id`, then MONDO `xrefs.orpha_id` | Yes, when the Orphanet row is active | Usually `original = Orphanet`, `mondo = MONDO`; otherwise both can be the Orphanet row |
| `DOID` | No | MONDO `xrefs.do_id` | No | `original = null`, `mondo = MONDO` |
| `GARD` | No | MONDO `xrefs.gard_id` | No | `original = null`, `mondo = MONDO` |
| `MEDGEN` | No | MONDO `xrefs.medgen_id` | No | Nominally supported, but the current importer does not populate this field |
| `UMLS` | No | MONDO `xrefs.umls_id` | No | `original = null`, `mondo = MONDO` |

MESH, NCIT, and OGMS values may be stored on MONDO rows, but `DiseaseResolver` does not accept those namespaces. ICD relationships from Orphadata are not stored and are not resolver inputs.

## Permissive and submission resolution

The default call, `resolve($id)`, accepts any result produced by the namespace rules above.

`resolve($id, true)` adds one check for `OMIM` and `OMIMPS`: the selected MONDO row must itself list the submitted number in `xrefs.omim_id`. This rejects an OMIM mapping that exists only because an OMIM row's `mondo_id` was inferred through Orphanet. When the check fails the resolver returns null; it does not retry the xref strategy against a different MONDO row.

The check means “MONDO mentions this OMIM identifier,” not “MONDO declares an exact match.” Exact matches and generic OMIM xrefs are combined in the stored `omim_id` array, so the resolver cannot distinguish them at this stage.

The additional check does not apply to Orphanet. Submission mode therefore accepts all of the following:

- an Orphanet foreign key whose selected MONDO row does not contain the Orphanet ID;
- an Orphanet mapping inferred through OMIM; and
- an active Orphanet row with no MONDO target, resolved to itself.

Requiring a stored MONDO backlink for Orphanet would reject the roughly 200 Orphanet rows whose `mondo_id` came from the OMIM bridge, a small number of direct exact matches on MONDO terms that list several Orphanet xrefs and kept a different one in the scalar `orpha_id`, and the intentional active-Orphanet fallback. The current policy consequently preserves useful Orphanet submissions, but it does not distinguish direct mappings from transitive ones as strictly as the OMIM submission check does.

For namespaces other than OMIM and OMIMPS, the boolean argument does not change the resolver's result.

## Application contexts

The repository has one resolution implementation but two policies. Callers select the default or submission policy, and some entry points impose their own syntax or storage requirements.

| Context | Resolver call | Input namespaces exposed by that context | Consequence |
| --- | --- | --- | --- |
| Spreadsheet column validation | `resolve($id, true)` | Regex permits MONDO, OMIM, ORPHA, and Orphanet | Rejects FK-only OMIM mappings; accepts mapped or standalone active Orphanet terms. |
| Spreadsheet duplicate validation | `resolve($id, true)` | Same spreadsheet syntax | Uses the resolved `original` row for duplicate identity, falling back to the target when `original` is null. With the spreadsheet regex that fallback is reachable only in the xref-only edge case below. |
| Spreadsheet Orphanet warning | `resolve($id, true)` | Orphanet spellings allowed by the spreadsheet | Adds a non-blocking grouped warning for `via = orphanet_self`. |
| Spreadsheet row processing | `resolve($id)` | Rows that passed spreadsheet validation | Stores both disease references. It relies on the earlier strict validation to have rejected an FK-only OMIM mapping. |
| Programmatic submission API (`POST /api/submit`) | `resolve($id)` through `Submission::load_from_json()` | Resolver-supported namespaces; no controller-level disease syntax check | Uses permissive resolution. A successful xref-only result still lacks `original`, which `load_from_json()` records as an invalid disease error and replaces with the placeholder. |
| Programmatic API `check` action | No disease resolution | None are actually checked | The controller returns `OK` before processing the packet; it does not validate disease identifiers. |
| Portal disease search | `resolve($id)` through `GET /api/lookup/disease/{id}` | Frontend permits MONDO, OMIM, ORPHA, and Orphanet | Displays the resolved target only. An unmapped active Orphanet term is displayed as itself. |
| Portal manual disease edit | `resolve($id)` | Frontend permits MONDO, OMIM, ORPHA, and Orphanet | Requires `resolution->original`; stores `original_disease_id` and `disease_id`. Uses permissive OMIM behavior. |
| OMIM-generated submissions (`php artisan update:omim`) | `resolve($id)` | Synthesized OMIM identifiers | Uses permissive resolution and stores only `disease_id` on the generated submission. |
| Historical GenCC import (`php artisan import:gencc`) | No resolver | CURIEs already present in the import file | Looks up `disease_curie` and `disease_original_curie` by exact, case-sensitive `curie` match with no normalization and no status filter (soft-deleted rows are excluded by the model's global scope); if the original row is absent, it uses the normalized disease row. |

### Spreadsheet upload

The file validator accepts only `MONDO:<digits>`, `OMIM:<digits>`, `ORPHA:<digits>`, or `Orphanet:<digits>`, case-insensitively. It creates one resolver for the validation pass and reuses it for column checks, duplicate checks, and Orphanet fallback warnings.

New and republish rows are later processed through `Submission::load_from_json()` with another resolver reused for that processing pass. That method calls the default policy rather than submission mode. In the normal workflow this does not weaken OMIM file validation because a row rejected during validation is not eligible for processing. It does mean `load_from_json()` does not independently enforce the strict rule if invoked without the file-validation step.

There is also an xref-only edge case. The validator considers a result valid when a MONDO row contains the submitted OMIM or Orphanet xref even if the corresponding standalone OMIM or Orphanet row is missing. Duplicate validation then uses the MONDO target as the original identity. During processing, `load_from_json()` requires `resolution->original`, records an invalid-disease error when it is null, and assigns the placeholder disease as `original_disease_id`. Normal source coverage makes this unusual, but the two phases do not have identical success requirements.

For a successful row, the intended storage is:

| Submitted identifier | `original_disease_id` | `disease_id` |
| --- | --- | --- |
| MONDO | Submitted MONDO row | Same MONDO row |
| Mapped OMIM | Submitted OMIM row | Selected MONDO row |
| Mapped Orphanet | Submitted Orphanet row | Selected MONDO row |
| Active Orphanet without MONDO | Submitted Orphanet row | Same Orphanet row |

The last case is deliberately accompanied by a warning because `disease_id` is not a MONDO row.

### Programmatic submissions

`SubmitController` passes API payloads directly to `Submission::load_from_json()`, which uses permissive resolution. This means an OMIM identifier accepted through an inferred `mondo_id` can enter through the programmatic API even when spreadsheet validation would reject the same identifier.

The API's `check` action currently validates authentication, submitter ownership, and the outer action, then returns success without calling `load_from_json()`. It should not be interpreted as a disease mapping check.

### Portal lookup and manual edits

The portal's disease dialog restricts user input to MONDO, OMIM, and the two Orphanet spellings. Its search request and update request both use permissive resolution.

The lookup endpoint returns only the resolved target's CURIE, name, and description. For mapped OMIM or Orphanet input, the UI therefore previews the MONDO target. For an unmapped active Orphanet input, it previews the Orphanet row.

The update endpoint resolves the original input again and requires a non-null `original`. That requirement is satisfied for normal MONDO, OMIM, and Orphanet rows, but would reject xref-only namespaces even if the resolver found a MONDO target for them.

## Practical interpretation of stored relationships

When reading a record, use these rules:

- A MONDO row's `xrefs` means that the imported MONDO source mentioned those identifiers, subject to the lossy scalar fields described above.
- An OMIM or Orphanet row's `mondo_id` means the update pipeline selected that MONDO row. It does not by itself prove that MONDO directly mentions the source identifier.
- An Orphanet row's `xrefs.omim_id` means the Orphadata source listed that OMIM identifier, but the stored value no longer says whether the relationship was exact, broader, narrower, undecided, or validated.
- Matching foreign keys in both directions do not prove that both upstream ontologies asserted the relationship. The application may have materialized the reverse or transitive direction.
- `DiseaseResolution::via` describes how the resolver found the stored data (`mondo_self`, `equivalence_fk`, `equivalence_xref`, or `orphanet_self`). It does not recover the upstream provenance that was discarded during import.

Any future policy that distinguishes exact equivalence from broader, narrower, or inferred mappings will require preserving more source relationship metadata than the current `xrefs` and `mondo_id` fields retain. The metadata exists upstream: MONDO's `skos:exactMatch` predicate, Orphadata's `DisorderMappingRelation`, and Orphadata's own MONDO references are all read or readable by the importer today and discarded before storage.

### Observed data

Counts below are from an August 2026 database snapshot, the MONDO 2026-07-06 release, and the Orphadata file dated 2026-06-23. They will drift with each update and are included to show which code paths matter in practice.

| Measure | Count |
| --- | --- |
| OMIM rows whose `mondo_id` target lacks the OMIM number in `xrefs.omim_id` | 18, all reachable via an Orphanet bridge |
| Orphanet rows whose `mondo_id` target lacks the Orphanet number in `xrefs.orpha_id` | 227, of which 225 have an OMIM bridge |
| Active Orphanet rows with `mondo_id = null` (resolve to themselves) | 1,573 |
| Active OMIM or Orphanet rows whose `mondo_id` target is a deprecated MONDO row | 15 |
| OMIM or Orphanet numbers stored on more than one MONDO row | 0 |
| Rows with `REMOVED` status or a `deleted_at` value | 0 |
| MONDO terms with more than one Orphanet xref (one lost to the scalar) | 98 |
| Orphadata OMIM references that are non-exact (`BTNT`, `NTBT`, `ND`) | 4,964 of 8,745 |
| Orphadata MONDO references with no MONDO-side counterpart | about 2,100 of about 10,000 |

## Implementation references

- [`UpdateDiseases`](../app/Console/Commands/UpdateDiseases.php) imports and reconciles MONDO, OMIM, and Orphanet data.
- [`Disease`](../app/Models/Disease.php) defines stored types, statuses, relationships, and CURIE normalization.
- [`DiseaseResolver`](../app/Services/DiseaseResolver.php) contains the resolution rules and submission-mode check.
- [`DiseaseResolution`](../app/Services/DiseaseResolution.php) defines the resolver result and strategy names.
- [`SubmissionFileValidation`](../app/Services/SubmissionFileValidation.php) applies submission mode during spreadsheet validation.
- [`Submission::load_from_json()`](../app/Models/Submission.php) stores the original and normalized disease references during spreadsheet and programmatic API processing.
