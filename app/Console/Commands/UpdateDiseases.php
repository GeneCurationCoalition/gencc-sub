<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Disease;
use App\Models\Submission;
use App\Console\Traits\CachesFileHeaders;
use App\Services\AdminProgressTracker;
use JsonMachine\Items;

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
     * Track diseases seen in this update run (by curie)
     */
    protected $seenMondoIds = [];
    protected $seenOmimIds = [];
    protected $seenOrphanetIds = [];

    /**
     * Track exact_match relationships from MONDO
     */
    protected $mondoExactMatchOmim = [];  // ['OMIM:123' => 'MONDO:456']
    protected $mondoExactMatchOrphanet = [];  // ['Orphanet:123' => 'MONDO:456']

    /**
     * Track xref relationships from MONDO (non-exact_match)
     */
    protected $mondoXrefOmim = [];  // ['OMIM:123' => ['MONDO:456', 'MONDO:789']]
    protected $mondoXrefOrphanet = [];  // ['Orphanet:123' => ['MONDO:456']]

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
            // MONDO must go first - it determines the canonical disease set and mappings
            // Each method returns true if updates were made, false if skipped
            $mondoUpdated = $this->mondo();

            // OMIM and Orphanet updates using MONDO mappings
            $omimUpdated = $this->omim();
            $orphanetUpdated = $this->orphanet();

            // Only run post-processing if at least one source was updated
            if ($mondoUpdated || $omimUpdated || $orphanetUpdated) {
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'post_processing', 0, 3, 'Starting post-processing...');

                // Step 3: Assign mondo_id to OMIM diseases using Orphanet equivalence
                $this->assignMondoIdViaOrphanet();
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'post_processing', 1, 3, 'OMIM via Orphanet complete');

                // Step 4: Assign mondo_id to Orphanet diseases using OMIM equivalence
                $this->assignMondoIdViaOmim();
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'post_processing', 2, 3, 'Orphanet via OMIM complete');

                // Reconcile all existing diseases not seen in this update
                $this->reconcileUnseenDiseases();
                AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'post_processing', 'Reconciliation complete');
            } else {
                $this->info('All source files unchanged - skipping post-processing');
                AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'post_processing', 'Skipped - no changes');
            }

            $this->info('Disease update complete');

            // Build summary
            $summary = sprintf(
                "MONDO: %d, OMIM: %d, Orphanet: %d diseases processed",
                count($this->seenMondoIds),
                count($this->seenOmimIds),
                count($this->seenOrphanetIds)
            );
            AdminProgressTracker::complete(self::PROGRESS_OPERATION, $summary);

        } catch (\Exception $e) {
            AdminProgressTracker::fail(self::PROGRESS_OPERATION, $e->getMessage());
            throw $e;
        }
    }


    /**
     * Update disease information from MONDO
     *
     * This extracts:
     * 1. All MONDO diseases
     * 2. exact_match relationships (from basicPropertyValues)
     * 3. xref relationships (from xrefs)
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

            // Still need to load mappings from existing MONDO diseases for OMIM/Orphanet phases
            $this->loadExistingMondoMappings();

            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mondo', 'Skipped - file unchanged');

            return false;
        }

        // Download the file to disk (not memory) for streaming
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', 0, 100, 'Downloading MONDO (~100MB)...');
        $cachePath = $this->downloadFileToDisk($url, $cacheFilename);

        if ($cachePath === null) {
            $this->error('......FAILED to retrieve data from MONDO');
            return false;
        }

        $this->info('...processing MONDO diseases using streaming parser');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', 0, 100, 'Processing MONDO diseases...');

        $deprecatedCount = 0;
        $processedCount = 0;
        $lastProgressUpdate = 0;

        // Use streaming JSON parser to avoid loading entire file into memory
        // The MONDO file structure is: { "graphs": [{ "nodes": [...] }] }
        // We stream through graphs/0/nodes
        try {
            $nodes = Items::fromFile($cachePath, [
                'pointer' => '/graphs/0/nodes',
            ]);

            // Estimate total nodes (MONDO typically has ~25K diseases)
            $totalNodes = 30000; // Approximate for progress tracking

            // Loop through the nodes (streaming - one at a time)
            foreach ($nodes as $nodeArray)
        {
            // Convert array to object for compatibility with existing code
            $node = json_decode(json_encode($nodeArray));

            // MONDO uses underscore instead of colon
            $term = str_replace('_', ':', basename($node->id));

            if (strpos($term, 'MONDO') !== 0)
                continue;

            // Track this MONDO ID as seen
            $this->seenMondoIds[] = $term;
            $processedCount++;

            // Update progress every 1000 items or 5%
            if ($processedCount - $lastProgressUpdate >= 1000 || ($processedCount / $totalNodes * 100) - ($lastProgressUpdate / $totalNodes * 100) >= 5) {
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mondo', $processedCount, $totalNodes);
                $lastProgressUpdate = $processedCount;
            }

            // Check if deprecated
            $is_deprecated = $node->meta->deprecated ?? false;
            if ($is_deprecated) {
                $deprecatedCount++;
            }

            // Extract exact_match and xref mappings
            $this->extractMondoMappings($term, $node->meta ?? null);

            // Get existing disease record if it exists
            $existingDisease = Disease::where('curie', $term)->first();

            // Prepare disease data
            $d = [
                'type' => Disease::TYPE_MONDO,
                'mondo_id' => null,  // MONDO diseases don't have mondo_id
                'curie' => $term,
                'xrefs' => $this->x_mondo_xrefs($node->meta ?? null),
            ];

            // Handle name based on deprecation status
            if ($is_deprecated) {
                // For deprecated diseases, always set deprecated_name to the exact incoming name (with "obsolete " prefix)
                $d['deprecated_name'] = $node->lbl ?? "";

                // For new deprecated diseases, set initial name (without "obsolete " prefix)
                if (!$existingDisease) {
                    $d['name'] = $this->x_mondo_label($node->lbl ?? null);
                }
                // Don't update name, description, or synonyms for existing deprecated diseases (preserve historical data)
            } else {
                // For active diseases, update name, description, and synonyms normally
                $d['name'] = $node->lbl ?? "";
                $d['description'] = $node->meta->definition->val ?? '';
                $d['synonyms'] = $this->x_mondo_synonym($node->meta->synonyms ?? []);
            }

            $d['status'] = ($is_deprecated ? Disease::STATUS_DEPRECATED : Disease::STATUS_ACTIVE);

            $disease = Disease::updateOrCreate(['curie' => $term], $d);
        }

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $mondoCount = count($this->seenMondoIds);
        $this->info('...MONDO update complete (' . $mondoCount . ' diseases processed)');
        if ($deprecatedCount > 0) {
            $this->info('...found ' . $deprecatedCount . ' deprecated MONDO diseases (deprecated: true)');
        }
        $this->info('...found ' . count($this->mondoExactMatchOmim) . ' OMIM exact_match relationships');
        $this->info('...found ' . count($this->mondoExactMatchOrphanet) . ' Orphanet exact_match relationships');

        } catch (\Exception $e) {
            $this->error('......FAILED to parse MONDO JSON: ' . $e->getMessage());
            return false;
        }

        AdminProgressTracker::completePhase(
            self::PROGRESS_OPERATION,
            'mondo',
            sprintf('%d diseases processed (%d deprecated)', $mondoCount, $deprecatedCount)
        );

        return true;
    }


    /**
     * Extract exact_match and xref mappings from MONDO metadata
     */
    protected function extractMondoMappings($mondoCurie, $meta)
    {
        if ($meta === null)
            return;

        // Extract exact_match from basicPropertyValues
        foreach (($meta->basicPropertyValues ?? []) as $property) {
            if ($property->pred === 'http://www.w3.org/2004/02/skos/core#exactMatch') {
                $val = $property->val;

                // Check for OMIM exact_match (NOT OMIMPS)
                if (strpos($val, '/omim.org/entry/') !== false) {
                    $omimId = basename($val);
                    $omimCurie = 'OMIM:' . $omimId;

                    // Validate uniqueness
                    if (isset($this->mondoExactMatchOmim[$omimCurie]) &&
                        $this->mondoExactMatchOmim[$omimCurie] !== $mondoCurie) {
                        throw new \Exception(
                            "OMIM {$omimCurie} has multiple MONDO exact_match: " .
                            "{$this->mondoExactMatchOmim[$omimCurie]} and {$mondoCurie}"
                        );
                    }

                    $this->mondoExactMatchOmim[$omimCurie] = $mondoCurie;
                }

                // Check for Orphanet exact_match
                if (strpos($val, 'orpha.net') !== false || strpos($val, 'Orphanet') !== false) {
                    // Extract Orphanet ID from various URL formats
                    if (preg_match('/Orphanet[:\/_](\d+)/', $val, $matches)) {
                        $orphanetId = $matches[1];
                        $orphanetCurie = 'Orphanet:' . $orphanetId;

                        // Validate uniqueness
                        if (isset($this->mondoExactMatchOrphanet[$orphanetCurie]) &&
                            $this->mondoExactMatchOrphanet[$orphanetCurie] !== $mondoCurie) {
                            throw new \Exception(
                                "Orphanet {$orphanetCurie} has multiple MONDO exact_match: " .
                                "{$this->mondoExactMatchOrphanet[$orphanetCurie]} and {$mondoCurie}"
                            );
                        }

                        $this->mondoExactMatchOrphanet[$orphanetCurie] = $mondoCurie;
                    }
                }
            }
        }

        // Extract xrefs (these are NOT exact_match)
        foreach (($meta->xrefs ?? []) as $property) {
            $val = explode(':', $property->val);

            switch ($val[0]) {
                case 'OMIM':  // Regular OMIM xref (NOT OMIMPS)
                    $omimCurie = 'OMIM:' . $val[1];
                    // Skip if already an exact_match
                    if (!isset($this->mondoExactMatchOmim[$omimCurie])) {
                        if (!isset($this->mondoXrefOmim[$omimCurie])) {
                            $this->mondoXrefOmim[$omimCurie] = [];
                        }
                        $this->mondoXrefOmim[$omimCurie][] = $mondoCurie;
                    }
                    break;

                case 'Orphanet':
                    $orphanetCurie = 'Orphanet:' . $val[1];
                    // Skip if already an exact_match
                    if (!isset($this->mondoExactMatchOrphanet[$orphanetCurie])) {
                        if (!isset($this->mondoXrefOrphanet[$orphanetCurie])) {
                            $this->mondoXrefOrphanet[$orphanetCurie] = [];
                        }
                        $this->mondoXrefOrphanet[$orphanetCurie][] = $mondoCurie;
                    }
                    break;
            }
        }
    }


    /**
     * Load existing MONDO mappings when MONDO update is skipped
     *
     * Note: This reads from the processed xrefs format stored in the database,
     * not the raw MONDO JSON format. The database stores xrefs as an object with
     * keys like omim_id, orpha_id, etc.
     */
    protected function loadExistingMondoMappings()
    {
        $this->info('......loading existing MONDO mappings from database');

        $mondoDiseases = Disease::where('type', Disease::TYPE_MONDO)
            ->where('status', Disease::STATUS_ACTIVE)
            ->get();

        foreach ($mondoDiseases as $disease) {
            $this->seenMondoIds[] = $disease->curie;

            $xrefs = $disease->xrefs;
            if ($xrefs === null) {
                continue;
            }

            // Process OMIM mappings (stored as array in omim_id)
            $omimIds = $xrefs->omim_id ?? [];
            if (!is_array($omimIds)) {
                $omimIds = [$omimIds];
            }
            foreach ($omimIds as $omimId) {
                if ($omimId) {
                    $omimCurie = 'OMIM:' . $omimId;
                    $this->mondoExactMatchOmim[$omimCurie] = $disease->curie;
                }
            }

            // Process Orphanet mappings (stored as single value in orpha_id)
            $orphaId = $xrefs->orpha_id ?? null;
            if ($orphaId) {
                $orphaCurie = 'Orphanet:' . $orphaId;
                $this->mondoExactMatchOrphanet[$orphaCurie] = $disease->curie;
            }
        }
    }


    /**
     * Update disease information from OMIM
     *
     * Uses mimTitles.txt and MONDO mappings to create/update OMIM diseases
     */
    protected function omim()
    {
        $this->info('...retrieving data from OMIM');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', 0, 100, 'Checking OMIM source...');

        $key = env('OMIM_API_KEY');
        if (!$key)
        {
            $this->error('...ERROR, no OMIM key. Set OMIM_API_KEY in .env');
            return false;
        }

        $url = "https://data.omim.org/downloads/" . $key . "/mimTitles.txt";
        $fileIdentifier = "omim_mimTitles";
        $cacheFilename = "mimTitles.txt";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url)) {
            $this->info('...OMIM update skipped (file unchanged)');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'omim', 'Skipped - file unchanged');

            // Still populate seenOmimIds for reconciliation
            $this->seenOmimIds = Disease::whereIn('type', [
                Disease::TYPE_OMIM,
                Disease::TYPE_OMIM_PLUS,
                Disease::TYPE_OMIM_NUMBER,
                Disease::TYPE_OMIM_CARET,
                Disease::TYPE_OMIM_PERCENT
            ])
            ->pluck('curie')
            ->toArray();

            return false;
        }

        // Download and cache the file
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', 0, 100, 'Downloading OMIM...');
        $data = $this->downloadAndCacheFile($url, $cacheFilename);

        if ($data === null) {
            $data = $this->getCachedFile($cacheFilename);
            if ($data === null) {
                $this->error('......FAILED to retrieve data from OMIM');
                return false;
            }
        }

        $this->info('...processing OMIM diseases');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'omim', 0, 100, 'Processing OMIM diseases...');

        $deprecatedCount = 0;

        // Skip copyright line
        $line = strtok($data, "\n");

        // Parse the rest
        while (($line = strtok("\n")) !== false)
        {
            $value = explode("\t", $line);

            // Ignore comments
            if (strpos($value[0], '#') === 0)
                continue;

            // Set type based on OMIM prefix
            $isDeprecated = false;
            switch ($value[0])
            {
                case 'Plus':
                    $type = Disease::TYPE_OMIM_PLUS;
                    break;
                case "Number Sign":
                    $type = Disease::TYPE_OMIM_NUMBER;
                    break;
                case "Caret":  // MOVED, MERGED, or REMOVED
                    $type = Disease::TYPE_OMIM_CARET;
                    $isDeprecated = true;
                    $deprecatedCount++;
                    break;
                case "Percent":
                    $type = Disease::TYPE_OMIM_PERCENT;
                    break;
                case "Asterisk":  // Gene - skip
                    continue 2;
                case "NULL":
                default:
                    $type = Disease::TYPE_OMIM;
                    break;
            }

            $omimId = $value[1];
            $curie = 'OMIM:' . $omimId;
            $newName = $value[2];

            // Track as seen
            $this->seenOmimIds[] = $curie;

            // Determine mondo_id using exact_match priority, then xrefs
            $mondoId = $this->determineMondoIdForOmim($curie);

            // Get existing disease
            $existingDisease = Disease::where('curie', $curie)->first();

            // Prepare data
            $d = [
                'type' => $type,
                'mondo_id' => $mondoId,
                'curie' => $curie,
                'synonyms' => (empty($value[3]) ? [] : [$value[3]]),
                'xrefs' => ['include_titles' => $value[4] ?? null],
            ];

            // Handle name based on deprecation status
            if ($isDeprecated) {
                // For deprecated diseases, always set deprecated_name to the exact incoming name
                $d['deprecated_name'] = $newName;

                // For new deprecated diseases, set initial name (same as deprecated_name for OMIM)
                if (!$existingDisease) {
                    $d['name'] = $newName;
                    $d['description'] = null;
                }
                // Don't update name for existing deprecated diseases (preserve historical data)
            } else {
                // For active diseases, update name normally
                $d['name'] = $newName;
                $d['description'] = null;
            }

            $d['status'] = ($isDeprecated ? Disease::STATUS_DEPRECATED : Disease::STATUS_ACTIVE);

            $disease = Disease::updateOrCreate(['curie' => $curie], $d);
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

        return true;
    }


    /**
     * Determine the MONDO ID for an OMIM disease using priority:
     * 1. exact_match
     * 2. First xref found
     */
    protected function determineMondoIdForOmim($omimCurie)
    {
        // Priority 1: exact_match
        if (isset($this->mondoExactMatchOmim[$omimCurie])) {
            $mondoCurie = $this->mondoExactMatchOmim[$omimCurie];
            $mondoDisease = Disease::where('curie', $mondoCurie)->first();
            return $mondoDisease ? $mondoDisease->id : null;
        }

        // Priority 2: First xref
        if (isset($this->mondoXrefOmim[$omimCurie]) && count($this->mondoXrefOmim[$omimCurie]) > 0) {
            $mondoCurie = $this->mondoXrefOmim[$omimCurie][0];  // Use first one
            $mondoDisease = Disease::where('curie', $mondoCurie)->first();
            return $mondoDisease ? $mondoDisease->id : null;
        }

        return null;
    }


    /**
     * Update disease information from Orphanet
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

            // Still need to track seen IDs from existing Orphanet diseases
            $this->seenOrphanetIds = Disease::where('type', Disease::TYPE_ORPHANET)
                ->where('status', Disease::STATUS_ACTIVE)
                ->pluck('curie')
                ->toArray();

            return false;
        }

        // Download and cache the file
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'orphanet', 0, 100, 'Downloading Orphanet...');
        $data = $this->downloadAndCacheFile($url, $cacheFilename);

        if ($data === null) {
            $data = $this->getCachedFile($cacheFilename);
            if ($data === null) {
                $this->error('......FAILED to retrieve data from Orphanet');
                return false;
            }
        }

        // Parse XML
        $xml = simplexml_load_string($data);

        if ($xml === false) {
            $this->error('......FAILED to parse Orphanet XML');
            return false;
        }

        $this->info('...processing Orphanet diseases');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'orphanet', 0, 100, 'Processing Orphanet diseases...');

        $deprecatedCount = 0;

        foreach ($xml->DisorderList->Disorder as $node)
        {
            $orphanetId = (string)$node->OrphaCode;
            $curie = 'Orphanet:' . $orphanetId;
            $newName = (string)$node->Name;

            // Track as seen
            $this->seenOrphanetIds[] = $curie;

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

            // Determine mondo_id
            $mondoId = $this->determineMondoIdForOrphanet($curie);

            // Get existing disease
            $existingDisease = Disease::where('curie', $curie)->first();

            // Prepare data
            $d = [
                'type' => Disease::TYPE_ORPHANET,
                'mondo_id' => $mondoId,
                'curie' => $curie,
                'synonyms' => $this->x_orphanet_synonyms_xml($node->SynonymList ?? null),
                'xrefs' => $this->x_orphanet_xrefs_xml($node->ExternalReferenceList ?? null),
            ];

            // Handle names based on deprecation status
            if ($isDeprecated) {
                // For deprecated diseases, always set deprecated_name to the exact incoming name (with OBSOLETE: prefix)
                $d['deprecated_name'] = $newName;

                // For new deprecated diseases, set initial name (remove OBSOLETE: prefix)
                if (!$existingDisease) {
                    $d['name'] = $this->x_orphanet_label($newName);
                }
                // Don't update name or description for existing deprecated diseases (preserve historical data)
            } else {
                // For active diseases, update name and description normally (remove OBSOLETE: prefix if present)
                $d['name'] = $this->x_orphanet_label($newName);
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
                $d['description'] = $description ?: null;
            }

            $d['status'] = ($isDeprecated ? Disease::STATUS_DEPRECATED : Disease::STATUS_ACTIVE);

            $disease = Disease::updateOrCreate(['curie' => $curie], $d);
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

        return true;
    }


    /**
     * Determine the MONDO ID for an Orphanet disease
     */
    protected function determineMondoIdForOrphanet($orphanetCurie)
    {
        // Priority 1: exact_match
        if (isset($this->mondoExactMatchOrphanet[$orphanetCurie])) {
            $mondoCurie = $this->mondoExactMatchOrphanet[$orphanetCurie];
            $mondoDisease = Disease::where('curie', $mondoCurie)->first();
            return $mondoDisease ? $mondoDisease->id : null;
        }

        // Priority 2: First xref
        if (isset($this->mondoXrefOrphanet[$orphanetCurie]) && count($this->mondoXrefOrphanet[$orphanetCurie]) > 0) {
            $mondoCurie = $this->mondoXrefOrphanet[$orphanetCurie][0];
            $mondoDisease = Disease::where('curie', $mondoCurie)->first();
            return $mondoDisease ? $mondoDisease->id : null;
        }

        return null;
    }


    /**
     * Step 3: Assign mondo_id to OMIM diseases using Orphanet equivalence
     *
     * If an OMIM disease doesn't have a mondo_id but is referenced by an Orphanet
     * disease that does have a mondo_id, use the Orphanet disease's mondo_id.
     */
    protected function assignMondoIdViaOrphanet()
    {
        $this->info('...assigning mondo_id to OMIM diseases via Orphanet equivalence');

        // Find all OMIM diseases without mondo_id
        $omimWithoutMondo = Disease::whereIn('type', [
                Disease::TYPE_OMIM,
                Disease::TYPE_OMIM_PLUS,
                Disease::TYPE_OMIM_NUMBER,
                Disease::TYPE_OMIM_CARET,
                Disease::TYPE_OMIM_PERCENT
            ])
            ->whereNull('mondo_id')
            ->get();

        $assignedCount = 0;

        foreach ($omimWithoutMondo as $omimDisease) {
            // Extract OMIM ID from curie (e.g., "OMIM:615221" -> "615221")
            $omimId = str_replace('OMIM:', '', $omimDisease->curie);

            // Find Orphanet diseases that reference this OMIM ID in their xrefs
            $orphanetDiseases = Disease::where('type', Disease::TYPE_ORPHANET)
                ->whereNotNull('mondo_id')
                ->whereRaw("JSON_EXTRACT(xrefs, '$.omim_id') = ?", [$omimId])
                ->get();

            if ($orphanetDiseases->count() > 0) {
                // Use the first Orphanet disease's mondo_id
                $orphanetDisease = $orphanetDiseases->first();
                $omimDisease->update(['mondo_id' => $orphanetDisease->mondo_id]);
                $assignedCount++;

                $this->info("......assigned mondo_id to {$omimDisease->curie} via Orphanet:{$orphanetDisease->curie}");
            }
        }

        $this->info("...assigned {$assignedCount} mondo_id values via Orphanet equivalence");
    }


    /**
     * Step 4: Assign mondo_id to Orphanet diseases using OMIM equivalence
     *
     * If an Orphanet disease doesn't have a mondo_id but references an OMIM
     * disease (in its xrefs) that does have a mondo_id, use the OMIM disease's mondo_id.
     *
     * This handles cases like Orphanet:722 which has xrefs->omim_id = "217090",
     * and OMIM:217090 has mondo_id pointing to MONDO:0009009.
     */
    protected function assignMondoIdViaOmim()
    {
        $this->info('...assigning mondo_id to Orphanet diseases via OMIM equivalence');

        // Find all Orphanet diseases without mondo_id
        $orphanetWithoutMondo = Disease::where('type', Disease::TYPE_ORPHANET)
            ->whereNull('mondo_id')
            ->whereNotNull('xrefs')
            ->get();

        $assignedCount = 0;

        foreach ($orphanetWithoutMondo as $orphanetDisease) {
            // Check if this Orphanet disease has an OMIM xref
            $xrefs = $orphanetDisease->xrefs;
            if ($xrefs === null) {
                continue;
            }

            $omimId = $xrefs->omim_id ?? null;
            if (empty($omimId)) {
                continue;
            }

            // Build the OMIM curie
            $omimCurie = 'OMIM:' . $omimId;

            // Find the OMIM disease with this curie that has a mondo_id
            $omimDisease = Disease::whereIn('type', [
                    Disease::TYPE_OMIM,
                    Disease::TYPE_OMIM_PLUS,
                    Disease::TYPE_OMIM_NUMBER,
                    Disease::TYPE_OMIM_PERCENT
                ])
                ->where('curie', $omimCurie)
                ->whereNotNull('mondo_id')
                ->first();

            if ($omimDisease) {
                // Use the OMIM disease's mondo_id
                $orphanetDisease->update(['mondo_id' => $omimDisease->mondo_id]);
                $assignedCount++;

                $this->info("......assigned mondo_id to {$orphanetDisease->curie} via {$omimCurie}");
            }
        }

        $this->info("...assigned {$assignedCount} mondo_id values via OMIM equivalence");
    }


    /**
     * Reconcile diseases that weren't seen in this update
     * Treat them as deprecated/removed
     */
    protected function reconcileUnseenDiseases()
    {
        $this->info('...reconciling unseen diseases');

        // Find MONDO diseases not seen
        $unseenMondo = Disease::where('type', Disease::TYPE_MONDO)
            ->whereNotIn('curie', $this->seenMondoIds)
            ->get();

        foreach ($unseenMondo as $disease) {
            $this->markAsRemovedOrDeprecated($disease);
        }

        // Find OMIM diseases not seen
        $unseenOmim = Disease::whereIn('type', [
                Disease::TYPE_OMIM,
                Disease::TYPE_OMIM_PLUS,
                Disease::TYPE_OMIM_NUMBER,
                Disease::TYPE_OMIM_CARET,
                Disease::TYPE_OMIM_PERCENT
            ])
            ->whereNotIn('curie', $this->seenOmimIds)
            ->get();

        foreach ($unseenOmim as $disease) {
            $this->markAsRemovedOrDeprecated($disease);
        }

        // Find Orphanet diseases not seen
        $unseenOrphanet = Disease::where('type', Disease::TYPE_ORPHANET)
            ->whereNotIn('curie', $this->seenOrphanetIds)
            ->get();

        foreach ($unseenOrphanet as $disease) {
            $this->markAsRemovedOrDeprecated($disease);
        }

        $totalUnseen = $unseenMondo->count() + $unseenOmim->count() + $unseenOrphanet->count();
        $this->info("...reconciliation complete ({$totalUnseen} diseases marked as removed/deprecated)");
    }


    /**
     * Mark a disease as removed/deprecated
     * Preserve mondo_id, set deprecated_name with REMOVED- prefix
     */
    protected function markAsRemovedOrDeprecated(Disease $disease)
    {
        // Check for submission references
        $hasReferences = Submission::where('disease_id', $disease->id)
            ->orWhere('original_disease_id', $disease->id)
            ->exists();

        // Only update if status is currently ACTIVE
        if ($disease->status !== Disease::STATUS_ACTIVE) {
            return;
        }

        // Preserve current name, set deprecated_name with REMOVED- prefix
        $updates = [
            'status' => Disease::STATUS_DEPRECATED,
            'deprecated_name' => 'REMOVED- ' . $disease->name,
            // DON'T update mondo_id - preserve it
        ];

        $disease->update($updates);

        if ($hasReferences) {
            $this->warn("......DEPRECATED (has refs): {$disease->curie}");
        } else {
            $this->info("......DEPRECATED (no refs): {$disease->curie}");
        }
    }


    /**
     * Transform MONDO synonyms
     */
    protected function x_mondo_synonym($synonyms)
    {
        $cleansed = [];

        foreach ($synonyms as $synonym)
            if ($synonym->pred === "hasExactSynonym")
                $cleansed[] = $synonym->val;

        return $cleansed;
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
     * Parse MONDO xrefs
     */
    protected function x_mondo_xrefs($meta)
    {
        $cleansed = [
            'omim_id' => [], 'omim_label' => null,
            'orpha_id' =>  null, 'orpha_label' => null, 'ogms' => null,
            'do_id' => null, 'medgen_id' => null, 'mesh' => null,
            'gard_id' => null, 'umls_id' => null, 'ncit' => null
        ];

        if ($meta === null)
            return $cleansed;

        // Get OMIM from basicPropertyValues (could be exact_match or other)
        foreach (($meta->basicPropertyValues ?? []) as $property)
            if (($n = strpos($property->val, '/omim.org/entry/')) > 0)
                $cleansed['omim_id'][] = substr($property->val, $n + 16);

        // Get the rest from xrefs
        foreach (($meta->xrefs ?? []) as $property)
        {
            $val = explode(':', $property->val);

            switch ($val[0])
            {
                case 'DOID':
                    $cleansed['do_id'] = $val[1];
                    break;
                case 'OMIM':
                    $cleansed['omim_id'][] = $val[1];
                    break;
                case 'Orphanet':
                    $cleansed['orpha_id'] = $val[1];
                    break;
                case 'GARD':
                    $cleansed['gard_id'] = $val[1];
                    break;
                case 'UMLS':
                    $cleansed['umls_id'] = $val[1];
                    break;
                case 'MESH':
                    $cleansed['mesh'] = $val[1];
                    break;
                case 'NCIT':
                    $cleansed['ncit'] = $val[1];
                    break;
                case 'OGMS':
                    $cleansed['ogms'] = $val[1];
                    break;
            }
        }

        $cleansed['omim_id'] = array_values(array_unique($cleansed['omim_id']));

        return $cleansed;
    }


    /**
     * Transform Orphanet synonyms
     */
    protected function x_orphanet_synonyms($synonyms)
    {
        $cleansed = [];

        if ($synonyms === null)
            return $cleansed;

        foreach ($synonyms as $synonym)
            $cleansed[] = $synonym->label;

        return $cleansed;
    }


    /**
     * Parse Orphanet xrefs (JSON format - legacy)
     */
    protected function x_orphanet_xrefs($externals)
    {
        $cleansed = [];

        if ($externals === null)
            return $cleansed;

        foreach ($externals as $external)
        {
            switch ($external->Source)
            {
                case 'OMIM':
                    $cleansed['omim_id'] = $external->Reference;
                    break;
                case 'UMLS':
                    $cleansed['umls_id'] = $external->Reference;
                    break;
            }
        }

        return $cleansed;
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
     * Parse Orphanet xrefs from XML
     */
    protected function x_orphanet_xrefs_xml($externalRefList)
    {
        $cleansed = [];

        if ($externalRefList === null || !isset($externalRefList->ExternalReference))
            return $cleansed;

        foreach ($externalRefList->ExternalReference as $external)
        {
            $source = (string)$external->Source;
            $reference = (string)$external->Reference;

            switch ($source)
            {
                case 'OMIM':
                    $cleansed['omim_id'] = $reference;
                    break;
                case 'UMLS':
                    $cleansed['umls_id'] = $reference;
                    break;
            }
        }

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
