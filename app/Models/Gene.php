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
 * The genes table holds information about all genes, regardless if they are linked
 * to current submissions or not.  The table is updated daily with information from 
 * HUGO (genenames).
 *
 * */
class Gene extends Model
{
  use HasFactory;
  use SoftDeletes;


  /**
   * The attributes that should be cast to native types.
   *
   * @var array
   */
  protected $casts = [
      'alias_symbols' => 'object',
      'previous_symbols' => 'object',
      'alias_names' => 'object',
      'previous_names' => 'object',
      'coordinates' => 'object',
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
  protected $fillable = [	'ident', 'type', 
                          'hgnc_id', 'symbol', 'name', 'description', 'alias_symbols',
                          'alias_names', 'previous_names', 'date_name_changed', 'gene_group',
                          'gene_group_id', 'previous_symbols', 'date_symbol_changed', 'locus_group',
                          'locus_type', 'location', 'coordinates', 'scores', 'xrefs', 'counts',
                          'coordinates->mane_select', 'coordinates->mane_plus', 'events',
                          'activity', 'notes',
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
  public const TYPE_GENE = 1;


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
   * Get all the submissions associated with this gene
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
   * Query scope by hgnc_id
   *
   * @param	string	$hgnc_id
   * @return Illuminate\Database\Eloquent\Collection
   */
  public static function lookup_hgnc_id($hgnc_id)
  {
    $gene =  self::where('hgnc_id', $hgnc_id)->first();
    return $gene;
  }

    /**
     * Query scope by hgnc_id
     *
     * @param	string	$hgnc_id
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function scopeHgnc_id($query, $hgnc_id)
    {
        return $query->where('hgnc_id', $hgnc_id);
    }

  /**
   * Query scope by gene symbol
   *
   * @param	string	$symbol
   * @return Illuminate\Database\Eloquent\Collection
   */
  public function scopeSymbol($query, $symbol)
  {
    return $query->where('symbol', $symbol);
  }


  /**
   * Lookup a gene by symbol, by alias, and by previous names
   * 
   * @param string $symbol
   * @return Model
   */
  public static function lookup($string)
  {
    // return if no symbol name provided or if the reserved blank name is given
    if (empty($string) || $string == '-')
      return null;

    $gene = self::symbol($string)->first();

    if ($gene !== null)
      return $gene;

    // gene not found, so search by previous name or aliases
    $gene = self::whereJsonContains('previous_symbols', $string)
                    ->orWhereJsonContains('alias_symbols', $string)
                    ->first();

    return $gene;
  }


  /**
   * Create a blank gene for linking null references.  This is
   * primarily used when creating a blank submission entry that 
   * the user will complete at a later date.
   *
   * @return void
   * 
   */
  public static function initialize()
  {
    // prevent multiples from being added
    if (self::where('type', 0)->where('symbol', '-')->exists())
      return;

    $gene = new self([
      'type' => 0,
      'hgnc_id' => '',
      'symbol' => '-',
      'name' => '-',
      'locus_group' => '-',
      'locus_type' => '-',
      'location' => '-',
      'status' => 0
    ]);

    $gene->save();
  }

}
