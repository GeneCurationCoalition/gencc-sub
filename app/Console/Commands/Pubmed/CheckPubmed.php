<?php

namespace App\Console\Commands\Pubmed;

use Illuminate\Console\Command;

use App\Query;
use App\Pmid;
use App\Task;

use Setting;
use Carbon\Carbon;

class CheckPubmed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pubmed:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Pubmed for IDs relevant to search terms';

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
      // calculate the days since the last update
      $last = Setting::get('pubmed-lastquery', false);
      if ($last === false)
        $last = '2020-07-01';

      $start = new Carbon($last);
      $now = Carbon::now();

      $days = $start->diffInDays($now);

      $api_key = env('NCBI_API_KEY');

      // set up search array
      $parms = ['db' => 'pubmed', 'term' => '',
                'retstart' => 0,
                'retmax' => 1000,
                'datetype' => 'dp',
                //'mindate' => '2021/07/01',  // pubmeds since this date
                //'maxdate' => '2021/08/01',  // pubmeds until this date
                'reldate' => $days + 1,        // only past number of days
			    'retmode' => 'json'];

      // Add API key if available (improves rate limits from 3 to 10 requests/second)
      if ($api_key) {
          $parms['api_key'] = $api_key;
      }

        // Get the query strings
        $querys = Query::all();

        $status = true;

        foreach($querys as $query)
        {
            echo "Querying " . $query->parameters['term'] . " \n";

            $count = 1;
            $retstart = 0;

          while ($retstart < $count)
          {
            $task = new Task();
            $query->tasks()->save($task);

            $parms['term'] = $query->parameters['term'];
            $parms['retstart'] = $retstart;
            $encoded_parms = http_build_query($parms);
//dd($encoded_parms);
            echo "Searching start = $retstart of count = $count \n";

            $results = file_get_contents('http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?' . $encoded_parms);
            if ($results === false)
            {
              // error getting the query results
              $task->update(['status' => 9]);
              $status = false;
              break;
            }

            $task->term_response = $results;
            $task->status = 1;
            $task->save();
            $json = json_decode($results);

            //dd($json->esearchresult);

            $count = (int) $json->esearchresult->count;

            echo "Query count is $count \n";

            // We only populate the new PMIDs, we do not gather publication details in this command
            foreach ($json->esearchresult->idlist as $pmid)
            {
              Pmid::firstOrCreate(['pmid' => $pmid, 'uid' => $pmid],
                        [ 'status' => 20, 'task_id' => $task->id]);
            }

            $retstart += (int) $json->esearchresult->retmax;
          }
        }

        if ($status)
        {
            Setting::set('pubmed-lastquery', $now->format('Y-m-d'));
            Setting::save();
        }
    }
}
