<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Submitter;
use App\Services\YamlImporter;

class ImportSubmitters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gencc:import-submitters
        {file? : Path to YAML file (or set GENCC_SUBMITTERS_FILE env var)}
        {--upsert : Update existing submitters instead of skipping}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import submitters from a YAML file';

    /**
     * Execute the console command.
     */
    public function handle(YamlImporter $yamlImporter): int
    {
        $filePath = $this->argument('file')
            ?? env('GENCC_SUBMITTERS_FILE')
            ?? 'data/submitters.yaml';

        $this->info("Importing submitters from: {$filePath}");

        try {
            $data = $yamlImporter->parseFile($filePath);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (empty($data['submitters']) || !is_array($data['submitters'])) {
            $this->error("No submitters found in YAML file");
            return 1;
        }

        $upsert = $this->option('upsert');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($data['submitters'] as $index => $submitterData) {
            $name = $submitterData['name'] ?? "Submitter #{$index}";
            $curie = $submitterData['curie'] ?? 'unknown';

            try {
                // Validate required fields
                if (empty($submitterData['curie'])) {
                    throw new \InvalidArgumentException("Missing curie");
                }
                if (empty($submitterData['name'])) {
                    throw new \InvalidArgumentException("Missing name");
                }

                // Check if submitter exists
                $existingSubmitter = Submitter::where('curie', $submitterData['curie'])->first();

                if ($existingSubmitter && !$upsert) {
                    $this->line("  Skipped: {$name} ({$curie}) - already exists (add --upsert to update)");
                    $stats['skipped']++;
                    continue;
                }

                // Create or update submitter
                Submitter::createSubmitter([
                    'curie' => $submitterData['curie'],
                    'name' => $submitterData['name'],
                    'description' => $submitterData['description'] ?? null,
                    'logo' => $submitterData['logo'] ?? null,
                    'website' => $submitterData['website'] ?? null,
                    'assertion' => $submitterData['assertion'] ?? null,
                    'contacts' => $submitterData['contacts'] ?? null,
                    'type' => $submitterData['type'] ?? Submitter::TYPE_SUBMITTER,
                    'status' => $submitterData['status'] ?? Submitter::STATUS_ACTIVE,
                    'upsert' => $upsert,
                ]);

                if ($existingSubmitter) {
                    $this->info("  Updated: {$name} ({$curie})");
                    $stats['updated']++;
                } else {
                    $this->info("  Created: {$name} ({$curie})");
                    $stats['created']++;
                }

            } catch (\Exception $e) {
                $this->error("  Failed: {$name} ({$curie}) - {$e->getMessage()}");
                $stats['failed']++;
            }
        }

        // Summary
        $this->newLine();
        $this->info("Import complete:");
        $this->line("  Created: {$stats['created']}");
        $this->line("  Updated: {$stats['updated']}");
        $this->line("  Skipped: {$stats['skipped']}");
        $this->line("  Failed:  {$stats['failed']}");

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
