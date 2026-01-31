<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Submitter;
use Illuminate\Support\Facades\Http;

class ImportSubmitterLogos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:submitter-logos
                            {--base-url=https://search.thegencc.org/brand/submitters : Base URL to download logos from}
                            {--dry-run : Show what would be imported without making changes}
                            {--force : Re-import logos even if already stored in database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import submitter logos from gencc-search website into database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $baseUrl = rtrim($this->option('base-url'), '/');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info("Importing submitter logos from: {$baseUrl}");

        if ($dryRun) {
            $this->warn("DRY RUN - No changes will be made");
        }

        // Get all submitters with a CURIE
        $query = Submitter::whereNotNull('curie')
            ->where('curie', '!=', '');

        // Skip those already imported unless --force
        if (!$force) {
            $query->whereNull('logo_contents');
        }

        $submitters = $query->get();

        if ($submitters->isEmpty()) {
            $this->info("No submitters found to import logos for.");
            return 0;
        }

        $this->info("Found {$submitters->count()} submitter(s) to check for logos.");

        $imported = 0;
        $failed = 0;
        $notFound = 0;

        foreach ($submitters as $submitter) {
            // Build URL from CURIE: GENCC:000101 -> GENCC_000101.png
            $curieFilename = str_replace(':', '_', $submitter->curie);
            $logoUrl = "{$baseUrl}/{$curieFilename}.png";

            $this->line("  [{$submitter->curie}] {$submitter->name}");
            $this->line("    URL: {$logoUrl}");

            if ($dryRun) {
                $this->line("    -> Would attempt import");
                $imported++;
                continue;
            }

            try {
                // Download the logo
                $response = Http::timeout(30)->get($logoUrl);

                if ($response->status() === 404) {
                    $this->warn("    -> Not found (no logo available)");
                    $notFound++;
                    continue;
                }

                if (!$response->successful()) {
                    $this->error("    -> Failed: HTTP {$response->status()}");
                    $failed++;
                    continue;
                }

                $contents = $response->body();
                $mimeType = $response->header('Content-Type');

                // Validate it's an image
                if (!str_starts_with($mimeType, 'image/')) {
                    $this->error("    -> Failed: Not an image (Content-Type: {$mimeType})");
                    $failed++;
                    continue;
                }

                // Store as base64 in database
                $submitter->logo_contents = base64_encode($contents);
                $submitter->logo_mime_type = $mimeType;
                $submitter->save();

                $size = strlen($contents);
                $this->info("    -> Imported ({$mimeType}, " . number_format($size) . " bytes)");
                $imported++;

            } catch (\Exception $e) {
                $this->error("    -> Failed: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Import complete: {$imported} imported, {$notFound} not found, {$failed} failed");

        return $failed > 0 ? 1 : 0;
    }
}
