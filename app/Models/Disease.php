<?php

namespace App\Models;

use App\Services\DiseaseResolver;
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
 * The diseases table holds all information about diseases for the portal, including
 * name, curie, cross-referencing, and status.  Submissions typically join to this table for
 * the curated disease.
 * 
 * */
class Disease extends Model
{
    use HasFactory;
    use SoftDeletes;


    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'synonyms' => 'object',
        'scores'=> 'object',
        'xrefs' => 'object',
        'counts' => 'object',
        'activity' => 'object',
        'events' => 'object'
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
	protected $fillable = [	'ident', 'type', 'mondo_id',
                            'curie', 'name', 'deprecated_name', 'description', 'synonyms', 'events',
                            'scores', 'xrefs', 'counts', 'activity', 'notes',
                            'status' ];

	/**
     * Non-persistent storage model attributes.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes that should be visible in arrays.
     *
     * @var array
     */
    protected $visible = ['id', 'mondo_id', 'ident', 'type', 'curie', 'name', 'deprecated_name', 'description', 'synonyms', 'xrefs', 'scores', 'counts', 'activity', 'events', 'notes', 'status', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Enumerted constants for status
     */
    public const STATUS_INITIALIZING = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_DEPRECATED = 8;
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
    public const TYPE_MONDO = 1;
    public const TYPE_OMIM = 10;
    public const TYPE_OMIM_PLUS= 11;
    public const TYPE_OMIM_PERCENT = 12;
    public const TYPE_OMIM_CARET = 13;
    public const TYPE_OMIM_NUMBER = 14;
    public const TYPE_OMIM_GENE = 15;
    public const TYPE_ORPHANET = 20;



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
     * Get all the submissions associated with this disease
     */
    public function submissions()
    {
       return $this->hasMany('App\Models\Submission');
    }


    /**
     * Get the canonical MONDO disease for this disease
     * (null for MONDO diseases themselves)
     */
    public function mondoDisease()
    {
        return $this->belongsTo('App\Models\Disease', 'mondo_id');
    }


    /**
     * Get all OMIM/Orphanet diseases that map to this MONDO disease
     */
    public function equivalentDiseases()
    {
        return $this->hasMany('App\Models\Disease', 'mondo_id');
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
     * Query scope by ontology type
     *
     * @param	integer	$type
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function scopeType($query, $type)
    {
      return $query->where('type', $type);
    }


    /**
     * Query scope for MONDO types
     *
     * @param	integer	$type
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function scopeMondo($query, $curie)
    {
      return $query->where('type', self::TYPE_MONDO)->where('curie', $curie);
    }


    /**
     * Query scope for all omim types
     *
     * @param	integer	$type
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function scopeOmim($query, $curie)
    {
      $types = [self::TYPE_OMIM, self::TYPE_OMIM_CARET, self::TYPE_OMIM_NUMBER,
                self::TYPE_OMIM_PERCENT, self::TYPE_OMIM_PLUS];

      return $query->whereIn('type', $types)->where('curie', $curie);
    }


    /**
     * Normalize a submitted disease identifier to the CURIE form stored in the
     * diseases table.
     *
     * Submitters use whatever prefix casing their source system emits, and the
     * Orphanet ontology is referenced as both "ORPHA:" and "Orphanet:".  The
     * curie column stores exactly one spelling per ontology, so every in-memory
     * (case-sensitive) cache lookup has to go through here first.
     *
     * @param string|null $id The submitted disease identifier
     * @return string|null The canonical CURIE, or null if $id is not a CURIE
     */
    public static function normalizeCurie($id): ?string
    {
        if (empty($id))
            return null;

        // Preserve the existing identifier format: strip any path,
        // then keep the first two colon-separated tokens and discard the rest,
        // so "OMIM:123:456" is read as "OMIM:123"
        $parts = explode(':', basename(trim($id)));

        if (!isset($parts[1]))
            return null;

        $prefix = strtoupper(trim($parts[0]));
        $number = trim($parts[1]);

        // Orphanet is the only ontology with two accepted prefixes
        if ($prefix === 'ORPHA' || $prefix === 'ORPHANET')
            $prefix = 'Orphanet';

        return $prefix . ':' . $number;
    }


    /**
     * A fresh resolver for a one-off resolution in request handling.
     *
     * File validation and row processing build their own and thread it
     * explicitly, so a resolver's memoized lookups never outlive one upload.
     */
    public static function resolver(): DiseaseResolver
    {
        return new DiseaseResolver();
    }

  
  
}
