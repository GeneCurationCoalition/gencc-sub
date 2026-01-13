<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sgc_sequences', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent();
        });

        // Backfill with existing SID sequence numbers
        // Get the max sequence number from existing SIDs (format: SGC-1XXXXX)
        $maxSeq = DB::table('submissions')
            ->selectRaw('MAX(CAST(SUBSTRING(sid, 6) AS UNSIGNED)) as max_seq')
            ->whereNotNull('sid')
            ->where('sid', 'LIKE', 'SGC-1%')
            ->first()
            ->max_seq ?? 0;

        if ($maxSeq > 0) {
            // Insert placeholder rows to bring the sequence up to the current max
            // This ensures the next auto-increment ID will be max + 1
            $batchSize = 1000;
            $inserted = 0;

            while ($inserted < $maxSeq) {
                $batch = [];
                $batchEnd = min($inserted + $batchSize, $maxSeq);

                for ($i = $inserted + 1; $i <= $batchEnd; $i++) {
                    $batch[] = ['created_at' => now()];
                }

                DB::table('sgc_sequences')->insert($batch);
                $inserted = $batchEnd;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sgc_sequences');
    }
};
