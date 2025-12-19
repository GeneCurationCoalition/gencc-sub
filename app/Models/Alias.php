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
 * @license    
 * @version    Release: @package_version@
 * @link       
 * @see        
 * @since      Class available since Release 1.0.0
 * 
 * The Aliases table allows the user to save key -> value mappings, facilitating
 * the population of criteria specification and change reason codes.
 *
 * */
class Alias extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	  protected $fillable = [	'ident', 'type', 'subtype',
                          'submitter_id', 'user_id', 'key', 'value', 'shared',
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
    public const TYPE_CRITERIA = 1;


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
     * Get the submitter who owns this alias
     */
    public function submitter()
    {
       return $this->belongsTo('App\Models\Submitter');
    }


    /**
     * Get the user who owns this alias
     */
    public function user()
    {
       return $this->belongsTo('App\Models\User');
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
     * Query scope by type
     *
     * @param	string	$curie
     * @return Illuminate\Database\Eloquent\Collection
     */
	  public function scopeType($query, $type)
    {
		  return $query->where('type', $type);
    }


    /**
     * Query scope by key
     *
     * @param	string	$curie
     * @return Illuminate\Database\Eloquent\Collection
     */
	  public function scopeKey($query, $key)
    {
		  return $query->where('key', $key);
    }


    /**
     * Initialize a new table with the standard values
     * 
     * 
     */
    public static function initialize()
    {
      // universal reason codes
      $codes = ["NEW_CURATION", "RECURATION_NEW_EVIDENCE", "RECURATION_COMMUNITY_REQUEST", "RECURATION_ERR-R_SCORE_CLASS", "RECURATION_TIMING",
                "RECURATION_DISCREP_RESOLUTION", "REFCURATION_FRAMEWORK"];

      foreach ($codes as $code)
      {
        // 
      }
    
    }

}
