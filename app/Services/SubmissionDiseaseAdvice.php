<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Support\Collection;

class SubmissionDiseaseAdvice
{
    /** Decorate responses only, never persist advice on the submission. */
    public static function attach(Collection $submissions): void
    {
        $resolver = new DiseaseResolver();
        $service = new DiseaseReplacementRecommendations($resolver);
        $contexts = $submissions->mapWithKeys(function (Submission $submission) {
            $raw = data_get($submission->submission_data, 'disease.id');

            return [$submission->id => ['submitted term' => is_string($raw) ? $raw : null,
                'stored MONDO' => $submission->disease?->curie]];
        });
        $service->preload($contexts->flatten()->all());
        foreach ($submissions as $submission) {
            $submission->setAttribute('disease_recommendations', $service->forContexts($contexts[$submission->id]));
        }
        MondoRelationshipWarnings::attach($submissions, $resolver);
    }
}
