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
 * The mechanisms table holds information about the various mechanisms of disease
 * definitions used in GenCC.  This is a relatively new feature and few submissions
 * join at this time, although the number is expected to rise.
 * 
 * */
class Mechanism extends Model
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
	protected $fillable = [	'ident', 'type', 
                            'curie', 'name', 'description', 'abbreviation', 'informational', 'style_class',
                            'order', 'status' ];

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
    public const TYPE_CLASSIFICATION = 1;


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
     * Get all the submissions associated with this mod
     */
    public function submissions()
    {
       return $this->hasMany('App\Models\Submission');
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
     * Initialize a new table with the standard mod values
     * 
     * 
     */
    public static function initialize()
    {
        self::create(['curie' => 'GENCC:200001', 'name' => 'Gain of Function', 'status' => self::STATUS_ACTIVE,
                      'description' => 'Gain of Function', 'abbreviation' => 'GOF', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:200002', 'name' => 'Loss of Function', 'status' => self::STATUS_ACTIVE,
            'description' => 'Loss of Function', 'abbreviation' => 'LOF', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:200003', 'name' => 'Not Loss of Function', 'status' => self::STATUS_ACTIVE,
                      'description' => 'Not Loss of Function', 'abbreviation' => 'NLF', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:200004', 'name' => 'Dominant Negative', 'status' => self::STATUS_ACTIVE,
                      'description' => 'Dominant Negative', 'abbreviation' => 'DNG', 'type' => self::TYPE_CLASSIFICATION]);

    }
}
