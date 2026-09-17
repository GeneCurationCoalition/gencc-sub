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
 *    term has no record of its own in the diseases table.
 *  - `mondo` is the normalized MONDO term, and becomes `submissions.disease_id`.
 *    Resolution only ever succeeds with a MONDO term, so this is never null.
 *
 * `via` records which step of the resolution policy matched, so callers can
 * report how a mapping was reached without re-deriving it.
 */
class DiseaseResolution
{
    /** The submitted identifier was itself a MONDO term. */
    public const VIA_MONDO_SELF = 'mondo_self';

    /** A MONDO term skos:exactMatch-es the submitted identifier. */
    public const VIA_MONDO_EXACT_MATCH = 'mondo_exact_match';

    /** Orphadata asserts an exact, validated MONDO equivalent for the term. */
    public const VIA_ORPHANET_EXACT_MATCH = 'orphanet_exact_match';

    /**
     * Orphadata asserts an exact OMIM reference and a MONDO term
     * skos:exactMatch-es that OMIM id.
     */
    public const VIA_OMIM_BRIDGE = 'omim_bridge';

    public function __construct(
        public readonly ?Disease $original,
        public readonly Disease $mondo,
        public readonly string $via
    ) {
    }
}
