<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Disease;
use App\Models\Submission;
use App\Console\Traits\CachesFileHeaders;
use App\Services\AdminProgressTracker;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Illuminate\Support\Str;

/**
 * Import MONDO, OMIM and Orphanet into the diseases table.
 *
 * Storage policy: a row's `xrefs` is a faithful, exact-only record of what that
 * row's *own* ontology asserts about other ontologies.  Nothing stores another
 * ontology's claim about itself, and nothing stores a non-exact relationship.
 * Presence in `xrefs` therefore implies exactness, so no provenance column or
 * relation qualifier is needed anywhere downstream.
 *
 *   MONDO row     `omim_id`         skos:exactMatch OMIM ids
 *                 `orpha_id`        skos:exactMatch Orphanet codes
 *                 `replaced_by`     successor CURIE when the term is obsolete
 *   Orphanet row  `mondo_id`        exact + validated MONDO CURIEs from Orphadata
 *                 `omim_id`         exact + validated OMIM ids from Orphadata
 *   OMIM row      `include_titles` only; OMIM's source file asserts nothing
 *
 * Every equivalence key is an array, even when empty.  Values are bare
 * identifiers — the key already names the namespace — except `mondo_id`, which
 * holds CURIEs so that the zero-padding MONDO identifiers require stays visible.
 *
 * `omim_id` and `orpha_id` keep the names they had before this policy, when
 * they also held non-exact identifiers, so that gencc-search, which reads them,
 * needs no change.
 */
class UpdateDiseases extends Command
{
    use CachesFileHeaders;

    /**
     * Operation identifier for progress tracking
     */
    public const PROGRESS_OPERATION = 'update_diseases';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:diseases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update disease information from MONDO, OMIM, Orphanet with comprehensive reconciliation';

    /**
     * The outcome of one source phase.  "Skipped" and "failed" are distinct
     * because reconciliation deprecates every row a phase did not report as
     * seen: doing that for a phase that *failed* would mass-deprecate the whole
     * namespace.
     */
    protected const PHASE_UPDATED = 'updated';
    protected const PHASE_SKIPPED = 'skipped';
    protected const PHASE_FAILED = 'failed';

    /**
     * The only MONDO predicate that relates a term to another ontology under
     * this policy, plus the obsolescence successor predicate.
     */
    protected const PRED_EXACT_MATCH = 'http://www.w3.org/2004/02/skos/core#exactMatch';
    protected const PRED_REPLACED_BY = 'http://purl.obolibrary.org/obo/IAO_0100001';

    /**
     * The path segment that marks a MONDO exactMatch value as an OMIM entry.
     * Phenotypic series sit under /phenotypicSeries/ and are not OMIM ids.
     */
    protected const OMIM_ENTRY_PATH = '/omim.org/entry/';

    /**
     * The `xrefs` keys that hold cross-ontology equivalences; see the class
     * docblock.  DiseaseResolver reads them through these constants.
     */
    public const FIELD_EXACT_OMIM = 'omim_id';
    public const FIELD_EXACT_ORPHANET = 'orpha_id';
    public const FIELD_EXACT_MONDO = 'mondo_id';
    public const FIELD_REPLACED_BY = 'replaced_by';

    /**
     * Orphadata's numeric ids for "E (Exact mapping...)" and "Validated".  The
     * ids are matched rather than the sibling <Name>, which is localisable
     * English prose.
     */
    protected const ORPHA_RELATION_EXACT = '21527';
    protected const ORPHA_VALIDATION_VALIDATED = '21611';

    /**
     * Track diseases seen in this update run (by curie)
     */
    protected $seenMondoIds = [];
    protected $seenOmimIds = [];
    protected $seenOrphanetIds = [];

    /**
     * The MONDO term claiming each OMIM or Orphanet identifier in this run, used
     * only to reject a release in which two terms claim the same one.
     * Resolution reads the stored xrefs, not this map.
     *
     * @var array<string, string> e.g. ['OMIM:123' => 'MONDO:0000456']
     */
    protected $exactMatchClaims = [];

    /**
     * Pre-loaded disease cache for FK-safe upserts: curie => ['id' => int, 'ident' => string, 'status' => int, 'name' => string]
     */
    protected $diseaseCache = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating disease information with comprehensive reconciliation');

        // Initialize progress tracking
        AdminProgressTracker::start(self::PROGRESS_OPERATION, [
            'mondo' => 'MONDO Diseases',
            'omim' => 'OMIM Diseases',
            'orphanet' => 'Orphanet Diseases',
            'post_processing' => 'Post-processing',
        ]);

        try {
            // Pre-load all existing disease data into memory for FK-safe upserts
            $this->preloadDiseaseCache();

            // MONDO goes first because it determines the canonical disease set.
            // Each method reports updated / skipped / failed.
            $outcomes = [];
            $outcomes['mondo'] = $this->mondo();
            $outcomes['omim'] = $this->omim();
            $outcomes['orphanet'] = $this->orphanet();

            $failed = array_keys($outcomes, self::PHASE_FAILED, true);

            foreach ($failed as $namespace) {
                $this->error("...{$namespace} phase failed - its rows will not be reconciled");
            }

            // Only reconcile if at least one source was actually re-read
            if (in_array(self::PHASE_UPDATED, $outcomes, true)) {
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'post_processing', 0, 1, 'Starting post-processing...');

                // Reconcile all existing diseases not seen in this update
                $this->reconcileUnseenDiseases($failed);
                AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'post_processing', 'Reconciliation complete');
            } else {
                $this->info('All source files unchanged or failed - skipping post-processing');
                AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'post_processing', 'Skipped - no changes');
            }

            // Build summary
            $summary = sprintf(
                "MONDO: %d, OMIM: %d, Orphanet: %d diseases processed",
                count($this->seenMondoIds),
                count($this->seenOmimIds),
                count($this->seenOrphanetIds)
            );

            if (!empty($failed)) {
                $message = 'Disease update failed for: '.implode(', ', $failed);
                AdminProgressTracker::fail(self::PROGRESS_OPERATION, $message);

                return self::FAILURE;
            }

            $this->info('Disease update complete');
            AdminProgressTracker::complete(self::PROGRESS_OPERATION, $summary);

            return self::SUCCESS;
        } catch (\Exception $e) {
            AdminProgressTracker::fail(self::PROGRESS_OPERATION, $e->getMessage());
            throw $e;
        }
    }


    /**
     * Pre-load all existing disease data into memory for FK-safe upserts.
     * This avoids querying for each disease individually.
     */
    protected function preloadDiseaseCache()
    {
        $this->info('...pre-loading disease cache for FK-safe updates');

        $diseases = Disease::select('id', 'ident', 'curie', 'status', 'name', 'type')
            ->get();

        foreach ($diseases as $disease) {
            $this->diseaseCache[$disease->curie] = [
                'id' => $disease->id,
                'ident' => $disease->ident,
                'status' => $disease->status,
                'name' => $disease->name,
                'type' => $disease->type,
            ];
        }

        $this->info("......loaded " . count($this->diseaseCache) . " existing diseases");
    }

    /**
     * Update disease information from MONDO
     *
     * This extracts:
     * 1. All MONDO diseases
     * 2. skos:exactMatch relationships to OMIM and Orphanet
     * 3. the successor of an obsolete term
     *
     * @return string One of the PHASE_* outcomes
     */
    protected function mondo()
    {
        $this->info('...retrieving data from MONDO');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', 0, 100, 'Checking MONDO source...');

        $url = 'http://purl.obolibrary.org/obo/mondo/mondo-with-equivalents.json';
        $fileIdentifier = "mondo_with_equivalents";
        $cacheFilename = "mondo-with-equivalents.json";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url, 'diseases')) {
            $this->info('...MONDO update skipped (file unchanged)');

            // Still need the existing identifiers so reconciliation does not
            // deprecate every MONDO row
            $this->loadExistingMondoCuries();

            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mondo', 'Skipped - file unchanged');

            return self::PHASE_SKIPPED;
        }

        // Download the file to disk (not memory) for streaming
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', 0, 100, 'Downloading MONDO (~100MB)...');
        $cachePath = $this->downloadFileToDisk($url, $cacheFilename);

        if ($cachePath === null) {
            $this->error('......FAILED to retrieve data from MONDO');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mondo', 'Failed - download error');

            return self::PHASE_FAILED;
        }

        $this->info('...processing MONDO diseases using batch upsert');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', 0, 100, 'Processing MONDO diseases...');

        $deprecatedCount = 0;
        $processedCount = 0;
        $batchSize = 500;
        $batch = [];
        $now = now();

        try {
            $nodes = Items::fromFile($cachePath, [
                'pointer' => '/graphs/0/nodes',
                'decoder' => new ExtJsonDecoder(true), // true = return assoc arrays
            ]);

            $totalNodes = 40000; // Approximate for progress tracking

            foreach ($nodes as $node) {
                // ExtJsonDecoder(true) returns associative arrays
                $nodeId = $node['id'] ?? '';
                $term = str_replace('_', ':', basename($nodeId));

                if (strpos($term, 'MONDO') !== 0) {
                    continue;
                }

                $this->seenMondoIds[] = $term;
                $processedCount++;

                $meta = $node['meta'] ?? [];
                $is_deprecated = $meta['deprecated'] ?? false;
                if ($is_deprecated) {
                    $deprecatedCount++;
                }

                $xrefs = $this->x_mondo_xrefs_array($meta);

                // Detect a release in which two terms claim the same identifier
                $this->recordMondoExactMatches($term, $xrefs);

                // Check if disease exists in cache
                $existing = $this->diseaseCache[$term] ?? null;
                $ident = $existing['ident'] ?? Str::uuid()->toString();

                // Build record for upsert
                $record = [
                    'ident' => $ident,
                    'curie' => $term,
                    'type' => Disease::TYPE_MONDO,
                    'xrefs' => json_encode($xrefs),
                    'status' => $is_deprecated ? Disease::STATUS_DEPRECATED : Disease::STATUS_ACTIVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Handle name based on deprecation and existence
                // Note: All records must have identical columns for batch upsert
                $label = $node['lbl'] ?? '';
                if ($is_deprecated) {
                    $record['deprecated_name'] = $label;
                    // For existing deprecated diseases, preserve their current values
                    $record['name'] = $existing ? $existing['name'] : $this->x_mondo_label($label);
                    $record['description'] = null;
                    $record['synonyms'] = $existing ? json_encode([]) : null;
                } else {
                    $record['name'] = $label;
                    $record['description'] = $meta['definition']['val'] ?? '';
                    $record['synonyms'] = json_encode($this->x_mondo_synonym_array($meta['synonyms'] ?? []));
                    $record['deprecated_name'] = null;
                }

                $batch[] = $record;

                if (count($batch) >= $batchSize) {
                    $this->upsertMondoBatch($batch);
                    $batch = [];
                    AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', $processedCount, $totalNodes);
                }
            }

            // Upsert remaining batch
            if (count($batch) > 0) {
                $this->upsertMondoBatch($batch);
            }

            // Update cached headers after successful processing
            $this->updateCachedHeaders($fileIdentifier, $url);

            $mondoCount = count($this->seenMondoIds);
            $this->info('...MONDO update complete (' . $mondoCount . ' diseases processed)');
            if ($deprecatedCount > 0) {
                $this->info('...found ' . $deprecatedCount . ' deprecated MONDO diseases (deprecated: true)');
            }
            $this->info('...found ' . count($this->exactMatchClaims) . ' OMIM and Orphanet exact_match relationships');

        } catch (\Exception $e) {
            $this->error('......FAILED to parse MONDO JSON: ' . $e->getMessage());
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mondo', 'Failed - ' . $e->getMessage());

            return self::PHASE_FAILED;
        }

        AdminProgressTracker::completePhase(
            self::PROGRESS_OPERATION,
            'mondo',
            sprintf('%d diseases processed (%d deprecated)', $mondoCount, $deprecatedCount)
        );

        return self::PHASE_UPDATED;
    }

    /**
     * Upsert a batch of MONDO diseases
     */
    protected function upsertMondoBatch(array $batch): void
    {
        // Columns to update on conflict
        $updateColumns = [
            'type', 'xrefs', 'status', 'updated_at',
            'name', 'description', 'synonyms', 'deprecated_name',
        ];

        Disease::upsert($batch, ['curie'], $updateColumns);
    }

    /**
     * Claim this term's exact matches, throwing if another term in the release
     * already claimed one of them.
     *
     * @param  array{omim_id: string[], orpha_id: string[]}  $exactMatches  As
     *      returned by x_mondo_xrefs_array(), i.e. exactly what is stored
     */
    protected function recordMondoExactMatches(string $mondoCurie, array $exactMatches): void
    {
        foreach (['OMIM' => self::FIELD_EXACT_OMIM, 'Orphanet' => self::FIELD_EXACT_ORPHANET] as $prefix => $field) {
            foreach ($exactMatches[$field] as $id) {
                $claimant = $this->exactMatchClaims["{$prefix}:{$id}"] ??= $mondoCurie;

                if ($claimant !== $mondoCurie) {
                    throw new \Exception("{$prefix}:{$id} has multiple MONDO exact_match: {$claimant} and {$mondoCurie}");
                }
            }
        }
    }

    /**
     * Parse what a MONDO term asserts about other ontologies.
     *
     * Only `skos:exactMatch` values are read: they are the sole equivalence
     * signal in the OBO Graphs JSON, and this policy relates terms across
     * ontologies on exactness alone.  The generic `meta.xrefs` list carries no
     * per-entry relation annotation and is deliberately not read.
     *
     * The predicate filter on the OMIM branch is load-bearing even though every
     * `omim.org/entry/` value currently sits under exactMatch: an OMIM id may
     * only map to a MONDO term that maps back, so a value arriving under some
     * other predicate must not be stored.
     *
     * @return array{omim_id: string[], orpha_id: string[], replaced_by: ?string}
     */
    protected function x_mondo_xrefs_array($meta)
    {
        $cleansed = [
            self::FIELD_EXACT_OMIM => [],
            self::FIELD_EXACT_ORPHANET => [],
            self::FIELD_REPLACED_BY => null,
        ];

        foreach (($meta['basicPropertyValues'] ?? []) as $property) {
            $pred = $property['pred'] ?? '';
            $val = $property['val'] ?? '';

            if ($pred === self::PRED_REPLACED_BY) {
                // Never let a later foreign successor erase a MONDO one
                $cleansed[self::FIELD_REPLACED_BY] = $this->x_mondo_curie($val) ?? $cleansed[self::FIELD_REPLACED_BY];
            } elseif ($pred !== self::PRED_EXACT_MATCH) {
                continue;
            } elseif (($n = strpos($val, self::OMIM_ENTRY_PATH)) !== false) {
                $cleansed[self::FIELD_EXACT_OMIM][] = substr($val, $n + strlen(self::OMIM_ENTRY_PATH));
            } elseif (preg_match('/Orphanet[:\/_](\d+)/', $val, $matches)) {
                $cleansed[self::FIELD_EXACT_ORPHANET][] = $matches[1];
            }
        }

        $cleansed[self::FIELD_EXACT_OMIM] = array_values(array_unique($cleansed[self::FIELD_EXACT_OMIM]));
        $cleansed[self::FIELD_EXACT_ORPHANET] = array_values(array_unique($cleansed[self::FIELD_EXACT_ORPHANET]));

        return $cleansed;
    }

    /**
     * The MONDO CURIE named by an OBO purl, or null if it names another ontology.
     */
    protected function x_mondo_curie($val)
    {
        $curie = str_replace('_', ':', basename((string) $val));

        return str_starts_with($curie, 'MONDO:') ? $curie : null;
    }

    /**
     * Transform MONDO synonyms from array format
     */
    protected function x_mondo_synonym_array($synonyms)
    {
        $cleansed = [];
        foreach ($synonyms as $synonym) {
            if (($synonym['pred'] ?? '') === "hasExactSynonym") {
                $cleansed[] = $synonym['val'] ?? '';
            }
        }
        return $cleansed;
    }


    /**
     * Note every stored MONDO identifier as seen, deprecated ones included, for
     * the run where the MONDO file is unchanged.
     */
    protected function loadExistingMondoCuries()
    {
        $this->info('......loading existing MONDO identifiers from database');

        foreach ($this->diseaseCache as $curie => $data) {
            if ($data['type'] === Disease::TYPE_MONDO) {
                $this->seenMondoIds[] = $curie;
            }
        }

        $this->info('......loaded ' . count($this->seenMondoIds) . ' MONDO identifiers');
    }


    /**
     * Update disease information from OMIM
     *
     * Creates the portal's OMIM rows from mimTitles.txt.  The OMIM source
     * asserts no relationship to any other ontology, so an OMIM row stores
     * nothing but its own titles: an OMIM id reaches MONDO only when a MONDO
     * term exact-matches it, which is what makes the reciprocity rule automatic.
     *
     * @return string One of the PHASE_* outcomes
     */
    protected function omim()
    {
        $this->info('...retrieving data from OMIM');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', 0, 100, 'Checking OMIM source...');

        $key = env('OMIM_API_KEY');
        if (!$key) {
            $this->error('...ERROR, no OMIM key. Set OMIM_API_KEY in .env');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'omim', 'Failed - no API key');

            return self::PHASE_FAILED;
        }

        $url = "https://data.omim.org/downloads/" . $key . "/mimTitles.txt";
        $fileIdentifier = "omim_mimTitles";
        $cacheFilename = "mimTitles.txt";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url)) {
            $this->info('...OMIM update skipped (file unchanged)');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'omim', 'Skipped - file unchanged');

            // Still populate seenOmimIds for reconciliation from cache
            foreach ($this->diseaseCache as $curie => $data) {
                if (in_array($data['type'], [
                    Disease::TYPE_OMIM, Disease::TYPE_OMIM_PLUS, Disease::TYPE_OMIM_NUMBER,
                    Disease::TYPE_OMIM_CARET, Disease::TYPE_OMIM_PERCENT
                ])) {
                    $this->seenOmimIds[] = $curie;
                }
            }

            return self::PHASE_SKIPPED;
        }

        // Download and cache the file
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', 0, 100, 'Downloading OMIM...');
        $data = $this->downloadAndCacheFile($url, $cacheFilename);

        if ($data === null) {
            $data = $this->getCachedFile($cacheFilename);
            if ($data === null) {
                $this->error('......FAILED to retrieve data from OMIM');
                AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'omim', 'Failed - download error');

                return self::PHASE_FAILED;
            }
        }

        $this->info('...processing OMIM diseases using batch upsert');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', 0, 100, 'Processing OMIM diseases...');

        $deprecatedCount = 0;
        $processedCount = 0;
        $batchSize = 500;
        $batch = [];
        $now = now();

        // Skip copyright line
        $line = strtok($data, "\n");

        // Parse the rest
        while (($line = strtok("\n")) !== false) {
            $value = explode("\t", $line);

            // Ignore comments
            if (strpos($value[0], '#') === 0) {
                continue;
            }

            // Set type based on OMIM prefix
            $isDeprecated = false;
            switch ($value[0]) {
                case 'Plus':
                    $type = Disease::TYPE_OMIM_PLUS;
                    break;
                case "Number Sign":
                    $type = Disease::TYPE_OMIM_NUMBER;
                    break;
                case "Caret":
                    $type = Disease::TYPE_OMIM_CARET;
                    $isDeprecated = true;
                    $deprecatedCount++;
                    break;
                case "Percent":
                    $type = Disease::TYPE_OMIM_PERCENT;
                    break;
                case "Asterisk":
                    continue 2;
                case "NULL":
                default:
                    $type = Disease::TYPE_OMIM;
                    break;
            }

            $omimId = $value[1];
            $curie = 'OMIM:' . $omimId;
            $newName = $value[2];

            $this->seenOmimIds[] = $curie;
            $processedCount++;

            // Use cached lookup instead of database query
            $existing = $this->diseaseCache[$curie] ?? null;
            $ident = $existing['ident'] ?? Str::uuid()->toString();

            // Build record for upsert
            $record = [
                'ident' => $ident,
                'curie' => $curie,
                'type' => $type,
                'synonyms' => json_encode(empty($value[3]) ? [] : [$value[3]]),
                'xrefs' => json_encode(['include_titles' => $value[4] ?? null]),
                'status' => $isDeprecated ? Disease::STATUS_DEPRECATED : Disease::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Handle name based on deprecation and existence
            // Note: All records must have identical columns for batch upsert
            if ($isDeprecated) {
                $record['deprecated_name'] = $newName;
                // For existing deprecated diseases, preserve their current name
                $record['name'] = $existing ? $existing['name'] : $newName;
                $record['description'] = null;
            } else {
                $record['name'] = $newName;
                $record['description'] = null;
                $record['deprecated_name'] = null;
            }

            $batch[] = $record;

            if (count($batch) >= $batchSize) {
                $this->upsertOmimBatch($batch);
                $batch = [];
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', $processedCount, 15000);
            }
        }

        // Upsert remaining batch
        if (count($batch) > 0) {
            $this->upsertOmimBatch($batch);
        }

        // Update cached headers
        $this->updateCachedHeaders($fileIdentifier, $url);

        $omimCount = count($this->seenOmimIds);
        $this->info('...OMIM update complete (' . $omimCount . ' diseases processed)');
        if ($deprecatedCount > 0) {
            $this->info('...found ' . $deprecatedCount . ' deprecated/removed OMIM diseases (Caret prefix)');
        }

        AdminProgressTracker::completePhase(
            self::PROGRESS_OPERATION,
            'omim',
            sprintf('%d diseases processed', $omimCount)
        );

        return self::PHASE_UPDATED;
    }

    /**
     * Upsert a batch of OMIM diseases
     */
    protected function upsertOmimBatch(array $batch): void
    {
        $updateColumns = [
            'type', 'synonyms', 'xrefs', 'status', 'updated_at',
            'name', 'description', 'deprecated_name',
        ];

        Disease::upsert($batch, ['curie'], $updateColumns);
    }


    /**
     * Update disease information from Orphanet
     *
     * @return string One of the PHASE_* outcomes
     */
    protected function orphanet()
    {
        $this->info('...retrieving data from Orphanet');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'orphanet', 0, 100, 'Checking Orphanet source...');

        $url = 'https://www.orphadata.com/data/xml/en_product1.xml';
        $fileIdentifier = 'orphanet_product1';
        $cacheFilename = 'en_product1.xml';

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url, 'diseases')) {
            $this->info('...Orphanet update skipped (file unchanged)');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'orphanet', 'Skipped - file unchanged');

            // Still need to track seen IDs from existing Orphanet diseases (use cache)
            foreach ($this->diseaseCache as $curie => $data) {
                if ($data['type'] === Disease::TYPE_ORPHANET) {
                    $this->seenOrphanetIds[] = $curie;
                }
            }

            return self::PHASE_SKIPPED;
        }

        // Download and cache the file
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'orphanet', 0, 100, 'Downloading Orphanet...');
        $data = $this->downloadAndCacheFile($url, $cacheFilename);

        if ($data === null) {
            $data = $this->getCachedFile($cacheFilename);
            if ($data === null) {
                $this->error('......FAILED to retrieve data from Orphanet');
                AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'orphanet', 'Failed - download error');

                return self::PHASE_FAILED;
            }
        }

        // Parse XML
        $xml = simplexml_load_string($data);

        // The raw string is no longer needed once the DOM exists, and together
        // they are the largest allocation in the command
        unset($data);

        if ($xml === false) {
            $this->error('......FAILED to parse Orphanet XML');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'orphanet', 'Failed - parse error');

            return self::PHASE_FAILED;
        }

        $this->info('...processing Orphanet diseases using batch upsert');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'orphanet', 0, 100, 'Processing Orphanet diseases...');

        $deprecatedCount = 0;
        $processedCount = 0;
        $batchSize = 500;
        $batch = [];
        $now = now();
        $totalNodes = 12000; // Approximate for progress tracking

        foreach ($xml->DisorderList->Disorder as $node)
        {
            $orphanetId = (string)$node->OrphaCode;
            $curie = 'Orphanet:' . $orphanetId;
            $newName = (string)$node->Name;

            // Track as seen
            $this->seenOrphanetIds[] = $curie;
            $processedCount++;

            // Check if this is an inactive/obsolete disease
            // DisorderFlag id="495" with Value="8192" indicates inactive
            $isDeprecated = false;
            if (isset($node->DisorderFlagList->DisorderFlag)) {
                foreach ($node->DisorderFlagList->DisorderFlag as $flag) {
                    $flagId = (string)$flag['id'];
                    $flagValue = (string)$flag->Value;

                    if ($flagId === '495' && $flagValue === '8192') {
                        $isDeprecated = true;
                        $deprecatedCount++;
                        break;
                    }
                }
            }

            // Use cached lookup instead of database query
            $existing = $this->diseaseCache[$curie] ?? null;
            $ident = $existing['ident'] ?? Str::uuid()->toString();

            // Build record for upsert
            $record = [
                'ident' => $ident,
                'curie' => $curie,
                'type' => Disease::TYPE_ORPHANET,
                'synonyms' => json_encode($this->x_orphanet_synonyms_xml($node->SynonymList ?? null)),
                'xrefs' => json_encode($this->x_orphanet_xrefs_xml($node->ExternalReferenceList ?? null)),
                'status' => $isDeprecated ? Disease::STATUS_DEPRECATED : Disease::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Handle names based on deprecation status
            // Note: All records must have identical columns for batch upsert
            if ($isDeprecated) {
                $record['deprecated_name'] = $newName;
                // For existing deprecated diseases, preserve their current name
                $record['name'] = $existing ? $existing['name'] : $this->x_orphanet_label($newName);
                $record['description'] = null;
            } else {
                $record['name'] = $this->x_orphanet_label($newName);
                $record['deprecated_name'] = null;

                // Get description from SummaryInformationList
                $description = '';
                if (isset($node->SummaryInformationList->SummaryInformation)) {
                    foreach ($node->SummaryInformationList->SummaryInformation as $sumInfo) {
                        if (isset($sumInfo->TextSectionList->TextSection)) {
                            foreach ($sumInfo->TextSectionList->TextSection as $section) {
                                if (isset($section->Contents)) {
                                    $description = (string)$section->Contents;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $record['description'] = $description ?: null;
            }

            $batch[] = $record;

            if (count($batch) >= $batchSize) {
                $this->upsertOrphanetBatch($batch);
                $batch = [];
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'orphanet', $processedCount, $totalNodes);
            }
        }

        // Upsert remaining batch
        if (count($batch) > 0) {
            $this->upsertOrphanetBatch($batch);
        }

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $orphanetCount = count($this->seenOrphanetIds);
        $this->info('...Orphanet update complete (' . $orphanetCount . ' diseases processed)');
        if ($deprecatedCount > 0) {
            $this->info('...found ' . $deprecatedCount . ' deprecated/inactive Orphanet diseases (DisorderFlag 495:8192)');
        }

        AdminProgressTracker::completePhase(
            self::PROGRESS_OPERATION,
            'orphanet',
            sprintf('%d diseases processed', $orphanetCount)
        );

        return self::PHASE_UPDATED;
    }

    /**
     * Upsert a batch of Orphanet diseases
     */
    protected function upsertOrphanetBatch(array $batch): void
    {
        $updateColumns = [
            'type', 'synonyms', 'xrefs', 'status', 'updated_at',
            'name', 'description', 'deprecated_name',
        ];

        Disease::upsert($batch, ['curie'], $updateColumns);
    }


    /**
     * Reconcile diseases that weren't seen in this update
     * Treat them as deprecated/removed
     *
     * @param  string[]  $failedNamespaces  Namespaces whose phase failed; their
     *      rows are left alone, because "not seen" there means "not read".
     */
    protected function reconcileUnseenDiseases(array $failedNamespaces = [])
    {
        $this->info('...reconciling unseen diseases');

        $deprecatedCount = 0;
        $withRefsCount = 0;
        $totalChecked = 0;

        $sweeps = [
            'mondo' => fn () => Disease::where('type', Disease::TYPE_MONDO)
                ->whereNotIn('curie', $this->seenMondoIds)
                ->get(),
            'omim' => fn () => Disease::whereIn('type', [
                    Disease::TYPE_OMIM,
                    Disease::TYPE_OMIM_PLUS,
                    Disease::TYPE_OMIM_NUMBER,
                    Disease::TYPE_OMIM_CARET,
                    Disease::TYPE_OMIM_PERCENT
                ])
                ->whereNotIn('curie', $this->seenOmimIds)
                ->get(),
            'orphanet' => fn () => Disease::where('type', Disease::TYPE_ORPHANET)
                ->whereNotIn('curie', $this->seenOrphanetIds)
                ->get(),
        ];

        foreach ($sweeps as $namespace => $unseen) {
            if (in_array($namespace, $failedNamespaces, true)) {
                $this->info("......skipping {$namespace} (phase failed)");
                continue;
            }

            foreach ($unseen() as $disease) {
                $totalChecked++;
                $result = $this->markAsRemovedOrDeprecated($disease);
                if ($result['deprecated']) {
                    $deprecatedCount++;
                    if ($result['has_refs']) $withRefsCount++;
                }
            }
        }

        $this->info("...reconciliation complete: checked {$totalChecked}, deprecated {$deprecatedCount} ({$withRefsCount} with submission refs)");
    }


    /**
     * Mark a disease as removed/deprecated.
     *
     * Set deprecated_name with a REMOVED- prefix and intentionally retain the
     * last exact-only xrefs. Existing submissions already retain their disease
     * foreign keys; keeping the xrefs additionally allows future identifiers to
     * resolve through the deprecated term and receive the portal warning. This
     * may be revisited if mappings absent from the current release should stop
     * participating in resolution.
     *
     * All three sources mark retired terms in their own files, and those are
     * stored as DEPRECATED by the phases above.  This only catches rows a
     * source no longer lists at all (e.g. MONDO ids withdrawn without
     * obsoletion, or OMIM entries reclassified as Asterisk, which are not
     * imported), and records them the same way.
     *
     * TODO: give these their own status (e.g. MISSING_FROM_UPSTREAM) instead
     * of reusing DEPRECATED plus a name prefix, and record when a term became
     * missing or deprecated.  Needs a migration and a review of every status
     * check, so it was left out of the exact-only mapping change.
     *
     * @return array ['deprecated' => bool, 'has_refs' => bool]
     */
    protected function markAsRemovedOrDeprecated(Disease $disease): array
    {
        // Only update if status is currently ACTIVE
        if ($disease->status !== Disease::STATUS_ACTIVE) {
            return ['deprecated' => false, 'has_refs' => false];
        }

        // Check for submission references
        $hasReferences = Submission::where('disease_id', $disease->id)
            ->orWhere('original_disease_id', $disease->id)
            ->exists();

        // Preserve current name, set deprecated_name with REMOVED- prefix
        $updates = [
            'status' => Disease::STATUS_DEPRECATED,
            'deprecated_name' => 'REMOVED- ' . $disease->name,
        ];

        $disease->update($updates);

        return ['deprecated' => true, 'has_refs' => $hasReferences];
    }


    /**
     * Remove obsolete prefix from MONDO label
     */
    protected function x_mondo_label($label)
    {
        if ($label === null)
            return "";

        if (strpos($label, 'obsolete ') === 0)
            return substr($label, 9);

        return $label;
    }


    /**
     * Remove OBSOLETE prefix from Orphanet label
     */
    protected function x_orphanet_label($label)
    {
        if ($label === null)
            return "";

        // Remove "OBSOLETE: " prefix (case insensitive)
        if (stripos($label, 'OBSOLETE: ') === 0)
            return substr($label, 10);

        return $label;
    }


    /**
     * Transform Orphanet synonyms from XML
     */
    protected function x_orphanet_synonyms_xml($synonymList)
    {
        $cleansed = [];

        if ($synonymList === null || !isset($synonymList->Synonym))
            return $cleansed;

        foreach ($synonymList->Synonym as $synonym) {
            $cleansed[] = (string)$synonym;
        }

        return $cleansed;
    }


    /**
     * Parse what an Orphanet disorder asserts about other ontologies.
     *
     * Orphadata annotates every external reference with a relation and a
     * validation status, and only "E (Exact mapping...)" + "Validated" may
     * relate terms across ontologies under this policy.  More than half of
     * Orphadata's OMIM references are broader, narrower or undecided and are
     * dropped here.  Matching is on the numeric id attribute rather than the
     * sibling <Name>, which is localisable English prose.
     *
     * MONDO <Reference> values are bare digits and about a fifth of them are
     * written unpadded ("44", "7800"), so they are left-padded to the 7 digits
     * MONDO CURIEs use.
     *
     * @return array{mondo_id: string[], omim_id: string[]}
     */
    protected function x_orphanet_xrefs_xml($externalRefList)
    {
        $cleansed = [self::FIELD_EXACT_MONDO => [], self::FIELD_EXACT_OMIM => []];

        if ($externalRefList === null || !isset($externalRefList->ExternalReference))
            return $cleansed;

        foreach ($externalRefList->ExternalReference as $external)
        {
            $source = (string)$external->Source;
            $reference = trim((string)$external->Reference);

            if (!in_array($source, ['MONDO', 'OMIM'], true)
                || $reference === ''
                || (string)$external->DisorderMappingRelation['id'] !== self::ORPHA_RELATION_EXACT
                || (string)$external->DisorderMappingValidationStatus['id'] !== self::ORPHA_VALIDATION_VALIDATED)
                continue;

            if ($source === 'MONDO')
                $cleansed[self::FIELD_EXACT_MONDO][] = 'MONDO:' . str_pad($reference, 7, '0', STR_PAD_LEFT);
            else
                $cleansed[self::FIELD_EXACT_OMIM][] = $reference;
        }

        $cleansed[self::FIELD_EXACT_MONDO] = array_values(array_unique($cleansed[self::FIELD_EXACT_MONDO]));
        $cleansed[self::FIELD_EXACT_OMIM] = array_values(array_unique($cleansed[self::FIELD_EXACT_OMIM]));

        return $cleansed;
    }


    /**
     * Download a file directly to disk for streaming (avoids loading into memory)
     *
     * @param string $url
     * @param string $cacheFilename
     * @return string|null The file path, or null on failure
     */
    protected function downloadFileToDisk($url, $cacheFilename)
    {
        $dataDir = base_path('data');
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $cachePath = $dataDir . '/' . $cacheFilename;

        // Check if we already have a cached file from today
        if (file_exists($cachePath)) {
            $fileAge = time() - filemtime($cachePath);
            // Use cached file if less than 1 hour old
            if ($fileAge < 3600) {
                $this->info("......using cached file (age: " . round($fileAge / 60) . " minutes)");
                return $cachePath;
            }
        }

        $this->info("......downloading file to {$cacheFilename}");

        try {
            // Use cURL for efficient streaming download
            $ch = curl_init($url);
            $fp = fopen($cachePath, 'w');

            if (!$fp) {
                $this->error("......failed to open file for writing");
                return null;
            }

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minute timeout
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);
            fclose($fp);

            if (!$success || $httpCode !== 200) {
                $this->error("......download failed (HTTP {$httpCode}): {$error}");
                @unlink($cachePath);
                return null;
            }

            $size = filesize($cachePath);
            $this->info("......downloaded " . round($size / 1024 / 1024, 1) . " MB");

            return $cachePath;

        } catch (\Exception $e) {
            $this->error("......download failed: " . $e->getMessage());
            return null;
        }
    }
}
