<?php

namespace App\Jobs;

use App\Models\AdminLog;
use App\Services\AdminProgressTracker;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RunAdminCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     * Must be >= queue worker's --timeout (3600s) to avoid premature kills.
     */
    public $timeout = 3600; // 1 hour

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    public string $operation;
    public string $command;
    public int $userId;
    public string $summaryType;

    /**
     * Create a new job instance.
     */
    public function __construct(string $operation, string $command, int $userId, string $summaryType = 'default')
    {
        $this->operation = $operation;
        $this->command = $command;
        $this->userId = $userId;
        $this->summaryType = $summaryType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = Carbon::now();

        try {
            Log::info("Admin job: Running {$this->command}", ['user_id' => $this->userId]);

            // Pass user_id to gencc:release so it can be stored on the Release record
            $commandArgs = [];
            if ($this->command === 'gencc:release') {
                $commandArgs = ['--user_id' => $this->userId];
            }

            $exitCode = Artisan::call($this->command, $commandArgs);
            $output = Artisan::output();

            $endTime = Carbon::now();
            $duration = $startTime->diffInSeconds($endTime);

            // Check output size - TEXT column limit is 64KB
            $outputBytes = strlen($output);
            $maxBytes = 60000; // Leave some margin below 64KB

            if ($outputBytes > $maxBytes) {
                // Log full output to file for debugging
                $logFile = storage_path('logs/admin-command-output-' . date('Y-m-d-His') . '.log');
                file_put_contents($logFile, $output);

                Log::warning("Admin command output exceeded {$maxBytes} bytes", [
                    'command' => $this->command,
                    'output_bytes' => $outputBytes,
                    'log_file' => $logFile,
                ]);

                // Truncate for database storage
                $output = substr($output, 0, $maxBytes)
                    . "\n\n--- OUTPUT TRUNCATED ({$outputBytes} bytes total) ---"
                    . "\n--- Full output saved to: {$logFile} ---";
            }

            $summary = $this->generateSummary($output, $exitCode);

            // Log to database
            AdminLog::create([
                'operation' => $this->operation,
                'user_id' => $this->userId,
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output,
                'summary' => $summary,
                'executed_at' => $startTime,
                'duration_seconds' => $duration,
            ]);

            // Update progress tracker with result data
            AdminProgressTracker::complete($this->operation, $summary, [
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $output,
                'summary' => $summary,
                'duration_seconds' => $duration,
            ]);

            Log::info("Admin job: {$this->command} completed", [
                'exit_code' => $exitCode,
                'duration' => $duration,
            ]);

        } catch (\Exception $e) {
            $endTime = Carbon::now();
            $duration = $startTime->diffInSeconds($endTime);

            Log::error("Admin job: {$this->command} failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            AdminLog::create([
                'operation' => $this->operation,
                'user_id' => $this->userId,
                'success' => false,
                'exit_code' => -1,
                'output' => $e->getMessage(),
                'summary' => "**Error:** {$e->getMessage()}",
                'executed_at' => $startTime,
                'duration_seconds' => $duration,
            ]);

            AdminProgressTracker::fail($this->operation, $e->getMessage(), [
                'exit_code' => -1,
                'output' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]);
        }
    }

    /**
     * Handle a job failure (called by queue worker even on fatal errors).
     */
    public function failed(?\Throwable $exception): void
    {
        $message = $exception ? $exception->getMessage() : 'Unknown error';

        Log::error("Admin job failed: {$this->command}", ['error' => $message]);

        // Update progress tracker so frontend isn't stuck on "running"
        AdminProgressTracker::fail($this->operation, $message, [
            'exit_code' => -1,
            'output' => $message,
        ]);

        // Log to database if not already logged by handle()
        $existing = AdminLog::where('operation', $this->operation)
            ->where('user_id', $this->userId)
            ->where('executed_at', '>=', now()->subMinutes(30))
            ->latest('executed_at')
            ->first();

        if (!$existing) {
            AdminLog::create([
                'operation' => $this->operation,
                'user_id' => $this->userId,
                'success' => false,
                'exit_code' => -1,
                'output' => $message,
                'summary' => "**Error:** {$message}",
                'executed_at' => now(),
                'duration_seconds' => 0,
            ]);
        }
    }

    /**
     * Generate summary based on summary type.
     */
    protected function generateSummary(string $output, int $exitCode): string
    {
        return match ($this->summaryType) {
            'publish' => self::publishSummary($output, $exitCode),
            'diseases' => self::diseasesSummary($output, $exitCode),
            'genes' => self::genesSummary($output, $exitCode),
            'pubmed' => self::pubmedSummary($output, $exitCode),

            default => self::defaultSummary($this->command, $output, $exitCode),
        };
    }

    public static function publishSummary(string $output, int $exitCode): string
    {
        $summary = $exitCode === 0 ? "**Release completed successfully**\n\n" : "**Release completed with issues**\n\n";

        if (preg_match('/Found (\d+) submitted jobs/', $output, $matches)) {
            $summary .= "- Jobs processed: {$matches[1]}\n";
        }
        if (preg_match('/Release record created: (.+)/', $output, $matches)) {
            $summary .= "- Release: {$matches[1]}\n";
        }

        return $summary;
    }

    public static function diseasesSummary(string $output, int $exitCode): string
    {
        $summary = $exitCode === 0 ? "**Disease update completed successfully**\n\n" : "**Disease update completed with issues**\n\n";

        if (preg_match('/(\d+) new diseases?/', $output, $matches)) {
            $summary .= "- New diseases: {$matches[1]}\n";
        }
        if (preg_match('/(\d+) updated/', $output, $matches)) {
            $summary .= "- Updated: {$matches[1]}\n";
        }
        if (preg_match('/MONDO.*?(\d+)/', $output, $matches)) {
            $summary .= "- MONDO records: {$matches[1]}\n";
        }

        return $summary;
    }

    public static function genesSummary(string $output, int $exitCode): string
    {
        $summary = $exitCode === 0 ? "**Gene update completed successfully**\n\n" : "**Gene update completed with issues**\n\n";

        if (preg_match('/(\d+) new genes?/', $output, $matches)) {
            $summary .= "- New genes: {$matches[1]}\n";
        }
        if (preg_match('/(\d+) updated/', $output, $matches)) {
            $summary .= "- Updated: {$matches[1]}\n";
        }
        if (preg_match('/Total.*?(\d+)/', $output, $matches)) {
            $summary .= "- Total genes: {$matches[1]}\n";
        }

        return $summary;
    }

    public static function pubmedSummary(string $output, int $exitCode): string
    {
        $summary = $exitCode === 0 ? "**PubMed sync completed successfully**\n\n" : "**PubMed sync completed with issues**\n\n";

        if (preg_match('/Total PMIDs processed: (\d+)/', $output, $matches)) {
            $summary .= "- PMIDs processed: {$matches[1]}\n";
        }
        if (preg_match('/Successfully synced: (\d+)/', $output, $matches)) {
            $summary .= "- Successfully synced: {$matches[1]}\n";
        }
        if (preg_match('/No PMIDs to process/', $output)) {
            $summary .= "- No PMIDs needed processing\n";
        }

        return $summary;
    }

    public static function defaultSummary(string $command, string $output, int $exitCode): string
    {
        $status = $exitCode === 0 ? 'completed successfully' : 'completed with issues';
        $lines = array_filter(explode("\n", trim($output)));

        $summary = "**{$command}** {$status}\n\n";

        if (count($lines) > 0) {
            $lastLines = array_slice($lines, -5);
            $summary .= "```\n" . implode("\n", $lastLines) . "\n```";
        }

        return $summary;
    }
}
