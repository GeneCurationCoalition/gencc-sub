<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonMachine\Items;

use App\Models\Gene;
use App\Console\Traits\CachesFileHeaders;
use App\Services\AdminProgressTracker;

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
        if (!$this->shouldUpdateFileAndTruncate($fileIdentifier, $url, 'genes')) {
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

        $this->info('...processing HUGO genes using streaming parser');
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', 0, 100, 'Processing HUGO genes...');

        // Use JsonMachine to stream through /response/docs without loading entire file
        $geneCount = 0;
        try {
            $genes = Items::fromFile($cachePath, [
                'pointer' => '/response/docs',
            ]);

            foreach ($genes as $recordArray) {
                // JsonMachine returns arrays, convert to object for compatibility
                $record = json_decode(json_encode($recordArray));

                $d = [
                    'type' => Gene::TYPE_GENE,
                    'symbol' => $record->symbol,
                    'name' => $record->name,
                    'xrefs' => [
                                'vega_id' => $record->vega_id ?? null, 'omim_id' => $record->omim_id[0] ?? null,
                                'pubmed_id' => $record->pubmed_id[0] ?? null, 'uniprot_ids' => $record->uniprot_ids[0] ?? null,
                                'mgd_id' => $record->mgd_id[0] ?? null, 'ccds_id' => $record->ccds_id[0] ?? null,
                                'entrez_id' => $record->entrez_id ?? null, 'ucsc_id' => $record->ucsc_id ?? null,
                                'ensembl_gene_id' => $record->ensembl_gene_id ?? null, 'agr' => $record->agr ?? null,
                                'hugo_uuid' => $record->uuid ?? null, 'rgd_id' => $record->rgd_id[0] ?? null,
                                'lsdb' => $record->lsdb ?? null, 'orphanet_id' => $record->orphanet ?? null,
                                'gencc_id' => $record->gencc ?? null
                                ],
                    'location' => $record->location ?? '',
                    'locus_type' => $record->locus_type,
                    'locus_group' => $record->locus_group,
                    'gene_group_id' => $record->gene_group_id[0] ?? null,
                    'gene_group' => $record->gene_group[0] ?? null,
                    'alias_symbols' => $record->alias_symbol ?? null,
                    'previous_symbols' => $record->prev_symbol ?? null,
                    'alias_names' => $record->alias_name ?? null,
                    'previous_names' => $record->prev_name ?? null,
                    'date_symbol_changed' => $record->date_symbol_changed ?? null,
                    'date_name_changed' => $record->date_name_changed ?? null,
                    'status' => Gene::STATUS_ACTIVE
                ];

                Gene::updateOrCreate(['hgnc_id' => $record->hgnc_id], $d);
                $geneCount++;

                // Update progress every 1000 genes (estimate ~44000 total genes)
                if ($geneCount % 1000 === 0) {
                    $percent = min(99, intval($geneCount / 440));
                    AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'hugo', $geneCount, 44000);
                }
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

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 0, 100, 'Downloading UniProt...');
        try {

			$results = file_get_contents($url);

		} catch (\Exception $e) {

			$this->error('......FAILED to retrieve data from UNIPROT');
			AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 0, 100, 'Download failed');
			return 0;

		}

		// unzip the data
		$data = gzdecode($results);

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'uniprot', 50, 100, 'Processing UniProt descriptions...');

        $current = ['gn' => null, 'fn' => [], 'ac' => null];
        $state = 0;

        $line = strtok($data, "\n");

		// parse the remaining file
        while ($line !== false)
        {
            $parms = preg_split('/\s+/', $line, 2);
            switch ($parms[0])
            {
                case 'ID':      // start of new section
                    $state = 1;     // seen a valid id
                    break;
                case 'AC':      // uniprot id
                    if ($state != 1)
                    {
                        $line = strtok("\n");

                        continue 2;
                    }
                    $state = 2;
                    $ac = preg_split('/;/', $parms[1], 2);
                    $current['ac'] = $ac[0];
                    break;
                case 'GN':      // gene name
                    if ($state != 2)
                    {
                        $line = strtok("\n");

                        continue 2;
                    }
                    $state = 3;
                    $gn = preg_split('/[ ;]/', substr($parms[1], 5));
                    $current['gn'] = $gn[0];
                    break;
                case 'CC':      // annotations, possibly function
                    if ($state < 3)
                    {
                        $line = strtok("\n");

                        continue 2;
                    }
                    if (strpos($parms[1], '-!- FUNCTION: ') === 0)
                    {
                        $state = 4;
                        $parms[1] = substr($parms[1], 14);
                    }
                    else if (strpos($parms[1], '-!- ') === 0 && $state == 4)
                    {
                        $state = 0;

                        // combine the function lines into one.
                        $function = implode(' ', $current['fn']);
                        $function = str_replace("\n", "", $function);

                        $record = Gene::symbol($current['gn'])->first();

                        if ($record !== null)
                        {
                            $record->xrefs->uniprot_id = $current['ac'];
                            $record->description = $function;
                            $record->save();
                        }

                        $current = ['gn' => null, 'fn' => [], 'ac' => null];

                    }
                    if ($state == 4)
                        $current['fn'][] = $parms[1];
                    break;
                default:
            }

            $line = strtok("\n");
        }

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info('...UNIPROT update complete');
        AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'uniprot', 'Descriptions updated');
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

        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 0, 100, 'Downloading MANE...');
		try {

			$results = file_get_contents($url);

		} catch (\Exception $e) {

			$this->error('......FAILED to retrieve data from NIH');
            AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 0, 100, 'Download failed');
			return 0;

		}

		// unzip the data
		$data = gzdecode($results);
        AdminProgressTracker::updatePhase(self::PROGRESS_OPERATION, 'mane', 50, 100, 'Processing MANE transcripts...');

		// discard the header
		$line = strtok($data, "\n");

		// hgncid is col2, plus/select is col9, transcipt is 10 through 13 (chr, start, stop, strand)

		// clear the plus fields since there can be any number of them
		Gene::query()->update(['coordinates->mane_plus' => null]);
		Gene::query()->update(['coordinates->mane_select' => null]);

		// parse the remaining file
		while (($line = strtok("\n")) !== false)
		{
			$parts = explode("\t", $line);

			$gene = Gene::hgnc_id($parts[2])->first();

			// there is at least one record with no hgncid, but it does have a symbol.
			if (empty($gene))
			{
				$gene = Gene::symbol($parts[3])->first();
			}

			if (empty($gene))
				continue;

			$xscript = [
					'chr' => $parts[10],
					'start' => $parts[11],
					'stop' => $parts[12],
					'strand' => $parts[13],
					'refseq_nuc' => $parts[5],
					'ensembl_nuc' => $parts[7]
				];

			if ($parts[9] == 'MANE Select')
				$gene->update(['coordinates->mane_select' => $xscript]);
			else if ($parts[9] == 'MANE Plus Clinical')
				$gene->update(['coordinates->mane_plus' => $xscript]);
			else
				$this->warn("Unknown MANE status: {$parts[9]}");
		}

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info('...MANE update complete');
        AdminProgressTracker::completePhase(self::PROGRESS_OPERATION, 'mane', 'Transcripts updated');
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
