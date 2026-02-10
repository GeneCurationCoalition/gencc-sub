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
 * The actions table holds commands to process on the GenCC database that cannot
 * be interpreted by submission status entries.
 * 
 * */
class Action extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'command' => 'object'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type', 'local_key',
                            'command', 'user_id', 'submitter_id', 'job_id', 'submission_id',
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
    public const STATUS_PENDING = 1;
    public const STATUS_COMPLETE = 2;
    public const STATUS_REMOVED = 9;

    /*
     * Status strings for display methods
     *
     * @var array
     * */
     protected $status_strings = [
	 		0 => 'Initializing',
	 		1 => 'Pending',
            2 => 'Complete',
	 		9 => 'Deleted',
	];

    /**
     * Enumerted constants for type
     */
    public const TYPE_UNKNOWN = 0;
    public const TYPE_UNPUBLISH = 1;


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
     * Get the user associated with this action
     */
    public function user()
    {
       return $this->belongsTo('App\Models\User');
    }


    /**
     * Get the submitter associated with this action
     */
    public function submitter()
    {
       return $this->belongsTo('App\Models\Submitter');
    }


    /**
     * Get the job associated with this action
     */
    public function job()
    {
       return $this->belongsTo('App\Models\Job');
    }


    /**
     * Get the submission associated with this action
     */
    public function submission()
    {
       return $this->belongsTo('App\Models\Submission');
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
     * Query scope by status
     *
     * @param	string	$status
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeStatus($query, $status)
    {
		return $query->where('status', $status);
    }

}

