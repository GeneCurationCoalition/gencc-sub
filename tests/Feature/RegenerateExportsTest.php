<?php

namespace Tests\Feature;

use App\Console\Commands\GenccRelease;
use App\Models\Classification;
use App\Models\Disease;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Job;
use App\Models\Release;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class RegenerateExportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_regenerate_exports_writes_files_without_release_or_job_processing(): void
    {
        $this->makeLivePublishedSubmission();
        $pendingJob = $this->makePendingJob();

        $this->assertSame(0, Release::count());

        $this->artisan('gencc:release', ['arg' => 'regenerate-exports', '--no-interaction' => true])
            ->expectsOutputToContain('GCS is not configured. Export files will be stored locally.')
            ->assertExitCode(0);

        // All six export files exist and are non-empty.
        foreach (['current', 'legacy'] as $folder) {
            foreach (['csv', 'tsv', 'xlsx'] as $format) {
                $path = storage_path("app/public/{$folder}/{$format}/gencc-submissions.{$format}");
                $this->assertTrue(File::exists($path), "Missing export: {$folder}/{$format}");
                $this->assertGreaterThan(0, File::size($path), "Empty export: {$folder}/{$format}");
            }
        }

        // No release record was created.
        $this->assertSame(0, Release::count());

        // The pending job was not processed.
        $pendingJob->refresh();
        $this->assertSame(Job::STATUS_SUBMITTED, $pendingJob->status);
    }

    public function test_regenerate_exports_writes_empty_files_when_no_live_published_submissions(): void
    {
        $this->assertSame(0, Submission::where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)->count());

        $this->artisan('gencc:release', ['arg' => 'regenerate-exports', '--no-interaction' => true])
            ->expectsOutputToContain('No live published submissions; writing empty export files.')
            ->assertExitCode(0);

        foreach (['current', 'legacy'] as $folder) {
            foreach (['csv', 'tsv', 'xlsx'] as $format) {
                $path = storage_path("app/public/{$folder}/{$format}/gencc-submissions.{$format}");
                $this->assertTrue(File::exists($path), "Missing export: {$folder}/{$format}");
                $this->assertGreaterThan(0, File::size($path), "Invalid export: {$folder}/{$format}");
            }
        }

        $this->assertSame(0, Release::count());
    }

    public function test_regenerate_exports_fails_when_configured_gcs_upload_fails(): void
    {
        $this->makeLivePublishedSubmission();
        config()->set('filesystems.disks.gcs.bucket', 'configured-test-bucket');

        $command = new class extends GenccRelease
        {
            public function useFailingGcsBucket(): void
            {
                $this->gcsBucket = new class
                {
                    public function upload(): void
                    {
                        throw new \RuntimeException('simulated upload failure');
                    }
                };
            }
        };
        $command->setLaravel($this->app);
        $command->useFailingGcsBucket();

        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput(['arg' => 'regenerate-exports']), $output);

        $this->assertSame(1, $exitCode, $output->fetch());
    }

    private function makeLivePublishedSubmission(): Submission
    {
        $submitter = Submitter::create([
            'name' => 'Test Submitter',
            'status' => Submitter::STATUS_ACTIVE,
            'type' => Submitter::TYPE_SUBMITTER,
        ]);
        $user = User::factory()->create(['submitter_id' => $submitter->id]);
        $job = Job::create([
            'user_id' => $user->id,
            'submitter_id' => $submitter->id,
            'status' => Job::STATUS_RELEASED,
            'type' => Job::TYPE_NONE,
        ]);
        $gene = Gene::create([
            'hgnc_id' => 'HGNC:5',
            'symbol' => 'A1BG',
            'name' => 'alpha-1-B glycoprotein',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'location' => '19q13.43',
            'status' => Gene::STATUS_ACTIVE,
        ]);
        $disease = Disease::create([
            'curie' => 'MONDO:0000001',
            'name' => 'canonical disease label',
            'status' => Disease::STATUS_ACTIVE,
        ]);
        $inheritance = Inheritance::create([
            'curie' => 'HP:0000006',
            'name' => 'Autosomal dominant',
            'description' => 'Autosomal dominant inheritance',
            'abbreviation' => 'AD',
            'type' => Inheritance::TYPE_MOI,
            'status' => Inheritance::STATUS_ACTIVE,
        ]);
        $classification = Classification::create([
            'curie' => 'GENCC:100001',
            'name' => 'Definitive',
            'description' => 'Definitive classification',
            'abbreviation' => 'DEF',
            'type' => Classification::TYPE_CLASSIFICATION,
            'status' => Classification::STATUS_ACTIVE,
        ]);

        $data = [
            'submission_id' => null,
            'local_key' => null,
            'submission_label' => null,
            'gene' => ['id' => 'HGNC:5', 'symbol' => 'A1BG'],
            'disease' => ['id' => 'MONDO:0000001', 'name' => 'canonical disease label'],
            'moi' => ['id' => 'HP:0000006', 'name' => 'Autosomal dominant'],
            'classification' => ['id' => 'GENCC:100001', 'name' => 'Definitive'],
            'additional_information' => [
                'submitter_curie' => $submitter->curie,
                'submitter_title' => 'Test Submitter',
                'submitted_as_submission_id' => 'LOCAL-1',
            ],
            'notes' => ['display' => 'Public note', 'private' => 'Private note'],
        ];

        return Submission::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'submitter_id' => $submitter->id,
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'inheritance_id' => $inheritance->id,
            'classification_id' => $classification->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
            'friendly' => 'Portal submission',
            'local_key' => 'LOCAL-1',
            'type' => Submission::TYPE_PORTAL_SUBMISSION,
            'submission_data' => $data,
            'original_submission_data' => $data,
        ]);
    }

    private function makePendingJob(): Job
    {
        $submitter = Submitter::create([
            'name' => 'Pending Submitter',
            'status' => Submitter::STATUS_ACTIVE,
            'type' => Submitter::TYPE_SUBMITTER,
        ]);
        $user = User::factory()->create(['submitter_id' => $submitter->id]);

        return Job::create([
            'user_id' => $user->id,
            'submitter_id' => $submitter->id,
            'status' => Job::STATUS_SUBMITTED,
            'type' => Job::TYPE_NONE,
        ]);
    }
}
