<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZipArchive;

class ClinGenSyncSubmissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clingen:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run ClinGen pipeline and create zip file of Excel outputs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting ClinGen GCI Sync process...');

        // Path to the pipeline script (v2.0 - uses modular Python package)
        $scriptPath = base_path('scripts/run_clingen_pipeline.py');

        // Fall back to archived scripts if new one doesn't exist
        if (!file_exists($scriptPath)) {
            $scriptPath = base_path('scripts/archived_clingen_scripts/run_clingen_pipeline.py');
        }
        if (!file_exists($scriptPath)) {
            $scriptPath = base_path('scripts/archived_clingen_scripts/run_clingen_full_process.py');
        }

        if (!file_exists($scriptPath)) {
            $this->error("Pipeline script not found: {$scriptPath}");
            return 1;
        }

        // Run the pipeline script
        $this->info('Running ClinGen pipeline...');
        $this->info("Script: {$scriptPath}");
        $output = [];
        $returnCode = 0;

        exec("python3 {$scriptPath} 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error('Pipeline script failed with return code: ' . $returnCode);
            $this->line(implode("\n", $output));
            return 1;
        }

        $this->info('Pipeline completed successfully!');

        // Create zip file
        $this->info('Creating zip file...');

        $comparisonDir = base_path('data/clingen/comparison');
        $zipFileName = 'clingen_sync_' . date('Y-m-d_His') . '.zip';
        $zipPath = storage_path('app/public/' . $zipFileName);

        // Ensure the public storage directory exists
        if (!file_exists(storage_path('app/public'))) {
            mkdir(storage_path('app/public'), 0755, true);
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Failed to create zip file');
            return 1;
        }

        // Add the three Excel files
        $files = [
            'all_current_submissions.xlsx',
            'changed_submissions.xlsx',
            'deleted_submissions.xlsx'
        ];

        $filesAdded = 0;
        foreach ($files as $file) {
            $filePath = $comparisonDir . '/' . $file;
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $file);
                $filesAdded++;
                $this->info("  Added: {$file}");
            } else {
                $this->warn("  File not found: {$file}");
            }
        }

        $zip->close();

        if ($filesAdded === 0) {
            $this->error('No files were added to the zip archive');
            unlink($zipPath);
            return 1;
        }

        $this->info("Zip file created: {$zipPath}");
        $this->info("Files included: {$filesAdded}");

        // Output the zip filename for the controller to use
        $this->line($zipFileName);

        return 0;
    }
}
