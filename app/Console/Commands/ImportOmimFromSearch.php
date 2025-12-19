<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Disease;

/**
 * Import OMIM disease records from gencc_search database
 *
 * This command safely imports OMIM diseases from the gencc_search database
 * into the gencc_sub database, avoiding duplicates based on curie (disease ID).
 */
class ImportOmimFromSearch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:omim-from-search {--dry-run : Show what would be imported without actually importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import OMIM disease records from gencc_search database (avoiding duplicates)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('Importing OMIM diseases from gencc_search database...');
        $this->newLine();

        try {
            // Connect to gencc_search database
            $searchDb = DB::connection('gencc_search');

            // Test connection
            $searchDb->getPdo();
            $this->info('✓ Connected to gencc_search database');

        } catch (\Exception $e) {
            $this->error('✗ Failed to connect to gencc_search database');
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Make sure the gencc_search connection is configured in config/database.php');
            return 1;
        }

        // Get OMIM diseases from gencc_search
        $this->info('Fetching OMIM diseases from gencc_search...');

        $searchOmimDiseases = $searchDb->table('diseases')
            ->where('curie', 'LIKE', 'OMIM:%')
            ->get();

        if ($searchOmimDiseases->isEmpty()) {
            $this->warn('No OMIM diseases found in gencc_search database');
            return 0;
        }

        $this->info("Found {$searchOmimDiseases->count()} OMIM diseases in gencc_search");
        $this->newLine();

        // Check for existing OMIM diseases in gencc_sub
        $existingCuries = Disease::where('curie', 'LIKE', 'OMIM:%')
            ->pluck('curie')
            ->toArray();

        $this->info("Found " . count($existingCuries) . " existing OMIM diseases in gencc_sub");
        $this->newLine();

        // Filter out duplicates
        $newDiseases = $searchOmimDiseases->filter(function($disease) use ($existingCuries) {
            return !in_array($disease->curie, $existingCuries);
        });

        $duplicateCount = $searchOmimDiseases->count() - $newDiseases->count();

        $this->info("Analysis:");
        $this->info("  • Total OMIM in gencc_search: {$searchOmimDiseases->count()}");
        $this->info("  • Already in gencc_sub: {$duplicateCount}");
        $this->info("  • New records to import: {$newDiseases->count()}");
        $this->newLine();

        if ($newDiseases->isEmpty()) {
            $this->info('✓ All OMIM diseases already exist in gencc_sub - nothing to import');
            return 0;
        }

        if ($dryRun) {
            $this->info('DRY RUN - Would import the following diseases:');
            $this->newLine();

            $preview = $newDiseases->take(10);
            foreach ($preview as $disease) {
                $name = $disease->title ?? $disease->name ?? 'N/A';
                $this->line("  {$disease->curie} - " . substr($name, 0, 60));
            }

            if ($newDiseases->count() > 10) {
                $this->line("  ... and " . ($newDiseases->count() - 10) . " more");
            }

            $this->newLine();
            $this->info('Run without --dry-run to actually import these records');
            return 0;
        }

        // Confirm before proceeding
        if (!$this->confirm("Import {$newDiseases->count()} OMIM diseases into gencc_sub?", true)) {
            $this->warn('Import cancelled');
            return 0;
        }

        // Import diseases
        $this->info('Importing diseases...');
        $bar = $this->output->createProgressBar($newDiseases->count());
        $bar->start();

        $imported = 0;
        $errors = 0;

        foreach ($newDiseases as $disease) {
            try {
                // Map gencc_search type (string/int) to gencc_sub type (integer constant)
                $type = $disease->type ?? Disease::TYPE_OMIM;

                // If type is a string (from gencc_search), map to integer
                if (is_string($type)) {
                    $type = match(strtoupper($type)) {
                        'OMIM' => Disease::TYPE_OMIM,
                        'OMIM_PLUS', 'PLUS' => Disease::TYPE_OMIM_PLUS,
                        'OMIM_PERCENT', 'PERCENT' => Disease::TYPE_OMIM_PERCENT,
                        'OMIM_CARET', 'CARET' => Disease::TYPE_OMIM_CARET,
                        'OMIM_NUMBER', 'NUMBER', 'NUMBER SIGN' => Disease::TYPE_OMIM_NUMBER,
                        'OMIM_GENE', 'GENE' => Disease::TYPE_OMIM_GENE,
                        default => Disease::TYPE_OMIM
                    };
                }

                // Prepare disease data
                // gencc_search uses 'title' field instead of 'name'
                $data = [
                    'type' => $type,
                    'curie' => $disease->curie,
                    'name' => $disease->title ?? $disease->name ?? '',
                    'description' => $disease->description ?? null,
                    'synonyms' => is_string($disease->synonyms_exact ?? $disease->synonyms ?? null)
                        ? json_decode($disease->synonyms_exact ?? $disease->synonyms, true)
                        : ($disease->synonyms_exact ?? $disease->synonyms ?? []),
                    'xrefs' => is_string($disease->xrefs ?? null)
                        ? json_decode($disease->xrefs, true)
                        : ($disease->xrefs ?? []),
                    'status' => $disease->status ?? Disease::STATUS_ACTIVE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Generate unique ident (UUID)
                $data['ident'] = \Illuminate\Support\Str::uuid()->toString();

                // Insert into gencc_sub
                Disease::create($data);

                $imported++;
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("  Error importing {$disease->curie}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Import complete!');
        $this->newLine();
        $this->info("Results:");
        $this->info("  ✓ Successfully imported: {$imported}");
        if ($errors > 0) {
            $this->warn("  ✗ Failed: {$errors}");
        }

        $this->newLine();

        // Verify
        $totalOmim = Disease::where('curie', 'LIKE', 'OMIM:%')->count();
        $this->info("Total OMIM diseases now in gencc_sub: {$totalOmim}");

        return 0;
    }
}
