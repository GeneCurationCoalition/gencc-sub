<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Disease;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiseaseUpdateRefactoringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that MONDO diseases have null mondo_id
     */
    public function test_mondo_diseases_have_null_mondo_id()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $this->assertNull($mondoDisease->mondo_id);
        $this->assertEquals(Disease::TYPE_MONDO, $mondoDisease->type);
    }

    /**
     * Test that OMIM diseases can have mondo_id
     */
    public function test_omim_diseases_can_have_mondo_id()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => ['omim_id' => ['615438']]
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $this->assertEquals($mondoDisease->id, $omimDisease->mondo_id);
        $this->assertEquals(Disease::TYPE_OMIM, $omimDisease->type);
    }

    /**
     * Test mondoDisease relationship
     */
    public function test_omim_disease_belongs_to_mondo()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $linkedMondo = $omimDisease->mondoDisease;

        $this->assertNotNull($linkedMondo);
        $this->assertEquals($mondoDisease->id, $linkedMondo->id);
        $this->assertEquals('MONDO:0000001', $linkedMondo->curie);
    }

    /**
     * Test equivalentDiseases relationship
     */
    public function test_mondo_has_many_equivalent_diseases()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $orphanetDisease = Disease::factory()->create([
            'type' => Disease::TYPE_ORPHANET,
            'curie' => 'Orphanet:464724',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $equivalents = $mondoDisease->equivalentDiseases;

        $this->assertCount(2, $equivalents);
        $this->assertTrue($equivalents->contains($omimDisease));
        $this->assertTrue($equivalents->contains($orphanetDisease));
    }

    /**
     * Test rosetta returns MONDO for OMIM input
     */
    public function test_rosetta_returns_mondo_for_omim()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => ['omim_id' => ['615438']]
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $result = Disease::rosetta('OMIM:615438');

        $this->assertNotNull($result);
        $this->assertEquals($mondoDisease->id, $result->id);
        $this->assertEquals(Disease::TYPE_MONDO, $result->type);
    }

    /**
     * Test rosetta returns MONDO for Orphanet input
     */
    public function test_rosetta_returns_mondo_for_orphanet()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => ['orpha_id' => '464724']
        ]);

        $orphanetDisease = Disease::factory()->create([
            'type' => Disease::TYPE_ORPHANET,
            'curie' => 'Orphanet:464724',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $result = Disease::rosetta('Orphanet:464724');

        $this->assertNotNull($result);
        $this->assertEquals($mondoDisease->id, $result->id);
        $this->assertEquals(Disease::TYPE_MONDO, $result->type);
    }

    /**
     * Test rosetta allows deprecated diseases
     */
    public function test_rosetta_allows_deprecated_diseases()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_DEPRECATED  // DEPRECATED
        ]);

        $result = Disease::rosetta('MONDO:0000001');

        $this->assertNotNull($result);
        $this->assertEquals('MONDO:0000001', $result->curie);
        $this->assertEquals(Disease::STATUS_DEPRECATED, $result->status);
    }

    /**
     * Test rosetta returns null for removed diseases
     */
    public function test_rosetta_returns_null_for_removed_diseases()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_REMOVED  // REMOVED
        ]);

        $result = Disease::rosetta('MONDO:0000001');

        $this->assertNull($result);
    }

    /**
     * Test rosetta with bare OMIM ID (no prefix)
     */
    public function test_rosetta_rejects_bare_omim_id()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => ['omim_id' => ['615438']]
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        // Bare numbers without CURIE prefix should be rejected
        $result = Disease::rosetta('615438');
        $this->assertNull($result);

        // With proper CURIE prefix should resolve
        $result = Disease::rosetta('OMIM:615438');
        $this->assertNotNull($result);
        $this->assertEquals($mondoDisease->id, $result->id);
    }
}
