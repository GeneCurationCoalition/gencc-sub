<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiseaseAuditRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'source_metadata' => 'array',
        'case_counts' => 'array',
    ];
}
