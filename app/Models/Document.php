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
 * The documents table holds information about uploaded documents, including their stored
 * path on the server.  It is primarily used for uploading and storing submissions using the
 * legacy spreadsheet format.
 *  
 * */
class Document extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'processing_errors' => 'array',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type',
                            'user_id', 'submitter_id', 'job_id', 'file_name', 'extension', 'mime_type',
                            'size', 'original_path', 'disk', 'local_path',
                            'status', 'processing_errors',
                            'upload_state', 'processed_submissions', 'total_submissions',
                            'upload_started_at', 'upload_completed_at' ];

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
    public const STATUS_STORED_UNPROCESSED = 1;
    public const STATUS_STORED_PROCESSED = 2;
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
    public const TYPE_EXCEL = 1;

    /**
     * Upload state constants
     */
    public const UPLOAD_STATE_VALIDATING = 'validating';
    public const UPLOAD_STATE_VALIDATION_FAILED = 'validation_failed';
    public const UPLOAD_STATE_VALIDATED = 'validated';
    public const UPLOAD_STATE_UPLOADING = 'uploading';
    public const UPLOAD_STATE_UPLOAD_COMPLETE = 'upload_complete';
    public const UPLOAD_STATE_UPLOAD_PARTIAL = 'upload_partial';



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
     * Get the user who uploaded the document
     * 
     */
    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }


    /**
     * Get the submitter associated with the document
     * 
     */
    public function submitter()
    {
        return $this->belongsTo('App\Models\Submitter');
    }


    /**
     * Get the job associated with the document
     * 
     */
    public function job()
    {
        return $this->belongsTo('App\Models\Job');
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
