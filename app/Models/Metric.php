<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
 * 
 * The metrics table contains daily entries for various measured metrics within the system.
 * Some of the metrics are used on the portal dashboard, and some collected for future reporting.
 * 
 * */
class Metric extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'jobs_queued' => 'array',
        'jobs_processing' => 'array',
        'jobs_errors' => 'array',
        'jobs_window' => 'array',
        'jobs_complete' => 'array',
        'jobs_removed' => 'array',
        'submissions_queued' => 'array',
        'submissions_processing' => 'array',
        'submissions_errors' => 'array',
        'submissions_window' => 'array',
        'submissions_published' => 'array',
        'submissions_removed' => 'array',
        'classifications_definitive' => 'array',
        'classifications_strong' => 'array',
        'classifications_moderate' => 'array',
        'classifications_supportive' => 'array',
        'classifications_limited' => 'array', 
        'classifications_disputed' => 'array',
        'classifications_refuted' => 'array',
        'classifications_animal' => 'array',
        'classifications_nodisease' => 'array', 
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type', 
                            'jobs_queued', 'jobs_processing', 'jobs_errors', 'jobs_window', 'jobs_complete', 'jobs_removed',
                            'submissions_queued', 'submissions_processing', 'submissions_errors', 'submissions_window', 'submissions_published', 'submissions_removed',
                            'classifications_definitive', 'classifications_strong', 'classifications_moderate', 'classifications_supportive', 'classifications_limited', 
                            'classifications_disputed', 'classifications_refuted', 'classifications_animal', 'classifications_nodisease', 
                            'status' ];

	/**
     * Non-persistent storage model attributes.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * Enumerted constants for status
     */
    public const STATUS_INITIALIZING = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_REMOVED = 9;

    /*
     * Status strings for display methods
     *
     * @var array
     * */
     protected $status_strings = [
	 		0 => 'Initializing',
	 		1 => 'Active',
	 		9 => 'Deleted',
	];

    /**
     * Enumerted constants for type
     */
    public const TYPE_UNKNOWN = 0;
    public const TYPE_GENERAL = 1;


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
     * Query scope by ident
     *
     * @param	string	$ident
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeIdent($query, $ident)
    {
		return $query->where('ident', $ident);
    }

}
