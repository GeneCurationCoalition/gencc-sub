<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Service for tracking progress of long-running admin operations
 */
class AdminProgressTracker
{
    /**
     * Cache key prefix for admin operation progress
     */
    protected const CACHE_PREFIX = 'admin_progress_';

    /**
     * Cache TTL in seconds (30 minutes - enough for long operations + result availability)
     */
    protected const CACHE_TTL = 1800;

    /**
     * Initialize progress tracking for an operation
     */
    public static function start(string $operation, array $phases = []): void
    {
        $data = [
            'operation' => $operation,
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'current_phase' => null,
            'current_phase_index' => 0,
            'phases' => $phases,
            'phase_progress' => [],
            'messages' => [],
            'error' => null,
        ];

        Cache::put(self::CACHE_PREFIX . $operation, $data, self::CACHE_TTL);
    }

    /**
     * Update progress for a specific phase
     */
    public static function updatePhase(
        string $operation,
        string $phase,
        int $current,
        int $total,
        ?string $message = null
    ): void {
        $data = Cache::get(self::CACHE_PREFIX . $operation);

        if (!$data) {
            return;
        }

        $data['current_phase'] = $phase;
        $data['phase_progress'][$phase] = [
            'current' => $current,
            'total' => $total,
            'percent' => $total > 0 ? round(($current / $total) * 100, 1) : 0,
        ];

        if ($message) {
            $data['messages'][] = [
                'time' => now()->toIso8601String(),
                'phase' => $phase,
                'message' => $message,
            ];
            // Keep only last 20 messages
            if (count($data['messages']) > 20) {
                $data['messages'] = array_slice($data['messages'], -20);
            }
        }

        Cache::put(self::CACHE_PREFIX . $operation, $data, self::CACHE_TTL);
    }

    /**
     * Add a status message
     */
    public static function addMessage(string $operation, string $message, ?string $phase = null): void
    {
        $data = Cache::get(self::CACHE_PREFIX . $operation);

        if (!$data) {
            return;
        }

        $data['messages'][] = [
            'time' => now()->toIso8601String(),
            'phase' => $phase ?? $data['current_phase'],
            'message' => $message,
        ];

        // Keep only last 20 messages
        if (count($data['messages']) > 20) {
            $data['messages'] = array_slice($data['messages'], -20);
        }

        Cache::put(self::CACHE_PREFIX . $operation, $data, self::CACHE_TTL);
    }

    /**
     * Mark a phase as complete
     */
    public static function completePhase(string $operation, string $phase, ?string $message = null): void
    {
        $data = Cache::get(self::CACHE_PREFIX . $operation);

        if (!$data) {
            return;
        }

        $data['phase_progress'][$phase] = [
            'current' => 1,
            'total' => 1,
            'percent' => 100,
            'complete' => true,
        ];

        if ($message) {
            $data['messages'][] = [
                'time' => now()->toIso8601String(),
                'phase' => $phase,
                'message' => $message,
            ];
        }

        // Move to next phase index
        $data['current_phase_index']++;

        Cache::put(self::CACHE_PREFIX . $operation, $data, self::CACHE_TTL);
    }

    /**
     * Mark operation as complete
     */
    public static function complete(string $operation, ?string $summary = null, array $resultData = []): void
    {
        $data = Cache::get(self::CACHE_PREFIX . $operation);

        if (!$data) {
            return;
        }

        $data['status'] = 'complete';
        $data['completed_at'] = now()->toIso8601String();
        $data['summary'] = $summary;
        $data['result'] = $resultData;

        Cache::put(self::CACHE_PREFIX . $operation, $data, self::CACHE_TTL);
    }

    /**
     * Mark operation as failed
     */
    public static function fail(string $operation, string $error, array $resultData = []): void
    {
        $data = Cache::get(self::CACHE_PREFIX . $operation);

        if (!$data) {
            return;
        }

        $data['status'] = 'failed';
        $data['completed_at'] = now()->toIso8601String();
        $data['error'] = $error;
        $data['result'] = $resultData;

        Cache::put(self::CACHE_PREFIX . $operation, $data, self::CACHE_TTL);
    }

    /**
     * Get current progress for an operation
     */
    public static function get(string $operation): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $operation);
    }

    /**
     * Clear progress data for an operation
     */
    public static function clear(string $operation): void
    {
        Cache::forget(self::CACHE_PREFIX . $operation);
    }

    /**
     * Check if an operation is currently running
     */
    public static function isRunning(string $operation): bool
    {
        $data = Cache::get(self::CACHE_PREFIX . $operation);
        return $data && $data['status'] === 'running';
    }
}
