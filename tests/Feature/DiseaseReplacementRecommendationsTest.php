<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Services\DiseaseReplacementRecommendations;
use App\Services\DiseaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiseaseReplacementRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_and_list_successors_are_advice_only(): void
    {
        $target = Disease::factory()->mondo()->create(['curie' => 'MONDO:222']);
        $old = Disease::factory()->mondo()->deprecated()->withXrefs(['replaced_by' => $target->curie])->create();
        $service = new DiseaseReplacementRecommendations();
        $service->preload([$old->curie]);
        $this->assertTrue($service->forTerm($old)['replacement_available']);
        $this->assertSame($old->id, (new DiseaseResolver())->resolve($old->curie)->mondo->id);
        $old->xrefs = ['replaced_by' => [$target->curie, $target->curie, null, ['bad']]];
        $this->assertSame([$target->curie], DiseaseReplacementRecommendations::identifiers($old->xrefs));
        $advice = (new DiseaseReplacementRecommendations())->forTerm($old);
        $this->assertTrue($advice['unparsed']);
        $this->assertFalse($advice['replacement_available']);
    }

    public function test_unsupported_missing_deleted_and_deprecated_successors_are_distinct(): void
    {
        Disease::factory()->mondo()->deprecated()->create(['curie' => 'MONDO:201']);
        Disease::factory()->mondo()->create(['curie' => 'MONDO:202'])->delete();
        $old = Disease::factory()->mondo()->deprecated()->withXrefs([
            'replaced_by' => ['HP:001', 'OMIM:123456', 'OMIM:654321', 'MONDO:201', 'MONDO:202', 'MONDO:203'],
            'replacement_details' => ['OMIM:123456' => ['prefix' => 'Asterisk', 'title' => 'A gene']],
        ])->create();
        $service = new DiseaseReplacementRecommendations();
        $service->preload([$old->curie]);
        $advice = $service->forTerm($old);
        $this->assertFalse($advice['replacement_available']);
        $this->assertSame(['phenotype', 'gene', 'unknown', 'disease', 'disease', 'disease'], array_column($advice['targets'], 'kind'));
        $this->assertSame(['missing', 'missing', 'missing', 'deprecated', 'deleted', 'missing'], array_column($advice['targets'], 'availability'));
    }

    public function test_self_and_unrecognized_assertions_are_not_usable(): void
    {
        $old = Disease::factory()->mondo()->deprecated()->withXrefs(['replaced_by' => ['MONDO:201']])->create(['curie' => 'MONDO:201']);
        $service = new DiseaseReplacementRecommendations();
        $service->preload([$old->curie]);
        $this->assertFalse($service->forTerm($old)['replacement_available']);
        $old->xrefs = ['replacement_parse_status' => 'unrecognized'];
        $advice = (new DiseaseReplacementRecommendations())->forTerm($old);
        $this->assertTrue($advice['unparsed']);
        $this->assertStringNotContainsString('No replacement', $advice['message']);
    }

    public function test_successor_loading_is_batched_for_a_page(): void
    {
        $curies = [];
        for ($i = 1; $i <= 25; $i++) {
            $target = Disease::factory()->mondo()->create(['curie' => 'MONDO:'.(100 + $i)]);
            $curies[] = Disease::factory()->mondo()->deprecated()->withXrefs(['replaced_by' => [$target->curie]])->create()->curie;
        }
        DB::enableQueryLog();
        $service = new DiseaseReplacementRecommendations();
        $service->preload($curies);
        foreach ($curies as $curie) {
            $this->assertTrue($service->forTerm($service->term($curie))['replacement_available']);
        }
        $this->assertCount(3, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_active_successor_can_map_to_deprecated_mondo_and_is_not_marked_usable(): void
    {
        Disease::factory()->mondo()->deprecated()->withXrefs(['omim_id' => ['145420']])->create(['curie' => 'MONDO:800']);
        Disease::factory()->create(['curie' => 'OMIM:145420', 'type' => Disease::TYPE_OMIM, 'status' => Disease::STATUS_ACTIVE]);
        $old = Disease::factory()->deprecated()->withXrefs(['replaced_by' => ['OMIM:145420']])->create(['curie' => 'OMIM:145410']);
        $service = new DiseaseReplacementRecommendations();
        $service->preload([$old->curie]);
        $advice = $service->forTerm($old);
        $this->assertFalse($advice['replacement_available']);
        $this->assertSame('active', $advice['targets'][0]['availability']);
        $this->assertSame('MONDO:800', $advice['targets'][0]['mondo']);
        $this->assertTrue($advice['targets'][0]['mondo_deprecated']);
    }
}
