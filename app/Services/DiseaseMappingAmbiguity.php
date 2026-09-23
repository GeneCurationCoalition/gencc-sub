<?php

namespace App\Services;

use App\Models\Disease;

/** Multiple eligible MONDO candidates at a resolution step that was evaluated. */
class DiseaseMappingAmbiguity
{
    /** @param array<int, Disease> $candidates Distinct candidates, ordered by CURIE. */
    public function __construct(
        public readonly string $step,
        public readonly array $candidates
    ) {
    }

    public function message(string $submitted): string
    {
        $source = match ($this->step) {
            DiseaseResolution::VIA_MONDO_EXACT_MATCH => "MONDO's exact-match records identify multiple candidates",
            DiseaseResolution::VIA_ORPHANET_EXACT_MATCH => 'Orphadata lists multiple exact MONDO equivalents',
            DiseaseResolution::VIA_OMIM_BRIDGE => 'The exact OMIM references lead to multiple MONDO terms',
        };

        $candidates = array_map(function (Disease $disease) {
            $deprecated = (int) $disease->status === Disease::STATUS_DEPRECATED;

            return $disease->curie.($deprecated ? ' (deprecated)' : '');
        }, $this->candidates);

        return "Disease ID '".trim($submitted)."' cannot be mapped uniquely to MONDO. "
            .$source.': '.implode('; ', $candidates).'. '
            .'No MONDO mapping was assigned. Learn more: '.route('help.disease-mapping').'.';
    }
}
