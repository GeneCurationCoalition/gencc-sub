<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Document;
use App\Models\Job;
use App\Models\Submitter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Unit tests for Document database file storage
 *
 * These tests verify that files are stored in the database as base64
 * instead of on the filesystem.
 */
class DocumentDatabaseStorageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Submitter $submitter;
    protected Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTestData();
    }

    protected function seedTestData(): void
    {
        $this->submitter = Submitter::create([
            'curie' => 'GENCC:000113',
            'name' => 'Test Submitter',
            'status' => 1,
            'type' => 0
        ]);

        $this->user = User::factory()->create([
            'submitter_id' => $this->submitter->id,
        ]);

        $this->job = Job::create([
            'submitter_id' => $this->submitter->id,
            'user_id' => $this->user->id,
            'status' => Job::STATUS_DRAFT,
            'type' => Job::TYPE_FILE_SUBMISSION
        ]);
    }

    /**
     * Test that file contents can be stored as base64 in the document
     */
    public function test_file_contents_stored_as_base64(): void
    {
        $originalContent = 'This is test file content with special chars: é ñ 中文';

        $document = Document::create([
            'type' => 1,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'test.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => strlen($originalContent),
            'status' => Document::STATUS_STORED_UNPROCESSED,
            'file_contents' => base64_encode($originalContent),
            'local_path' => null,
        ]);

        // Verify storage
        $this->assertNotNull($document->file_contents);
        $this->assertNull($document->local_path);

        // Verify we can decode and get original content
        $decoded = base64_decode($document->file_contents);
        $this->assertEquals($originalContent, $decoded);
    }

    /**
     * Test that local_path can be null when using database storage
     */
    public function test_local_path_can_be_null(): void
    {
        $document = Document::create([
            'type' => 1,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'test.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 100,
            'status' => Document::STATUS_STORED_UNPROCESSED,
            'file_contents' => base64_encode('test content'),
            'local_path' => null,
        ]);

        $this->assertNull($document->local_path);
        $this->assertNotNull($document->file_contents);
    }

    /**
     * Test that file contents can store binary data
     */
    public function test_file_contents_stores_binary_data(): void
    {
        // Create some binary content (simulating Excel file bytes)
        $binaryContent = pack('C*', 0x50, 0x4B, 0x03, 0x04, 0x14, 0x00);

        $document = Document::create([
            'type' => 1,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'test.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => strlen($binaryContent),
            'status' => Document::STATUS_STORED_UNPROCESSED,
            'file_contents' => base64_encode($binaryContent),
            'local_path' => null,
        ]);

        // Verify we can decode binary content correctly
        $decoded = base64_decode($document->file_contents);
        $this->assertEquals($binaryContent, $decoded);
        $this->assertEquals(6, strlen($decoded));
    }

    /**
     * Test that deleting document removes file contents from database
     */
    public function test_deleting_document_removes_file_contents(): void
    {
        $document = Document::create([
            'type' => 1,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'test.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 100,
            'status' => Document::STATUS_STORED_UNPROCESSED,
            'file_contents' => base64_encode('test content'),
            'local_path' => null,
        ]);

        $documentId = $document->id;

        // Delete the document
        $document->delete();

        // Verify document is soft deleted (Document uses SoftDeletes)
        $this->assertSoftDeleted('documents', ['id' => $documentId]);

        // Force delete to verify data is truly removed
        Document::withTrashed()->find($documentId)->forceDelete();

        // Verify the document no longer exists
        $this->assertDatabaseMissing('documents', ['id' => $documentId]);
    }

    /**
     * Test that empty file contents is detected correctly
     */
    public function test_empty_file_contents_detection(): void
    {
        $document = Document::create([
            'type' => 1,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'test.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 0,
            'status' => Document::STATUS_STORED_UNPROCESSED,
            'file_contents' => null,
            'local_path' => null,
        ]);

        $this->assertTrue(empty($document->file_contents));
    }

    /**
     * Test that large file contents can be stored
     */
    public function test_large_file_contents_storage(): void
    {
        // Create 1MB of content
        $largeContent = str_repeat('A', 1024 * 1024);

        $document = Document::create([
            'type' => 1,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'large.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => strlen($largeContent),
            'status' => Document::STATUS_STORED_UNPROCESSED,
            'file_contents' => base64_encode($largeContent),
            'local_path' => null,
        ]);

        // Verify we can retrieve and decode the large content
        $decoded = base64_decode($document->file_contents);
        $this->assertEquals(1024 * 1024, strlen($decoded));
        $this->assertEquals($largeContent, $decoded);
    }
}
