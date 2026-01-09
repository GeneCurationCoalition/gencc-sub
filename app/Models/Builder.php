<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Http;
use Jenssegers\Model\Model;

use Carbon\Carbon;

use App\Models\Nodal;

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
 * Builder is a tableless model that allows multi-submission schemas to be
 * built and processed within the traditional scope and syntax as any other
 * Laravel model.
 *
 * */
class Builder extends Model
{
    use HasFactory;

    /**
     * Automatically assign an ident and created_at timestamp on instantiation
     *
     * @param	array	$attributes
     * @return 	void
     */
    public function __construct(array $attributes = array())
    {
      $attributes['created_at'] = Carbon::now();
      $attributes['data'] = collect();
      parent::__construct($attributes);
    }


    /**
     * Create a new subbmission schema from the data provided
     *
     * @param	array	$data
     * @return 	void
     */
    public function addSubmission($data)
    {

      // create a submission data object
      $node = new Nodal();

      $node->action = $data['action'];
      $node->submission_id = $data['submission_id'] ?? '';
      $node->submission_label = $data['submission_label'] ?? '';
      $node->hgnc_id = $data['hgnc_id'];
      $node->gene_symbol = $data['hgnc_symbol'];
      $node->mondo_id = $data['disease_id'];
      $node->disease_name = $data['disease_name'];
      $node->hp_id = $data['moi_id'];
      $node->moi_name = $data['moi_name'];
      $node->mechanism_id = null;
      $node->mechanism_name = null;
      $node->mechanism_comment = null;
      $node->workflow_dates = $data['workflow_dates'] ?? [];
      $node->publish_date = $data['report_date'];
      $node->report_date = $data['report_date'];
      $node->report_url = $data['public_report_url'];
      $node->gencc_classification_id = $data['classification_id'];
      $node->gencc_classification_name = $data['classification_name'];
      $node->criteria_url = $data['assertion_criteria_url'];
      $node->criteria_name = $data['criteria_name'] ?? '';
      $node->evidence_items = (is_array($data['pmids']) ? $data['pmids'] : explode(',', $data['pmids']));
      $node->notes_display = $data['notes'];
      $node->notes_private = "Imported from ClinGen";
      $node->version_display = $data['$node->version_display'] ?? "1.1";
      $node->version_internal = $data['version_internal'] ?? "1.1.0.0";
      $node->reason_codes = $data['reason_codes'] ?? [];
      $node->version_description = "Periodic bulk submission from clinicalgenome.org";
      $node->primary_contributor_id = "";
      $node->primary_contributor_group_name = $data['primary_contributor_group_name'];
      
      $this->data->push($node);
    }


    /**
     * Process the saved submissions and submit via the api
     *
     * @param	array	$attributes
     * @return 	json
     */
    public function process($run = true)
    {
      $server = env('SUBMIT_URL', false);

      if ($server)
      {
        if (!$run)
          $server .= '/check';

        $template = view('json.submission')->with('job', $this);

       // dd($template->render());

        // post packet
        $response = Http::withHeaders([
            'GEN-API-KEY' => env('GENCC_PUBLISH_TOKEN')
        ])->withBody(
            $template->render(), 'application/json; charset=UTF8'
        )->post($server);

        return $response->json();
      }

      return([]);

    }

}
