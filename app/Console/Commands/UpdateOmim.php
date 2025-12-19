<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Carbon\Carbon;

use App\Models\Gene;
use App\Models\Submission;
use App\Models\Classification;
use App\Models\Job;
use App\Models\Submitter;
use App\Models\Disease;
use App\Models\Inheritance;

class UpdateOmim extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:omim';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update OMIM submissions as a total reload.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating OMIM submissions');

        $this->mim();

        $this->info('OMIM submission update complete');
    }


    /**
     * Update disease information from MIM
     *
     */
    protected function mim()
    {
        $this->info('...retrieving data from OMIM');

        $submitter = Submitter::curie('GENCC:000109')->first();

        if ($submitter === null)
        {
            $this->error("...ERROR, can't find OMIM submitter record");
            return 0;
        }

        $user = $submitter->users->first();

        $classification = Classification::curie('GENCC:100009')->first();

        if ($classification === null)
        {
            $this->error("...ERROR, can't find Supportive Classification record");
            return 0;
        }

        $key = env('OMIM_API_KEY');
        if (!$key)
        {
            $this->error('...ERROR, no OMIM key. Set OMIM_API_KEY in .env');
            exit;
        }

        // Path to store the genemap2.txt file
        $filePath = base_path('data/genemap2.txt');
        $url = "https://data.omim.org/downloads/" . $key . "/genemap2.txt";

        // Check if we need to download a new version
        $needsDownload = true;
        $localFileTime = null;

        if (file_exists($filePath)) {
            $localFileTime = filemtime($filePath);
            $this->info("...local file found (last modified: " . date('Y-m-d H:i:s', $localFileTime) . ")");

            // Check remote file's last-modified date using HEAD request
            $this->info("...checking remote file for updates");

            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'header' => "User-Agent: GenCC-Submission-Portal\r\n"
                ]
            ]);

            $headers = @get_headers($url, 1, $context);

            if ($headers && isset($headers['Last-Modified'])) {
                $remoteFileTime = strtotime($headers['Last-Modified']);
                $this->info("...remote file modified: " . date('Y-m-d H:i:s', $remoteFileTime));

                if ($remoteFileTime <= $localFileTime) {
                    $needsDownload = false;
                    $this->info("...local file is up to date, using cached version");
                } else {
                    $this->info("...remote file is newer, downloading update");
                }
            } else {
                $this->warn("...could not determine remote file date, will download");
            }
        } else {
            $this->info("...no local file found, will download");
        }

        // Download if needed
        if ($needsDownload) {
            try {
                $this->info("...downloading genemap2.txt from OMIM");
                $results = file_get_contents($url);

                if ($results === false) {
                    throw new \Exception("Failed to download file");
                }

                // Save the file
                file_put_contents($filePath, $results);
                $this->info("...saved to " . $filePath . " (" . number_format(strlen($results)) . " bytes)");

            } catch (\Exception $e) {
                $this->error('......FAILED to retrieve data from OMIM: ' . $e->getMessage());

                // If download fails but we have a local copy, use it
                if (file_exists($filePath)) {
                    $this->warn("...using local cached file instead");
                    $results = file_get_contents($filePath);
                } else {
                    return 0;
                }
            }
        } else {
            // Use the local file
            $results = file_get_contents($filePath);
            $this->info("...loaded " . number_format(strlen($results)) . " bytes from local file");
        }

        $job = new Job([
                'friendly' => 'OMIM Import ' . Carbon::now()->format('Y-m-d'),
                'type' => Job::TYPE_GENCC_IMPORT,
                'user_id' => $user->id ?? 1,
                'submitter_id' => $submitter->id,
                'submission_date' => Carbon::now(),
                'status' => Job::STATUS_PROCESSED,
        ]);
        $job->save();

        // skip over the copyright line
        $line = strtok($results, "\n"); 

        //parse the rest
        while (($line = strtok("\n")) !== false)
        {

            $value = explode("\t", $line);

            // ignore comments
            if (strpos($value[0], '#') === 0)
                continue;

            // we can ignore anything without a phenotype
            if (empty($value[12]))
                continue;
           
                $gene = Gene::lookup($value[8]);

                /*
                    NOTES:

                    Need a way to reload without a unique ID and without losing previous subs for easy rollback

                    ?? Can we do that without an itermediate table ??

                    !! Also need to supress downloads and hide submission IDs for OMIM !!

                    !! No hgnc ids! !!
                */
                if ($gene !== null)
                {

                    $phenotypes = $this->phenoparse($value[12]);

                    foreach ($phenotypes as $phenotype)
                    {
                        if (!isset($phenotype['mim']))
                            continue;

                        // only process 3s
                        if ($phenotype['key'] != 3)
                            continue;

                        $disease = Disease::rosetta($phenotype['mim']);

                        if ($disease === null)
                            continue;

                        $inheritance = Inheritance::where('name', $phenotype['moi'])->first();

                        if ($inheritance === null)
                            continue;

                        $submission = new Submission([
                            'type' => Submission::TYPE_OMIM_SUBMISSION,
                            'friendly' => null,
                            'job_id' => $job->id,
                            'user_id' => $user->id,
                            'gene_id' => $gene->id,
                            'disease_id' => $disease->id,
                            'inheritance_id' => $inheritance->id,
                            'submitter_id' => $submitter->id,
                            'classification_id' => $classification->id,
                            'mechanism_id' => null,
                            'evidence' => [],
                            'submission_date' => Carbon::now(),
                            'publish_date' => Carbon::now(),
                            'report_date' => Carbon::now(),
                            'report_url' => "https://www.omim.org/entry/" . $phenotype['mim'],
                            'version' => '1.0.0',
                            'status' => Submission::STATUS_PUBLISHED

                        ]);

                        $submission->original_submission_data = [
                            "moi" => ["id" => $inheritance->curie, "name" => $phenotype['moi']],
                            "submission_id" => '',
                            "submission_label" => '',
                            "gene" => ["id" => $gene->hgnc_id, "symbol" => $gene->symbol],
                            "type" => "Reserved",
                            "notes" => ["display" => "", "private" => ""],
                            "report" => ["ext_url" => "https://www.omim.org/entry/" . $phenotype['mim'], "display_date" => Carbon::now()],
                            "disease" => ["id" => $phenotype['mim'], "name" => $phenotype['title']],
                            "version" => ["display" => "1.0", "reasons" => ["PARTNER_IMPORT"], "internal" => "1.0.0.0", "description" => "OMIM Import"],
                            "criteria" => ["url" => "", "name" => ""],
                            "evidence" => [],
                            "workflow"=> ["submission_date" => Carbon::now(), "publish_date" => Carbon::now()],
                            "contributors"=> ["primary" => ["id" => "", "name" => ""]],
                            "classification" => ["id" => $classification->curie, "name" => $classification->name],
                            "mechanism" => ["id" => "", "name" => "", "comment" => ""],
                            "additional_information" => ['submitter_curie' => $submitter->curie, 'submitter_title' => $submitter->name,
                                                        'submitted_as_submission_id' => $submitter->curie]
                        ];

                        $submission->submission_data = $submission->original_submission_data;

                        /*
                        $stat = Mim::updateOrCreate(  ['mim' => $phenotype['mim']],
                                                [
                                                    'type' => Mim::TYPE_PHENO,
                                                    'gene_name' => $line[8],
                                                    'gene_id' => $gene->id,
                                                    'title' => $phenotype['title'],
                                                    'moi' => $phenotype['moi'],
                                                    'map_key' => $phenotype['key']
                                                    //'status' => 1
                                                ]);
                        */

                        $job->submissions()->save($submission);
                    }
                }
                else
                {
                   // $this->info("Skipping $line, gene not found");
                }
        }


        echo "... DONE\n";
    }


    public function phenoparse($data)
    {
        if (empty($data))
            return [];

        $phenotypes = [];

        /**
        # Each Phenotype is followed by its MIM number, if different from
        # that of the locus, preceded by a comma
        # Phenotype mapping key in parentheses follows the phenotype MIM
        # number (explanation below).
        # Allelic disorders are separated by a semi-colon following the
        # phenotype mapping key.
        # Inheritance for the phenotype follows the phenotype mapping key
        # preceded by a ,<space>
        # Explanation of the symbols and question marks (?) in the Phenotype
        # field is given in OMIM FAQ 1.6)
        #
        #
        # Phenotype Mapping Method - Appears in parentheses after a disorder :
        # --------------------------------------------------------------------
        #
        # 1 - the disorder is placed on the map based on its association with
        # a gene, but the underlying defect is not known.
        # 2 - the disorder has been placed on the map by linkage; no mutation has
        # been found.
        # 3 - the molecular basis for the disorder is known; a mutation has been
        # found in the gene.
        # 4 - a contiguous gene deletion or duplication syndrome, multiple genes
        # are deleted or duplicated causing the phenotype.

        Muscular dystrophy-dystroglycanopathy (congenital with brain and eye anomalies), type A, 1, 236670 (3), Autosomal recessive; Muscular dystrophy-dystroglycanopathy (limb-girdle), type C, 1, 609308 (3), Autosomal recessive; Muscular dystrophy-dystroglycanopathy (congenital with mental retardation), type B, 1, 613155 (3), Autosomal recessive

        */

        // split out the disorders
        $disorders = explode(';', $data);

        foreach($disorders as $disorder)
        {
            $disorder = trim($disorder);

            $stat = preg_match('/^(.*),\s(\d{6})\s\((\d)\)(|, (.*))$/', $disorder, $parts);

            if ($stat)
            {
                $phenotypes[] = [ 'title' => $parts[1], 'mim' => $parts[2], 'key' => $parts[3], 'moi' => $parts[5] ?? null ];
            }
            else{
                // this is what omim calls a short phenotype, which uses a different parser
                $stat = preg_match('/^(.*)\((\d)\)(|, (.*))$/', $disorder, $parts);
                if ($stat !== false)
                    $phenotypes[] = [ 'title' => $parts[1], 'key' => $parts[2], 'moi' => $parts[3] ?? null ];

            }
        }

        return $phenotypes;
    }
}
