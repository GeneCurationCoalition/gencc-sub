<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\SubmissionFileValidation;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Mechanism;
use App\Models\Submitter;
use App\Models\Submission;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SubmissionFileValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Submitter $submitter;

    public function test_cross_namespace_relationships_do_not_block_the_workbook(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['disease_id' => 'OMIM:123456', 'local_key' => 'one']),
            $this->createValidDataRow(['disease_id' => 'MONDO:0000001', 'local_key' => 'two']),
        ]);
        // Shared-MONDO advice is shown on the imported submissions, not by the upload gate,
        // which rejects the file on any result it returns.
        $this->assertSame([], SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id));
    }

    public function test_republishing_live_assertions_that_share_only_mondo_is_not_a_duplicate(): void
    {
        $job = Job::create(['submitter_id' => $this->submitter->id, 'user_id' => 1, 'status' => Job::STATUS_PROCESSED]);
        $gene = Gene::where('hgnc_id', 'HGNC:5')->first();
        $mondo = Disease::where('curie', 'MONDO:0000001')->first();
        $moi = Inheritance::where('curie', 'HP:0000006')->first();
        foreach (['SGC-100001' => 'OMIM:123456', 'SGC-100002' => 'MONDO:0000001'] as $sid => $submitted) {
            Submission::create([
                'sid' => $sid, 'job_id' => $job->id, 'submitter_id' => $this->submitter->id, 'user_id' => 1,
                'submission_data' => ['disease' => ['id' => $submitted]], 'gene_id' => $gene->id,
                'disease_id' => $mondo->id, 'original_disease_id' => Disease::where('curie', $submitted)->value('id'),
                'inheritance_id' => $moi->id, 'status' => Submission::STATUS_PUBLISHED,
                'is_live' => true, 'is_most_recent' => true,
            ]);
        }
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['action' => 'R', 'sgc_id' => 'SGC-100001', 'disease_id' => 'OMIM:123456', 'local_key' => 'one']),
            $this->createValidDataRow(['action' => 'R', 'sgc_id' => 'SGC-100002', 'disease_id' => 'MONDO:0000001', 'local_key' => 'two']),
        ]);

        $this->assertSame([], SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id));
    }

    /**
     * A republish keeps the stored disease mapping, so the gate accepts a row naming the
     * stored submitted disease even when current data maps it elsewhere or not at all.
     */
    public function test_republish_row_naming_the_stored_disease_passes_whatever_it_resolves_to_now(): void
    {
        $this->publishedOmimAssertion();
        $row = $this->createValidSpreadsheet([$this->createValidDataRow([
            'action' => 'R', 'sgc_id' => 'SGC-100001', 'disease_id' => 'OMIM:123456',
        ])]);

        // MONDO moves its exact match for OMIM:123456 to a different term.
        Disease::where('curie', 'MONDO:0000001')->first()->update(['xrefs' => ['omim_id' => []]]);
        Disease::create(['curie' => 'MONDO:0000099', 'name' => 'new target', 'description' => '',
            'type' => Disease::TYPE_MONDO, 'status' => Disease::STATUS_ACTIVE, 'xrefs' => ['omim_id' => ['123456']]]);
        SubmissionFileValidation::resetCache();
        $this->assertSame([], SubmissionFileValidation::validate_upload_gate($row, $this->submitter->id));

        // No MONDO term exact-matches it any more.
        Disease::where('curie', 'MONDO:0000099')->first()->update(['xrefs' => ['omim_id' => []]]);
        SubmissionFileValidation::resetCache();
        $this->assertSame([], SubmissionFileValidation::validate_upload_gate($row, $this->submitter->id));
    }

    public function test_republish_row_naming_a_different_disease_is_still_rejected(): void
    {
        $this->publishedOmimAssertion();
        $gate = fn ($disease) => array_column(SubmissionFileValidation::validate_upload_gate(
            $this->createValidSpreadsheet([$this->createValidDataRow([
                'action' => 'R', 'sgc_id' => 'SGC-100001', 'disease_id' => $disease,
            ])]), $this->submitter->id), 'error_type');

        // Resolves to the stored MONDO term, but is not the identifier that was submitted.
        $this->assertContains('republish_relationship_mismatch', $gate('MONDO:0000001'));
        $this->assertContains('republish_invalid_disease', $gate('OMIM:999999'));
    }

    private function publishedOmimAssertion(): Submission
    {
        $job = Job::create(['submitter_id' => $this->submitter->id, 'user_id' => 1, 'status' => Job::STATUS_PROCESSED]);

        return Submission::create([
            'sid' => 'SGC-100001', 'job_id' => $job->id, 'submitter_id' => $this->submitter->id, 'user_id' => 1,
            'submission_data' => ['disease' => ['id' => 'OMIM:123456']],
            'gene_id' => Gene::where('hgnc_id', 'HGNC:5')->value('id'),
            'original_disease_id' => Disease::where('curie', 'OMIM:123456')->value('id'),
            'disease_id' => Disease::where('curie', 'MONDO:0000001')->value('id'),
            'inheritance_id' => Inheritance::where('curie', 'HP:0000006')->value('id'),
            'status' => Submission::STATUS_PUBLISHED, 'publish_date' => '2025-01-01',
            'is_live' => true, 'is_most_recent' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset static cache to ensure fresh data between test runs
        SubmissionFileValidation::resetCache();

        // Seed test data
        $this->seedTestData();
    }

    /**
     * Seed minimal test data needed for validation
     */
    protected function seedTestData(): void
    {
        // Create test submitter (curie is auto-assigned)
        $this->submitter = Submitter::create([
            'name' => 'Test Submitter',
            'status' => 1
        ]);

        // Create test classifications
        Classification::create([
            'curie' => 'GENCC:100001',
            'name' => 'Definitive',
            'description' => 'Test classification',
            'abbreviation' => 'DEF',
            'type' => Classification::TYPE_CLASSIFICATION,
            'status' => Classification::STATUS_ACTIVE
        ]);

        Classification::create([
            'curie' => 'GENCC:100002',
            'name' => 'Strong',
            'description' => 'Test classification',
            'abbreviation' => 'STR',
            'type' => Classification::TYPE_CLASSIFICATION,
            'status' => Classification::STATUS_ACTIVE
        ]);

        // Create test inheritances (MOI)
        Inheritance::create([
            'curie' => 'HP:0000006',
            'name' => 'Autosomal dominant',
            'description' => 'Test inheritance',
            'abbreviation' => 'AD',
            'type' => Inheritance::TYPE_MOI,
            'status' => Inheritance::STATUS_ACTIVE
        ]);

        Inheritance::create([
            'curie' => 'HP:0000007',
            'name' => 'Autosomal recessive',
            'description' => 'Test inheritance',
            'abbreviation' => 'AR',
            'type' => Inheritance::TYPE_MOI,
            'status' => Inheritance::STATUS_ACTIVE
        ]);

        // Create test genes
        Gene::create([
            'hgnc_id' => 'HGNC:5',
            'symbol' => 'A1BG',
            'name' => 'alpha-1-B glycoprotein',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'location' => '19q13.43',
            'status' => Gene::STATUS_ACTIVE
        ]);

        Gene::create([
            'hgnc_id' => 'HGNC:9673',
            'symbol' => 'BRCA1',
            'name' => 'breast cancer 1',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'location' => '17q21.31',
            'status' => Gene::STATUS_ACTIVE
        ]);

        Gene::create([
            'hgnc_id' => 'HGNC:1234',
            'symbol' => 'TEST1',
            'name' => 'test gene 1',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'location' => '1p36.33',
            'status' => Gene::STATUS_ACTIVE
        ]);

        // Create test diseases
        $mondo = Disease::create([
            'curie' => 'MONDO:0000001',
            'name' => 'disease',
            'description' => 'Test disease',
            'type' => Disease::TYPE_MONDO,
            // The identifiers MONDO itself exact-matches, which is the only way
            // an OMIM or Orphanet code reaches a MONDO term
            'xrefs' => ['omim_id' => ['123456'], 'orpha_id' => ['700001'], 'replaced_by' => null],
            'status' => Disease::STATUS_ACTIVE
        ]);

        Disease::create([
            'curie' => 'OMIM:123456',
            'name' => 'Test OMIM Disease',
            'description' => 'Test OMIM disease',
            'type' => Disease::TYPE_OMIM,
            'status' => Disease::STATUS_ACTIVE
        ]);

        // An Orphanet term MONDO has ingested
        Disease::create([
            'curie' => 'Orphanet:700001',
            'name' => 'Test Orphanet Disease',
            'description' => 'Test Orphanet disease',
            'type' => Disease::TYPE_ORPHANET,
            'status' => Disease::STATUS_ACTIVE
        ]);

        // An Orphanet term with no exact MONDO equivalent from any direction.
        // Warned about at upload, then rejected per record.
        Disease::create([
            'curie' => 'Orphanet:723146',
            'name' => 'Unmapped Orphanet Disease',
            'description' => 'Orphanet term with no MONDO equivalent',
            'type' => Disease::TYPE_ORPHANET,
            'status' => Disease::STATUS_ACTIVE
        ]);

        // Create test mechanisms
        Mechanism::create([
            'curie' => 'MECH:001',
            'name' => 'Loss of function',
            'description' => 'Test mechanism',
            'abbreviation' => 'LOF',
            'status' => Mechanism::STATUS_ACTIVE
        ]);
    }

    /**
     * Helper to create a valid spreadsheet structure
     */
    protected function createValidSpreadsheet(array $dataRows = []): array
    {
        $worksheet = [];

        // Rows 1-5: Metadata/info rows (can be empty for testing)
        for ($i = 0; $i < 5; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }

        // Row 6: Header row
        $worksheet[] = [
            'sgc_id',
            'action',
            'local_key',
            'hgnc_id',
            'hgnc_symbol',
            'disease_id',
            'disease_name',
            'moi_id',
            'moi_name',
            'submitter_id',
            'submitter_name',
            'classification_id',
            'classification_name',
            'date',
            'public_report_url',
            'notes',
            'pmids',
            'assertion_criteria_url'
        ];

        // Rows 7-12: Help text rows (can be empty for testing)
        for ($i = 0; $i < 6; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }

        // Row 13+: Data rows
        foreach ($dataRows as $row) {
            $worksheet[] = $row;
        }

        return $worksheet;
    }

    /**
     * Helper to create a valid data row
     * Returns a numeric-indexed array (like Excel rows)
     */
    protected function createValidDataRow(array $overrides = []): array
    {
        $defaults = [
            'sgc_id' => '',
            'action' => 'N',
            'local_key' => 'TEST001',
            'hgnc_id' => 'HGNC:5',
            'hgnc_symbol' => 'A1BG',
            'disease_id' => 'MONDO:0000001',
            'disease_name' => 'Test Disease',
            'moi_id' => 'HP:0000006',
            'moi_name' => 'Autosomal dominant',
            'submitter_id' => $this->submitter->curie,
            'submitter_name' => 'Test Submitter',
            'classification_id' => 'GENCC:100001',
            'classification_name' => 'Definitive',
            'date' => '2024-01-15',
            'public_report_url' => 'https://example.com/report',
            'notes' => 'Test notes',
            'pmids' => '12345678',
            'assertion_criteria_url' => 'https://example.com/criteria'
        ];

        // Merge overrides
        $merged = array_merge($defaults, $overrides);

        // Convert to numeric-indexed array (Excel row format)
        return [
            $merged['sgc_id'],                      // 0
            $merged['action'],                      // 1
            $merged['local_key'],                   // 2
            $merged['hgnc_id'],                     // 3
            $merged['hgnc_symbol'],                 // 4
            $merged['disease_id'],                  // 5
            $merged['disease_name'],                // 6
            $merged['moi_id'],                      // 7
            $merged['moi_name'],                    // 8
            $merged['submitter_id'],                // 9
            $merged['submitter_name'],              // 10
            $merged['classification_id'],           // 11
            $merged['classification_name'],         // 12
            $merged['date'],                        // 13
            $merged['public_report_url'],           // 14
            $merged['notes'],                       // 15
            $merged['pmids'],                       // 16
            $merged['assertion_criteria_url']       // 17
        ];
    }

    /**
     * Test 1: Minimum row requirement validation
     */
    public function test_fails_when_fewer_than_minimum_rows(): void
    {
        $worksheet = [];
        for ($i = 0; $i < 10; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_file_format', $errors[0]['error_type']);
        $this->assertTrue($errors[0]['is_file_format_error']);
    }

    /**
     * Test 2: Missing header row validation
     */
    public function test_fails_when_header_row_missing(): void
    {
        $worksheet = [];
        for ($i = 0; $i < 13; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_header_columns', $errors[0]['error_type']);
        $this->assertTrue($errors[0]['is_file_format_error']);
    }

    /**
     * Test 3: Invalid header columns validation
     */
    public function test_fails_when_header_columns_invalid(): void
    {
        $worksheet = [];
        for ($i = 0; $i < 5; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }

        // Row 6: Invalid header
        $worksheet[] = ['wrong_column', 'another_wrong', 'invalid'];

        for ($i = 0; $i < 6; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }

        $worksheet[] = $this->createValidDataRow();

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_header_columns', $errors[0]['error_type']);
    }

    /**
     * Test 4: Invalid action type validation
     */
    public function test_fails_when_action_is_invalid(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['action' => 'X']) // Invalid action
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_field_format', $errors[0]['error_type']);
        $this->assertStringContainsString('action', strtolower($errors[0]['message']));
    }

    /**
     * Test 5: New (N) submissions must NOT have SGC_ID
     */
    public function test_fails_when_new_submission_has_sgc_id(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'N',
                'sgc_id' => 'SGC-100001' // Should be empty for new
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('new_with_sgc_id', $errors[0]['error_type']);
    }

    /**
     * Test 6: Republish (R) requires SGC_ID
     */
    public function test_fails_when_republish_missing_sgc_id(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => '' // Missing for republish
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('action_missing_sgc_id', $errors[0]['error_type']);
    }

    /**
     * Test 7: Unpublish (U) requires SGC_ID
     */
    public function test_fails_when_unpublish_missing_sgc_id(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'U',
                'sgc_id' => '' // Missing for unpublish
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        // Missing SGC ID is reported even when the U row also contains extra data.
        $this->assertContains('action_missing_sgc_id', array_column($errors, 'error_type'));
    }

    /**
     * Test 8: Unpublish (U) must have only SGC_ID and Action filled
     */
    public function test_fails_when_unpublish_has_other_fields(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'U',
                'sgc_id' => 'SGC-100001',
                // These should all be empty for unpublish
                'hgnc_id' => 'HGNC:5',
                'disease_id' => 'MONDO:0000001'
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('unpublish_has_data', $errors[0]['error_type']);
    }

    /**
     * Test 9: SGC_ID format validation
     */
    public function test_fails_when_sgc_id_has_invalid_format(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-0001' // Leading zeros not allowed
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_sgc_id_format', $errors[0]['error_type']);
    }

    /**
     * Test 16: Duplicate SGC_ID within spreadsheet
     */
    public function test_fails_when_sgc_id_duplicated_in_spreadsheet(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001'
            ]),
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001' // Duplicate
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        // The validator reports this as unique_column_requirement
        $this->assertEquals('duplicate_sgc_id', $errors[0]['error_type']);
    }

    /**
     * Test 17: Valid new submission passes all validations
     */
    public function test_passes_with_valid_new_submission(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow()
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertEmpty($errors);
    }

    /**
     * Test 18: Republish cannot change gene - NEW TEST
     *
     * This tests the gene change validation that was recently added.
     * When attempting to republish with a different gene, it should fail.
     */
    public function test_fails_when_republish_changes_gene(): void
    {
        // Create a published submission with HGNC:9673
        $job = Job::create([
            'submitter_id' => $this->submitter->id,
            'user_id' => 1,
            // created_at is auto-set by Laravel
            'status' => Job::STATUS_PROCESSED
        ]);

        $diseaseId = Disease::where('curie', 'MONDO:0000001')->first()->id;
        $submission = Submission::create([
            'sid' => 'SGC-100001',
            'job_id' => $job->id,
            'submitter_id' => $this->submitter->id,
            'user_id' => 1,
            // created_at is auto-set by Laravel
            'submission_data' => json_encode([]),
            'gene_id' => Gene::where('hgnc_id', 'HGNC:9673')->first()->id,
            'disease_id' => $diseaseId,
            'original_disease_id' => $diseaseId,
            'classification_id' => Classification::where('curie', 'GENCC:100001')->first()->id,
            'moi_id' => Inheritance::where('curie', 'HP:0000006')->first()->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
        ]);

        // Try to republish with different gene (use HGNC:5 which is A1BG, different from BRCA1)
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001',
                'hgnc_id' => 'HGNC:5', // Different gene! (A1BG instead of BRCA1)
                'hgnc_symbol' => 'A1BG'
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);
        // The validator correctly reports this as republish_gene_change
        $this->assertEquals('republish_gene_change', $errors[0]['error_type']);
        $this->assertStringContainsString('cannot change the gene', strtolower($errors[0]['message']));
    }

    /**
     * Test 19: Republish with same gene passes validation
     *
     * This tests that when the gene ID matches (with or without HGNC: prefix),
     * the validation passes correctly.
     */
    public function test_passes_when_republish_keeps_same_gene(): void
    {
        // Create a published submission with HGNC:9673
        $job = Job::create([
            'submitter_id' => $this->submitter->id,
            'user_id' => 1,
            // created_at is auto-set by Laravel
            'status' => Job::STATUS_PROCESSED
        ]);

        $gene = Gene::where('hgnc_id', 'HGNC:9673')->first();
        $disease = Disease::where('curie', 'MONDO:0000001')->first();
        $classification = Classification::where('curie', 'GENCC:100001')->first();
        $moi = Inheritance::where('curie', 'HP:0000006')->first();

        $submission = Submission::create([
            'sid' => 'SGC-100001',
            'job_id' => $job->id,
            'submitter_id' => $this->submitter->id,
            'user_id' => 1,
            // created_at is auto-set by Laravel
            'submission_data' => json_encode([]),
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'classification_id' => $classification->id,
            'moi_id' => $moi->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
        ]);

        // Republish with same gene (no HGNC: prefix in file)
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001',
                'hgnc_id' => '9673' // Same gene (without HGNC: prefix)
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        // Should not have gene change error
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertEmpty($geneChangeErrors, 'Should not have gene change error when gene is the same');
    }

    /**
     * Test 20: Duplicate submission within same file upload
     *
     * When uploading multiple rows with the same gene-disease-MOI combination
     * (intra-batch duplicates), the validator should report an error.
     */
    public function test_fails_when_file_has_duplicate_gene_disease_moi(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'N',
                'local_key' => 'TEST001',
                'hgnc_id' => 'HGNC:5',
                'disease_id' => 'MONDO:0000001',
                'moi_id' => 'HP:0000006',
            ]),
            $this->createValidDataRow([
                'action' => 'N',
                'local_key' => 'TEST002', // Different local key
                'hgnc_id' => 'HGNC:5', // Same gene
                'disease_id' => 'MONDO:0000001', // Same disease
                'moi_id' => 'HP:0000006', // Same MOI = DUPLICATE!
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        $this->assertNotEmpty($errors);

        // Find the duplicate submission error (consolidated as 'duplicate_submission' with grouped rows)
        $duplicateErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'duplicate_submission'
                && isset($error['message']) && str_contains($error['message'], 'Duplicate rows within file');
        });

        $this->assertNotEmpty($duplicateErrors, 'Should report duplicate submission error for same gene-disease-MOI in file');
    }

    /**
     * Test 23: No error when different MOI even with same gene-disease
     *
     * Confirms that duplicate detection works on all three fields, not just two.
     */
    public function test_passes_when_different_moi_with_same_gene_disease(): void
    {
        // Create an existing published submission
        $job = Job::create([
            'submitter_id' => $this->submitter->id,
            'user_id' => 1,
            // created_at is auto-set by Laravel
            'status' => Job::STATUS_PROCESSED
        ]);

        $gene = Gene::where('hgnc_id', 'HGNC:5')->first();
        $disease = Disease::where('curie', 'MONDO:0000001')->first();
        $classification = Classification::where('curie', 'GENCC:100001')->first();
        $moiAD = Inheritance::where('curie', 'HP:0000006')->first(); // Autosomal dominant

        $existingSubmission = Submission::create([
            'sid' => 'SGC-100003',
            'job_id' => $job->id,
            'submitter_id' => $this->submitter->id,
            'user_id' => 1,
            // created_at is auto-set by Laravel
            'submission_data' => json_encode([]),
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'classification_id' => $classification->id,
            'inheritance_id' => $moiAD->id, // Autosomal dominant
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
        ]);

        // Try to upload a NEW submission with DIFFERENT MOI
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'N',
                'local_key' => 'TEST-NEW-003',
                'hgnc_id' => 'HGNC:5', // Same gene
                'disease_id' => 'MONDO:0000001', // Same disease
                'moi_id' => 'HP:0000007', // Different MOI (Autosomal recessive)
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->submitter->id);
        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->submitter->id);

        // Should have no duplicate errors
        $duplicateErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) &&
                   ($error['error_type'] === 'duplicate_submission' ||
                    $error['error_type'] === 'batch_duplicate_submission' ||
                    $error['error_type'] === 'unpublished_duplicate_submission');
        });

        $this->assertEmpty($duplicateErrors, 'Should NOT have duplicate error when MOI is different');
    }

}
