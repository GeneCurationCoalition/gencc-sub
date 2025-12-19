<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Submitter;
use App\Services\YamlImporter;

class ImportUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gencc:import-users
        {file? : Path to YAML file (or set GENCC_USERS_FILE env var)}
        {--upsert : Update existing users instead of skipping}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import users from a YAML file';

    /**
     * Execute the console command.
     */
    public function handle(YamlImporter $yamlImporter): int
    {
        $filePath = $this->argument('file')
            ?? env('GENCC_USERS_FILE')
            ?? 'data/users.yaml';

        $this->info("Importing users from: {$filePath}");

        try {
            $data = $yamlImporter->parseFile($filePath);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (empty($data['users']) || !is_array($data['users'])) {
            $this->error("No users found in YAML file");
            return 1;
        }

        $upsert = $this->option('upsert');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $adminUserCount = 0;

        foreach ($data['users'] as $index => $userData) {
            // For error reporting
            $identifier = $userData['email'] ?? $userData['name'] ?? "entry #{$index}";

            try {
                // Import-specific validation: look up submitter by curie
                if (empty($userData['submitter_curie'])) {
                    throw new \InvalidArgumentException("Missing submitter_curie");
                }
                $submitter = Submitter::where('curie', $userData['submitter_curie'])->first();
                if (!$submitter) {
                    throw new \InvalidArgumentException("Submitter not found: {$userData['submitter_curie']}");
                }

                // Check if user exists (for skip logic and output messaging)
                $existingUser = !empty($userData['email'])
                    ? User::where('email', $userData['email'])->first()
                    : null;

                if ($existingUser && !$upsert) {
                    $this->line("  Skipped: {$userData['name']} ({$userData['email']}) - already exists (add --upsert to update)");
                    $stats['skipped']++;
                    if (!empty($userData['is_admin'])) {
                        $adminUserCount++;
                    }
                    continue;
                }

                // Create or update user - User::createUser handles validation
                User::createUser([
                    'name' => $userData['name'] ?? null,
                    'email' => $userData['email'] ?? null,
                    'password' => $userData['password'] ?? null,
                    'submitter_id' => $submitter->id,
                    'credentials' => $userData['credentials'] ?? null,
                    'phone' => $userData['phone'] ?? null,
                    'upsert' => $upsert,
                ]);

                if ($existingUser) {
                    $this->info("  Updated: {$userData['name']} ({$userData['email']})");
                    $stats['updated']++;
                } else {
                    $this->info("  Created: {$userData['name']} ({$userData['email']})");
                    $stats['created']++;
                }

                if (!empty($userData['is_admin'])) {
                    $adminUserCount++;
                }

            } catch (\Exception $e) {
                $this->error("  Failed: {$identifier} - {$e->getMessage()}");
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

        if ($adminUserCount > 0) {
            $this->line("  Admin users: {$adminUserCount}");
        }

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
