<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Team;
use App\Services\YamlImporter;

class ImportTeams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gencc:import-teams
        {file? : Path to YAML file (or set GENCC_TEAMS_FILE env var)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import teams from a YAML file';

    /**
     * Execute the console command.
     */
    public function handle(YamlImporter $yamlImporter): int
    {
        $filePath = $this->argument('file')
            ?? env('GENCC_TEAMS_FILE')
            ?? 'data/teams.yaml';

        $this->info("Importing teams from: {$filePath}");

        try {
            $data = $yamlImporter->parseFile($filePath);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (empty($data['teams']) || !is_array($data['teams'])) {
            $this->error("No teams found in YAML file");
            return 1;
        }

        $stats = ['created' => 0, 'updated' => 0, 'members_added' => 0, 'failed' => 0];

        foreach ($data['teams'] as $index => $teamData) {
            $name = $teamData['name'] ?? "Team #{$index}";

            try {
                // Validate required fields
                if (empty($teamData['name'])) {
                    throw new \InvalidArgumentException("Missing team name");
                }

                // Find owner by email (if specified)
                $owner = null;
                if (!empty($teamData['owner_email'])) {
                    $owner = User::where('email', $teamData['owner_email'])->first();
                    if (!$owner) {
                        $this->warn("  Warning: Owner not found for {$name}: {$teamData['owner_email']}");
                    }
                }

                // If no owner specified, use the first member
                if (!$owner && !empty($teamData['members'])) {
                    $firstMemberEmail = $teamData['members'][0];
                    $owner = User::where('email', $firstMemberEmail)->first();
                }

                if (!$owner) {
                    throw new \InvalidArgumentException("No valid owner found for team");
                }

                // Find or create the team (non-personal teams only)
                $team = Team::where('name', $teamData['name'])
                    ->where('personal_team', false)
                    ->first();

                if ($team) {
                    // Update owner if different
                    if ($team->user_id !== $owner->id) {
                        $team->user_id = $owner->id;
                        $team->save();
                        $this->info("  Updated owner for team: {$name}");
                    }
                    $stats['updated']++;
                } else {
                    // Create new team
                    $team = new Team(['name' => $teamData['name']]);
                    $team->user_id = $owner->id;
                    $team->personal_team = false;
                    $team->save();
                    $this->info("  Created team: {$name}");
                    $stats['created']++;
                }

                // Add members to team
                if (!empty($teamData['members']) && is_array($teamData['members'])) {
                    foreach ($teamData['members'] as $memberEmail) {
                        $member = User::where('email', $memberEmail)->first();
                        if (!$member) {
                            $this->warn("    Member not found: {$memberEmail}");
                            continue;
                        }

                        // Check if already a member
                        if (!$member->teams()->where('teams.id', $team->id)->exists()) {
                            $member->teams()->attach($team->id);
                            $this->line("    Added member: {$member->name} ({$memberEmail})");
                            $stats['members_added']++;
                        }
                    }
                }

            } catch (\Exception $e) {
                $this->error("  Failed: {$name} - {$e->getMessage()}");
                $stats['failed']++;
            }
        }

        // Summary
        $this->newLine();
        $this->info("Import complete:");
        $this->line("  Created: {$stats['created']}");
        $this->line("  Updated: {$stats['updated']}");
        $this->line("  Members added: {$stats['members_added']}");
        $this->line("  Failed:  {$stats['failed']}");

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
