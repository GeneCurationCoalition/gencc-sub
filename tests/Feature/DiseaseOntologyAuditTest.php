<?php

namespace Tests\Feature;

use App\Models\AdminLog;
use App\Models\Classification;
use App\Models\Disease;
use App\Models\DiseaseAuditFinding;
use App\Models\DiseaseAuditRun;
use App\Models\Gene;
use App\Models\StaticFileHeader;
use App\Models\Submission;
use App\Models\Team;
use App\Models\User;
use App\Services\AdminProgressTracker;
use App\Services\DiseaseOntologyAudit;
use App\Services\DiseaseOntologyLock;
use App\Services\DiseaseOntologySources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\SeedsDiseaseWorld;
use Tests\TestCase;

class DiseaseOntologyAuditTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiseaseWorld;

    private array $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Gene::factory()->create();
        Classification::factory()->create();
        $this->world = self::seedDiseaseWorld();
    }

    private function submission(mixed $curie, ?string $stored = 'mondo_plain', array $attributes = []): Submission
    {
        $original = is_string($curie) ? Disease::where('curie', Disease::normalizeCurie($curie))->first() : null;

        return Submission::factory()->create(array_merge([
            'disease_id' => $stored ? $this->world[$stored]->id : null,
            'original_disease_id' => $original?->id,
            'submission_data' => ['disease' => ['id' => $curie]],
            'is_most_recent' => true,
        ], $attributes));
    }

    public function test_replacement_evidence_is_snapshotted_and_old_scan_filters_are_unavailable(): void
    {
        $old = $this->world['mondo_deprecated'];
        $old->update(['xrefs' => ['replaced_by' => [$this->world['mondo_plain']->curie]]]);
        $submission = $this->submission($old->curie, 'mondo_deprecated');
        $run = app(DiseaseOntologyAudit::class)->run();
        $finding = DiseaseAuditFinding::where('submission_id', $submission->id)->firstOrFail();
        $this->assertContains('replacement_available', $finding->cases);
        $this->assertCount(1, $finding->evidence['replacements']);
        $this->assertContains('submitted term', $finding->evidence['replacements'][0]['contexts']);
        $old->update(['xrefs' => ['replaced_by' => []]]);
        $this->assertNotEmpty($finding->fresh()->evidence['replacements'][0]['targets']);
        $admin = User::factory()->create();
        $team = Team::create(['user_id' => $admin->id, 'name' => 'admin', 'personal_team' => false]);
        $admin->teams()->attach($team);
        $this->actingAs($admin);
        $this->get('/admin/disease-ontology-audit?run='.$run->id.'&case=replacement_needs_review')->assertOk();
        $counts = $run->case_counts;
        unset($counts['replacement_available']);
        $run->update(['case_counts' => $counts]);
        foreach (['/admin/disease-ontology-audit', '/admin/disease-ontology-audit/export'] as $path) {
            $this->get($path.'?run='.$run->id.'&case=replacement_available')->assertStatus(422);
        }
    }

    public function test_scan_classifies_mappings_without_mutating_submissions_and_is_repeatable(): void
    {
        $changed = $this->submission('OMIM:600001');
        $unresolved = $this->submission('Orphanet:700200');
        $ambiguous = $this->submission('ORPHA:700777');
        $missing = $this->submission('OMIM:600004', 'mondo_exact');
        $nowValid = $this->submission('OMIM:600001', null);
        $still = $this->submission('OMIM:999999', null);
        $malformed = $this->submission(['unexpected' => 'object'], null);
        $deprecated = $this->submission('MONDO:0000004', 'mondo_deprecated');
        $inconsistent = $this->submission('MONDO:0000001', 'mondo_trashed');
        $clean = $this->submission('ORPHA:700001', 'mondo_exact');
        $this->world['orpha_unmapped']->update(['status' => Disease::STATUS_DEPRECATED]);
        $existing = $this->submission('Orphanet:700200', 'orpha_unmapped', [
            'submission_errors' => ['disease_curie_id' => 'Existing placeholder error'],
        ]);
        $before = DB::table('submissions')->orderBy('id')->get()->toJson();

        $run = app(DiseaseOntologyAudit::class)->run();
        $findings = DiseaseAuditFinding::where('run_id', $run->id)->get()->keyBy('submission_id');
        foreach ([
            [$changed, 'different_target'], [$unresolved, 'no_longer_resolves'],
            [$ambiguous, 'ambiguous'], [$missing, 'original_missing'], [$nowValid, 'now_valid'],
            [$still, 'still_unresolved'], [$malformed, 'invalid_identifier'],
            [$deprecated, 'submitted_deprecated'], [$deprecated, 'stored_deprecated'],
            [$deprecated, 'candidate_deprecated'], [$inconsistent, 'inconsistent_references'],
            [$existing, 'existing_disease_error'], [$unresolved, 'submitted_deprecated'],
        ] as [$submission, $case]) {
            $this->assertContains($case, $findings[$submission->id]->cases);
        }
        $this->assertFalse($findings[$missing->id]->evidence['accepted']);
        $this->assertTrue($findings[$deprecated->id]->evidence['accepted']);
        $this->assertCount(2, $findings[$ambiguous->id]->evidence['candidates']);
        $this->assertArrayNotHasKey($clean->id, $findings);
        $this->assertEquals(11, $run->examined_count);
        $this->assertEquals(10, $run->affected_count);
        $this->assertSame($before, DB::table('submissions')->orderBy('id')->get()->toJson());

        $second = app(DiseaseOntologyAudit::class)->run();
        $this->assertSame($run->case_counts, $second->case_counts);
        $this->assertSame(
            $findings->map->only(['submission_id', 'cases', 'evidence'])->values()->all(),
            DiseaseAuditFinding::where('run_id', $second->id)->get()->map->only(['submission_id', 'cases', 'evidence'])->all()
        );
    }

    public function test_absent_replacements_are_distinct_from_unusable_or_unparsed_assertions(): void
    {
        $old = $this->world['mondo_deprecated'];
        $submission = $this->submission($old->curie, 'mondo_deprecated');
        foreach ([
            ['replaced_by' => [], 'case' => 'replacement_not_recorded'],
            ['replaced_by' => ['MONDO:9999999'], 'case' => 'replacement_needs_review'],
            ['replaced_by' => [], 'replacement_parse_status' => 'unrecognized', 'case' => 'replacement_needs_review'],
        ] as $metadata) {
            $expected = $metadata['case'];
            unset($metadata['case']);
            $old->update(['xrefs' => $metadata]);
            $run = app(DiseaseOntologyAudit::class)->run();
            $finding = DiseaseAuditFinding::where('run_id', $run->id)->where('submission_id', $submission->id)->firstOrFail();
            $this->assertContains($expected, $finding->cases);
            $this->assertNotContains($expected === 'replacement_not_recorded' ? 'replacement_needs_review' : 'replacement_not_recorded', $finding->cases);
            $this->assertArrayHasKey('replacement_not_recorded', $run->case_counts);
        }
    }

    public function test_scope_keeps_live_and_pending_versions_but_excludes_archived_and_deleted(): void
    {
        $live = $this->submission('OMIM:600001', 'mondo_plain', [
            'status' => Submission::STATUS_PUBLISHED, 'is_live' => true, 'is_most_recent' => false,
        ]);
        $pending = $this->submission('OMIM:600001', 'mondo_exact', ['sid' => $live->sid, 'version_number' => 2]);
        $unpublished = $this->submission('OMIM:600001', 'mondo_plain', [
            'status' => Submission::STATUS_UNPUBLISHED, 'is_live' => true,
        ]);
        $this->submission('OMIM:600001', 'mondo_plain', ['is_live' => false, 'is_most_recent' => false]);
        $this->submission('OMIM:600001')->delete();

        $run = app(DiseaseOntologyAudit::class)->run();
        $this->assertEquals(3, $run->examined_count);
        $this->assertEquals(2, $run->affected_count);
        $this->assertEqualsCanonicalizing([$live->id, $unpublished->id], DiseaseAuditFinding::pluck('submission_id')->all());
        $this->assertDatabaseMissing('disease_audit_findings', ['submission_id' => $pending->id]);
    }

    public function test_failure_discards_partial_results_and_preserves_successful_run(): void
    {
        $this->submission('OMIM:600001');
        $successful = app(DiseaseOntologyAudit::class)->run();
        DiseaseAuditFinding::creating(function () {
            throw new RuntimeException('Injected write failure');
        });
        try {
            app(DiseaseOntologyAudit::class)->run();
            $this->fail('Expected scan failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected write failure', $e->getMessage());
        } finally {
            DiseaseAuditFinding::flushEventListeners();
        }
        $this->assertEquals($successful->id, DiseaseAuditRun::where('status', DiseaseAuditRun::SUCCEEDED)->sole()->id);
        $this->assertEquals(DiseaseAuditRun::FAILED, DiseaseAuditRun::orderByDesc('id')->first()->status);
        $this->assertSame(1, DiseaseAuditFinding::count());
        $this->assertEquals(DiseaseAuditRun::SUCCEEDED, app(DiseaseOntologyAudit::class)->run()->status);
    }

    public function test_scan_is_refused_during_a_disease_update_or_another_scan(): void
    {
        foreach (['forImport', 'forAudit'] as $holder) {
            $lock = DiseaseOntologyLock::$holder();
            try {
                $this->artisan('audit:disease-ontologies')->assertFailed();
                $this->assertSame(0, DiseaseAuditRun::count());
                $this->assertSame('failed', AdminProgressTracker::get(AdminLog::OP_AUDIT_DISEASES)['status']);
            } finally {
                DiseaseOntologyLock::release($lock);
            }
        }
        $this->artisan('audit:disease-ontologies')->assertSuccessful();
        $this->assertSame('complete', AdminProgressTracker::get(AdminLog::OP_AUDIT_DISEASES)['status']);
    }

    public function test_successful_scan_keeps_only_the_most_recent_successful_runs(): void
    {
        $this->submission('MONDO:0000001', 'mondo_deprecated');
        $audit = app(DiseaseOntologyAudit::class);
        $first = $audit->run();
        $failed = DiseaseAuditRun::create(['status' => DiseaseAuditRun::FAILED, 'started_at' => now(), 'scope' => 'test']);
        for ($i = 1; $i < DiseaseOntologyAudit::KEEP_SUCCEEDED_RUNS; $i++) {
            $audit->run();
        }
        $this->assertTrue(DiseaseAuditRun::whereKey([$first->id, $failed->id])->count() === 2);

        $latest = $audit->run();

        $this->assertSame(0, DiseaseAuditRun::whereKey([$first->id, $failed->id])->count());
        $this->assertSame(0, DiseaseAuditFinding::where('run_id', $first->id)->count());
        $this->assertSame(DiseaseOntologyAudit::KEEP_SUCCEEDED_RUNS, DiseaseAuditRun::where('status', DiseaseAuditRun::SUCCEEDED)->count());
        $this->assertTrue(DiseaseAuditFinding::where('run_id', $latest->id)->exists());
    }

    public function test_page_warns_when_disease_sources_were_imported_after_the_scan(): void
    {
        $header = fn ($etag) => StaticFileHeader::create(['file_identifier' => DiseaseOntologySources::MONDO,
            'etag' => $etag, 'last_modified' => null, 'content_length' => 1]);
        $header('"first"');
        app(DiseaseOntologyAudit::class)->run();
        $this->actingAs($this->admin());
        $this->get(route('admin.disease-ontology-audit'))->assertInertia(fn (Assert $page) => $page->where('sourcesChanged', false));

        $header('"second"');
        $this->get(route('admin.disease-ontology-audit'))->assertInertia(fn (Assert $page) => $page->where('sourcesChanged', true));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['submitter_id' => null]);
        $team = Team::create(['user_id' => $admin->id, 'name' => 'admin', 'personal_team' => false]);
        $admin->teams()->attach($team);

        return $admin;
    }

    public function test_report_and_export_require_admin_and_work_without_selected_submitter(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.disease-ontology-audit'))->assertForbidden();
        $this->get(route('admin.disease-ontology-audit.export'))->assertForbidden();
        session()->flush();
        $this->actingAs($this->admin());
        $this->get(route('admin.disease-ontology-audit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/DiseaseOntologyAudit')->where('run', null)->where('counts.versions', 0));
        $this->get(route('admin.disease-ontology-audit.export'))->assertNotFound();
    }

    public function test_filters_counts_pagination_and_export_share_a_pinned_successful_run(): void
    {
        $changed = $this->submission('OMIM:600001');
        $this->submission('MONDO:0000004', 'mondo_deprecated');
        $this->submission('=HYPERLINK("bad")', null);
        $run = app(DiseaseOntologyAudit::class)->run();
        $this->actingAs($this->admin());
        $filters = ['run' => $run->id, 'case' => 'different_target', 'namespace' => 'OMIM',
            'submitter' => $changed->submitter_id, 'submission_status' => 'new', 'job_status' => 'draft', 'search' => $changed->sid];
        $this->get(route('admin.disease-ontology-audit', $filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('run.id', $run->id)->where('counts.versions', 1)->where('counts.sgc_ids', 1)
            ->where('counts.identifiers', 1)->where('counts.submitters', 1)
            ->where('findings.total', 1)->where('findings.data.0.submission_id', $changed->id));
        $csv = $this->get(route('admin.disease-ontology-audit.export', $filters))->assertOk()->streamedContent();
        $this->assertSame(2, count(array_filter(explode("\n", $csv))));
        $this->assertStringContainsString($changed->sid, $csv);
        $allCsv = $this->get(route('admin.disease-ontology-audit.export'))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $allCsv);
        $this->submission('OMIM:600001');
        app(DiseaseOntologyAudit::class)->run();
        $this->get(route('admin.disease-ontology-audit', ['run' => $run->id]))->assertInertia(fn (Assert $page) => $page
            ->where('findings.total', 3));
        $failed = DiseaseAuditRun::create(['status' => 'failed', 'started_at' => now(), 'scope' => 'test']);
        $this->get(route('admin.disease-ontology-audit', ['run' => $failed->id]))->assertNotFound();
        $this->get(route('admin.disease-ontology-audit.export', ['run' => $failed->id]))->assertNotFound();
    }

    public function test_later_pages_keep_full_filtered_counts(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->submission('OMIM:600001');
        }
        app(DiseaseOntologyAudit::class)->run();
        $this->actingAs($this->admin());
        $this->get(route('admin.disease-ontology-audit', ['page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('findings.total', 26)
                ->has('findings.data', 1)->where('counts.versions', 26)->where('counts.sgc_ids', 26));
    }
}
