<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Team;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip this migration during testing to avoid polluting test database
        if (app()->environment('testing')) {
            return;
        }

        // Import submitters, users, and teams from YAML files
        $this->call('gencc:import-submitters', ['--upsert' => true]);
        $this->call('gencc:import-users', ['--upsert' => true]);
        $this->call('gencc:import-teams');

        // Find the admin team
        $adminTeam = Team::where('name', 'admin')->where('personal_team', false)->first();

        if (!$adminTeam) {
            echo "Admin team not found, skipping admin team setup.\n";
            return;
        }

        // Find pweller and lbabb
        $pweller = User::where('email', 'pweller1@geisinger.edu')->first();
        $lbabb = User::where('email', 'lbabb@broadinstitute.org')->first();

        if (!$pweller || !$lbabb) {
            echo "Could not find pweller or lbabb users.\n";
            return;
        }

        // Update pweller's name to "Phil Weller"
        if ($pweller->name !== 'Phil Weller') {
            $pweller->name = 'Phil Weller';
            $pweller->first_name = 'Phil';
            $pweller->last_name = 'Weller';
            $pweller->save();
            echo "Updated Phil Weller's name.\n";
        }

        // Delete duplicate "GenCC Administrators" team if it exists
        $genccAdminTeam = Team::where('name', 'GenCC Administrators')->where('personal_team', false)->first();
        if ($genccAdminTeam) {
            // Remove all user associations first
            $genccAdminTeam->users()->detach();
            $genccAdminTeam->delete();
            echo "Deleted duplicate 'GenCC Administrators' team.\n";
        }

        // Ensure admin team ownership: make lbabb the owner
        if ($adminTeam->user_id !== $lbabb->id) {
            $adminTeam->user_id = $lbabb->id;
            $adminTeam->save();
            echo "Set Larry Babb as owner of admin team.\n";
        }

        // Ensure pweller is a member of admin team
        if (!$pweller->teams()->where('teams.id', $adminTeam->id)->exists()) {
            $pweller->teams()->attach($adminTeam->id);
            echo "Added Phil Weller to admin team.\n";
        }

        // Ensure lbabb is a member of admin team
        if (!$lbabb->teams()->where('teams.id', $adminTeam->id)->exists()) {
            $lbabb->teams()->attach($adminTeam->id);
            echo "Added Larry Babb to admin team.\n";
        }

        // Ensure other admin users are members
        $kferrite = User::where('email', 'kferrite@broadinstitute.org')->first();
        $toneill = User::where('email', 'toneill@broadinstitute.org')->first();

        if ($kferrite && !$kferrite->teams()->where('teams.id', $adminTeam->id)->exists()) {
            $kferrite->teams()->attach($adminTeam->id);
            echo "Added Kyle Ferriter to admin team.\n";
        }

        if ($toneill && !$toneill->teams()->where('teams.id', $adminTeam->id)->exists()) {
            $toneill->teams()->attach($adminTeam->id);
            echo "Added Terry O'Neill to admin team.\n";
        }

        // Update Phil Weller's personal team name specifically
        $pwellerPersonalTeam = $pweller->ownedTeams()->where('personal_team', true)->first();
        if ($pwellerPersonalTeam && $pwellerPersonalTeam->name !== "Phil Weller's Team") {
            $pwellerPersonalTeam->name = "Phil Weller's Team";
            $pwellerPersonalTeam->save();
            echo "Updated Phil Weller's personal team name.\n";
        }

        // Rename all other personal teams to "[First Name]'s Team" format
        $personalTeams = Team::where('personal_team', true)->with('owner')->get();

        foreach ($personalTeams as $team) {
            if (!$team->owner) {
                continue;
            }

            // Skip Phil Weller's team as we already handled it above
            if ($team->owner->id === $pweller->id) {
                continue;
            }

            // Use first name + "'s Team" for other users
            $firstName = explode(' ', $team->owner->name, 2)[0];
            $newName = $firstName . "'s Team";

            if ($team->name !== $newName) {
                $team->name = $newName;
                $team->save();
                echo "Updated {$team->owner->name}'s personal team name to {$newName}.\n";
            }
        }

        // Ensure all members of admin team are associated with GenCC Administrator submitter
        $genccSubmitter = \App\Models\Submitter::where('name', 'GenCC Administrator')
            ->orWhere('curie', 'GENCC:000100')
            ->first();

        if ($genccSubmitter) {
            $adminMembers = User::whereHas('teams', function($query) use ($adminTeam) {
                $query->where('teams.id', $adminTeam->id);
            })->get();

            foreach ($adminMembers as $member) {
                if ($member->submitter_id !== $genccSubmitter->id) {
                    $member->submitter_id = $genccSubmitter->id;
                    $member->save();
                    echo "Updated {$member->name}'s submitter_id to {$genccSubmitter->id}.\n";
                }
            }
        } else {
            echo "Warning: GenCC Administrator submitter not found. Skipping submitter_id assignment.\n";
        }

        // Set current_team_id to each user's personal team
        $allUsers = User::with('ownedTeams')->get();
        foreach ($allUsers as $user) {
            $personalTeam = $user->ownedTeams()->where('personal_team', true)->first();
            if ($personalTeam && $user->current_team_id !== $personalTeam->id) {
                $user->current_team_id = $personalTeam->id;
                $user->save();
                echo "Set {$user->name}'s current_team_id to their personal team (ID: {$personalTeam->id}).\n";
            }
        }

        echo "Admin team setup completed successfully.\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is not reversible
        echo "This migration cannot be reversed.\n";
    }

    /**
     * Helper method to call artisan commands
     */
    protected function call($command, $parameters = [])
    {
        \Illuminate\Support\Facades\Artisan::call($command, $parameters);
    }
};
