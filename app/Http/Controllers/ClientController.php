<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;


use Carbon\Carbon;

use App\Models\Nodal;
use App\Models\Builder;
use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Gene;

/**
 *
 * @category   Controller
 * @package    GenCC
 * @author     P. Weller <pweller1@geisinger.edu>
 * @copyright  2024 Geisinger, GenCC, ClinGen
 * @license    
 * @version    Release: @package_version@
 * @link       
 * @see        
 * @since      Class available since Release 1.0.0
 * 
 * ClientController is a vehicle for testing the various API Submit queries.  It is not actually used by
 * the application, except through temporary test routes.  
 *
 * */
class ClientController extends Controller
{
    protected $server = "http://gencc-sub.local/api/submit";
    

    /**
     * Test all queries
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_all(Request $request)
    {
        //$this->test_job($request);
        //$this->test_status($request);
        //$this->test_job_status($request);
        //$this->test_check($request);
        //$this->test_submission($request);
        //$this->test_remove($request);
        $this->test_job_remove($request);
    }


    /**
     * Test a full job submission by importing the 
     * entire ClinGen set.
     * 
     */
    public function test_job(Request $request)
    {

        // open import file
        $fp = fopen(base_path() . '/data/Clingen-Gene-Disease-Summary.csv', 'r');
		if ($fp === false)
		{
			die("Error opening ClinGen import file");
		}

        // skip over the header section
        for ($n = 0; $n < 6; $n++)
        {
            $data = fgetcsv($fp);
        }

        $job = new Builder(['gencc_submitter_id' => 'GENCC:000102', 'submitter_organization_name' => 'ClinGen']);
    
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
                echo "Assertion ID error\n";
                dd($data);
            }

            // get the HP term for the MOI
            if ($data[4] == "UD")
                $data[4] = "UN";           // Clingen uses undetermined instead of known;
            else if ($data[4] == "MT")
                $data[4] = "MIT";

            $hp = Inheritance::where('abbreviation', $data[4])->first();
            if ($hp === null)
            {
                echo "Error mapping moi $data[4] \n";
                dd($data);
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
                echo "Error mapping classification $data[6] \n";
                dd($data);
            }

            $gene = Gene::hgnc_id($data[1])->first();
            if ($gene === null)
            {
				echo "Gene " . $data[1] . " not found\n";
				continue;
            }

            // build up the new format
            $d = [
                "action" => "create",
                'submission_label' => $assertion_id,
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
                "mechanism_comment" => "",
                'assertion_criteria_url' => null,
                'pmids' => '',
                'notes' => null,
                'reason_codes' => ["RECURATION_TIMING"],
                'public_report_url' => $data[7],
                'primary_contributor_group_name' => $data[9]
                ];

            $job->addSubmission($d);

        }

        $response = $job->process();

        fclose($fp);

        dd($response);


    }



    /**
     * Test a ping/check of the system.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_check(Request $request)
    {
        $job = new Builder(['gencc_submitter_id' => 'GENCC:000102', 'submitter_organization_name' => 'ClinGen']);

        // build up the new format
        $d = [
            'action' => "check",
            'submission_id' => "",
            'submission_label' => "325ca62f-064b-4013-978b-6f9400a9ae74",
            'hgnc_id' => "HGNC:26182",
            'hgnc_symbol' => "COLGALT1",
            'disease_id' => "MONDO:0100105",
            'disease_name' => "brain small vessel disease 3",
            'moi_id' => "HP:0000007",
            'moi_name' => "Autosomal recessive inheritance",
            'workflow_dates' => ['approval date' => "2020-12-12 15:27:20"],
            'report_date' => Carbon::now(),
            'classification_id' => "GENCC:100001",
            'classification_name' => "Definitive",
            'mechanism_id' => "GENCC:200001",
            'mechanism_name' => "Gain of Function",
            'criteria_name' => "ClinGen SOP10",
            'assertion_criteria_url' => "https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-10/",
            'pmids' => [21396583, 23379592, 25795793, 29469822],
            'notes' => null,
            'reason_codes' => ["RECURATION_TIMING"],
            'public_report_url' => "https://search.thegencc.org/genes/HGNC:13666",
            'primary_contributor_group_name' => "ClinGen Gene Curtion WG"
            ];

        $job->addSubmission($d);

        $response = $job->process(false);

        dd($response);
    }


    /**
     * Test a submission into the system.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_submission(Request $request)
    {
        // create a new job object
        $job = new Nodal();
        $job->created_at = Carbon::now();
        $job->gencc_submitter_id = "GENCC:000102";
        $job->submitter_organization_name = "ClinGen";


        // create a submission data object
        $data = new Nodal();
        $data->created_at = Carbon::now();
        $data->submission_label = "325ca62f-064b-4013-978b-6f9400a9ae74";
        $data->local_key = "12345";
        $data->uuid = "GENCC_000101-HGNC_26182-MONDO_0100105-HP_0000007-GENCC_100003";
        $data->hgnc_id = "HGNC:26182";
        $data->gene_symbol = "COLGALT1";
        $data->mondo_id = "MONDO:0100105";
        $data->disease_name = "brain small vessel disease 3";
        $data->hp_id = "HP:0000007";
        $data->moi_name = "Autosomal recessive inheritance";
        $data->workflow_dates = ['approval date' => "2020-12-12 15:27:20"];
        $data->publish_date = Carbon::now();
        $data->report_date = Carbon::now();
        $data->report_url = "https://search.thegencc.org/genes/HGNC:13666";
        $data->gencc_classification_id = "GENCC:100001";
        $data->gencc_classification_name = "Definitive";
        $data->gencc_mechanism_id = "GENCC:200001";
        $data->gencc_mechanism_name = "Gain of Function";
        $data->criteria_name = "ClinGen SOP10";
        $data->asserttion_criteria_url = "https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-10/";
        $data->evidence_items = [21396583, 23379592, 25795793, 29469822];
        $data->notes_display = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer venenatis, odio consectetur condimentum ornare, mi neque porttitor massa, quis iaculis nisl ligula eu neque. Etiam ac egestas quam, vel rutrum leo. In a aliquet augue. Integer lobortis tincidunt facilisis. Donec ut vestibulum nunc. Cras vel gravida nisi, non venenatis turpis. Morbi faucibus, nisi a fermentum vulputate, diam ligula rutrum tellus, eu mattis nunc massa a nunc.";
        $data->notes_private = "Pellentesque mollis quam quis feugiat facilisis. Vivamus eu interdum arcu. Maecenas maximus egestas vehicula. Nullam nec nisl lobortis, eleifend lacus a, ultricies elit. Fusce vehicula dui quis neque tempor, et porta sem sollicitudin. Etiam eget felis non tortor lacinia volutpat ac a massa. Ut eget metus at nulla ultrices tincidunt. Phasellus at finibus ligula.";
        $data->version_display = "2.1";
        $data->version_internal = "2.1.3.4";
        $data->reason_codes = ["NEW_EVIDENCE", "NEW_CLASSIFICATION"];
        $data->version_description = "Suspendisse potenti. Duis et dapibus risus. Curabitur malesuada enim risus, et pretium eros venenatis a. Aliquam ullamcorper sapien sit amet massa luctus, vitae fermentum magna blandit. Aenean sit amet lacus quam. Nam commodo risus a dolor tristique aliquet. Proin molestie maximus faucibus. Vestibulum tellus nibh, egestas auctor congue at, finibus consequat odio. Donec luctus consequat augue vel blandit. Sed in ultricies ligula. Donec a libero turpis.";
        $data->gencc_submitter_id = "GENCC:000102";
        $data->submitter_organization_name = "ClinGen";
        $data->primary_contributor_id = "GCID:10004";
        $data->primary_contributor_group_name = "ClinGen Gene Curtion WG";

        $job->data = [$data];

		// load the template
        $template = view('json.submission')->with('job', $job);

        // post packet
        $response = Http::withHeaders([
            'GEN-API-KEY' => env('GENCC_PUBLISH_TOKEN')
        ])->withBody(
            $template->render(), 'application/json; charset=UTF8'
        )->post($this->server);

        $reply = $response->body();
        dd($reply);

		return $reply;
    }


    /**
     * Test a status query into the system.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_status(Request $request)
    {
        $server = "http://gencc-sub.local/api/status/SGC-102410";
        // set a sid for query
        $sid = "SGC-102410";

        // post packet
        $response = Http::acceptJson()->withHeaders([
            'GEN-API-KEY' => env('GENCC_PUBLISH_TOKEN')
        ])->get($server);
        //dd($response->body());
dd($response->json());
        //$reply = (string) $response->body();
        //\Response::make($response->body(), '200')->header('Content-Type', 'application/json');

        //return response([
        //    'status' => 'success'
        //], 200, ['Content-Type => application/json']);
		//return $reply;
    }


    /**
     * Test a status query into the system.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_job_status(Request $request)
    {
        // set a sid for query
        $server = "http://gencc-sub.local/api/status/job/J-100003";

        // post packet
        $response = Http::acceptJson()->withHeaders([
            'GEN-API-KEY' => env('GENCC_PUBLISH_TOKEN')
        ])->get($server);
        //dd($response->body());
dd($response->json());
        //$reply = (string) $response->body();
        //\Response::make($response->body(), '200')->header('Content-Type', 'application/json');

        //return response([
        //    'status' => 'success'
        //], 200, ['Content-Type => application/json']);
		//return $reply;
    }


    /**
     * Test a remove request into the system.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_remove(Request $request)
    {
        $server = "http://gencc-sub.local/api/remove/SGC-102410";
        // set a sid for query
        // post packet
        $response = Http::withHeaders([
            'GEN-API-KEY' => env('GENCC_PUBLISH_TOKEN')
        ])->get($server);

        //$reply = (string) $response->body();
	dd($response->json());	 
		//return $reply;
    }


    /**
     * Test a remove request into the system.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function test_job_remove(Request $request)
    {
        $server = "http://gencc-sub.local/api/remove/job/J-100004";
        // set a sid for query
        $sid = "J1332892f-bf31-4b67-8507-2052679ca183";

        // post packet
        $response = Http::withHeaders([
            'GEN-API-KEY' => env('GENCC_PUBLISH_TOKEN')
        ])->get($server);

        //$reply = (string) $response->body();
	dd($response->json());	 
		//return $reply;
    }

}
