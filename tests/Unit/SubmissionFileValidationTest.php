<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Support\SeedsDiseaseWorld;
use App\Services\SubmissionFileValidation;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Submitter;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;

class SubmissionFileValidationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiseaseWorld;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset static cache to ensure fresh data between test runs
        SubmissionFileValidation::resetCache();

        // Run migrations to create tables in the in-memory SQLite database
        $this->artisan('migrate');

        // Seed minimal test data
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Seed minimal test data required for validation tests
     */
    protected function seedTestData(): void
    {
        // Create a test gene
        Gene::create([
            'hgnc_id' => 'HGNC:5',
            'symbol' => 'A1BG',
            'name' => 'alpha-1-B glycoprotein',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'status' => 'Approved',
            'location' => '19q13.43',
            'gene_history' => json_encode([]),
        ]);

        // Create test diseases
        Disease::create([
            'curie' => 'MONDO:0000001',
            'name' => 'disease',
            'type' => Disease::TYPE_MONDO,
            'description' => 'Test disease',
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => [],
            'scores' => [],
            'counts' => [],
            'activity' => [],
            'events' => [],
        ]);

        Disease::create([
            'curie' => 'OMIM:203655',
            'name' => 'ALOPECIA UNIVERSALIS CONGENITA',
            'type' => Disease::TYPE_OMIM_NUMBER,
            'description' => 'Test OMIM disease',
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => [],
            'scores' => [],
            'counts' => [],
            'activity' => [],
            'events' => [],
        ]);

        // Create test classifications
        Classification::create([
            'curie' => 'GENCC:100001',
            'name' => 'Definitive',
            'description' => 'Test classification',
            'abbreviation' => 'DEF',
            'status' => Classification::STATUS_ACTIVE,
        ]);

        Classification::create([
            'curie' => 'GENCC:100002',
            'name' => 'Strong',
            'description' => 'Test classification',
            'abbreviation' => 'STR',
            'status' => Classification::STATUS_ACTIVE,
        ]);

        // Create test inheritances
        Inheritance::create([
            'curie' => 'HP:0000005',
            'name' => 'Mode of inheritance',
            'description' => 'Test inheritance',
            'abbreviation' => 'MOI',
            'status' => Inheritance::STATUS_ACTIVE,
        ]);

        Inheritance::create([
            'curie' => 'HP:0000006',
            'name' => 'Autosomal dominant',
            'description' => 'Test inheritance',
            'abbreviation' => 'AD',
            'status' => Inheritance::STATUS_ACTIVE,
        ]);

        // Create test submitter (curie is auto-assigned)
        $this->testSubmitter = Submitter::create([
            'name' => 'Test Submitter',
            'description' => 'Test submitter for validation',
        ]);

        // Create test user
        \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create test job
        \App\Models\Job::create([
            'user_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'status' => \App\Models\Job::STATUS_DRAFT,
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
     * Returns a numeric-indexed array (like Excel rows) not associative
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
            'disease_name' => 'disease',
            'moi_id' => 'HP:0000006',
            'moi_name' => 'Autosomal dominant',
            'submitter_id' => $this->testSubmitter->curie,
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
        // Order must match the header row in createValidSpreadsheet()
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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

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
            $worksheet[] = array_fill(0, 18, ''); // All empty including row 6 (header)
        }

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        // Empty headers are treated as invalid headers, not missing headers
        $this->assertEquals('invalid_header_columns', $errors[0]['error_type']);
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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('missing_required_field', $errors[0]['error_type']);
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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_sgc_id_format', $errors[0]['error_type']);
    }

    /**
     * Test 10: HGNC_ID format validation
     */
    public function test_fails_when_hgnc_id_has_invalid_format(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'hgnc_id' => 'INVALID' // Not numeric or HGNC:####
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_field_format', $errors[0]['error_type']);
    }

    /**
     * Test 11: Disease_ID format validation
     */
    public function test_fails_when_disease_id_has_invalid_format(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'disease_id' => 'INVALID:123' // Not MONDO, OMIM, or ORPHA
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_field_format', $errors[0]['error_type']);
    }

    /**
     * Test 12: Date format validation
     */
    public function test_fails_when_date_has_invalid_format(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'date' => '01-15-2024' // Wrong format, should be YYYY-MM-DD
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_field_format', $errors[0]['error_type']);
    }

    public function test_grouped_dates_preserve_each_rejection_reason(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['date' => '2999-01-01']),
            $this->createValidDataRow(['date' => '2999-01-01', 'local_key' => 'TEST002']),
            $this->createValidDataRow(['date' => '2024-01-15T99:99garbage', 'local_key' => 'TEST003']),
            $this->createValidDataRow(['date' => '2026', 'local_key' => 'TEST004']),
        ]);

        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);
        $dates = collect($errors)->where('column', 'date');
        $this->assertCount(1, $dates);
        $date = $dates->first();
        $this->assertSame("Invalid date for column 'date' (4 rows).", $date['message']);
        $this->assertCount(3, $date['details']);
        $details = collect($date['details'])->keyBy('value');
        $this->assertSame('13, 14', $details['2999-01-01']['rows']);
        $this->assertSame(2, $details['2999-01-01']['count']);
        $this->assertStringContainsString('outside the allowed date range', $details['2999-01-01']['reason']);
        $this->assertStringContainsString('one-day allowance', $details['2999-01-01']['reason']);
        $this->assertStringContainsString('Not a date.', $details['2024-01-15T99:99garbage']['reason']);
        $this->assertStringContainsString('YYYY-MM-DD', $details['2024-01-15T99:99garbage']['reason']);
        $this->assertStringContainsString('outside the allowed date range', $details['2026']['reason']);
        $this->assertArrayNotHasKey('_reasons', $date);
    }

    /**
     * Test 13: URL format validation
     */
    public function test_fails_when_url_has_invalid_format(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'public_report_url' => 'not-a-url' // Invalid URL
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_field_format', $errors[0]['error_type']);
    }

    /**
     * Test 14: PMID format validation - completely invalid PMIDs produce errors
     */
    public function test_fails_when_pmid_has_invalid_format(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'pmids' => 'abc,xyz' // Non-numeric values - no valid PMIDs extractable
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_pmid_format', $errors[0]['error_type']);
    }

    /**
     * Test 15: Required fields validation
     */
    public function test_fails_when_required_field_missing(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'hgnc_id' => '' // Required field missing
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('missing_required_field', $errors[0]['error_type']);
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

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('unique_column_requirement', $errors[0]['error_type']);
    }

    /**
     * Test 17: Valid new submission passes all validations
     */
    public function test_passes_with_valid_new_submission(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow()
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertEmpty($errors, 'Valid submission should not have errors. Errors: ' . json_encode($errors));
    }

    /**
     * Test 30: Republish cannot change gene - NEW TEST
     *
     * This tests the gene change validation that was recently added.
     * When attempting to republish with a different gene, it should fail.
     */
    public function test_fails_when_republish_changes_gene(): void
    {
        // Create a different gene for testing
        $differentGene = Gene::create([
            'hgnc_id' => 'HGNC:9673',
            'symbol' => 'TESTGENE',
            'name' => 'test gene',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'status' => 'Approved',
            'location' => '1p36.33',
            'gene_history' => json_encode([]),
        ]);

        // Create a published submission with the different gene (must be is_live=true)
        $submission = Submission::create([
            'sid' => 'SGC-100001',
            'gene_id' => $differentGene->id,
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => json_encode(['test' => 'data']),
            'original_submission_data' => json_encode(['test' => 'data']),
        ]);

        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001',
                'hgnc_id' => 'HGNC:5' // Different gene!
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertEquals('republish_gene_change', $errors[0]['error_type']);
        $this->assertStringContainsString('cannot change the gene', strtolower($errors[0]['message']));
    }

    /**
     * Test: Republish with same gene passes validation
     *
     * This tests that when the gene ID matches (with or without HGNC: prefix),
     * the validation passes correctly.
     */
    public function test_passes_when_republish_keeps_same_gene(): void
    {
        // Create a published submission with HGNC:5 gene (must be is_live=true)
        $submission = Submission::create([
            'sid' => 'SGC-100002',
            'gene_id' => 1, // The HGNC:5 gene created in seedTestData
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => json_encode(['test' => 'data']),
            'original_submission_data' => json_encode(['test' => 'data']),
        ]);

        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100002',
                'hgnc_id' => 'HGNC:5' // Same gene
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Should not have gene change error
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertEmpty($geneChangeErrors, 'Should not have gene change error when gene is the same');
    }

    /**
     * Test: Republish with same gene passes - spreadsheet uses numeric HGNC ID without prefix
     *
     * Tests that when the spreadsheet has just the numeric ID (e.g., "5" instead of "HGNC:5"),
     * the comparison still works correctly.
     */
    public function test_passes_when_republish_uses_numeric_hgnc_id(): void
    {
        // Create a published submission with HGNC:5 gene
        $submission = Submission::create([
            'sid' => 'SGC-100003',
            'gene_id' => 1, // The HGNC:5 gene created in seedTestData
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => json_encode(['test' => 'data']),
            'original_submission_data' => json_encode(['test' => 'data']),
        ]);

        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100003',
                'hgnc_id' => '5' // Just numeric, no HGNC: prefix
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Should not have gene change error
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertEmpty($geneChangeErrors, 'Should not have gene change error when using numeric HGNC ID');
    }

    /**
     * Test: Republish fails when gene relationship is null (gene_id = null)
     *
     * When the submission has no gene relationship, we should still detect gene changes
     * by falling back to original_submission_data.
     */
    public function test_republish_with_null_gene_relationship_uses_fallback(): void
    {
        // Create a published submission with null gene_id but original_submission_data has the gene
        // Note: Don't use json_encode() - the 'object' cast handles serialization
        $submission = Submission::create([
            'sid' => 'SGC-100004',
            'gene_id' => null, // No gene relationship
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => (object)['test' => 'data'],
            'original_submission_data' => (object)[
                'gene' => (object)['id' => 'HGNC:5', 'symbol' => 'A1BG']
            ],
        ]);

        // Try to republish with a DIFFERENT gene - should fail
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100004',
                'hgnc_id' => 'HGNC:9673' // Different gene
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Should have gene change error
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertNotEmpty($geneChangeErrors, 'Should detect gene change when using original_submission_data fallback');
    }

    /**
     * Test: Republish with null gene relationship and matching gene in original_submission_data
     */
    public function test_republish_with_null_gene_relationship_same_gene_passes(): void
    {
        // Create a published submission with null gene_id but original_submission_data has the gene
        // Note: Don't use json_encode() - the 'object' cast handles serialization
        $submission = Submission::create([
            'sid' => 'SGC-100005',
            'gene_id' => null, // No gene relationship
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => (object)['test' => 'data'],
            'original_submission_data' => (object)[
                'gene' => (object)['id' => 'HGNC:5', 'symbol' => 'A1BG']
            ],
        ]);

        // Try to republish with the SAME gene - should pass
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100005',
                'hgnc_id' => 'HGNC:5' // Same gene as in original_submission_data
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Should NOT have gene change error
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertEmpty($geneChangeErrors, 'Should not have gene change error when original_submission_data gene matches');
    }

    /**
     * Test: Republish with both gene relationship and original_submission_data, gene relationship takes priority
     */
    public function test_republish_gene_relationship_takes_priority_over_original_data(): void
    {
        // Create a different gene
        $differentGene = Gene::create([
            'hgnc_id' => 'HGNC:9999',
            'symbol' => 'OTHERGENE',
            'name' => 'other gene',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'status' => 'Approved',
            'location' => '1p36.33',
            'gene_history' => json_encode([]),
        ]);

        // Create a published submission with gene_id pointing to HGNC:5
        // but original_submission_data has a DIFFERENT gene
        $submission = Submission::create([
            'sid' => 'SGC-100006',
            'gene_id' => 1, // HGNC:5 gene
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => (object)['test' => 'data'],
            'original_submission_data' => (object)[
                'gene' => (object)['id' => 'HGNC:9999', 'symbol' => 'OTHERGENE'] // Different from gene_id
            ],
        ]);

        // Try to republish with HGNC:5 (matching gene_id, not original_submission_data)
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100006',
                'hgnc_id' => 'HGNC:5' // Matches gene relationship
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Should NOT have gene change error because gene relationship matches
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertEmpty($geneChangeErrors, 'Gene relationship should take priority over original_submission_data');
    }

    /**
     * Test: Case-insensitive comparison for HGNC prefix (hgnc: vs HGNC:)
     */
    public function test_republish_case_insensitive_hgnc_prefix(): void
    {
        // Create a published submission
        $submission = Submission::create([
            'sid' => 'SGC-100007',
            'gene_id' => 1, // HGNC:5 gene
            'disease_id' => 1,
            'original_disease_id' => 1,
            'classification_id' => 1,
            'inheritance_id' => 1,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => 'published',
            'is_live' => true,
            'submission_data' => json_encode(['test' => 'data']),
            'original_submission_data' => json_encode(['test' => 'data']),
        ]);

        // Use lowercase hgnc: prefix
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100007',
                'hgnc_id' => 'hgnc:5' // lowercase prefix
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Should NOT have gene change error - case insensitive match
        $geneChangeErrors = array_filter($errors, function($error) {
            return isset($error['error_type']) && $error['error_type'] === 'republish_gene_change';
        });

        $this->assertEmpty($geneChangeErrors, 'HGNC prefix comparison should be case-insensitive');
    }

    // =========================================================================
    // Error Grouping & Detail Tests
    // =========================================================================

    /**
     * Test: Field-level errors include column and value fields
     */
    public function test_field_errors_include_column_and_value(): void
    {
        $row = $this->createValidDataRow(['hgnc_id' => 'INVALID']);

        $errors = SubmissionFileValidation::validate_data_row($row, 13);

        $formatError = collect($errors)->firstWhere('error_type', 'invalid_field_format');
        $this->assertNotNull($formatError, 'Should have invalid_field_format error');
        $this->assertEquals('hgnc_id', $formatError['column']);
        $this->assertEquals('INVALID', $formatError['value']);
        $this->assertStringContainsString('INVALID', $formatError['message']);
    }

    /**
     * Test: Enum validation errors include column and value
     */
    public function test_enum_errors_include_column_and_value(): void
    {
        $row = $this->createValidDataRow(['classification_id' => 'GENCC:999999']);

        $errors = SubmissionFileValidation::validate_data_row($row, 13);

        $valueError = collect($errors)->first(fn($e) => ($e['error_type'] ?? '') === 'invalid_field_value' && ($e['column'] ?? '') === 'classification_id');
        $this->assertNotNull($valueError, 'Should have invalid_field_value error for classification_id');
        $this->assertEquals('classification_id', $valueError['column']);
        $this->assertEquals('GENCC:999999', $valueError['value']);
        $this->assertStringContainsString('GENCC:999999', $valueError['message']);
    }

    /**
     * Test: Database lookup errors include column and value
     */
    public function test_database_lookup_errors_include_column_and_value(): void
    {
        $row = $this->createValidDataRow(['disease_id' => 'MONDO:9999999']);

        $errors = SubmissionFileValidation::validate_data_row($row, 13);

        $valueError = collect($errors)->firstWhere('error_type', 'invalid_field_value');
        $this->assertNotNull($valueError, 'Should have invalid_field_value error for unknown disease');
        $this->assertEquals('disease_id', $valueError['column']);
        $this->assertEquals('MONDO:9999999', $valueError['value']);
    }

    /**
     * Test: Long values are truncated to 80 characters
     */
    public function test_long_values_are_truncated(): void
    {
        $longValue = str_repeat('x', 120);
        $row = $this->createValidDataRow(['assertion_criteria_url' => $longValue]);

        $errors = SubmissionFileValidation::validate_data_row($row, 13);

        $formatError = collect($errors)->firstWhere('column', 'assertion_criteria_url');
        $this->assertNotNull($formatError);
        $this->assertEquals(83, mb_strlen($formatError['value'])); // 80 + '...'
        $this->assertStringEndsWith('...', $formatError['value']);
    }

    /**
     * Test: Errors with same column are grouped together in validate_spreadsheet
     */
    public function test_errors_grouped_by_column(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['disease_id' => 'MONDO:9999999']),
            $this->createValidDataRow(['disease_id' => 'MONDO:8888888', 'local_key' => 'TEST002']),
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // Filter to just disease_id errors
        $diseaseErrors = collect($errors)->filter(fn($e) => ($e['column'] ?? null) === 'disease_id');

        // Should be grouped into one entry (not two separate ones)
        $this->assertCount(1, $diseaseErrors, 'Disease ID errors should be grouped into one entry');

        $grouped = $diseaseErrors->first();
        $this->assertStringContainsString('2 rows', $grouped['message']);
        $this->assertArrayHasKey('details', $grouped);
        $this->assertCount(2, $grouped['details']); // Two distinct values
    }

    /**
     * Test: Grouped errors have details with value, rows, and count
     */
    public function test_grouped_errors_have_details_structure(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['disease_id' => 'MONDO:9999999']),
            $this->createValidDataRow(['disease_id' => 'MONDO:9999999', 'local_key' => 'TEST002']),
            $this->createValidDataRow(['disease_id' => 'MONDO:8888888', 'local_key' => 'TEST003']),
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $diseaseError = collect($errors)->firstWhere('column', 'disease_id');
        $this->assertNotNull($diseaseError);

        // 3 total rows
        $this->assertStringContainsString('3 rows', $diseaseError['message']);

        // Details sorted by count descending
        $this->assertCount(2, $diseaseError['details']);
        $this->assertEquals(2, $diseaseError['details'][0]['count']); // MONDO:9999999 appears twice
        $this->assertEquals(1, $diseaseError['details'][1]['count']); // MONDO:8888888 appears once

        // Each detail has required fields
        foreach ($diseaseError['details'] as $detail) {
            $this->assertArrayHasKey('value', $detail);
            $this->assertArrayHasKey('rows', $detail);
            $this->assertArrayHasKey('count', $detail);
        }
    }

    public function test_grouped_disease_warnings_keep_ambiguity_candidates_separate_from_missing_mappings(): void
    {
        self::seedJuvenileAbsenceMappings(false);
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['disease_id' => 'ORPHA:1941']),
            $this->createValidDataRow(['disease_id' => 'ORPHA:1941', 'local_key' => 'TEST002']),
            $this->createValidDataRow(['disease_id' => 'Orphanet:999999', 'local_key' => 'TEST003']),
        ]);

        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);
        $group = collect($errors)->firstWhere('column', 'disease_id');
        $this->assertSame('warning', $group['severity']);
        $this->assertTrue($group['blocks_submission']);
        $this->assertStringContainsString('could not be resolved to a unique MONDO term', $group['message']);
        $this->assertCount(2, $group['details']);
        $details = collect($group['details'])->keyBy('value');
        $this->assertSame('13, 14', $details['ORPHA:1941']['rows']);
        $this->assertStringContainsString('MONDO:0011876', $details['ORPHA:1941']['reason']);
        $this->assertStringContainsString('MONDO:0800453', $details['ORPHA:1941']['reason']);
        $this->assertStringNotContainsString('MONDO:0020772', $details['ORPHA:1941']['reason']);
        $this->assertStringContainsString('No MONDO term found', $details['Orphanet:999999']['reason']);
        $this->assertStringNotContainsString('MONDO:0011876', $details['Orphanet:999999']['reason']);
    }

    /**
     * Test: Non-column errors (like duplicates) still group by message
     */
    public function test_non_column_errors_group_by_message(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001'
            ]),
            $this->createValidDataRow([
                'action' => 'R',
                'sgc_id' => 'SGC-100001'
            ])
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        // duplicate_sgc_id errors should be grouped (same message, no column field)
        $dupErrors = collect($errors)->where('error_type', 'duplicate_sgc_id');
        $this->assertCount(1, $dupErrors, 'Duplicate SGC ID errors should be grouped into one entry');

        $grouped = $dupErrors->first();
        $this->assertStringContainsString('13, 14', $grouped['rows']);
        $this->assertNull($grouped['column'] ?? null);
        $this->assertArrayNotHasKey('details', $grouped);
    }

    /**
     * Test: Grouped errors do not leak internal fields (sgc_id, local_key, _values)
     */
    public function test_grouped_errors_clean_internal_fields(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['hgnc_id' => 'INVALID']),
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        foreach ($errors as $error) {
            $this->assertArrayNotHasKey('_values', $error, 'Internal _values should be removed');
            $this->assertArrayNotHasKey('sgc_id', $error, 'sgc_id should be removed from grouped errors');
            $this->assertArrayNotHasKey('local_key', $error, 'local_key should be removed from grouped errors');
            $this->assertArrayNotHasKey('row', $error, 'Individual row field should be removed (rows string used instead)');
        }
    }

    /**
     * Test: File format errors preserve is_file_format_error, user_title, user_message
     */
    public function test_file_format_errors_preserve_user_fields(): void
    {
        $worksheet = [];
        for ($i = 0; $i < 5; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }
        // Invalid headers
        $worksheet[] = ['wrong_col_1', 'wrong_col_2'];
        for ($i = 0; $i < 6; $i++) {
            $worksheet[] = array_fill(0, 18, '');
        }
        $worksheet[] = $this->createValidDataRow();

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $this->assertNotEmpty($errors);
        $this->assertTrue($errors[0]['is_file_format_error'] ?? false);
        $this->assertNotEmpty($errors[0]['user_title'] ?? '');
        $this->assertNotEmpty($errors[0]['user_message'] ?? '');
    }

    /**
     * Test: Single row error still gets grouped summary with "1 row"
     */
    public function test_single_row_error_grouped_with_singular_count(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['hgnc_id' => 'INVALID']),
        ]);

        SubmissionFileValidation::set_submitter_id($this->testSubmitter->id);
        $errors = SubmissionFileValidation::validate_spreadsheet($worksheet, $this->testSubmitter->id, true);

        $hgncError = collect($errors)->firstWhere('column', 'hgnc_id');
        $this->assertNotNull($hgncError);
        $this->assertStringContainsString('1 row)', $hgncError['message']);
        $this->assertStringNotContainsString('1 rows', $hgncError['message']);
    }

    public function test_upload_gate_allows_content_errors_to_become_record_errors(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'hgnc_id' => '',
                'disease_id' => 'NOT_A_DISEASE',
                'moi_id' => 'HP:9999999',
                'classification_id' => 'GENCC:999999',
                'date' => '2024-02-30',
                'public_report_url' => 'not-a-url',
                'pmids' => 'not-a-pmid',
                'assertion_criteria_url' => 'not-a-url',
            ]),
        ]);

        $this->assertSame([], SubmissionFileValidation::validate_upload_gate(
            $worksheet,
            $this->testSubmitter->id
        ));
    }

    public function test_upload_gate_requires_the_exact_header_order(): void
    {
        $worksheet = $this->createValidSpreadsheet([$this->createValidDataRow()]);
        [$worksheet[5][3], $worksheet[5][5]] = [$worksheet[5][5], $worksheet[5][3]];

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertSame('invalid_header_columns', $errors[0]['error_type']);
        $this->assertStringContainsString('exact order', $errors[0]['user_message']);
    }

    public function test_upload_gate_rejects_extra_headers(): void
    {
        $worksheet = $this->createValidSpreadsheet([$this->createValidDataRow()]);
        $worksheet[5][] = 'unexpected_column';

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertSame('invalid_header_columns', $errors[0]['error_type']);
        $this->assertStringContainsString('Unexpected columns', $errors[0]['user_message']);
    }

    public function test_upload_gate_does_not_compress_an_internal_blank_header_cell(): void
    {
        $worksheet = $this->createValidSpreadsheet([$this->createValidDataRow()]);
        array_splice($worksheet[5], 3, 0, ['']);

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertSame('invalid_header_columns', $errors[0]['error_type']);
        $this->assertStringContainsString('exact order', $errors[0]['user_message']);
    }

    public function test_upload_gate_rejects_values_beyond_the_declared_columns(): void
    {
        $row = $this->createValidDataRow();
        $row[] = 'unexpected value';
        $worksheet = $this->createValidSpreadsheet([$row]);

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertNotNull(collect($errors)->firstWhere('error_type', 'unexpected_data_columns'));
    }

    public function test_upload_gate_ignores_template_content_above_row_thirteen(): void
    {
        $worksheet = $this->createValidSpreadsheet([$this->createValidDataRow()]);
        $worksheet[7] = $this->createValidDataRow();

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertSame([], $errors);
    }

    public function test_upload_gate_rejects_a_file_without_submission_rows(): void
    {
        $worksheet = $this->createValidSpreadsheet([]);
        $worksheet[] = array_fill(0, 18, '');

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertNotNull(collect($errors)->firstWhere('error_type', 'missing_submission_rows'));
    }

    public function test_upload_gate_rejects_duplicate_relationship_keys_within_the_file(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['local_key' => 'FIRST']),
            $this->createValidDataRow(['local_key' => 'SECOND']),
        ]);

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $duplicate = collect($errors)->firstWhere('error_type', 'duplicate_submission');
        $this->assertNotNull($duplicate);
        $this->assertStringContainsString('(13, 14)', $duplicate['message']);
    }

    public function test_upload_gate_does_not_reject_a_relationship_matching_an_existing_submission(): void
    {
        $gene = Gene::where('hgnc_id', 'HGNC:5')->firstOrFail();
        $disease = Disease::where('curie', 'MONDO:0000001')->firstOrFail();
        $inheritance = Inheritance::where('curie', 'HP:0000006')->firstOrFail();

        Submission::factory()->create([
            'submitter_id' => $this->testSubmitter->id,
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'inheritance_id' => $inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
            'submission_data' => (object) [],
        ]);

        $worksheet = $this->createValidSpreadsheet([$this->createValidDataRow()]);

        $this->assertSame([], SubmissionFileValidation::validate_upload_gate(
            $worksheet,
            $this->testSubmitter->id
        ));
    }

    public function test_upload_gate_preserves_missing_field_language_when_grouping(): void
    {
        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(['action' => '', 'submitter_id' => '']),
        ]);

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $action = collect($errors)->firstWhere('column', 'action');
        $submitter = collect($errors)->firstWhere('column', 'submitter_id');
        $this->assertStringContainsString("Required field 'action' is missing", $action['message']);
        $this->assertStringContainsString("Required field 'submitter_id' is missing", $submitter['message']);
    }

    public function test_upload_gate_requires_a_republish_sgc_to_match_the_relationship(): void
    {
        $gene = Gene::where('hgnc_id', 'HGNC:5')->firstOrFail();
        $disease = Disease::where('curie', 'MONDO:0000001')->firstOrFail();
        $inheritance = Inheritance::where('curie', 'HP:0000006')->firstOrFail();
        $classification = Classification::where('curie', 'GENCC:100001')->firstOrFail();

        Submission::create([
            'sid' => 'SGC-100099',
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'inheritance_id' => $inheritance->id,
            'classification_id' => $classification->id,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
            'submission_data' => (object) [],
        ]);

        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow([
                'sgc_id' => 'SGC-100099',
                'action' => 'R',
                'moi_id' => 'HP:0000005',
            ]),
        ]);

        $errors = SubmissionFileValidation::validate_upload_gate($worksheet, $this->testSubmitter->id);

        $this->assertNotNull(collect($errors)->firstWhere('error_type', 'republish_relationship_mismatch'));
    }

    /**
     * @dataProvider invalidRepublishRelationshipValues
     */
    public function test_upload_gate_rejects_an_invalid_republish_relationship_value(
        array $override,
        string $errorType
    ): void
    {
        $gene = Gene::where('hgnc_id', 'HGNC:5')->firstOrFail();
        $disease = Disease::where('curie', 'MONDO:0000001')->firstOrFail();
        $inheritance = Inheritance::where('curie', 'HP:0000006')->firstOrFail();
        $classification = Classification::where('curie', 'GENCC:100001')->firstOrFail();

        Submission::create([
            'sid' => 'SGC-100098',
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'inheritance_id' => $inheritance->id,
            'classification_id' => $classification->id,
            'submitter_id' => $this->testSubmitter->id,
            'job_id' => 1,
            'user_id' => 1,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
            'submission_data' => (object) [],
        ]);

        $worksheet = $this->createValidSpreadsheet([
            $this->createValidDataRow(array_merge([
                'sgc_id' => 'SGC-100098',
                'action' => 'R',
            ], $override)),
        ]);

        $errors = SubmissionFileValidation::validate_upload_gate(
            $worksheet,
            $this->testSubmitter->id
        );

        $this->assertNotNull(collect($errors)->firstWhere('error_type', $errorType));
    }

    public static function invalidRepublishRelationshipValues(): array
    {
        return [
            'gene' => [['hgnc_id' => 'NOT_A_GENE'], 'republish_invalid_gene'],
            'disease' => [['disease_id' => 'NOT_A_DISEASE'], 'republish_invalid_disease'],
            'mode of inheritance' => [['moi_id' => 'HP:9999999'], 'republish_invalid_moi'],
        ];
    }
}
