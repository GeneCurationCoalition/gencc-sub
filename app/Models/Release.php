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
     *
     * Slugs are 0-indexed sequential numbers (GCC-00000, GCC-00001, etc.)
     * based on the highest existing slug number, ensuring uniqueness even
     * if records are deleted.
     */
    public static function booted(): void
    {
        // Set a temporary slug to satisfy NOT NULL constraint
        static::creating(function (Model $model) {
            if (empty($model->slug)) {
                $model->slug = 'GCC-TEMP-' . uniqid();
            }
        });

        // Update with the real sequential slug after creation
        // Uses max slug number + 1 to ensure uniqueness
        static::created(function (Model $model) {
            if (str_starts_with($model->slug, 'GCC-TEMP-')) {
                $nextNumber = self::getNextSlugNumber();
                $model->update(['slug' => 'GCC-'
                    . str_pad($nextNumber, 5, '0', STR_PAD_LEFT)]);
            }
        });
    }

    /**
     * Get the next available slug number (max existing + 1, or 0 if none).
     */
    public static function getNextSlugNumber(): int
    {
        $maxSlug = Release::where('slug', 'like', 'GCC-%')
            ->where('slug', 'not like', 'GCC-TEMP-%')
            ->orderByRaw("CAST(SUBSTRING(slug, 5) AS UNSIGNED) DESC")
            ->value('slug');

        if (!$maxSlug) {
            return 0;
        }

        // Extract number from GCC-XXXXX format
        $number = (int) substr($maxSlug, 4);
        return $number + 1;
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
