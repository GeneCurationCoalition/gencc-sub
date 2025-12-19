<?php

namespace App\Console\Commands\Pubmed;

use Illuminate\Console\Command;

use App\Query;
use App\Pmid;
use App\Task;

use Setting;
use Carbon\Carbon;

class FixPubmed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pubmed:fix';

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
        // reset lastquery back to 7/1/2021
        Setting::set('pubmed-lastquery', '2020-07-01');
        Setting::save();

        // calculate number of days since 7/1/2021
        $start = new Carbon('2020-07-01');
        $now = Carbon::now();

        $days = $start->diffInDays($now);

        // remove all unlinked PMIDs
        PMID::whereIn('status', [0, 1, 20, 21])->delete();

        // run the new query
        $this->call('pubmed:check');
        $this->call('pubmed:sync'); // Replaced pubmed:query and pubmed:efetch

        // process the 5/17/2021 list
        echo "Processing 5/17/2021 data ...\n";

        $fp = fopen(base_path() . '/data/pmids_curation_status_20210517.csv', "r");

		if ($fp === false)
		{
			die("Error processing 5/17 data");
		}

        // parse the headers
        $keys = fgetcsv($fp);
        /*
            $data[0] = PMID
            $data[1] = Reviewed
            $data[2] = Curated
            $data[3] = Note / Handle
        */

		// parse the data
        while (($data = fgetcsv($fp)) !== false)
        {
            $pmid = PMID::pmid(trim($data[0]))->first();

            if ($pmid === null)
            {
                echo "Adding new pmid " . $data[0] . "\n";

				$pmid = Pmid::firstOrCreate(['pmid' => (int) trim($data[0]), 'uid' => (int) trim($data[0])],
									[ 'status' => 20, 'task_id' => null, 'notes' => 'Included in 05/17/2021 backfill list']);

                $this->call('pubmed:sync'); // Replaced pubmed:query and pubmed:efetch

            }

            $curation_status = PMID::STATUS_NEW;
            $priority = $pmid->priority;

            switch (trim($data[3]))
            {
                case "doesn't qualify":
                case "doesn’t qualify":
                    $curation_status = PMID::STATUS_NA;
                    $priority = 0;
                    break;
                case "no access; relevant":
                    $curation_status = PMID::STATUS_LIBRARY;
                    $priority = 1;
                    break;
                case "no access":
                    $curation_status = PMID::STATUS_LIBRARY;
                    break;
                default:
                    $curation_status = PMID::STATUS_COMPLETED;
                    $priority = 0;
            }

            $pmid->update(['status' => $curation_status, 'user_id' => 4, 'priority' => $priority]);

            echo "Updated pmid " . $data[0] . " to $curation_status \n";

        }

        // now load the Jul to Dec list in case we missed any
        $fp = fopen(base_path() . '/data/PMIDs_July_Dec2020.csv', "r");

		if ($fp === false)
		{
			die("Error processing July to December data");
		}

        // parse the headers
        $keys = fgetcsv($fp);
        /*
            $data[0] = PMID
        */

        while (($data = fgetcsv($fp)) !== false)
        {
			$pubmed = Pmid::where('pmid', trim($data[0]))->first();

			if ($pubmed === null)
			{
				// We only populate the new PMIDs, we do not gather publication details in this command
				Pmid::firstOrCreate(['pmid' => (int) trim($data[0]), 'uid' => (int) trim($data[0])],
									[ 'status' => 20, 'task_id' => null]);
			}
		}

        $this->call('pubmed:sync'); // Replaced pubmed:query and pubmed:efetch

        echo "DONE\n";
    }
}
