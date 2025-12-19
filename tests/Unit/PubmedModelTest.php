<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Pubmed;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PubmedModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that pubmed gets uuid ident on instantiation
     */
    public function test_pubmed_gets_uuid_ident_on_instantiation(): void
    {
        $pubmed = new Pubmed();

        $this->assertNotNull($pubmed->ident);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $pubmed->ident
        );
    }

    /**
     * Test status constants exist
     */
    public function test_status_constants_exist(): void
    {
        $this->assertEquals(20, Pubmed::STATUS_INITIALIZING);
        $this->assertEquals(21, Pubmed::STATUS_SUMMARY_COMPLETE);
        $this->assertEquals(1, Pubmed::STATUS_ACTIVE);
        $this->assertEquals(9, Pubmed::STATUS_REMOVED);
    }

    /**
     * Test type constants exist
     */
    public function test_type_constants_exist(): void
    {
        $this->assertEquals(0, Pubmed::TYPE_UNKNOWN);
    }

    /**
     * Test pubmed can be created with required fields
     */
    public function test_pubmed_can_be_created(): void
    {
        $pubmed = Pubmed::create([
            'pmid' => '12345678',
            'uid' => '12345678',
            'status' => Pubmed::STATUS_ACTIVE
        ]);

        $this->assertNotNull($pubmed->id);
        $this->assertEquals('12345678', $pubmed->pmid);
        $this->assertEquals(Pubmed::STATUS_ACTIVE, $pubmed->status);
    }

    /**
     * Test pubmed has many submissions through pivot
     */
    public function test_pubmed_belongs_to_many_submissions(): void
    {
        $pubmed = Pubmed::create([
            'pmid' => '11111111',
            'uid' => '11111111',
            'status' => Pubmed::STATUS_ACTIVE
        ]);

        $submission1 = Submission::factory()->create();
        $submission2 = Submission::factory()->create();

        $pubmed->submissions()->attach([$submission1->id, $submission2->id]);

        $pubmed->refresh();
        $this->assertCount(2, $pubmed->submissions);
        $this->assertTrue($pubmed->submissions->contains($submission1));
        $this->assertTrue($pubmed->submissions->contains($submission2));
    }

    /**
     * Test pubmed can store article metadata
     */
    public function test_pubmed_stores_article_metadata(): void
    {
        $pubmed = Pubmed::create([
            'pmid' => '22222222',
            'uid' => '22222222',
            'title' => 'Test Article Title',
            'authors' => 'Smith J, Doe A',
            'source' => 'Nature',
            'pubdate' => '2023',
            'volume' => '123',
            'issue' => '4',
            'pages' => '100-110',
            'status' => Pubmed::STATUS_ACTIVE
        ]);

        $this->assertEquals('Test Article Title', $pubmed->title);
        $this->assertEquals('Smith J, Doe A', $pubmed->authors);
        $this->assertEquals('Nature', $pubmed->source);
        $this->assertEquals('2023', $pubmed->pubdate);
        $this->assertEquals('123', $pubmed->volume);
        $this->assertEquals('4', $pubmed->issue);
        $this->assertEquals('100-110', $pubmed->pages);
    }

    /**
     * Test soft delete works
     */
    public function test_soft_delete_works(): void
    {
        $pubmed = Pubmed::create([
            'pmid' => '33333333',
            'uid' => '33333333',
            'status' => Pubmed::STATUS_ACTIVE
        ]);
        $pubmedId = $pubmed->id;

        $pubmed->delete();

        $this->assertNull(Pubmed::find($pubmedId));
        $this->assertNotNull(Pubmed::withTrashed()->find($pubmedId));
    }

    /**
     * Test firstOrCreate works for pubmed
     */
    public function test_first_or_create_works(): void
    {
        // First call should create
        $pubmed1 = Pubmed::firstOrCreate(
            ['pmid' => '44444444', 'uid' => '44444444'],
            ['status' => Pubmed::STATUS_INITIALIZING]
        );

        // Second call should find existing
        $pubmed2 = Pubmed::firstOrCreate(
            ['pmid' => '44444444', 'uid' => '44444444'],
            ['status' => Pubmed::STATUS_ACTIVE] // Different status shouldn't matter
        );

        $this->assertEquals($pubmed1->id, $pubmed2->id);
        $this->assertEquals(Pubmed::STATUS_INITIALIZING, $pubmed2->status); // Original status preserved
    }

    /**
     * Test pubmed status workflow
     */
    public function test_pubmed_status_workflow(): void
    {
        // Start with initializing
        $pubmed = Pubmed::create([
            'pmid' => '55555555',
            'uid' => '55555555',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $this->assertEquals(Pubmed::STATUS_INITIALIZING, $pubmed->status);

        // Move to summary complete after fetching summary
        $pubmed->update(['status' => Pubmed::STATUS_SUMMARY_COMPLETE]);
        $this->assertEquals(Pubmed::STATUS_SUMMARY_COMPLETE, $pubmed->status);

        // Move to active after full fetch
        $pubmed->update(['status' => Pubmed::STATUS_ACTIVE]);
        $this->assertEquals(Pubmed::STATUS_ACTIVE, $pubmed->status);
    }

    /**
     * Test pubmed can store full metadata
     */
    public function test_pubmed_full_metadata(): void
    {
        $pubmed = Pubmed::create([
            'pmid' => '66666666',
            'uid' => '66666666',
            'title' => 'Full Metadata Test',
            'sorttitle' => 'full metadata test',
            'authors' => 'Author A, Author B',
            'lastauthor' => 'Author B',
            'source' => 'Journal of Testing',
            'fullfournalname' => 'Journal of Testing Full Name',
            'pubdate' => '2024 Jan 15',
            'epubdate' => '2024 Jan 10',
            'sortpubdate' => '2024/01/15 00:00',
            'volume' => '50',
            'issue' => '1',
            'pages' => '1-25',
            'lang' => 'eng',
            'nlmuniqueid' => 'NLM12345',
            'issn' => '1234-5678',
            'essn' => '8765-4321',
            'pubtype' => 'Journal Article',
            'recordstatus' => 'PubMed',
            'pubstatus' => 'pubmed',
            'elocationid' => 'doi: 10.1234/test.2024',
            'status' => Pubmed::STATUS_ACTIVE
        ]);

        $this->assertEquals('Full Metadata Test', $pubmed->title);
        $this->assertEquals('full metadata test', $pubmed->sorttitle);
        $this->assertEquals('Author A, Author B', $pubmed->authors);
        $this->assertEquals('Author B', $pubmed->lastauthor);
        $this->assertEquals('Journal of Testing', $pubmed->source);
        $this->assertEquals('Journal of Testing Full Name', $pubmed->fullfournalname);
        $this->assertEquals('2024 Jan 15', $pubmed->pubdate);
        $this->assertEquals('eng', $pubmed->lang);
        $this->assertEquals('1234-5678', $pubmed->issn);
        $this->assertEquals('8765-4321', $pubmed->essn);
        $this->assertEquals('Journal Article', $pubmed->pubtype);
    }
}
