<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration that clears stand-in records from unresolved reference fields.
 */
class ClearPlaceholderReferencesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_clears_stand_ins_only_where_the_field_is_in_error(): void
    {
        $dash = Gene::factory()->create(['hgnc_id' => '', 'symbol' => '-']);
        $realGene = Gene::factory()->create(['hgnc_id' => 'HGNC:5', 'symbol' => 'A1BG']);
        $root = Disease::factory()->mondo()->create(['curie' => 'MONDO:0000001']);
        $unknown = Inheritance::create([
            'curie' => 'HP:0000005', 'name' => 'Unknown', 'description' => 'Test', 'abbreviation' => 'Unknown',
            'type' => Inheritance::TYPE_MOI, 'status' => Inheritance::STATUS_ACTIVE,
        ]);

        $placeholders = ['gene_id' => $dash->id, 'disease_id' => $root->id, 'original_disease_id' => $root->id, 'inheritance_id' => $unknown->id];

        $unresolved = Submission::factory()->create($placeholders + [
            'submission_errors' => [
                'gene_hgnc_id' => "Invalid HGNC ID 'HGNC:99999999'",
                'disease_curie_id' => "No exact MONDO equivalent for Disease ID 'Orphanet:716903'",
                'moi_curie_id' => "Invalid MOI ID 'HP:9999999'",
            ],
        ]);

        // A genuine "Unknown" inheritance, with an unrelated error elsewhere
        $genuine = Submission::factory()->create([
            'gene_id' => $realGene->id,
            'disease_id' => $root->id,
            'original_disease_id' => $root->id,
            'inheritance_id' => $unknown->id,
            'submission_errors' => ['report_date' => 'Missing Report Date'],
        ]);

        (require database_path('migrations/2026_09_17_120000_clear_placeholder_references_on_unresolved_submissions.php'))->up();

        $unresolved->refresh();
        foreach (array_keys($placeholders) as $column) {
            $this->assertNull($unresolved->{$column}, $column);
        }

        $genuine->refresh();
        $this->assertSame($realGene->id, $genuine->gene_id);
        $this->assertSame($root->id, $genuine->disease_id);
        $this->assertSame($root->id, $genuine->original_disease_id);
        $this->assertSame($unknown->id, $genuine->inheritance_id);
    }
}
