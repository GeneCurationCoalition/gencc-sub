<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Release extends Model
{
    protected $fillable = [
        'slug',
        'released_at',
        'release_notes_file',
        'submissions_csv_file',
        'user_id',
        'new_count',
        'republish_count',
        'unpublish_count',
        'failed_count',
        'total_count',
        'jobs_processed',
        'errors',
        'by_submitter',
        'cumulative_stats',
        'duration_seconds',
    ];

    protected $casts = [
        'released_at' => 'datetime',
        'jobs_processed' => 'array',
        'errors' => 'array',
        'by_submitter' => 'array',
        'cumulative_stats' => 'array',
    ];

    /**
     * Auto-generate slug in GCC-XXXXX format.
     */
    public static function booted(): void
    {
        // Set a temporary slug to satisfy NOT NULL constraint
        static::creating(function (Model $model) {
            if (empty($model->slug)) {
                $model->slug = 'GCC-TEMP-' . uniqid();
            }
        });

        // Update with the real ID-based slug after creation
        static::created(fn (Model $model) =>
            $model->update(['slug' => 'GCC-'
                    . str_pad($model->id, 5, '0', STR_PAD_LEFT)])
        );
    }

    /**
     * Get the user who triggered this release.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Extract job slugs from jobs_processed JSON.
     */
    public function jobSlugs(): array
    {
        if (!$this->jobs_processed) {
            return [];
        }

        return array_map(fn ($job) => $job['slug'] ?? '', $this->jobs_processed);
    }
}
