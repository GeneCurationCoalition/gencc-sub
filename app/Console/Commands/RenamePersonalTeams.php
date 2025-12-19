<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use App\Models\User;

class RenamePersonalTeams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:rename-personal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rename all personal teams to "[User Name] Team" format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Renaming personal teams...');

        $personalTeams = Team::where('personal_team', true)->with('owner')->get();

        $renamed = 0;
        $skipped = 0;

        foreach ($personalTeams as $team) {
            if (!$team->owner) {
                $this->warn("Team ID {$team->id} ({$team->name}) has no owner - skipping");
                $skipped++;
                continue;
            }

            $newName = $team->owner->name . ' Team';

            if ($team->name === $newName) {
                $this->line("Team ID {$team->id} already has correct name: {$team->name}");
                $skipped++;
                continue;
            }

            $oldName = $team->name;
            $team->name = $newName;
            $team->save();

            $this->info("Renamed Team ID {$team->id}: '{$oldName}' → '{$newName}' (Owner: {$team->owner->email})");
            $renamed++;
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Renamed: {$renamed} teams");
        $this->info("  Skipped: {$skipped} teams");
        $this->info("  Total:   " . ($renamed + $skipped) . " personal teams");

        return Command::SUCCESS;
    }
}
