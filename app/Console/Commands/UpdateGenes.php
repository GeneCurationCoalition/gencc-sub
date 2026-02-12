<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

use App\Models\Gene;
use App\Console\Traits\CachesFileHeaders;
use App\Services\AdminProgressTracker;
use Illuminate\Support\Facades\DB;

class UpdateGenes extends Command
{
    use CachesFileHeaders;

    /**
     * Progress tracking operation key (must match AdminLog::OP_UPDATE_GENES)
     */
    protected const PROGRESS_OPERATION = 'update_genes';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:genes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update gene information from HUGO, NHS, and others';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating gene information');

        // Initialize progress tracking
        AdminProgressTracker::start(self::PROGRESS_OPERATION, [
            'hugo' => 'HUGO/HGNC Genes',
            'uniprot' => 'UniProt Descriptions',
            'mane' => 'MANE Transcripts',
        ]);

        try {
            $this->hugo();
            $this->uniprot();
            $this->mane();

            $this->info('Gene update complete');

            AdminProgressTracker::complete(self::PROGRESS_OPERATION, 'Gene update completed successfully');

        } catch (\Exception $e) {
            AdminProgressTracker::fail(self::PROGRESS_OPERATION, $e->getMessage());
            throw $e;
        }
    }


    protected function hugo()
    {
        $this->info('...retrieving data from HUGO');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', 0, 100, 'Checking HUGO source...');

        $url = "https://storage.googleapis.com/public-download-files/hgnc/json/json/hgnc_complete_set.json";
        $fileIdentifier = "hgnc_complete_set";

        // Check if file needs updating (also checks if genes table is empty)
        // IMPORTANT: Use shouldUpdateFile (not shouldUpdateFileAndTruncate) to preserve
        // gene IDs and maintain FK integrity with submissions.gene_id
        if (!$this->shouldUpdateFile($fileIdentifier, $url, 'genes')) {
            $this->info('...HUGO update skipped (file unchanged)');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'hugo', 'Skipped - file unchanged');
            return 0;
        }

        // Download file to disk for streaming (avoids loading 34MB into memory)
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', 0, 100, 'Downloading HUGO (~34MB)...');
        $cachePath = $this->downloadFileToDisk($url, 'hgnc_complete_set.json');
        if (!$cachePath) {
            $this->error('......FAILED to download HUGO data');
            AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', 0, 100, 'Download failed');
            return 0;
        }

        $this->info('...processing HUGO genes using batch upsert');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', 0, 100, 'Loading existing gene identifiers...');

        // Pre-load existing hgnc_id → ident mappings for FK-safe upserts
        // This allows us to preserve existing idents while generating new ones for new genes
        $existingIdents = Gene::whereNotNull('hgnc_id')
            ->pluck('ident', 'hgnc_id')
            ->toArray();
        $this->info("......loaded " . count($existingIdents) . " existing gene identifiers");

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', 0, 100, 'Processing HUGO genes...');

        // Batch processing settings
        $batchSize = 500;
        $batch = [];
        $geneCount = 0;
        $now = now();

        try {
            $genes = Items::fromFile($cachePath, [
                'pointer' => '/response/docs',
                'decoder' => new ExtJsonDecoder(true), // true = return assoc arrays
            ]);

            foreach ($genes as $record) {
                // ExtJsonDecoder(true) returns associative arrays
                $hgncId = $record['hgnc_id'];

                // Use existing ident if gene exists, otherwise generate new UUID
                $ident = $existingIdents[$hgncId] ?? \Illuminate\Support\Str::uuid()->toString();

                $batch[] = [
                    'ident' => $ident,
                    'hgnc_id' => $hgncId,
                    'type' => Gene::TYPE_GENE,
                    'symbol' => $record['symbol'],
                    'name' => $record['name'],
                    'xrefs' => json_encode([
                        'vega_id' => $record['vega_id'] ?? null,
                        'omim_id' => $record['omim_id'][0] ?? null,
                        'pubmed_id' => $record['pubmed_id'][0] ?? null,
                        'uniprot_ids' => $record['uniprot_ids'][0] ?? null,
                        'mgd_id' => $record['mgd_id'][0] ?? null,
                        'ccds_id' => $record['ccds_id'][0] ?? null,
                        'entrez_id' => $record['entrez_id'] ?? null,
                        'ucsc_id' => $record['ucsc_id'] ?? null,
                        'ensembl_gene_id' => $record['ensembl_gene_id'] ?? null,
                        'agr' => $record['agr'] ?? null,
                        'hugo_uuid' => $record['uuid'] ?? null,
                        'rgd_id' => $record['rgd_id'][0] ?? null,
                        'lsdb' => $record['lsdb'] ?? null,
                        'orphanet_id' => $record['orphanet'] ?? null,
                        'gencc_id' => $record['gencc'] ?? null,
                    ]),
                    'location' => $record['location'] ?? '',
                    'locus_type' => $record['locus_type'],
                    'locus_group' => $record['locus_group'],
                    'gene_group_id' => $record['gene_group_id'][0] ?? null,
                    'gene_group' => $record['gene_group'][0] ?? null,
                    'alias_symbols' => isset($record['alias_symbol']) ? json_encode($record['alias_symbol']) : null,
                    'previous_symbols' => isset($record['prev_symbol']) ? json_encode($record['prev_symbol']) : null,
                    'alias_names' => isset($record['alias_name']) ? json_encode($record['alias_name']) : null,
                    'previous_names' => isset($record['prev_name']) ? json_encode($record['prev_name']) : null,
                    'date_symbol_changed' => $record['date_symbol_changed'] ?? null,
                    'date_name_changed' => $record['date_name_changed'] ?? null,
                    'status' => Gene::STATUS_ACTIVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $geneCount++;

                // Upsert in batches
                if (count($batch) >= $batchSize) {
                    $this->upsertGeneBatch($batch);
                    $batch = [];

                    // Update progress every batch (~500 genes)
                    AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', $geneCount, 44000);
                }
            }

            // Upsert remaining batch
            if (count($batch) > 0) {
                $this->upsertGeneBatch($batch);
            }

        } catch (\Exception $e) {
            $this->error('......FAILED to parse HUGO data: ' . $e->getMessage());
            return 0;
        }

        if ($geneCount == 0) {
            $this->error('......FAILED to retrieve data from HUGO (no genes found)');
            return 0;
        }

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info("...HUGO update complete ({$geneCount} genes processed)");
        AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'hugo', "{$geneCount} genes processed");
    }

    /**
     * Upsert a batch of genes using MySQL's ON DUPLICATE KEY UPDATE.
     * This is ~100x faster than individual updateOrCreate calls.
     */
    protected function upsertGeneBatch(array $batch): void
    {
        // Columns to update on conflict (everything except id, ident, hgnc_id, created_at)
        $updateColumns = [
            'type', 'symbol', 'name', 'xrefs', 'location', 'locus_type', 'locus_group',
            'gene_group_id', 'gene_group', 'alias_symbols', 'previous_symbols',
            'alias_names', 'previous_names', 'date_symbol_changed', 'date_name_changed',
            'status', 'updated_at',
        ];

        Gene::upsert($batch, ['hgnc_id'], $updateColumns);
    }


    /**
     * Update the gene table with the uniprot function description
     *
     */
    protected function uniprot()
    {
        $this->info('...retrieving data from UNIPROT');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 0, 100, 'Checking UniProt source...');

        $url = "https://ftp.uniprot.org/pub/databases/uniprot/current_release/knowledgebase/taxonomic_divisions/uniprot_sprot_human.dat.gz";
        $fileIdentifier = "uniprot_sprot_human";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url)) {
            $this->info('...UNIPROT update skipped (file unchanged)');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'uniprot', 'Skipped - file unchanged');
            return 0;
        }

        // Download and decompress the file to disk (avoids loading ~200MB into memory)
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 0, 100, 'Downloading UniProt (~100MB compressed)...');
        $cachePath = $this->downloadGzFileToDisk($url, 'uniprot_sprot_human.dat');
        if (!$cachePath) {
            $this->error('......FAILED to download/decompress UniProt data');
            AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 0, 100, 'Download failed');
            return 0;
        }

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 50, 100, 'Loading gene data...');

        // Pre-load all genes by symbol to avoid per-protein SELECT queries
        // This reduces ~20,000 SELECT queries to a single query
        $geneMap = Gene::whereNotNull('symbol')
            ->select('id', 'symbol', 'xrefs')
            ->get()
            ->keyBy('symbol')
            ->toArray();
        $this->info("......loaded " . count($geneMap) . " genes into memory");

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 60, 100, 'Parsing UniProt file...');

        $current = ['gn' => null, 'fn' => [], 'ac' => null];
        $state = 0;
        $updates = []; // Collect updates: symbol => ['uniprot_id' => ..., 'description' => ...]

        // Stream the file line by line to minimize memory usage
        $handle = fopen($cachePath, 'r');
        if (!$handle) {
            $this->error('......FAILED to open cached UniProt file');
            return 0;
        }

        // Parse the file line by line, collecting updates instead of saving immediately
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            $parms = preg_split('/\s+/', $line, 2);

            if (count($parms) < 1) {
                continue;
            }

            switch ($parms[0]) {
                case 'ID':      // start of new section
                    $state = 1;     // seen a valid id
                    break;
                case 'AC':      // uniprot id
                    if ($state != 1) {
                        continue 2;
                    }
                    $state = 2;
                    $ac = preg_split('/;/', $parms[1] ?? '', 2);
                    $current['ac'] = $ac[0];
                    break;
                case 'GN':      // gene name
                    if ($state != 2) {
                        continue 2;
                    }
                    $state = 3;
                    $gn = preg_split('/[ ;]/', substr($parms[1] ?? '', 5));
                    $current['gn'] = $gn[0];
                    break;
                case 'CC':      // annotations, possibly function
                    if ($state < 3) {
                        continue 2;
                    }
                    $content = $parms[1] ?? '';
                    if (strpos($content, '-!- FUNCTION: ') === 0) {
                        $state = 4;
                        $content = substr($content, 14);
                    } else if (strpos($content, '-!- ') === 0 && $state == 4) {
                        $state = 0;

                        // Combine the function lines into one
                        $function = implode(' ', $current['fn']);
                        $function = str_replace("\n", "", $function);

                        // Collect update if gene exists (no DB query here)
                        if (isset($geneMap[$current['gn']])) {
                            $updates[$current['gn']] = [
                                'uniprot_id' => $current['ac'],
                                'description' => $function,
                            ];
                        }

                        $current = ['gn' => null, 'fn' => [], 'ac' => null];
                    }
                    if ($state == 4) {
                        $current['fn'][] = $content;
                    }
                    break;
                default:
            }
        }

        fclose($handle);
        $this->info("......found " . count($updates) . " genes to update");

        // Batch update genes - uses raw SQL for efficiency
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 80, 100, 'Updating gene descriptions...');
        $updateCount = $this->batchUpdateUniprotDescriptions($updates, $geneMap);

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info("......updated {$updateCount} gene descriptions");
        $this->info('...UNIPROT update complete');
        AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'uniprot', "{$updateCount} descriptions updated");
    }

    /**
     * Batch update UniProt descriptions using MySQL JSON_SET for efficiency.
     * This avoids loading/saving Eloquent models for each gene.
     *
     * @param array $updates Map of symbol => ['uniprot_id' => ..., 'description' => ...]
     * @param array $geneMap Pre-loaded gene data keyed by symbol
     * @return int Number of genes updated
     */
    protected function batchUpdateUniprotDescriptions(array $updates, array $geneMap): int
    {
        $updateCount = 0;
        $batchSize = 500;
        $chunks = array_chunk($updates, $batchSize, true);
        $totalChunks = count($chunks);

        foreach ($chunks as $chunkIndex => $chunk) {
            // Use a transaction for each batch
            \DB::transaction(function () use ($chunk, $geneMap, &$updateCount) {
                foreach ($chunk as $symbol => $data) {
                    $gene = $geneMap[$symbol] ?? null;
                    if (!$gene) {
                        continue;
                    }

                    // Use MySQL's JSON_SET to update only the uniprot_id key
                    // This avoids loading and re-encoding the entire xrefs JSON
                    \DB::table('genes')
                        ->where('id', $gene['id'])
                        ->update([
                            'xrefs' => \DB::raw("JSON_SET(COALESCE(xrefs, '{}'), '\$.uniprot_id', " . \DB::getPdo()->quote($data['uniprot_id']) . ")"),
                            'description' => $data['description'],
                            'updated_at' => now(),
                        ]);

                    $updateCount++;
                }
            });

            // Progress update every few batches
            if ($chunkIndex % 5 === 0) {
                $progress = 80 + (int)(($chunkIndex / $totalChunks) * 15);
                AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', $progress, 100,
                    "Updating genes... ({$updateCount}/" . count($updates) . ")");
            }
        }

        return $updateCount;
    }

    /**
     * Update the gene table with MANE information
     *
     */
    protected function mane()
    {
        $this->info('...retrieving data from NIH (mane)');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 0, 100, 'Checking MANE source...');

        $url = "https://ftp.ncbi.nlm.nih.gov/refseq/MANE/MANE_human/current/MANE.GRCh38.v1.5.summary.txt.gz";
        $fileIdentifier = "mane_summary";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url)) {
            $this->info('...MANE update skipped (file unchanged)');
            AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mane', 'Skipped - file unchanged');
            return 0;
        }

        // Download and decompress the file to disk
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 0, 100, 'Downloading MANE...');
        $cachePath = $this->downloadGzFileToDisk($url, 'mane_summary.txt');
        if (!$cachePath) {
            $this->error('......FAILED to download/decompress MANE data');
            AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 0, 100, 'Download failed');
            return 0;
        }

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 50, 100, 'Processing MANE transcripts...');

        // Clear the plus fields since there can be any number of them
        Gene::query()->update(['coordinates->mane_plus' => null]);
        Gene::query()->update(['coordinates->mane_select' => null]);

        // hgncid is col2, plus/select is col9, transcript is 10 through 13 (chr, start, stop, strand)

        $handle = fopen($cachePath, 'r');
        if (!$handle) {
            $this->error('......FAILED to open cached MANE file');
            return 0;
        }

        // Discard the header
        fgets($handle);

        // Parse the remaining file
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            $parts = explode("\t", $line);

            if (count($parts) < 14) {
                continue;
            }

            $gene = Gene::hgnc_id($parts[2])->first();

            // there is at least one record with no hgncid, but it does have a symbol.
            if (empty($gene)) {
                $gene = Gene::symbol($parts[3])->first();
            }

            if (empty($gene)) {
                continue;
            }

            $xscript = [
                'chr' => $parts[10],
                'start' => $parts[11],
                'stop' => $parts[12],
                'strand' => $parts[13],
                'refseq_nuc' => $parts[5],
                'ensembl_nuc' => $parts[7]
            ];

            if ($parts[9] == 'MANE Select') {
                $gene->update(['coordinates->mane_select' => $xscript]);
            } else if ($parts[9] == 'MANE Plus Clinical') {
                $gene->update(['coordinates->mane_plus' => $xscript]);
            } else {
                $this->warn("Unknown MANE status: {$parts[9]}");
            }
        }

        fclose($handle);

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info('...MANE update complete');
        AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mane', 'Transcripts updated');
    }

    /**
     * Download a gzipped file to disk and decompress it.
     * Returns the path to the decompressed file on success, null on failure.
     * Uses cached decompressed file if less than 1 hour old.
     */
    protected function downloadGzFileToDisk(string $url, string $cacheFilename): ?string
    {
        $dataDir = base_path('data');
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $gzPath = $dataDir . '/' . $cacheFilename . '.gz';
        $txtPath = $dataDir . '/' . $cacheFilename;

        // Check if we already have a cached decompressed file
        if (file_exists($txtPath)) {
            $fileAge = time() - filemtime($txtPath);
            // Use cached file if less than 1 hour old
            if ($fileAge < 3600) {
                $this->info("......using cached file (age: " . round($fileAge / 60) . " minutes)");
                return $txtPath;
            }
        }

        $this->info("......downloading gzipped file...");

        try {
            // Download the gzipped file using cURL streaming
            $ch = curl_init($url);
            $fp = fopen($gzPath, 'w');

            if (!$fp) {
                $this->error("......failed to open file for writing");
                return null;
            }

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minute timeout for large files
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);
            fclose($fp);

            if (!$success || $httpCode !== 200) {
                $this->error("......download failed (HTTP {$httpCode}): {$error}");
                @unlink($gzPath);
                return null;
            }

            $gzSize = filesize($gzPath);
            $this->info("......downloaded " . round($gzSize / 1024 / 1024, 1) . " MB compressed");

            // Decompress the file using streaming to avoid memory issues
            $this->info("......decompressing...");

            $gzHandle = gzopen($gzPath, 'rb');
            if (!$gzHandle) {
                $this->error("......failed to open gzipped file for reading");
                @unlink($gzPath);
                return null;
            }

            $outHandle = fopen($txtPath, 'w');
            if (!$outHandle) {
                $this->error("......failed to open output file for writing");
                gzclose($gzHandle);
                @unlink($gzPath);
                return null;
            }

            // Stream decompression in chunks
            while (!gzeof($gzHandle)) {
                $chunk = gzread($gzHandle, 8192); // 8KB chunks
                fwrite($outHandle, $chunk);
            }

            gzclose($gzHandle);
            fclose($outHandle);

            // Clean up the gzipped file
            @unlink($gzPath);

            $txtSize = filesize($txtPath);
            $this->info("......decompressed to " . round($txtSize / 1024 / 1024, 1) . " MB");

            return $txtPath;

        } catch (\Exception $e) {
            $this->error("......download/decompress failed: " . $e->getMessage());
            @unlink($gzPath);
            @unlink($txtPath);
            return null;
        }
    }

    /**
     * Download a file to disk using cURL streaming.
     * Returns the cache path on success, null on failure.
     * Uses cached file if less than 1 hour old.
     */
    protected function downloadFileToDisk($url, $cacheFilename)
    {
        $dataDir = base_path('data');
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $cachePath = $dataDir . '/' . $cacheFilename;

        // Check if we already have a cached file from recently
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
