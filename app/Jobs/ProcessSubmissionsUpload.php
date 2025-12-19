<?php

namespace App\Jobs;

use App\Models\Document;
use App\Http\Controllers\API\DocumentController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSubmissionsUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour
    public $tries = 1;

    protected $document;

    /**
     * Create a new job instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \Log::info('ProcessSubmissionsUpload: Starting', [
            'document_id' => $this->document->id,
        ]);

        // Update state to uploading
        $this->document->update([
            'upload_state' => Document::UPLOAD_STATE_UPLOADING,
            'upload_started_at' => now(),
            'processed_submissions' => 0,
        ]);

        // Call the refactored parser method
        $controller = new DocumentController();
        $result = $controller->parser($this->document);

        // Determine final state
        $finalState = ($result['processed_rows'] === $result['total_rows'])
            ? Document::UPLOAD_STATE_UPLOAD_COMPLETE
            : Document::UPLOAD_STATE_UPLOAD_PARTIAL;

        // Update document
        $this->document->update([
            'upload_state' => $finalState,
            'processed_submissions' => $result['processed_rows'],
            'upload_completed_at' => now(),
            'processing_errors' => $result['errors'] ?? null,
        ]);

        // Unlock job
        $this->document->job->update(['is_processing' => false]);

        \Log::info('ProcessSubmissionsUpload: Complete', [
            'final_state' => $finalState,
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        \Log::error('ProcessSubmissionsUpload: Failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'upload_state' => Document::UPLOAD_STATE_UPLOAD_PARTIAL,
            'upload_completed_at' => now(),
            'processing_errors' => [[
                'error_type' => 'queue_job_failed',
                'severity' => 'error',
                'message' => 'Background processing failed: ' . $exception->getMessage(),
            ]],
        ]);

        $this->document->job->update(['is_processing' => false]);
    }
}
