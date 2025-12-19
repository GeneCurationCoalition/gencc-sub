<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateSources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:sources {schedule=daily}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Master command to update all sources based on schedule';

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
      $schedule = $this->argument('schedule');

      switch ($schedule)
      {
        case 'daily':
          $this->call('update:genes');       // Update gene data
          $this->call('update:diseases');    // Update disease data
          break;
        case 'weekly':
          $this->call('run:publish');       // Publish available jobs to GenCC
          break;
        case 'monthly':
          break;
        case 'init':
          //$this->call('update:acmg59c');     // local file
          break;

      }

    }
}
