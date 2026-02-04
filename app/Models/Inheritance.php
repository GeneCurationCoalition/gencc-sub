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
 * The inheritances table stores all the standard method of inheritance definitions
 * for GenCC, including name, description, and curie.  Submissions typically join
 * to this rather than replicating strings in various places.
 *
 * */
class Inheritance extends Model
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
                            'hex_color', 'css_class',
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
    public const TYPE_MOI = 1;


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
     * Get all the submissions associated with this inheritance
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
        self::create(['curie' => 'HP:0000005', 'name' => 'Unknown', 'status' => self::STATUS_ACTIVE,
                      'description' => 'Mode of inheritance HP:0000005', 'abbreviation' => 'Unknown', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0000006', 'name' => 'Autosomal dominant', 'status' => self::STATUS_ACTIVE,
        'description' => 'Autosomal dominant inheritance HP:0000006', 'abbreviation' => 'AD', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0010985', 'name' => 'Gonosomal', 'status' => self::STATUS_ACTIVE,
        'description' => 'Gonosomal inheritance HP:0010985', 'abbreviation' => 'GS', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001426', 'name' => 'Multifactorial', 'status' => self::STATUS_ACTIVE,
        'description' => 'Multifactorial inheritance HP:0001426', 'abbreviation' => 'MF', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0032382', 'name' => 'Uniparental disomy', 'status' => self::STATUS_ACTIVE,
        'description' => 'Uniparental disomy HP:0032382', 'abbreviation' => 'UNP', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001428', 'name' => 'Somatic mutation', 'status' => self::STATUS_ACTIVE,
        'description' => 'Somatic mutation HP:0001428', 'abbreviation' => 'SOM', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0000007', 'name' => 'Autosomal recessive', 'status' => self::STATUS_ACTIVE,
        'description' => 'Autosomal recessive inheritance HP:0000007', 'abbreviation' => 'AR', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001466', 'name' => 'Contiguous gene syndrome', 'status' => self::STATUS_ACTIVE,
        'description' => 'Contiguous gene syndrome HP:0001466', 'abbreviation' => 'CGS', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0003743', 'name' => 'Genetic anticipation', 'status' => self::STATUS_ACTIVE,
        'description' => 'Genetic anticipation HP:0003743', 'abbreviation' => 'GA', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001425', 'name' => 'Heterogeneous', 'status' => self::STATUS_ACTIVE,
        'description' => 'Heterogeneous HP:0001425', 'abbreviation' => 'HET', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001427', 'name' => 'Mitochondrial', 'status' => self::STATUS_ACTIVE,
        'description' => 'Mitochondrial inheritance HP:0001427', 'abbreviation' => 'MIT', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0032113', 'name' => 'Semidominant', 'status' => self::STATUS_ACTIVE,
        'description' => 'Semidominant mode of inheritance HP:0032113', 'abbreviation' => 'SD', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0003745', 'name' => 'Sporadic', 'status' => self::STATUS_ACTIVE,
        'description' => 'Sporadic HP:0003745', 'abbreviation' => 'SPR', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001417', 'name' => 'X-linked', 'status' => self::STATUS_ACTIVE,
        'description' => 'X-linked inheritance HP:0001417', 'abbreviation' => 'XL', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001419', 'name' => 'X-linked recessive', 'status' => self::STATUS_ACTIVE,
        'description' => 'X-linked recessive inheritance HP:0001419', 'abbreviation' => 'XLR', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001423', 'name' => 'X-linked dominant', 'status' => self::STATUS_ACTIVE,
        'description' => 'X-linked dominant inheritance HP:0001423', 'abbreviation' => 'XLD', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001450', 'name' => 'Y-linked inheritance', 'status' => self::STATUS_ACTIVE,
        'description' => 'Y-linked inheritance HP:0001450', 'abbreviation' => 'YLD', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0001442', 'name' => 'Somatic mosaicism', 'status' => self::STATUS_ACTIVE,
        'description' => 'Somatic mosaicism HP:0001442', 'abbreviation' => 'SM', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0012274', 'name' => 'Autosomal dominant inheritance with paternal imprinting',
        'status' => self::STATUS_ACTIVE, 'type' => self::TYPE_MOI,
        'description' => 'Autosomal dominant inheritance with paternal imprinting HP:0012274', 'abbreviation' => 'ADIPI']);

        self::create(['curie' => 'HP:0010984', 'name' => 'Digenic inheritance', 'status' => self::STATUS_ACTIVE,
        'description' => 'Digenic inheritance HP:0010984', 'abbreviation' => 'DI', 'type' => self::TYPE_MOI]);

        self::create(['curie' => 'HP:0012275', 'name' => 'Autosomal dominant inheritance with maternal imprinting',
        'status' => self::STATUS_ACTIVE, 'type' => self::TYPE_MOI,
        'description' => 'Autosomal dominant inheritance with maternal imprinting HP:0012275', 'abbreviation' => 'ADIMI']);
    }

}
