<?php

namespace App\Console\Commands\Pubmed;

use Illuminate\Console\Command;

use App\Pmid;
use App\Gene;

class PastPubmed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pubmed:past';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
		echo "Importing already reviewed PMID information ...\n";

		$handle = fopen(base_path() . '/data/oldpmids.csv', "r");
        if ($handle)
        {
            // discard header
            $line = fgets($handle);

            while (($line = fgets($handle)) !== false)
            {




                $data = str_getcsv($line);

                /*
                    0 => pmid
                    1 => reviewed (yes/No)
                    2 => curated (yes/No)
                    3 => note
                */

                $pmid = Pmid::pmid(trim($data[0]))->first();

                if ($pmid === null)
                {
                    echo "PMID " . $data[0] . " not found\n";
                    continue;
                }
                else
                {
                    echo "PMID " . $data[0] . " FOUND!\n";
                }

                //$pmid->save();
            }
        }
    }
}
