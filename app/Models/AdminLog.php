<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'operation',
        'user_id',
        'success',
        'exit_code',
        'output',
        'summary',
        'executed_at',
        'duration_seconds',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'success' => 'boolean',
        'executed_at' => 'datetime',
    ];

    /**
     * Operation constants
     */
    public const OP_RUN_PUBLISH = 'run_publish';
    public const OP_SYNC_PUBMED = 'sync_pubmed';
    public const OP_UPDATE_DISEASES = 'update_diseases';
    public const OP_UPDATE_GENES = 'update_genes';

    /**
     * Get the user who executed this operation.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the latest log for a specific operation.
     */
    public static function latestFor(string $operation)
    {
        return static::where('operation', $operation)
            ->orderBy('executed_at', 'desc')
            ->first();
    }

    /**
     * Get the latest logs for all operations.
     */
    public static function latestForAll()
    {
        $operations = [
            self::OP_RUN_PUBLISH,
            self::OP_SYNC_PUBMED,
            self::OP_UPDATE_DISEASES,
            self::OP_UPDATE_GENES,
        ];

        $latest = [];
        foreach ($operations as $op) {
            $log = static::latestFor($op);
            if ($log) {
                $latest[$op] = [
                    'id' => $log->id,
                    'success' => $log->success,
                    'executed_at' => $log->executed_at->toIso8601String(),
                    'executed_at_human' => $log->executed_at->diffForHumans(),
                    'summary' => $log->summary,
                    'duration_seconds' => $log->duration_seconds,
                    'user_name' => $log->user->name ?? 'Unknown',
                ];
            }
        }

        return $latest;
    }
}
