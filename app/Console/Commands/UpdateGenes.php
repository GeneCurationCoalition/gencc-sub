<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Gene;
use App\Console\Traits\CachesFileHeaders;

class UpdateGenes extends Command
{
    use CachesFileHeaders;
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

        $this->hugo();
        $this->uniprot();
        //$this->ucsc(); // is this needed anymore?
        $this->mane();

        $this->info('Gene update complete');
    }


    protected function hugo()
    {
        $this->info('...retrieving data from HUGO');

        $url = "https://storage.googleapis.com/public-download-files/hgnc/json/json/hgnc_complete_set.json";
        $fileIdentifier = "hgnc_complete_set";

        // Check if file needs updating (also checks if genes table is empty)
        if (!$this->shouldUpdateFileAndTruncate($fileIdentifier, $url, 'genes')) {
            $this->info('...HUGO update skipped (file unchanged)');
            return 0;
        }

		try {
            $results = file_get_contents($url);
		} catch (\Exception $e) {
            $this->error('......FAILED to retrieve data from HUGO');
			return 0;

		}

		$records = json_decode($results);

        // quick check to see if we got anything
        if (($records->response->numFound ?? 0) == 0)
        {
            $this->error('......FAILED to retrieve data from HUGO');
			return 0;
        }

        // parse and update each gene
        foreach($records->response->docs as $record)
        {
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

            $gene = Gene::updateOrCreate(['hgnc_id' => $record->hgnc_id], $d);

        }

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info('...HUGO update complete');
    }


    /**
     * Update the gene table with the uniprot function description
     *
     */
    protected function uniprot()
    {
        $this->info('...retrieving data from UNIPROT');

        $url = "https://ftp.uniprot.org/pub/databases/uniprot/current_release/knowledgebase/taxonomic_divisions/uniprot_sprot_human.dat.gz";
        $fileIdentifier = "uniprot_sprot_human";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url)) {
            $this->info('...UNIPROT update skipped (file unchanged)');
            return 0;
        }

        try {

			$results = file_get_contents($url);

		} catch (\Exception $e) {

			$this->error('......FAILED to retrieve data from UNIPROT');
			return 0;

		}

		// unzip the data
		$data = gzdecode($results);

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
                        //echo "Processing " . $current['gn'] . "\n";

                        // combine the function lines into one.
                        $function = implode(' ', $current['fn']);

                        $function = str_replace("\n", "", $function);

                        //if (strlen($function) > 500)
                        //{
                           // $function = substr($function, 0, 500) . '...';
                        //}

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
    }


    /**
     * Update the gene table with MANE information
     *
     */
    protected function mane()
    {
        $this->info('...retrieving data from NIH (mane)');

        $url = "https://ftp.ncbi.nlm.nih.gov/refseq/MANE/MANE_human/current/MANE.GRCh38.v1.5.summary.txt.gz";
        $fileIdentifier = "mane_summary";

        // Check if file needs updating
        if (!$this->shouldUpdateFile($fileIdentifier, $url)) {
            $this->info('...MANE update skipped (file unchanged)');
            return 0;
        }

		try {

			$results = file_get_contents($url);

		} catch (\Exception $e) {

			$this->error('......FAILED to retrieve data from NIH');
			return 0;

		}

		// unzip the data
		$data = gzdecode($results);

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

			//echo "Updating " . $parts[2] . " \n";

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
				echo "Bad Status " . $parts[9] . " \n";
		}

        // Update cached headers after successful processing
        $this->updateCachedHeaders($fileIdentifier, $url);

        $this->info('...MANE update complete');
    }
}
