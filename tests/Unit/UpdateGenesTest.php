<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Gene;
use App\Models\Submission;
use App\Models\StaticFileHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Tests for UpdateGenes command FK safety
 *
 * These tests ensure that the update:genes command preserves foreign key
 * relationships in the submissions table when updating gene data.
 *
 * Background: The original implementation used truncate() which reset all gene IDs,
 * breaking 98.8% of submission.gene_id foreign keys. The fix uses updateOrCreate
 * keyed by hgnc_id to preserve existing gene record IDs.
 */
class UpdateGenesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that Gene::updateOrCreate preserves existing gene ID
     *
     * This verifies the core mechanism that makes UpdateGenes FK-safe.
     */
    public function test_update_or_create_preserves_gene_id(): void
    {
        // Create a gene with known hgnc_id
        $originalGene = Gene::factory()->create([
            'hgnc_id' => 'HGNC:12345',
            'symbol' => 'ORIG_SYMBOL',
            'name' => 'Original Name',
        ]);
        $originalId = $originalGene->id;

        // Simulate what UpdateGenes does: updateOrCreate with same hgnc_id
        $updatedGene = Gene::updateOrCreate(
            ['hgnc_id' => 'HGNC:12345'],
            [
                'symbol' => 'NEW_SYMBOL',
                'name' => 'Updated Name',
                'type' => Gene::TYPE_GENE,
                'status' => Gene::STATUS_ACTIVE,
            ]
        );

        // ID must be preserved - this is critical for FK integrity
        $this->assertEquals($originalId, $updatedGene->id, 'Gene ID must be preserved after updateOrCreate');

        // Data should be updated
        $this->assertEquals('NEW_SYMBOL', $updatedGene->symbol);
        $this->assertEquals('Updated Name', $updatedGene->name);

        // Should still only have one gene with this hgnc_id
        $this->assertEquals(1, Gene::where('hgnc_id', 'HGNC:12345')->count());
    }

    /**
     * Test that submission gene_id FK remains valid after gene update
     *
     * This is the critical FK safety test - submissions must still reference
     * the correct gene after UpdateGenes runs.
     */
    public function test_submission_gene_fk_remains_valid_after_gene_update(): void
    {
        // Create a gene
        $gene = Gene::factory()->create([
            'hgnc_id' => 'HGNC:99999',
            'symbol' => 'TESTGENE',
            'name' => 'Test Gene Original',
        ]);

        // Create a submission linked to this gene
        $submission = Submission::factory()->create([
            'gene_id' => $gene->id,
        ]);

        // Store original values for verification
        $originalGeneId = $gene->id;
        $originalSubmissionGeneId = $submission->gene_id;

        // Simulate UpdateGenes behavior: updateOrCreate the gene
        Gene::updateOrCreate(
            ['hgnc_id' => 'HGNC:99999'],
            [
                'symbol' => 'TESTGENE_UPDATED',
                'name' => 'Test Gene Updated',
                'type' => Gene::TYPE_GENE,
                'status' => Gene::STATUS_ACTIVE,
            ]
        );

        // Refresh submission from database
        $submission->refresh();

        // Submission's gene_id should still be the same
        $this->assertEquals($originalSubmissionGeneId, $submission->gene_id);

        // And the gene relationship should still work
        $this->assertNotNull($submission->gene);
        $this->assertEquals('TESTGENE_UPDATED', $submission->gene->symbol);
        $this->assertEquals($originalGeneId, $submission->gene->id);
    }

    /**
     * Test that multiple submissions maintain correct gene references after bulk update
     *
     * Simulates a bulk update scenario like the HUGO import.
     */
    public function test_multiple_submissions_maintain_gene_references_after_bulk_update(): void
    {
        // Create several genes
        $gene1 = Gene::factory()->create(['hgnc_id' => 'HGNC:1', 'symbol' => 'GENE1']);
        $gene2 = Gene::factory()->create(['hgnc_id' => 'HGNC:2', 'symbol' => 'GENE2']);
        $gene3 = Gene::factory()->create(['hgnc_id' => 'HGNC:3', 'symbol' => 'GENE3']);

        // Create submissions for each gene
        $sub1 = Submission::factory()->create(['gene_id' => $gene1->id]);
        $sub2 = Submission::factory()->create(['gene_id' => $gene2->id]);
        $sub3a = Submission::factory()->create(['gene_id' => $gene3->id]);
        $sub3b = Submission::factory()->create(['gene_id' => $gene3->id]);

        // Store original mappings
        $originalMappings = [
            $sub1->id => $gene1->id,
            $sub2->id => $gene2->id,
            $sub3a->id => $gene3->id,
            $sub3b->id => $gene3->id,
        ];

        // Simulate UpdateGenes: update all genes with new data
        Gene::updateOrCreate(['hgnc_id' => 'HGNC:1'], ['symbol' => 'GENE1_NEW', 'name' => 'Updated 1']);
        Gene::updateOrCreate(['hgnc_id' => 'HGNC:2'], ['symbol' => 'GENE2_NEW', 'name' => 'Updated 2']);
        Gene::updateOrCreate(['hgnc_id' => 'HGNC:3'], ['symbol' => 'GENE3_NEW', 'name' => 'Updated 3']);

        // Verify all FK relationships are intact
        foreach ($originalMappings as $submissionId => $expectedGeneId) {
            $submission = Submission::find($submissionId);
            $this->assertEquals(
                $expectedGeneId,
                $submission->gene_id,
                "Submission {$submissionId} should still reference gene {$expectedGeneId}"
            );
            $this->assertNotNull($submission->gene, "Gene relationship should still work for submission {$submissionId}");
        }

        // Verify gene data was actually updated
        $this->assertEquals('GENE1_NEW', Gene::find($gene1->id)->symbol);
        $this->assertEquals('GENE2_NEW', Gene::find($gene2->id)->symbol);
        $this->assertEquals('GENE3_NEW', Gene::find($gene3->id)->symbol);
    }

    /**
     * Test that new genes get new IDs without affecting existing genes
     */
    public function test_new_genes_get_new_ids_without_affecting_existing(): void
    {
        // Create an existing gene
        $existingGene = Gene::factory()->create([
            'hgnc_id' => 'HGNC:1000',
            'symbol' => 'EXISTING',
        ]);
        $existingId = $existingGene->id;

        // Create a submission linked to it
        $submission = Submission::factory()->create(['gene_id' => $existingGene->id]);

        // Add a new gene (simulating HUGO adding new genes) - use factory for required fields
        $newGene = Gene::factory()->create([
            'hgnc_id' => 'HGNC:2000',
            'symbol' => 'NEWGENE',
            'name' => 'New Gene',
        ]);

        // New gene should have a different ID
        $this->assertNotEquals($existingId, $newGene->id);

        // Existing gene should be unchanged
        $existingGene->refresh();
        $this->assertEquals($existingId, $existingGene->id);
        $this->assertEquals('EXISTING', $existingGene->symbol);

        // Submission FK should still be valid
        $submission->refresh();
        $this->assertEquals($existingId, $submission->gene_id);
        $this->assertEquals('EXISTING', $submission->gene->symbol);
    }

    /**
     * NEGATIVE TEST: Demonstrate why truncate breaks FK integrity
     *
     * This test shows exactly what was wrong with the original implementation.
     * It uses truncate to show how it breaks foreign keys.
     *
     * @group dangerous
     */
    public function test_truncate_breaks_fk_integrity(): void
    {
        // Create a gene
        $gene = Gene::factory()->create([
            'hgnc_id' => 'HGNC:5555',
            'symbol' => 'ORIGINAL',
        ]);
        $originalId = $gene->id;

        // Create a submission with this gene
        $submission = Submission::factory()->create(['gene_id' => $gene->id]);

        // Verify initial state
        $this->assertEquals($originalId, $submission->gene_id);
        $this->assertEquals('ORIGINAL', $submission->gene->symbol);

        // Now truncate the genes table (what the old code did)
        DB::table('genes')->truncate();

        // Re-create a gene with the same hgnc_id but it gets a NEW id
        // Use factory to ensure all required fields are set
        $newGene = Gene::factory()->create([
            'hgnc_id' => 'HGNC:5555',
            'symbol' => 'RECREATED',
            'name' => 'Recreated Gene',
        ]);

        // The new gene has ID 1 (reset by truncate), not the original ID
        $this->assertEquals(1, $newGene->id);

        // Refresh submission - its gene_id is now orphaned!
        $submission->refresh();

        // The submission still has the old gene_id
        $this->assertEquals($originalId, $submission->gene_id);

        // But if originalId != 1, the FK is broken!
        if ($originalId != 1) {
            // The gene relationship returns the WRONG gene (or null if ID doesn't exist)
            $linkedGene = $submission->gene;

            if ($linkedGene !== null) {
                // FK points to wrong gene - this is the bug!
                $this->assertNotEquals('ORIGINAL', $linkedGene->symbol);
                $this->assertNotEquals($originalId, $linkedGene->id);
            }
        }
    }
}
