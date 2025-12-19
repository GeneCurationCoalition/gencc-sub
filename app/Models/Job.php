<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

use Carbon\Carbon;

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
 * The jobs table is the primary structure for grouping one or more
 * submissions together.  By definition, every submission must be associated
 * with a job.  
 * 
 * */
class Job extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'submission_date' => 'datetime',
        'submission_data' => 'object',
        'activity' => 'object',
        'published_at' => 'datetime',
        'processed_submission_ids' => 'array',
        'is_publishing' => 'boolean'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type', 'user_id', 'activity', 'activity->errors',
                            'submitter_id', 'submission_date', 'submission_data',
                            'slug', 'friendly',
                            'status', 'is_publishing', 'is_processing' ];

	/**
     * Non-persistent storage model attributes.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * String-based status constants
     * The status reflects where in the workflow a particular job is.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PROCESSED = 'processed';

    /**
     * @deprecated Legacy integer status constants - kept for migration commands only
     */
    public const LEGACY_STATUS_INITIALIZING = 0;
    public const LEGACY_STATUS_QUEUED = 1;
    public const LEGACY_STATUS_PROCESSING = 2;
    public const LEGACY_STATUS_COMPLETE = 3;
    public const LEGACY_STATUS_ERRORS = 4;
    public const LEGACY_STATUS_STAGED = 5;
    public const LEGACY_STATUS_REMOVED = 9;
    public const LEGACY_STATUS_FAILED = 99;

    /*
     * Status strings for display methods
     *
     * @var array
     * */
    protected $status_strings = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'processed' => 'Processed'
    ];

    /**
     * @deprecated Legacy status strings - kept for reference only
     */
    protected $legacy_status_strings = [
        0 => 'Initializing',
        1 => 'Newly Submitted',
        2 => 'Processing',
        3 => 'Completed',
        4 => 'Processing w/ errors',
        5 => 'Staged for Publication',
        9 => 'Removed',
        99 => 'Failed'
    ];

    public const TYPE_NONE = 0;
    public const TYPE_API_SUBMISSION = 1;
    public const TYPE_FILE_SUBMISSION = 2;
    public const TYPE_GENCC_IMPORT = 4;


    /**
     * To guarantee uniqueness of generated job ids (slugs), we base them off the
     * tables id field.  This means a quick update after a record is newly
     * created.  Since the record is still cached, the update is immediate.
     *
     */
    public static function booted(): void
    {
        static::created(fn (Model $model) =>
            $model->update(['slug' => 'J-1'
                    . str_pad($model->id ,5, '0', STR_PAD_LEFT)])
        );

        // Set published_at when job status changes to PROCESSED
        // Only set if not already set (to preserve historical dates during imports)
        static::updating(function (Model $model) {
            if ($model->isDirty('status') && $model->status === self::STATUS_PROCESSED && $model->published_at === null) {
                $model->published_at = Carbon::now();
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
     * Get the submissions associated with this job
     */
    public function submissions()
    {
       return $this->hasMany('App\Models\Submission');
    }


    /**
     * Get the user associated with this job
     */
    public function user()
    {
       return $this->belongsTo('App\Models\User');
    }


    /**
     * Get the submitter associated with this job
     */
    public function submitter()
    {
       return $this->belongsTo('App\Models\Submitter');
    }


    /**
     * Get all the documents associated with this job
     */
    public function documents()
    {
       return $this->hasMany('App\Models\Document');
    }


    /**
     * Get all the actions associated with this job
     */
    public function actions()
    {
       return $this->hasMany('App\Models\Action');
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
     * Query scope for draft status
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeDraft($query)
    {
		return $query->where('status', self::STATUS_DRAFT);
    }


    /**
     * Query scope for submitted status
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeSubmitted($query)
    {
		return $query->where('status', self::STATUS_SUBMITTED);
    }


    /**
     * Query scope for processed status
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeProcessed($query)
    {
		return $query->where('status', self::STATUS_PROCESSED);
    }


    /**
     * Query scope for active (non-processed) jobs
     *
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeActive($query)
    {
		return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SUBMITTED]);
    }

    /**
     * Get a display formatted form of job status
     *
     * @return string
     */
    public function getDisplayStatusAttribute()
    {
       return $this->status_strings[$this->status] ?? 'Unknown';
    }




    /**
     * Check if job has any submissions with errors
     *
     * @return bool
     */
    public function getHasErrorsAttribute(): bool
    {
        return $this->submissions()->withErrors()->exists();
    }


    /**
     * Update the activity log.  Note this does not save after update.
     *
     * @params string
     * @return void
     */
    public function addEvent($str)
    {
        $activity = $this->activity;

        array_push($activity, $str);

        $this->activity = $activity;
    }
}
