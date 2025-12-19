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
 * The submitters table holds information on the susmitter organizations that are approved for
 * submissions to GenCC.  Most of the submission related tables link to this table, as does the users
 * table.  It is useful to think or the submitter as the team, and the users as team members.
 * 
 * */
class Submitter extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'counts' => 'object',
        'activity' => 'object',
        'contacts' => 'object'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type', 
                            'curie', 'name', 'description', 'logo',
                            'website', 'assertion',
                            'counts', 'contacts', 'activity', 'notes',
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
    public const TYPE_SUBMITTER = 1;
    public const TYPE_SUBMITTER_TEST = 99;


	/**
     * Automatically assign an ident and default values on instantiation
     *
     * @param	array	$attributes
     * @return 	void
     */
    public function __construct(array $attributes = array())
    {
        $this->attributes['ident'] = Str::uuid()->toString();
        $this->attributes['counts'] = '[]';
        $this->attributes['activity'] = '[]';
        $this->attributes['contacts'] = '[]';
        $this->attributes['notes'] = '';
        parent::__construct($attributes);
    }


    /**
     * Get all the users associated with this submitter
     */
    public function users()
    {
       return $this->hasMany('App\Models\User');
    }


    /**
     * Get all the jobs associated with this submitter
     */
    public function jobs()
    {
       return $this->hasMany('App\Models\Job');
    }


    /**
     * Get all the submissions associated with this submitter
     */
    public function submissions()
    {
        return $this->hasMany('App\Models\Submission');
    }


    /**
     * Get all the documents associated with this submitter
     */
    public function documents()
    {
       return $this->hasMany('App\Models\Document');
    }


    /**
     * Get all the aliases associated with this submitter
     */
    public function aliases()
    {
       return $this->hasMany('App\Models\Alias');
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
     * Query scope by curie
     *
     * @param	string	$curie
     * @return Illuminate\Database\Eloquent\Collection
     */
	public function scopeCurie($query, $curie)
    {
		return $query->where('curie', $curie);
    }


    /**
     * Factory method to create or update a submitter with standard settings.
     *
     * @param array $data Submitter data with keys:
     *   - curie (required): Unique GENCC identifier (e.g., GENCC:000100)
     *   - name (required): Organization name
     *   - description (optional): Organization description
     *   - logo (optional): Path to logo image
     *   - website (optional): Organization website URL
     *   - assertion (optional): Assertion methodology URL
     *   - contacts (optional): Contact information (array or string)
     *   - type (optional): Submitter type (default: TYPE_SUBMITTER)
     *   - status (optional): Submitter status (default: STATUS_ACTIVE)
     *   - upsert (optional): If true, update existing submitter if found by curie
     * @return Submitter The created or updated submitter
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function createSubmitter(array $data): Submitter
    {
        // Validate required fields
        $required = ['curie', 'name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $upsert = $data['upsert'] ?? false;

        // Check for existing submitter
        $existingSubmitter = self::where('curie', $data['curie'])->first();
        if ($existingSubmitter && !$upsert) {
            throw new \InvalidArgumentException("Submitter with curie '{$data['curie']}' already exists");
        }

        // Prepare submitter attributes
        $attributes = [
            'curie' => $data['curie'],
            'name' => $data['name'],
            'status' => $data['status'] ?? self::STATUS_ACTIVE,
            'type' => $data['type'] ?? self::TYPE_SUBMITTER,
        ];

        // Optional fields
        $optionalFields = ['description', 'logo', 'website', 'assertion', 'contacts', 'notes'];
        foreach ($optionalFields as $field) {
            if (isset($data[$field])) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($existingSubmitter) {
            // Update existing submitter (upsert mode)
            $existingSubmitter->update($attributes);
            return $existingSubmitter->fresh();
        }

        // For new submitters, set counts if provided (constructor sets empty defaults)
        if (isset($data['counts'])) {
            $attributes['counts'] = $data['counts'];
        }
        if (isset($data['activity'])) {
            $attributes['activity'] = $data['activity'];
        }

        return self::create($attributes);
    }

}
