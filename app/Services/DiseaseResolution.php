<?php

namespace App\Services;

use App\Models\Disease;

/**
 * The outcome of resolving one submitted disease identifier.
 *
 * A submission stores two disease references, and this value object carries both:
 *
 *  - `original` is the record for the CURIE exactly as submitted, and becomes
 *    `submissions.original_disease_id`.  It is null when the submitted ontology
 *    term has no record of its own in the diseases table (for example a DOID or
 *    UMLS id, which are only ever reachable through a MONDO record's xrefs).
 *  - `mondo` is the normalized target, and becomes `submissions.disease_id`.  It
 *    is usually a MONDO term; the one exception is an Orphanet term that MONDO
 *    has not ingested, which stands on its own (see VIA_ORPHANET_SELF).
 *
 * `via` records which resolution strategy matched, so callers can distinguish
 * a strong mapping from a weak one without re-deriving it.
 */
class DiseaseResolution
{
    /** The submitted identifier was itself a MONDO term. */
    public const VIA_MONDO_SELF = 'mondo_self';

    /** An OMIM/Orphanet record carried a mondo_id FK to its MONDO equivalent. */
    public const VIA_EQUIVALENCE_FK = 'equivalence_fk';

    /** A MONDO record listed the submitted identifier in its xrefs. */
    public const VIA_EQUIVALENCE_XREF = 'equivalence_xref';

    /** An Orphanet term with no MONDO equivalent, resolved to itself. */
    public const VIA_ORPHANET_SELF = 'orphanet_self';

    public function __construct(
        public readonly ?Disease $original,
        public readonly ?Disease $mondo,
        public readonly string $via
    ) {
    }
}
