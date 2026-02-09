<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Log;

use App\Models\Inheritance;
use App\Models\Gene;
use App\Models\Classification;
use App\Models\Submission;
use App\Models\Builder;

class ImportClingen extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:clingen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import ClinGen gene-disease summary data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Log::info("Importing ClinGen GDV Data...");
               
        $this->clingen();
                
        Log::info("Done");
    }


    /**
     * Make a submission form from CG download.
     *
     * @return mixed
     */
    public function clingen()
    {
        // open import file
        $fp = fopen(base_path() . '/data/Clingen-Gene-Disease-Summary.csv', 'r');
		if ($fp === false)
		{
			$this->error("Error opening ClinGen table");
			return 1;
		}

        // skip over the header section
        for ($n = 0; $n < 6; $n++)
        {
            $data = fgetcsv($fp);
        }

        $job = new Builder(['gencc_submitter_id' => 'GENCC:000102', 'submitter_organization_name' => 'ClinGen']);
    
        $k = 0;

		// parse the remaining file
        while (($data = fgetcsv($fp)) !== false)
        {
            /*
                0 => gene symbol
                1 => gene hgnc id
                2 => disease label
                3 => disease mondo
                4 => moi
                5 => sop
                6 => classification string
                7 => assertion / report
                8 => classification date
                9 => gcep

            */

            // remove prefix and suffix from assertion id
            if (strpos($data[7], 'https://search.clinicalgenome.org/kb/gene-validity/CGGV:assertion_') === 0)
                $assertion_id = substr($data[7], strlen('https://search.clinicalgenome.org/kb/gene-validity/CGGV:assertion_'), 36);
            else if (strpos($data[7], 'https://search.clinicalgenome.org/kb/gene-validity/CGGCIEX:assertion_') === 0)
            {
                $assertion_id = substr($data[7], strlen('https://search.clinicalgenome.org/kb/gene-validity/CGGCIEX:assertion_'));
            }
            else
            {
                $this->error("Assertion ID error: " . json_encode($data));
                continue;
            }

            // get the HP term for the MOI
            if ($data[4] == "UD")
                $data[4] = "UN";           // Clingen uses undetermined instead of known;
            else if ($data[4] == "MT")
                $data[4] = "MIT";

            $hp = Inheritance::where('abbreviation', $data[4])->first();
            if ($hp === null)
            {
                $this->error("Error mapping moi {$data[4]}");
                continue;
            }

            // map the classification
            $sclass = $data[6];
            switch ($sclass)
            {
                case 'Disputed':
                    $sclass = 'Disputed Evidence';
                    break;
                case 'Refuted':
                    $sclass = 'Refuted Evidence';
                    break;

            }
            $class = Classification::where('name', $sclass)->first();
            if ($class === null)
            {
                $this->error("Error mapping classification {$data[6]}");
                continue;
            }

            $gene = Gene::hgnc_id($data[1])->first();
            if ($gene === null)
            {
				$this->warn("Gene {$data[1]} not found");
				continue;
            }

            // build up the new format
            $d = [
                'submission_id' => $assertion_id,
                'hgnc_id' => $data[1],
                'hgnc_symbol' => $data[0],
                'disease_id' => $data[3],
                'disease_name' => $data[2],
                'moi_id' => $hp->curie,
                'moi_name' => $hp->name,
                'report_date' => $data[8],
                'classification_id' => $class->curie,
                'classification_name' => $class->name,
                'mechanism_id' => null,
                'mechanism_name' => null,
                'criteria_name' => $data[5],
                'assertion_criteria_url' => null,
                'pmids' => '',
                'notes' => null,
                'reason_codes' => ["RECURATION_TIMING"],
                'public_report_url' => $data[7],
                'primary_contributor_group_name' => $data[9]
                ];

            $job->addSubmission($d);

            $k++;

            if ($k > 100)
                break;

        }

        $job->process();

        fclose($fp);


    }
}
