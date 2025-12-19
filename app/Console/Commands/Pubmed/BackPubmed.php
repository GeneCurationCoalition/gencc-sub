<?php

namespace App\Console\Commands\Pubmed;

use Illuminate\Console\Command;

use App\Curation;
use App\Pmid;
use App\Task;

class BackPubmed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pubmed:back';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backload pubmed IDs for older curations';

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
		// gather up all the curation pubmed IDs that are absent from the system
        $curations = Curation::all();
       
        foreach($curations as $curation)
        {
			$pubmed = Pmid::where('pmid', $curation->pmid)->first();
			
			if ($pubmed === null)
			{			
				if (((int) trim($curation->pmid)) == 0 && bin2hex($curation->pmid[0]) == "c2")
				{
					$curation->pmid = substr($curation->pmid, 2);
					$curation->save();
				}
				
				// We only populate the new PMIDs, we do not gather publication details in this command
				Pmid::firstOrCreate(['pmid' => (int) trim($curation->pmid), 'uid' => (int) trim($curation->pmid)],
									[ 'status' => 20, 'task_id' => null]);
			}
		}
    }
}
