<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Gene;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GeneModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that gene gets uuid ident on instantiation
     */
    public function test_gene_gets_uuid_ident_on_instantiation(): void
    {
        $gene = new Gene();

        $this->assertNotNull($gene->ident);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $gene->ident
        );
    }

    /**
     * Test scopeIdent works correctly
     */
    public function test_scope_ident_works(): void
    {
        $gene = Gene::factory()->create();

        $found = Gene::ident($gene->ident)->first();

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test scopeHgnc_id works correctly
     */
    public function test_scope_hgnc_id_works(): void
    {
        $gene = Gene::factory()->create(['hgnc_id' => 'HGNC:12345']);

        $found = Gene::hgnc_id('HGNC:12345')->first();

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test scopeSymbol works correctly
     */
    public function test_scope_symbol_works(): void
    {
        $gene = Gene::factory()->create(['symbol' => 'BRCA1']);

        $found = Gene::symbol('BRCA1')->first();

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test lookup_hgnc_id static method
     */
    public function test_lookup_hgnc_id_works(): void
    {
        $gene = Gene::factory()->create(['hgnc_id' => 'HGNC:54321']);

        $found = Gene::lookup_hgnc_id('HGNC:54321');

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test lookup_hgnc_id returns null for non-existent id
     */
    public function test_lookup_hgnc_id_returns_null_for_nonexistent(): void
    {
        $found = Gene::lookup_hgnc_id('HGNC:99999999');

        $this->assertNull($found);
    }

    /**
     * Test lookup finds gene by symbol
     */
    public function test_lookup_finds_by_symbol(): void
    {
        $gene = Gene::factory()->create(['symbol' => 'TP53']);

        $found = Gene::lookup('TP53');

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test lookup finds gene by previous symbol
     */
    public function test_lookup_finds_by_previous_symbol(): void
    {
        $gene = Gene::factory()->create([
            'symbol' => 'CURRENT',
            'previous_symbols' => ['OLD_SYMBOL', 'OLDER_SYMBOL']
        ]);

        $found = Gene::lookup('OLD_SYMBOL');

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test lookup finds gene by alias symbol
     */
    public function test_lookup_finds_by_alias_symbol(): void
    {
        $gene = Gene::factory()->create([
            'symbol' => 'MAIN',
            'alias_symbols' => ['ALIAS1', 'ALIAS2']
        ]);

        $found = Gene::lookup('ALIAS1');

        $this->assertNotNull($found);
        $this->assertEquals($gene->id, $found->id);
    }

    /**
     * Test lookup returns null for empty string
     */
    public function test_lookup_returns_null_for_empty_string(): void
    {
        $this->assertNull(Gene::lookup(''));
    }

    /**
     * Test lookup returns null for reserved blank name
     */
    public function test_lookup_returns_null_for_dash(): void
    {
        $this->assertNull(Gene::lookup('-'));
    }

    /**
     * Test lookup returns null for nonexistent symbol
     */
    public function test_lookup_returns_null_for_nonexistent(): void
    {
        $this->assertNull(Gene::lookup('NONEXISTENT_GENE'));
    }

    /**
     * Test gene has many submissions relationship
     */
    public function test_gene_has_many_submissions(): void
    {
        $gene = Gene::factory()->create();

        $submission1 = Submission::factory()->create(['gene_id' => $gene->id]);
        $submission2 = Submission::factory()->create(['gene_id' => $gene->id]);

        $this->assertCount(2, $gene->submissions);
        $this->assertTrue($gene->submissions->contains($submission1));
        $this->assertTrue($gene->submissions->contains($submission2));
    }

    /**
     * Test gene status constants exist
     */
    public function test_gene_status_constants(): void
    {
        $this->assertEquals(0, Gene::STATUS_INITIALIZING);
        $this->assertEquals(1, Gene::STATUS_ACTIVE);
        $this->assertEquals(9, Gene::STATUS_REMOVED);
    }

    /**
     * Test gene type constants exist
     */
    public function test_gene_type_constants(): void
    {
        $this->assertEquals(0, Gene::TYPE_UNKNOWN);
        $this->assertEquals(1, Gene::TYPE_GENE);
    }

    /**
     * Test initialize creates blank gene
     */
    public function test_initialize_creates_blank_gene(): void
    {
        Gene::initialize();

        $blankGene = Gene::symbol('-')->first();

        $this->assertNotNull($blankGene);
        $this->assertEquals('-', $blankGene->symbol);
        $this->assertEquals('-', $blankGene->name);
        $this->assertEquals(0, $blankGene->type);
    }

    /**
     * Test initialize does not create duplicate blank gene
     */
    public function test_initialize_does_not_create_duplicate(): void
    {
        Gene::initialize();
        Gene::initialize();
        Gene::initialize();

        $count = Gene::symbol('-')->where('type', 0)->count();

        $this->assertEquals(1, $count);
    }

    /**
     * Test soft delete works
     */
    public function test_soft_delete_works(): void
    {
        $gene = Gene::factory()->create();
        $geneId = $gene->id;

        $gene->delete();

        $this->assertNull(Gene::find($geneId));
        $this->assertNotNull(Gene::withTrashed()->find($geneId));
    }
}
