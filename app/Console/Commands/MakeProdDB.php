<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Mechanism;
Use App\Models\Gene;


/**
 * TODO - instructions on the files that need to be exported from
 * elsewhere, their formats and purpose.
 *
 *
 */
class MakeProdDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gencc:makeproddb {sgc-ids=true}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Script to bring the production gencc-sub database to production ready.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $send_sgc_ids = $this->argument('sgc-ids');

        echo("Exporting submissions from gencc-search...\n");
        $this->export_submissions_from_search();

        $this->init_tables();
        echo("Updating genes...\n");
        $this->call('update:genes');
        echo("Updating diseases...\n");
        $this->call('update:diseases');
        echo("Importing submitters...\n");
        $this->call('gencc:import-submitters', ['--upsert' => true]);
        echo("Importing users...\n");
        $this->call('gencc:import-users', ['--upsert' => true]);
        echo("Importing teams...\n");
        $this->call('gencc:import-teams');
        echo("Initializing Classification...\n");
        Classification::initialize();
        echo("Initializing Inheritence...\n");
        Inheritance::initialize();
        echo("Initializing Mechanism...\n");
        Mechanism::initialize();
        echo("Initializing Gene...\n");
        Gene::initialize();
        echo("Importing Submissions...\n");
        $this->call('import:gencc');
        if ($send_sgc_ids) {
            echo("Sending sgc_ids to search...\n");
            $this->call('run:publish', ['arg' => 'sgc_ids']);
        }
        echo("Running metrics...\n");
        $this->call('run:metrics');

        // Only create storage symlink if it doesn't already exist
        $storageLinkPath = public_path('storage');
        if (!file_exists($storageLinkPath)) {
            echo("Creating storage symlink...\n");
            $this->call('storage:link');
        } else {
            echo("Storage symlink already exists, skipping...\n");
        }
    }

    public function init_tables(): void
    {
        $this->clear_storage_files();

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('actions')->truncate();
        DB::table('aliases')->truncate();
        DB::table('classifications')->truncate();
        // diseases table managed by update:diseases command (truncates only if file changed)
        DB::table('documents')->truncate();
        // genes table managed by update:genes command (truncates only if file changed)
        DB::table('inheritances')->truncate();
        DB::table('jobs')->truncate();
        DB::table('mechanisms')->truncate();
        DB::table('metrics')->truncate();
        DB::table('model_has_permissions')->truncate();
        // pubmeds table NOT truncated - preserve existing PMID data to avoid re-fetching from PubMed API
        // DB::table('pubmeds')->truncate();
        DB::table('pubmed_submission')->truncate();
        DB::table('sessions')->truncate();
        // purposefully leaving Settings out for now
        // static_file_headers NOT truncated - used for caching external files
        DB::table('submissions')->truncate();
        DB::table('submitters')->truncate();
        DB::table('teams')->truncate();
        DB::table('team_invitations')->truncate();
        DB::table('team_user')->truncate();
        DB::table('users')->truncate();
        DB::table('workers')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function clear_storage_files(): void
    {
        echo("Clearing orphaned storage files...\n");

        // Clear uploaded spreadsheets
        $spreadsheetsPath = storage_path('app/spreadsheets');
        if (File::isDirectory($spreadsheetsPath)) {
            File::deleteDirectory($spreadsheetsPath);
            File::makeDirectory($spreadsheetsPath, 0755, true);
        }

        // Clear public files (clingen_sync zips, etc.)
        $publicPath = storage_path('app/public');
        if (File::isDirectory($publicPath)) {
            foreach (File::files($publicPath) as $file) {
                File::delete($file);
            }
        }

        // Clear application cache
        $cachePath = storage_path('framework/cache/data');
        if (File::isDirectory($cachePath)) {
            File::deleteDirectory($cachePath);
            File::makeDirectory($cachePath, 0755, true);
        }

        // Clear laravel-excel temp files
        $excelCachePath = storage_path('framework/cache/laravel-excel');
        if (File::isDirectory($excelCachePath)) {
            File::deleteDirectory($excelCachePath);
            File::makeDirectory($excelCachePath, 0755, true);
        }

        // Clear sessions (invalid after users table truncated)
        $sessionsPath = storage_path('framework/sessions');
        if (File::isDirectory($sessionsPath)) {
            foreach (File::files($sessionsPath) as $file) {
                File::delete($file);
            }
        }

        // Clear compiled views
        $viewsPath = storage_path('framework/views');
        if (File::isDirectory($viewsPath)) {
            foreach (File::files($viewsPath) as $file) {
                File::delete($file);
            }
        }

        // Truncate laravel log
        $logPath = storage_path('logs/laravel.log');
        if (File::exists($logPath)) {
            File::put($logPath, '');
        }
    }

    /**
     * Export submissions from gencc-search and save to data folder.
     *
     * Downloads the submissions export via API from gencc-search
     * and saves it as data/gencc-submissions.xlsx.
     *
     * @return void
     */
    public function export_submissions_from_search(): void
    {
        // Build the API URL from the PUBLISH_URL base
        $publishUrl = env('PUBLISH_URL', 'http://127.0.0.1:8000/api/publish');
        $baseUrl = preg_replace('#/api/publish$#', '', $publishUrl);
        $exportUrl = $baseUrl . '/api/export/submissions-with-rowid';

        $targetPath = base_path('data/gencc-submissions.xlsx');

        $this->info("Downloading submissions from: {$exportUrl}");

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->get($exportUrl);

            if (!$response->successful()) {
                $this->error("Failed to download submissions export: HTTP {$response->status()}");
                $this->error($response->body());
                return;
            }

            // Save the response body to file
            File::put($targetPath, $response->body());

            $fileSize = File::size($targetPath);
            $this->info("Successfully downloaded to: data/gencc-submissions.xlsx ({$fileSize} bytes)");

        } catch (\Exception $e) {
            $this->error("Failed to download submissions export: " . $e->getMessage());
        }
    }
}
