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
 * The classifications table stores all the standard classification definitions
 * for GenCC, including name, description, and curie.  Submissions typically join
 * to this rather than replicating strings in various places.
 * 
 * */
class Classification extends Model
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
     * Get all the submissions associated with this classification
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
     * Initialize a new table with the standard values
     * 
     * 
     */
    public static function initialize()
    {
        self::create(['curie' => 'GENCC:100001', 'name' => 'Definitive', 'status' => self::STATUS_ACTIVE, 'order' => 10,
                      'description' => 'Definitive classification', 'abbreviation' => 'DEF', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100002', 'name' => 'Strong', 'status' => self::STATUS_ACTIVE, 'order' => 20,
                      'description' => 'Strong classification', 'abbreviation' => 'STR', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100003', 'name' => 'Moderate', 'status' => self::STATUS_ACTIVE, 'order' => 30,
                      'description' => 'Moderate classification', 'abbreviation' => 'MOD', 'type' => self::TYPE_CLASSIFICATION]);

        // This is done so that the id's generated match the id's in gencc-sub
        self::create(['curie' => 'GENCC:100009', 'name' => 'Supportive', 'status' => self::STATUS_ACTIVE, 'order' => 40,
            'description' => 'Supportive', 'abbreviation' => 'SUP', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100004', 'name' => 'Limited', 'status' => self::STATUS_ACTIVE, 'order' => 50,
                      'description' => 'Limited classification', 'abbreviation' => 'LMT', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100005', 'name' => 'Disputed Evidence', 'status' => self::STATUS_ACTIVE, 'order' => 60,
                      'description' => 'Disputed evidence classification', 'abbreviation' => 'DE', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100006', 'name' => 'Refuted Evidence', 'status' => self::STATUS_ACTIVE, 'order' => 70,
                      'description' => 'Disputed evidence classification', 'abbreviation' => 'RE', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100007', 'name' => 'Animal Model Only', 'status' => self::STATUS_ACTIVE, 'order' => 80,
                      'description' => 'Animal model only classification', 'abbreviation' => 'AMO', 'type' => self::TYPE_CLASSIFICATION]);

        self::create(['curie' => 'GENCC:100008', 'name' => 'No Known Disease Relationship', 'status' => self::STATUS_ACTIVE, 'order' => 90,
                      'description' => 'No known disease relationship classification', 'abbreviation' => 'NKD', 'type' => self::TYPE_CLASSIFICATION]);



    
    }

}
