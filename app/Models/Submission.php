<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

use Carbon\Carbon;

use App\Jobs\ProcessPubmed;

use Auth;

/**
 *
 * @category   Model
 * @package    GenCC
 * @author     P. Weller <pweller1@geisinger.edu>
 * @copyright  2024 Geisinger
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version    Release: @package_version@
 * @link       http://pear.php.net/package/PackageName
 * @see        NetOther, Net_Sample::Net_Sample()
 * @since      Class available since Release 1.0.0
 *
 * The submissions table is the primary repository of all submission data for GenCC.  It includes 
 * joins to the submitting user, to the submitting organization, and to various tables for gene, disease, moi,
 * and mod.
 * 
 * */
class Submission extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * When true, bypasses immutability guards on published/unpublished/submitted submissions.
     * Only set this temporarily in trusted system processes (e.g. release pipeline).
     */
    public static bool $bypassImmutability = false;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'submission_data' => 'object',
        'original_submission_data' => 'object',
        'submission_errors' => 'object',
        'evidence' => 'object',
        'normalized_pmids' => 'string',
        'pmid_issues' => 'array',
        'history' => 'array',
        'tags' => 'array',
        'submitted_at' => 'datetime',
        'released_at' => 'datetime',
        'unpublished_at' => 'datetime',
        'version_number' => 'integer',
        'is_most_recent' => 'boolean',
        'is_live' => 'boolean'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type', 'gene_id', 'disease_id', 'original_disease_id', 'inheritance_id', 'friendly',
                            'classification_id', 'submitter_id', 'publish_date', 'report_date', 'report_url',
                            'uuid', 'sid', 'version_number', 'is_most_recent', 'is_live', 'job_id', 'user_id', 'evidence', 'original_submission_data',
                            'submission_data', 'submission_errors', 'normalized_pmids', 'pmid_issues', 'history', 'tags',
                            'status', 'action', 'origin_state', 'local_key', 'last_edited_by',
                            'document_id', 'submitted_at', 'released_at', 'unpublished_at'];

	/**
     * Non-persistent storage model attributes.
     *
     * @var array
     */
    protected $appends = ['last_edited_by_admin', 'display_id', 'is_archived'];

    /**
     * String-based status constants for submission workflow (simplified)
     *
     * Pending statuses (action-based):
     * - new: First submission for this SGC ID (v1)
     * - republish: Update to existing submission (v2+)
     * - unpublish: Hide existing submission (v2+)
     *
     * Released statuses (visibility-based):
     * - published: Visible in gencc-search
     * - unpublished: Hidden from gencc-search
     *
     * Stage (draft/submitted) is derived from Job.status, not stored here.
     */
    public const STATUS_NEW = 'new';
    public const STATUS_REPUBLISH = 'republish';
    public const STATUS_UNPUBLISH = 'unpublish';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';

    /**
     * @deprecated Legacy compound status constants - use simple STATUS_* constants above.
     * These are kept for backwards compatibility during migration.
     */
    public const STATUS_DRAFT_NEW = 'new';
    public const STATUS_SUBMITTED_NEW = 'new';
    public const STATUS_DRAFT_REPUBLISH = 'republish';
    public const STATUS_SUBMITTED_REPUBLISH = 'republish';
    public const STATUS_DRAFT_UNPUBLISH = 'unpublish';
    public const STATUS_SUBMITTED_UNPUBLISH = 'unpublish';

    /**
     * Action constants for audit trail
     * These represent the original intent of the submission
     */
    public const ACTION_NEW = 'new';
    public const ACTION_REPUBLISH = 'republish';
    public const ACTION_UNPUBLISH = 'unpublish';

    /**
     * @deprecated Legacy integer status constants - only kept for migration commands.
     * Use string STATUS_* constants above. Will be removed after all migrations complete.
     */
    public const LEGACY_STATUS_INITIALIZING = 0;
    public const LEGACY_STATUS_NEW = 1;
    public const LEGACY_STATUS_PROCESSING = 3;
    public const LEGACY_STATUS_ERRORS = 4;
    public const LEGACY_STATUS_REMOVED = 9;
    public const LEGACY_STATUS_PUBLISHED = 20;

    /*
     * Status strings for display methods (simplified status)
     * For pending statuses, stage is derived from Job.status
     *
     * @var array
     * */
    protected $status_strings = [
        // Pending statuses (stage derived from Job)
        'new' => 'New',
        'republish' => 'Republish',
        'unpublish' => 'Unpublish',
        // Released statuses
        'published' => 'Published',
        'unpublished' => 'Unpublished',
        // Legacy compound statuses (backwards compatibility)
        'draft_new' => 'Draft (New)',
        'submitted_new' => 'Submitted (New)',
        'draft_republish' => 'Draft (Republish)',
        'submitted_republish' => 'Submitted (Republish)',
        'draft_unpublish' => 'Draft (Unpublish)',
        'submitted_unpublish' => 'Submitted (Unpublish)'
    ];

    /**
     * Pending status values (action-based)
     */
    protected static $pendingStatuses = [
        self::STATUS_NEW,
        self::STATUS_REPUBLISH,
        self::STATUS_UNPUBLISH,
    ];

    /**
     * Released status values (visibility-based)
     */
    protected static $releasedStatuses = [
        self::STATUS_PUBLISHED,
        self::STATUS_UNPUBLISHED,
    ];

    public const TYPE_NONE = 0;
    public const TYPE_API_SUBMISSION = 1;
    public const TYPE_FILE_SUBMISSION = 2;
    public const TYPE_PORTAL_SUBMISSION = 3;
    public const TYPE_OMIM_SUBMISSION = 4;
    public const TYPE_GENCC_IMPORT = 7;


    /**
     * A map of json to model fields, so we can maintain
     * fexibility in the json schema without having to make
     * model changes
     *
     */

    /**
     * Maintain a temporary errors bag for the model so that custom bulk load methods
     * can keep track of problems
     */
    protected $errors_bag = [];


    /**
     * Generate a new unique SID using the sgc_sequences table.
     * This ensures sequential SIDs even when versioned submissions share the same SID.
     *
     * @return string The new SID in format SGC-1XXXXX
     */
    public static function generateNewSid(): string
    {
        $sequenceId = \DB::table('sgc_sequences')->insertGetId(['created_at' => now()]);
        return 'SGC-1' . str_pad($sequenceId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Auto-generate SID for new submissions using the sequence table.
     * Only generates a new SID if one is not already set (versioned submissions
     * inherit their SID from the original submission).
     */
    public static function booted(): void
    {
        static::created(function (Model $model) {
            if ($model->sid === null || $model->sid == '') {
                // Generate a new SID using the sequence table
                $newSid = self::generateNewSid();

                // Update sid without triggering timestamp updates to preserve historical dates during imports
                // Use a direct DB update to avoid any issues with model save() overwriting other attributes
                // (e.g., document_id was being lost when $model->save() was called here)
                \DB::table('submissions')
                    ->where('id', $model->id)
                    ->update(['sid' => $newSid]);

                // Update the model in memory to reflect the change
                $model->sid = $newSid;
                $model->syncOriginal(); // Reset dirty tracking to match DB state
            }
        });

        // Combined updating guard: immutability check + auto-set released_at
        static::updating(function (Submission $submission) {
            // --- Immutability guard ---
            // Prevent modifications to published, unpublished, or submitted submissions.
            // Only whitelisted fields (status transitions, release metadata) are allowed through.
            if (!static::$bypassImmutability) {
                $immutableStatuses = [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED];
                $isImmutable = in_array($submission->getOriginal('status'), $immutableStatuses);

                // Also check if submission is in a submitted job
                if (!$isImmutable && $submission->job) {
                    $isImmutable = $submission->job->status === Job::STATUS_SUBMITTED;
                }

                if ($isImmutable) {
                    // Fields that the release process and system are allowed to change
                    $allowedFields = [
                        'status', 'released_at', 'unpublished_at',
                        'is_most_recent', 'is_live',
                        'original_submission_data',
                        'job_id',              // failed publish moves submission to draft job
                        'submission_errors',   // publish error recording
                        'sid',                 // set by created event via raw DB update
                    ];

                    $dirty = array_keys($submission->getDirty());
                    $disallowed = array_diff($dirty, $allowedFields);

                    if (!empty($disallowed)) {
                        throw new \RuntimeException(
                            "Cannot modify immutable submission {$submission->sid} (status: {$submission->getOriginal('status')}). " .
                            "Disallowed fields: " . implode(', ', $disallowed)
                        );
                    }
                }
            }

            // --- Auto-set released_at ---
            // Set released_at when submission status changes to PUBLISHED (for non-import workflows)
            // Skip if we're updating released_at (backfill scenario)
            if ($submission->isDirty('released_at')) {
                return;
            }

            if ($submission->isDirty('status') &&
                $submission->status == self::STATUS_PUBLISHED &&
                $submission->released_at === null) {
                $submission->released_at = Carbon::now();
            }
        });
    }

	/**
     * Automatically assign an ident on instantiation
     *
     * @param	array	$attributes
     * @return 	void
     */
    public function __construct(array $attributes = array())
    {
        $this->attributes['ident'] = Str::uuid()->toString();
        parent::__construct($attributes);
    }


    /**
     * Get the user associated with this submission
     */
    public function user()
    {
       return $this->belongsTo('App\Models\User');
    }


    /**
     * Get the user who last edited this submission
     */
    public function lastEditedByUser()
    {
       return $this->belongsTo('App\Models\User', 'last_edited_by');
    }


    /**
     * Get the job associated with this submission
     */
    public function job()
    {
       return $this->belongsTo('App\Models\Job');
    }




    /**
     * Get the gene associated with this submission
     */
    public function gene()
    {
       return $this->belongsTo('App\Models\Gene');
    }


    /**
     * Get the disease associated with this submission (normalized to MONDO)
     */
    public function disease()
    {
       return $this->belongsTo('App\Models\Disease');
    }


    /**
     * Get the original disease that was uploaded/entered (could be MONDO, OMIM, or Orphanet)
     */
    public function originalDisease()
    {
       return $this->belongsTo('App\Models\Disease', 'original_disease_id');
    }


    /**
     * Get the classification associated with this submission
     */
    public function classification()
    {
       return $this->belongsTo('App\Models\Classification');
    }


    /**
     * Get the gene moi with this submission
     */
    public function inheritance()
    {
       return $this->belongsTo('App\Models\Inheritance');
    }


    /**
     * Get the mechanism of disease with this submission
     */
    public function mechanism()
    {
       return $this->belongsTo('App\Models\Mechanism');
    }


    /**
     * Get the submitter associated with this submission
     */
    public function submitter()
    {
       return $this->belongsTo('App\Models\Submitter');
    }


    /**
     * Query all pubmed articles referenced by this submission
     * 
     */
    public function pubmeds()
    {
        return $this->belongsToMany('App\Models\Pubmed');
    }


    /**
     * Query scope by ident
     *
     * @param	string	$ident
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeIdent($query, $ident)
    {
		return $query->where('ident', $ident);
    }


    /**
     * Query scope by status of published
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopePublished($query)
    {
		return $query->where('status', self::STATUS_PUBLISHED);
    }


    /**
     * Query scope by sid
     *
     * @param	string	$sid
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeSid($query, $sid)
    {
		return $query->where('sid', $sid);
    }


    /**
     * Query scope by errors
     *
     * @param
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeErrors($query)
    {
		return $query->whereNotNull('submission_errors');
    }


    /**
     * Query scope for draft states
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeDraftStates($query)
    {
		return $query->whereIn('status', [
            self::STATUS_DRAFT_NEW,
            self::STATUS_DRAFT_REPUBLISH,
            self::STATUS_DRAFT_UNPUBLISH
        ]);
    }


    /**
     * Query scope for submitted states
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeSubmittedStates($query)
    {
		return $query->whereIn('status', [
            self::STATUS_SUBMITTED_NEW,
            self::STATUS_SUBMITTED_REPUBLISH,
            self::STATUS_SUBMITTED_UNPUBLISH
        ]);
    }


    /**
     * Query scope for unpublished status
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeUnpublished($query)
    {
		return $query->where('status', self::STATUS_UNPUBLISHED);
    }


    /**
     * Query scope for live submissions (publicly accessible)
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeLive($query)
    {
		return $query->where('is_live', true);
    }


    /**
     * Query scope for archived submissions (released but superseded by newer release)
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeArchived($query)
    {
		return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED])
                     ->where('is_live', false);
    }


    /**
     * Query scope by specific status
     *
     * @param string $status
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeByStatus($query, $status)
    {
		return $query->where('status', $status);
    }


    /**
     * Query scope for listing/export views
     *
     * Includes all fields and relationships needed for the submissions listing
     * and CSV export functionality. Use this scope to ensure consistency between
     * JobController and SubmissionController queries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
	public function scopeForListing($query)
    {
		return $query->select('id', 'gene_id', 'user_id', 'disease_id', 'original_disease_id', 'inheritance_id',
                              'classification_id', 'submitter_id', 'job_id', 'ident', 'sid',
                              'version_number', 'is_most_recent', 'is_live', // Required for display_id accessor and historical/archived version styling
                              'local_key', 'friendly', 'created_at', 'submitted_at', 'released_at', 'unpublished_at', 'publish_date',
                              // Include submission_data for display of "Submitted as" labels
                              // Removed: 'original_submission_data', 'evidence' - too large for listing
                              'submission_data', 'submission_errors', 'status', 'origin_state')
                     ->with('gene:id,hgnc_id,symbol')
                     ->with('disease:id,curie,name,deprecated_name,status')
                     ->with('originalDisease:id,curie,name,deprecated_name,status')
                     ->with('inheritance:id,curie,name')
                     ->with('classification:id,curie,name')
                     ->with('submitter:id,curie,name')
                     ->with('job:id,ident,slug,status');
    }


    /**
     * Get a display formatted form of submission status
     * For pending submissions, includes stage derived from Job.status
     *
     * @return string
     */
    public function getDisplayStatusAttribute()
    {
        // For pending statuses, prefix with stage from Job
        if ($this->isPending() && $this->job) {
            $stage = $this->job->status === 'submitted' ? 'Submitted' : 'Draft';
            $statusLabel = $this->status_strings[$this->status] ?? ucfirst($this->status);
            return "{$stage} ({$statusLabel})";
        }

        return $this->status_strings[$this->status] ?? 'Unknown';
    }

    /**
     * Check if this submission is in a pending state (not yet released)
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return in_array($this->status, self::$pendingStatuses);
    }

    /**
     * Check if this submission is in a released state
     *
     * @return bool
     */
    public function isReleased(): bool
    {
        return in_array($this->status, self::$releasedStatuses);
    }

    /**
     * Get the stage of this submission (derived from Job.status)
     *
     * @return string|null
     */
    public function getStageAttribute(): ?string
    {
        return $this->job?->status;
    }

    /**
     * Get a display formatted form of submission status for JSON
     *
     * @return string
     */
    public function getJsonDisplayStatusAttribute()
    {
       return $this->json_status_strings[$this->status] ?? 'Unknown';
    }


    /**
     * Check if the last editor is a member of the admin team
     *
     * @return bool
     */
    public function getLastEditedByAdminAttribute()
    {
        if (!$this->last_edited_by || !$this->relationLoaded('lastEditedByUser')) {
            return false;
        }

        $lastEditor = $this->lastEditedByUser;
        if (!$lastEditor || !$lastEditor->relationLoaded('teams')) {
            return false;
        }

        return $lastEditor->teams()
            ->where('teams.name', 'admin')
            ->where('teams.personal_team', false)
            ->exists();
    }

    /**
     * Check if the submission has validation errors.
     * This replaces the legacy STATUS_ERRORS check.
     *
     * @return bool
     */
    public function getHasErrorsAttribute(): bool
    {
        if ($this->submission_errors === null) {
            return false;
        }

        // submission_errors is cast as object, check if it has any properties
        return count((array) $this->submission_errors) > 0;
    }

    /**
     * Get the display ID (SGC ID with version number).
     * Format: SGC-XXXXXX.N (e.g., SGC-100001.2)
     *
     * @return string
     */
    public function getDisplayIdAttribute(): string
    {
        return $this->sid . '.' . ($this->version_number ?? 1);
    }


    /**
     * Check if this submission is archived (superseded by a newer released version).
     *
     * A submission is archived if:
     * - It has been released (published or unpublished status)
     * - It is not currently live (is_live = false)
     *
     * Note: Archived is different from "historical" - a published submission
     * with a pending draft_republish is NOT archived because it's still the
     * publicly accessible version.
     *
     * @return bool
     */
    public function isArchived(): bool
    {
        // Only released submissions can be archived
        if (!in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED])) {
            return false;
        }

        return !$this->is_live;
    }


    /**
     * Check if this submission is currently live (publicly accessible).
     *
     * @return bool
     */
    public function isLive(): bool
    {
        return (bool) $this->is_live;
    }


    /**
     * Accessor for is_archived to allow use in Blade templates and Vue components
     *
     * @return bool
     */
    public function getIsArchivedAttribute(): bool
    {
        return $this->isArchived();
    }

    /**
     * Scope to get all versions of a specific SGC ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $sid The SGC ID (sid)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAllVersions($query, string $sid)
    {
        return $query->where('sid', '=', $sid)->orderBy('version_number', 'desc');
    }

    /**
     * Scope to get only the current (latest published) version of submissions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCurrentVersion($query)
    {
        return $query->where('status', '=', self::STATUS_PUBLISHED);
    }

    /**
     * Scope to query submissions with errors.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithErrors($query)
    {
        return $query->whereNotNull('submission_errors')
            ->whereRaw("JSON_LENGTH(submission_errors) > 0");
    }

    /**
     * Scope to query submissions without errors.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutErrors($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('submission_errors')
              ->orWhereRaw("JSON_LENGTH(submission_errors) = 0");
        });
    }

    /**
     * Add an event to the history
     *
     * @param string $event
     * @return void
     */
	public function addEvent($event)
    {
        if ($this->history === null)
            $this->history = [];
        
        $history = $this->history;
		$history[] = ['user_id' => Auth::user()->id, 'date' => Carbon::now(), 'event' => $event];
        $this->history = $history;
    }


    /**
     * Load the model from a json object.  This is used when processing api submissions.  Since
     * a job may contain multiple submissions, each submission creates/modifies a record and 
     * calls this method to update.
     * 
     * @params object $obj
     * @return boolean if no errors, array errorsbag if errors
     */
    public function load_from_json($obj, $lookupCaches = null)
    {
        // clear the errors
        $this->errors_bag = [];

        /**
         * Assert the gene lookup by HGNC ID.  If invalid, add to the errors_bag
         */
        if (isset($lookupCaches['genes']) && isset($obj->gene->id)) {
            // Use cache lookup for performance
            $gene = $lookupCaches['genes']->get($obj->gene->id);
        } else {
            // Fallback to database query
            $gene = isset($obj->gene->id) ? Gene::hgnc_id($obj->gene->id)->first() : null;
        }
        $this->gene_id = $this->asserterrors($gene->id ?? null, 'gene_hgnc_id', 'Invalid HGNC ID');
        if ($this->gene_id === null)
            $this->gene_id = $lookupCaches['defaults']['gene_id'] ?? Gene::symbol('-')->first()->id;

        /**
         * Assert the disease lookup by ID.  If invalid, add to the errors_bag
         * Logic:
         * 1. Find the exact disease record for the uploaded CURIE (original_disease_id)
         * 2. Find the MONDO mapping for normalization (disease_id)
         * 3. If MONDO uploaded: both fields point to same record
         * 4. If OMIM/Orphanet uploaded: original_disease_id = OMIM/Orphanet, disease_id = mapped MONDO
         * 5. If no MONDO mapping exists, validation error
         */
        $uploadedDiseaseId = $obj->disease->id ?? null;
        $originalDisease = null;
        $mondoDisease = null;

        if ($uploadedDiseaseId) {
            // Step 1: Find the exact disease record that was uploaded
            if (isset($lookupCaches['diseases'])) {
                $originalDisease = $lookupCaches['diseases']->get($uploadedDiseaseId);
            } else {
                // Direct lookup by curie for exact match
                $originalDisease = Disease::curie($uploadedDiseaseId)->first();
            }

            // Step 2: Find the MONDO mapping (normalized disease)
            if (isset($lookupCaches['mondo_mappings'])) {
                // Use MONDO mapping cache
                $mondoDisease = $lookupCaches['mondo_mappings']->get($uploadedDiseaseId);
            } else {
                // Fallback: Use rosetta method which handles MONDO normalization
                $mondoDisease = Disease::rosetta($uploadedDiseaseId);
            }
        }

        // Set original_disease_id (the exact disease record for uploaded CURIE)
        $this->original_disease_id = $this->asserterrors($originalDisease->id ?? null, 'disease_curie_id', 'Invalid Disease ID');
        if ($this->original_disease_id === null)
            $this->original_disease_id = $lookupCaches['defaults']['disease_id'] ?? Disease::curie('MONDO:0000001')->first()->id;

        // Set disease_id (normalized to MONDO)
        $this->disease_id = $this->asserterrors($mondoDisease->id ?? null, 'disease_curie_id', 'Invalid Disease ID - no MONDO mapping found');
        if ($this->disease_id === null)
            $this->disease_id = $lookupCaches['defaults']['disease_id'] ?? Disease::curie('MONDO:0000001')->first()->id;

        // Note: Deprecated diseases are allowed in submissions
        // The UI shows a warning symbol (⚠) to indicate deprecated status

        /**
         * Assert the inheritance lookup by ID.  If invalid, add to the errors_bag
         */
        if (isset($lookupCaches['moi']) && isset($obj->moi->id)) {
            // Use cache lookup for performance
            $moi = $lookupCaches['moi']->get($obj->moi->id);
        } else {
            // Fallback to database query
            $moi = isset($obj->moi->id) ? Inheritance::curie($obj->moi->id)->first() : null;
        }
        $this->inheritance_id = $this->asserterrors($moi->id ?? null, 'moi_curie_id', 'Invalid MOI ID');
        if ($this->inheritance_id === null)
            $this->inheritance_id = $lookupCaches['defaults']['moi_id'] ?? Inheritance::curie('HP:0000005')->first()->id;

        /**
         * Assert the classification lookup by ID.  If invalid, add to the errors_bag
         */
        if (isset($lookupCaches['classifications']) && isset($obj->classification->id)) {
            // Use cache lookup for performance
            $classification = $lookupCaches['classifications']->get($obj->classification->id);
        } else {
            // Fallback to database query
            $classification = isset($obj->classification->id) ? Classification::curie($obj->classification->id)->first() : null;
        }
        $this->classification_id = $this->asserterrors($classification->id ?? null, 'classification_curie_id', 'Invalid Classification ID');
        // classification_id can remain null if invalid - file validation prevents invalid data from being imported

        /**
         * Assert the mechanism lookup by ID.  If invalid, add to the errors_bag
         */
        if (isset($lookupCaches['mechanisms']) && !empty($obj->mechanism->id)) {
            // Use cache lookup for performance
            $mechanism = $lookupCaches['mechanisms']->get($obj->mechanism->id);
        } else {
            // Fallback to database query
            $mechanism = !empty($obj->mechanism->id) ? Mechanism::curie($obj->mechanism->id)->first() : null;
        }
        //$this->mechanism_id = $this->asserterrors($mechanism->id ?? null, 'mechanism_of_disease', 'Invalid Mechanism ID');

        $this->type = self::TYPE_API_SUBMISSION;

        $this->local_key = $obj->submission_label ?? null;
        $this->friendly = $obj->submission_label ?? null;

        // TODO:  Need SOP /assertion criteria 
        
        /**
         * Assert the report date is present.  If not, add to the errors_bag
         */
        $this->report_date = $this->asserterrors($obj->report->display_date ?? null, 'report_date', 'Missing Report Date');
        if ($this->report_date !== null)
        {
            // we let carbon try to parse the date, and if it can't we catch the exception and clean things up
            try {
                $this->report_date = Carbon::parse($this->report_date);
            } catch (Exception $e) {
                $this->report_date = null;
                $this->asserterrors(null, 'report_date', 'Invalid Report Date');
            }
        }
        
        /**
         * Assert the report url if present.  If not, add to the errors_bag
         */
        //$this->report_url = $this->asserterrors($obj->report->ext_url ?? null, 'report_url', 'Missing Report URL');
        $this->report_url = $obj->report->ext_url ?? null;
        if (!empty($this->report_url))
        {
            // we confirm that this is a valid URL, at least in format
            if(!filter_var($this->report_url, FILTER_VALIDATE_URL))
            {
                $this->report_url = null;
                $this->asserterrors(null, 'report_url', 'Invalid Report URL');
            }
        }

        /**
         * We save the entire submission packet, unmodified, for future use
         */
        $this->original_submission_data = $obj;

        /** 
         * We also save a copy which can be edited by the user
         */
        $this->submission_data = $obj;


        /**
         * Process any PMIDs
         */
        if (isset($this->submission_data->evidence))
        {
            // IMPORTANT: Clear existing evidence array to fully replace PMIDs
            // This ensures that republish/update operations don't append to old PMIDs
            $this->evidence = [];

            // Collect all raw PMID values from the evidence array
            $rawPmids = [];
            foreach ($this->submission_data->evidence as $evidence) {
                if (!empty($evidence->pmid)) {
                    $rawPmids[] = $evidence->pmid;
                }
            }

            // Normalize all PMIDs at once
            $rawString = implode(',', $rawPmids);
            $normResult = \App\Services\PmidNormalizer::normalize($rawString);

            // Store normalization results
            $this->normalized_pmids = !empty($normResult['pmids']) ? implode(',', $normResult['pmids']) : null;
            $this->pmid_issues = !empty($normResult['issues']) ? $normResult['issues'] : null;

            // Only flag as error if there were issues that resulted in lost PMIDs
            // (not just formatting cleanup like [PMID] suffix removal)
            if (!empty($normResult['issues']) && empty($normResult['pmids']) && !empty($rawPmids)) {
                $this->asserterrors(null, 'invalid_pmid', 'No valid PMIDs found after normalization');
            }

            // Build the evidence array and submission_data from normalized PMIDs
            $normalizedEvidence = [];
            foreach ($normResult['pmids'] as $pmid) {
                // Check if pmid exists in cache first, then database
                $pmid_record = null;
                if (isset($lookupCaches['pubmeds'])) {
                    $pmid_record = $lookupCaches['pubmeds']->get($pmid);
                    if (!$pmid_record) {
                        $pmid_record = Pubmed::firstOrCreate(
                            ['pmid' => $pmid, 'uid' => $pmid],
                            ['status' => Pubmed::STATUS_INITIALIZING]
                        );
                        $lookupCaches['pubmeds']->put($pmid, $pmid_record);
                    }
                } else {
                    $pmid_record = Pubmed::firstOrCreate(
                        ['pmid' => $pmid, 'uid' => $pmid],
                        ['status' => Pubmed::STATUS_INITIALIZING]
                    );
                }

                $ev = $this->evidence;
                $ev[] = $pmid_record->pmid;
                $this->evidence = $ev;

                $normalizedEvidence[] = (object)['pmid' => $pmid];
            }

            // Update submission_data->evidence with normalized values
            $this->submission_data->evidence = $normalizedEvidence;

            // note: we leave the parent to make changes to the pivot table
        }

        // Note: Workflow state is managed by status via the SubmissionStateMachine.
        // Error state is determined by submission_errors via has_errors accessor.

        if (!empty($this->errors_bag))
            return $this->errors_bag;

        return true;
    }


    /**
     * Assert that the passed element is a non-zero, or non-zero equivalent.
     * If not, add the errormsg to the errors_bag.
     * 
     * @params string $element
     * @params string $errortype
     * @params string $errormsg
     * @rerurn string 
     */
    protected function asserterrors($element, $errortype, $errormsg)
    {
        if (empty($element))
            $this->errors_bag[$errortype] = $errormsg;

        return $element;
    }

    /**
     * Initialize the error bag for a blank submission
     * 
     */
    public function initialize_submission_errors()
    {
        $this->submission_errors = [
            "gene_hgnc_id" => 'Missing or Invalid HGNC ID',
            "disease_curie_id" => 'Missing or Invalid Disease ID',
            "moi_curie_id" => 'Missing or Invalid MOI ID',
            "classification_curie_id" => 'Missing or Invalid Classification ID',
           // "mech_of_disease" => 'Missing or Invalid Mechanism ID',
            "criteria_url" => 'Missing or Invalid Criteria URL',
           // "report_url" => 'Missing or Invalid Report URL',
            "report_date" => 'Missing or Invalid Evaluated Date',
        ];
    }


    /**
     * Normalize a JSON cast field to an associative array for safe manipulation.
     * Laravel's 'object' cast can return stdClass, but setting values as arrays works.
     * This helper ensures consistent array access regardless of how the data was loaded.
     *
     * @param mixed $data The cast field value (stdClass or array)
     * @return array Normalized associative array
     */
    public static function normalizeJsonField($data): array
    {
        if ($data === null) {
            return [];
        }
        return json_decode(json_encode($data), true);
    }

    /**
     * Initialize the submission_data structure for a blank submission
     * Uses empty placeholders when foreign keys are null
     */
    public function initialize_submission_data()
    {
        $this->submission_data = [
                    "moi" => ["id" => "", "name" => ""],
                    "submission_id" => $this->sid,
                    "local_key" => $this->local_key,
                    "submission_label" => $this->friendly,
                    "gene" => ["id" => "", "symbol" => ""],
                    "type" => "Reserved",
                    "notes" => ["display" => "", "private" => ""],
                    "report" => ["ext_url" => "", "display_date" => ""],
                    "disease" => ["id" => "", "name" => ""],
                    "version" => ["display" => "1.0", "reasons" => ["NEW_CURATION"], "internal" => "1.0.0.0", "description" => ""],
                    "criteria" => ["url" => "", "name" => ""],
                    "evidence" => [["pmid" => ""]],
                    //"lumpsplit" => [["key" => "value"]],
                    "contributors"=> ["primary" => ["id" => "", "name" => ""]],
                    "classification" => ["id" => "", "name" => ""],
                    "mechanism" => ["id" => "", "name" => "", "comment" => ""],
                    "additional_information" => [["key" => "values"]]
                ];
    }

    /**
     * Validate that submission state is consistent with job state
     * Rules:
     * - Pending submissions (new/republish/unpublish) must be in active (draft/submitted) jobs
     * - Released submissions (published/unpublished) must be in released jobs
     *
     * Note: Stage (draft vs submitted) is derived from Job.status, not stored in submission.
     *
     * @return bool
     * @throws \Exception if inconsistent
     */
    public function validateJobStateConsistency(): bool
    {
        // Skip validation if no job assigned yet
        if (!$this->job_id || !$this->job) {
            return true;
        }

        // Skip validation if job doesn't have status yet
        if (!$this->status || !$this->job->status) {
            return true;
        }

        $submissionState = $this->status;
        $jobState = $this->job->status;

        // Pending submissions must be in active (draft or submitted) jobs
        if ($this->isPending()) {
            if (!in_array($jobState, [\App\Models\Job::STATUS_DRAFT, \App\Models\Job::STATUS_SUBMITTED])) {
                throw new \Exception("Pending submission {$this->sid} (state: {$submissionState}) cannot be in released job {$this->job->slug} (state: {$jobState})");
            }
        }

        // Released submissions (published/unpublished) must be in released jobs
        if ($this->isReleased()) {
            if ($jobState !== \App\Models\Job::STATUS_RELEASED) {
                throw new \Exception("Released submission {$this->sid} (state: {$submissionState}) cannot be in non-released job {$this->job->slug} (state: {$jobState})");
            }
        }

        return true;
    }
}
