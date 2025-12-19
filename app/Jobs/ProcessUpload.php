<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Http\Controllers\API\DocumentController;
use App\Events\SpreadsheetUpdate;
use App\Models\Document;

class ProcessUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $document;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 3600; // 1 hour for large files

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
        \Log::info('ProcessUpload: Starting background processing for document: ' . $this->document->id);

        try {
            // Send begin event
            $message = [
                'ident' => $this->document->job->ident,
                'file' => $this->document->file_name,
                'size' => $this->document->size,
                'status' => 'begin'
            ];
            SpreadsheetUpdate::dispatch((object) $message);

            // Run the parser (heavy processing)
            $dc = new DocumentController();
            $parserResults = $dc->parser($this->document);

            // Mark document as processed
            $this->document->update(['status' => Document::STATUS_STORED_PROCESSED]);

            \Log::info('ProcessUpload: Document processing completed successfully for document: ' . $this->document->id);

        } catch (\Exception $e) {
            \Log::error('ProcessUpload: Failed to process document ' . $this->document->id . ': ' . $e->getMessage());
            \Log::error('ProcessUpload: Stack trace: ' . $e->getTraceAsString());

            // Send error event
            $message = [
                'ident' => $this->document->job->ident,
                'status' => 'error',
                'message' => 'Processing failed: ' . $e->getMessage()
            ];
            SpreadsheetUpdate::dispatch((object) $message);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        \Log::error('ProcessUpload: Job failed permanently for document ' . $this->document->id . ': ' . $exception->getMessage());

        // Send final error notification
        $message = [
            'ident' => $this->document->job->ident,
            'status' => 'failed',
            'message' => 'Upload processing failed. Please contact support.'
        ];
        SpreadsheetUpdate::dispatch((object) $message);
    }
}
