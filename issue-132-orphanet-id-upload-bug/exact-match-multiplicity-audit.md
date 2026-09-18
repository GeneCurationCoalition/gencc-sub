# Exact-match multiplicity audit — 2026-09-17

Some input disease identifiers have exact-match paths to **multiple distinct
MONDO terms** in the audited files. The sources do not always agree on a single
MONDO target.

The resolver nevertheless selects one result for these inputs because it gives
some sources priority over others. Once a higher-priority lookup succeeds, it
does not inspect the alternatives. A single result from the resolver therefore
does **not** mean all available exact-match paths lead to that result.

## A real input with three different MONDO targets

**Orphanet:1941 — Juvenile absence epilepsy** illustrates the distinction:

| Source assertion or path | MONDO target |
| --- | --- |
| MONDO itself lists an exact match to Orphanet:1941 | MONDO:0800453 — active juvenile absence epilepsy |
| Orphadata lists a first exact, validated MONDO equivalent | MONDO:0011876 — obsolete juvenile absence epilepsy |
| Orphadata lists a second exact, validated MONDO equivalent | MONDO:0800453 — active juvenile absence epilepsy |
| Orphadata lists exact, validated OMIM:607631; MONDO:0020772 exact-matches that OMIM identifier | MONDO:0020772 |

These paths reach **three distinct MONDO terms**. They exist in the audited
files now; they are not synthetic examples. The first and second Orphadata
equivalents above are just two entries in its list, not a preference order.

For an Orphanet input, our resolver tries these sources in order:

1. MONDO's own exact-match list naming the Orphanet identifier.
2. The Orphanet record's exact, validated MONDO equivalents in Orphadata.
3. The Orphanet record's exact, validated OMIM references, followed back to
   MONDO through MONDO's own exact-match lists.

For Orphanet:1941, step 1 finds MONDO:0800453 and returns immediately. Steps 2
and 3 are never evaluated. The result is determined by **our source-priority
policy**, even though other exact-match paths lead elsewhere.

Across the audited snapshots, every lookup the resolver actually needs to
evaluate finds either no MONDO candidate or one candidate. The multiple
candidates occur in lower-priority lookups that it skips after an earlier
success. That is the limited sense in which the current resolution process has
no ambiguity. The underlying sources still contain competing exact-match paths.

## Inputs and method

- `data/mondo-with-equivalents.json`: MONDO release **2026-09-01**, 36,091 MONDO nodes.
- `data/en_product1.xml`: Orphadata generation date **2026-06-23**, 11,645 disorders.
- `data/mimTitles.txt`: generated **2026-09-16**. Its fields are prefix, MIM number,
  preferred title, alternative titles, and included titles. It supplies no MONDO
  or Orphanet equivalence assertions, so it cannot independently confirm or
  contradict cross-ontology mappings.
- Comparison: `reference-files/mondo-v2026-07-06.json`, 36,072 MONDO nodes.
  The reference Orphadata file is byte-identical to the cached copy.

The application downloads all three sources into `data/` (see
`CachesFileHeaders::downloadAndCacheFile()` and
`UpdateDiseases::downloadFileToDisk()`). They are already together there. No
download or database update was performed. These findings describe those local
snapshots, not a fresh check of remote releases.

The read-only `audit-exact-matches.php` script calls the actual import parsers:
MONDO `skos:exactMatch`, and Orphadata relation `E` (`21527`) with validation
status `Validated` (`21611`). It deduplicates targets as the application does,
includes obsolete MONDO nodes because the resolver permits them, builds reverse
MONDO indexes, and counts candidates at each step of the resolution priority.
There were no unvalidated Orphadata exact references to MONDO/OMIM in this file.

```sh
php -d error_reporting=24575 issue-132-orphanet-id-upload-bug/audit-exact-matches.php
php -d error_reporting=24575 issue-132-orphanet-id-upload-bug/audit-exact-matches.php issue-132-orphanet-id-upload-bug/reference-files mondo-v2026-07-06.json orphanet-en_product1.xml
```

The JSON output includes all matching records, labels, target statuses and
cross-references, not just the examples below.

## Counts

Both MONDO snapshots yield the same counts. Each row below counts records in
the named source file, not pairs of identifiers or resolver results:

| Source file | What each counted record explicitly asserts | Number of records |
| --- | --- | ---: |
| MONDO | One MONDO term lists multiple exact OMIM matches | 8 |
| MONDO | One MONDO term lists multiple exact Orphanet matches | 43 |
| Orphadata | One Orphanet record lists multiple exact MONDO matches | 43 |
| Orphadata | One Orphanet record lists multiple exact OMIM matches | 0 |

The two counts of 43 are separate findings from different source files. They
do not mean the same 43 relationships are asserted in both directions.

For example, **MONDO:0007947 — Marfan syndrome** lists two exact Orphanet
matches in the MONDO file:

```text
MONDO:0007947 → Orphanet:558
MONDO:0007947 → Orphanet:284963
```

This is one of the 43 MONDO terms in the second row. Neither Orphanet identifier
appears in another MONDO term's exact-match list. The resolver searches those
lists at step 1 when given an Orphanet identifier, so both inputs have the same
single result at that step:

| Submitted identifier | MONDO term found by the resolver |
| --- | --- |
| Orphanet:558 | MONDO:0007947 |
| Orphanet:284963 | MONDO:0007947 |

That distinction holds throughout both MONDO snapshots: a MONDO term can list
several Orphanet identifiers, but no individual Orphanet identifier appears in
the exact-match lists of two different MONDO terms. The same is true of OMIM
identifiers in MONDO's lists. Considering just the OMIM bridge route, no
Orphanet record reaches multiple MONDO terms through that route either. This
does not mean the bridge agrees with the other routes: Orphanet:1941 above
shows that its single bridge target can differ from its direct-match targets.

Separately, the third row counts **43 Orphanet records in Orphadata**, each
listing multiple exact MONDO equivalents. For each of these Orphanet records,
exactly one MONDO term's own exact-match list names its Orphanet identifier.
The resolver selects that term at step 1, before consulting the Orphadata
record's multiple MONDO equivalents at step 2. Orphanet:1941 above is a
concrete example of this separate finding.

For the September MONDO snapshot, 40 of those Orphanet records name one active
and one obsolete MONDO term. Three name two active MONDO terms:

| Orphanet | MONDO equivalents asserted by Orphadata | Target selected at step 1 from MONDO's own assertions |
| --- | --- | --- |
| 2273 — Ichthyosis follicularis-alopecia-photophobia syndrome | MONDO:0100213; MONDO:0100212 | MONDO:0100212 |
| 436252 — Combined immunodeficiency-multiple intestinal atresia | MONDO:0800030; MONDO:0030831 | MONDO:0030831 |
| 100081 — Neuroendocrine tumor of the rectum | MONDO:0003646; MONDO:0015068 | MONDO:0015068 |

Consequently, preferring an active target would not resolve every case even if
we changed the existing policy that permits deprecated targets.

Applying the resolver's priority order to all 11,645 Orphanet records gives
9,758 results at step 1, 36 at step 2, 161 at step 3, and 1,690 unresolved
identifiers. No evaluated lookup has multiple candidates. These are resolution
outcomes under that policy, not a
count of identifiers for which all possible paths agree.

The OMIM counterpart to the Marfan example is **MONDO:0001046 — imperforate
anus**. Its MONDO record lists exact matches to OMIM:207500 and OMIM:301800.
Submitting either OMIM identifier resolves to MONDO:0001046; neither identifier
appears in another MONDO term's exact-match list.

## Why the fallback bug still matters

The reported bug was a separate issue from choosing which source has priority:
when a lookup found multiple candidates, the code treated that result like
finding no candidate and tried the next fallback. The local implementation now
preserves ambiguity as a distinct outcome and stops resolution at that step.

For example, if MONDO's own match to Orphanet:1941 disappeared while the other
assertions remained, step 1 would find nothing. Step 2 would then find both
MONDO:0011876 and MONDO:0800453. Previously, the code would log that ambiguity
but continue to step 3 and accept MONDO:0020772. It now returns an error naming
the two step-2 candidates without consulting the bridge.

That change to the source data is hypothetical. The competing paths already
exist, but the complete audited snapshots do not trigger this fallback bug
because step 1 succeeds first.

## Implemented handling

1. Retain the current source priority. A unique MONDO-side assertion continues
   to win, including the 43 Orphanet records with multiple MONDO equivalents.
   Do not reject an entire upstream record merely because it contains multiple
   outgoing exact references.
2. Distinguish absent, unique, and ambiguous candidate sets internally.
   Advance to another fallback only for an absent mapping. Ambiguity at an
   evaluated step terminates resolution, including ambiguity within one of
   several OMIM bridge lookups. Memoization retains that distinction.
3. Surface a blocking per-submission validation error naming the submitted
   identifier and candidate CURIEs, labels and deprecated status; log the
   conflicting candidates and the step. Preserve the submitted payload and
   leave unresolved disease references null. This is a per-record error, not
   an unhandled exception that aborts the entire upload. Upload warning details,
   disease lookups and manual-edit failures carry the same explanation.
4. Cover the actual Orphanet:1941 assertions in a regression fixture: first
   verify its normal step-1 result, then remove that assertion in the fixture
   and verify that step-2 ambiguity cannot fall through to MONDO:0020772.
   Also cover step-1 collisions, ambiguous OMIM bridge members, and repeated
   lookups through the same resolver instance.

Rejecting conflicts across *all* source assertions even after a higher-priority
unique match would be a different policy and would affect real records now.
The change above preserves source priority while fixing the fallback bug.
Candidate lists are explanatory text within blocking record errors; no mapping
picker or per-record override has been added, and existing submissions are not
automatically revalidated.
The competing paths present in the sources remain distinct from the single
result selected by the resolver's priority policy. Neither this audit nor the
fix establishes that all exact assertions agree.

## Input hashes (SHA-256)

- September MONDO: `ba19a3be2a384f21d2a09eaa726daedf6f06b9747ae011ab81d29fcd4dd8d3ee`
- July MONDO: `80b8658b4ec7da7699f7f8f6460425396e42f1bb7fbead837469ebf1907f7c30`
- Orphadata (both copies): `df8d562a0c6011af36a74eb4000ce81ca7d723e8031010819fb71727c0962bbb`
