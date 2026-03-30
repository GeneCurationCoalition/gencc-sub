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
     * Map various disease ontology references to the canonical MONDO disease record.
     *
     * This method now uses the mondo_id foreign key for fast equivalence lookups.
     * Returns ACTIVE and DEPRECATED disease records (REMOVED diseases are excluded).
     *
     * For OMIM/Orphanet IDs:
     * 1. First looks for a direct OMIM/Orphanet record with that curie
     * 2. If found, returns its linked MONDO disease (via mondo_id)
     * 3. If not found, looks for MONDO disease with this ID in xrefs (legacy fallback)
     *
     * @param string $id The disease identifier (with or without prefix)
     * @return Disease|null The MONDO disease record (active or deprecated), or null if not found/removed
     */
    public static function rosetta($id)
    {
        // Return null if id is not set
        if (empty($id))
            return null;

        // Separate out prefix and identifier — requires CURIE format (PREFIX:ID)
        $parts = explode(':', basename(trim($id)));

        // Reject bare values without a prefix — callers must supply a proper CURIE
        if (!isset($parts[1]))
        {
            return null;
        }
        else
        {
            $id = $parts[1];
            $curie = strtoupper($parts[0]) . ':' . $id;

            switch (strtoupper($parts[0])) {
                case 'OMIM':
                case 'OMIMPS':
                    $record = self::rosettaOmim($curie);
                    break;

                case 'ORPHANET':
                case 'ORPHA':
                    $curie = 'Orphanet:' . $id;  // Normalize to Orphanet prefix
                    $record = self::rosettaOrphanet($curie);
                    break;

                case 'MONDO':
                    $record = self::rosettaMondo('MONDO:' . $id);
                    break;

                case 'DOID':
                    // For non-MONDO/OMIM/Orphanet ontologies, search xrefs (exclude REMOVED)
                    $record = self::where('type', self::TYPE_MONDO)
                                  ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DEPRECATED])
                                  ->where('xrefs->do_id', $id)
                                  ->first();
                    break;

                case 'GARD':
                    $record = self::where('type', self::TYPE_MONDO)
                                  ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DEPRECATED])
                                  ->where('xrefs->gard_id', $id)
                                  ->first();
                    break;

                case 'MEDGEN':
                    $record = self::where('type', self::TYPE_MONDO)
                                  ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DEPRECATED])
                                  ->where('xrefs->medgen_id', $id)
                                  ->first();
                    break;

                case 'UMLS':
                    $record = self::where('type', self::TYPE_MONDO)
                                  ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DEPRECATED])
                                  ->where('xrefs->umls_id', $id)
                                  ->first();
                    break;

                default:
                    $record = null;
            }
        }

        return $record;
    }


    /**
     * Stricter validation for submissions — ensures the MONDO mapping is
     * discoverable via xrefs (the same path the Phase 2 processing cache uses).
     *
     * For OMIM IDs, rosetta() may find a MONDO record via the mondo_id FK on the
     * OMIM record, but if the MONDO record doesn't list that OMIM ID in its xrefs,
     * the Phase 2 cache won't find it either.  This method rejects those cases so
     * Phase 1 validation is consistent with Phase 2 processing.
     *
     * @param string $id The disease identifier in CURIE format (PREFIX:ID)
     * @return Disease|null The MONDO disease record, or null if not resolvable via xrefs
     */
    public static function rosettaForSubmission($id): ?Disease
    {
        $result = self::rosetta($id);
        if ($result === null) {
            return null;
        }

        // Only OMIM IDs need the extra xref check — MONDO/Orphanet map directly
        $normalized = trim($id);
        $parts = explode(':', $normalized);
        if (!isset($parts[1])) {
            return null;
        }
        $prefix = strtoupper($parts[0]);
        $number = $parts[1];

        if (!in_array($prefix, ['OMIM', 'OMIMPS'])) {
            return $result;
        }

        // Verify the MONDO record's xrefs contain this OMIM number
        $xrefOmimIds = $result->xrefs->omim_id ?? null;
        if ($xrefOmimIds === null) {
            return null;
        }
        $xrefOmimIds = is_array($xrefOmimIds) ? $xrefOmimIds : [$xrefOmimIds];

        return in_array($number, $xrefOmimIds) ? $result : null;
    }


    /**
     * Resolve an OMIM ID to its canonical MONDO disease
     *
     * @param string $curie OMIM CURIE (e.g., "OMIM:615438")
     * @return Disease|null The MONDO disease (active or deprecated), or null if not found/removed
     */
    protected static function rosettaOmim($curie)
    {
        // Strategy 1: Look for direct OMIM record with mondo_id
        $omimDisease = self::omim($curie)->first();

        if ($omimDisease && $omimDisease->mondo_id) {
            // Prioritize ACTIVE MONDO disease over deprecated
            $mondoDisease = self::where('id', $omimDisease->mondo_id)
                ->where('status', self::STATUS_ACTIVE)
                ->first();

            if ($mondoDisease) {
                return $mondoDisease;
            }

            // Fallback to deprecated if no active found
            $mondoDisease = self::where('id', $omimDisease->mondo_id)
                ->where('status', self::STATUS_DEPRECATED)
                ->first();

            if ($mondoDisease) {
                return $mondoDisease;
            }
        }

        // Strategy 2: Legacy fallback - search MONDO xrefs
        $omimId = str_replace('OMIM:', '', $curie);

        // Prioritize active MONDO terms
        $mondoDisease = self::where('type', self::TYPE_MONDO)
            ->where('status', self::STATUS_ACTIVE)
            ->whereJsonContains('xrefs->omim_id', $omimId)
            ->first();

        if ($mondoDisease) {
            return $mondoDisease;
        }

        // Fallback to deprecated MONDO terms
        $mondoDisease = self::where('type', self::TYPE_MONDO)
            ->where('status', self::STATUS_DEPRECATED)
            ->whereJsonContains('xrefs->omim_id', $omimId)
            ->first();

        return $mondoDisease;
    }


    /**
     * Resolve an Orphanet ID to its canonical MONDO disease, or the Orphanet record itself
     *
     * Resolution order:
     * 1. Direct mondo_id on Orphanet record (set during UpdateDiseases)
     * 2. MONDO disease with matching orpha_id in xrefs (legacy fallback)
     * 3. Return the Orphanet record itself if no MONDO mapping exists
     *
     * @param string $curie Orphanet CURIE (e.g., "Orphanet:464724")
     * @return Disease|null The MONDO disease (preferred), or the Orphanet record if no MONDO mapping exists
     */
    protected static function rosettaOrphanet($curie)
    {
        // Strategy 1: Look for direct Orphanet record with mondo_id
        $orphanetDisease = self::where('type', self::TYPE_ORPHANET)
            ->where('curie', $curie)
            ->first();

        if ($orphanetDisease && $orphanetDisease->mondo_id) {
            // Prioritize ACTIVE MONDO disease over deprecated
            $mondoDisease = self::where('id', $orphanetDisease->mondo_id)
                ->where('status', self::STATUS_ACTIVE)
                ->first();

            if ($mondoDisease) {
                return $mondoDisease;
            }

            // Fallback to deprecated if no active found
            $mondoDisease = self::where('id', $orphanetDisease->mondo_id)
                ->where('status', self::STATUS_DEPRECATED)
                ->first();

            if ($mondoDisease) {
                return $mondoDisease;
            }
        }

        // Strategy 2: Legacy fallback - search MONDO xrefs
        $orphanetId = str_replace('Orphanet:', '', $curie);

        // Prioritize active MONDO terms
        $mondoDisease = self::where('type', self::TYPE_MONDO)
            ->where('status', self::STATUS_ACTIVE)
            ->where('xrefs->orpha_id', $orphanetId)
            ->first();

        if ($mondoDisease) {
            return $mondoDisease;
        }

        // Fallback to deprecated MONDO terms
        $mondoDisease = self::where('type', self::TYPE_MONDO)
            ->where('status', self::STATUS_DEPRECATED)
            ->where('xrefs->orpha_id', $orphanetId)
            ->first();

        if ($mondoDisease) {
            return $mondoDisease;
        }

        // Strategy 3: Return the Orphanet record itself if no MONDO mapping exists
        // This allows Orphanet diseases without MONDO equivalents to still be used
        if ($orphanetDisease && $orphanetDisease->status === self::STATUS_ACTIVE) {
            return $orphanetDisease;
        }

        return null;
    }


    /**
     * Resolve a MONDO ID to its disease record
     *
     * @param string $curie MONDO CURIE (e.g., "MONDO:0000001")
     * @return Disease|null The MONDO disease (active or deprecated), or null if not found/removed
     */
    protected static function rosettaMondo($curie)
    {
        // Return MONDO disease if active or deprecated (not removed)
        $mondoDisease = self::curie($curie)
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DEPRECATED])
            ->first();

        return $mondoDisease;
    }

  
  
}
