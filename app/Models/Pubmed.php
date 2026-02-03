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
 * The pubmeds table houses information from pubmed on PMIDs submitted as submission evidence.
 * Submissions who specify PMIDs will link with the PMIDs through a pivot table.
 * 
 * */
class Pubmed extends Model
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
                            'pmid', 'uid', 'pubdate', 'epubdate', 'source', 'authors', 'lastauthor',
                            'title', 'sorttitle', 'volume', 'issue', 'pages', 'lang',
                            'nlmuniqueid', 'issn', 'essn', 'pubtype', 'recordstatus', 'pubstatus',
                            'articleids', 'history', 'references', 'attributes', 'pmcrefcount', 'fullfournalname',
                            'elocationid', 'doctype', 'srccontriblist', 'booktitle', 'medium', 'edition',
                            'publisherlocation', 'publishername', 'srcdate', 'reportnumber', 'availablefromurl',
                            'locationlabel', 'doccontriblist', 'docdate', 'bookname', 'chapter', 'sortpubdate',
                            'sortfirstauthor', 'vernaculartitle', 'other', 'notes',
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
    public const STATUS_INITIALIZING = 20;
    public const STATUS_SUMMARY_COMPLETE = 21;
    public const STATUS_ACTIVE = 1;
    public const STATUS_REMOVED = 9;

    /*
     * Status strings for display methods
     *
     * @var array
     * */
     protected $status_strings = [
	 		20 => 'Initializing',
            21 => 'Summary complete',
	 		1 => 'Active',
	 		9 => 'Deleted',
	];

    /**
     * Enumerted constants for type
     */
    public const TYPE_UNKNOWN = 0;


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
     * Query all submissions referencing this pubmed article
     * 
     */
    public function submissions()
    {
        return $this->belongsToMany('App\Models\Submission');
    }


    /**
     * Query PubMed for a batch of PMIDs (up to 200 at once per NCBI guidelines)
     * This is much more efficient than individual queries
     *
     * @param array $pmids Array of PMID strings to fetch
     * @param callable|null $onBatchComplete Callback after each batch: fn(int $processed, int $total, int $batchNum, int $totalBatches)
     * @return int Number of records successfully processed
     */
    public static function query_summary_batch($pmids = null, ?callable $onBatchComplete = null)
    {
        $key = env('NCBI_API_KEY');

        // If no PMIDs provided, get all records that need summary
        if ($pmids === null) {
            $pmids = self::where('status', self::STATUS_INITIALIZING)
                        ->pluck('pmid')
                        ->toArray();
        }

        if (empty($pmids)) {
            echo "No PMIDs to process\n";
            return 0;
        }

        $total = count($pmids);
        $processed = 0;
        $batch_size = 200; // NCBI allows up to 200 IDs per request
        $batch_count = 0;
        $total_batches = ceil($total / $batch_size);

        // Process in batches
        foreach (array_chunk($pmids, $batch_size) as $batch) {
            $batch_count++;
            $batch_processed = 0;

            $parms = [
                'db' => 'pubmed',
                'id' => implode(',', $batch),
                'retmode' => 'json'
            ];

            // Add API key if available (improves rate limits)
            if ($key !== false) {
                $parms['api_key'] = $key;
            }

            $encoded_parms = http_build_query($parms);
            $results = file_get_contents('http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?' . $encoded_parms);

            if ($results !== false) {
                $json = json_decode($results, true);

                if ($json !== null && !empty($json['result']['uids'])) {
                    // Fetch all matching records in one query, keyed by pmid
                    $records = self::whereIn('pmid', $json['result']['uids'])
                        ->get()
                        ->keyBy('pmid');

                    // Update each record with the API response data
                    foreach ($json['result']['uids'] as $pmid) {
                        $record = $records->get($pmid);
                        if ($record) {
                            $record->status = self::STATUS_SUMMARY_COMPLETE;
                            $record->update($json['result'][$pmid]);
                            $processed++;
                            $batch_processed++;
                        }
                    }

                    // Show progress after each batch (like submission progress)
                    echo "\r  Progress: {$processed}/{$total} ({$batch_count}/{$total_batches} batches)";

                    if ($onBatchComplete) {
                        $onBatchComplete($processed, $total, $batch_count, $total_batches);
                    }
                } else {
                    echo "\n  ERROR: Error decoding batch response\n";
                }
            } else {
                echo "\n  ERROR: Failed to fetch batch\n";
            }

            // Rate limiting: NCBI allows 3 requests/second without API key, 10/second with key
            usleep(isset($parms['api_key']) ? 100000 : 334000);
        }

        echo "\n  Completed processing {$processed}/{$total} PMIDs\n";
        return $processed;
    }

}
