<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\DiseaseAuditFinding;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Submission;
use App\Services\DiseaseOntologyAudit;
use App\Services\MondoRelationshipWarnings as Warnings;
use App\Services\SubmissionDuplicateDetection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MondoRelationshipWarningsTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        Gene::factory()->create();
        $moi = Inheritance::factory()->create();
        $omim = Disease::factory()->create(['curie' => 'OMIM:123456', 'type' => Disease::TYPE_OMIM]);
        $mondo = Disease::factory()->mondo()->withXrefs(['omim_id' => ['123456']])->create(['curie' => 'MONDO:0001234']);
        $peer = Submission::factory()->create(['disease_id' => $mondo->id, 'original_disease_id' => $omim->id,
            'inheritance_id' => $moi->id, 'is_live' => true, 'is_most_recent' => true,
            'status' => Submission::STATUS_PUBLISHED, 'submission_data' => ['disease' => ['id' => $omim->curie]]]);
        $draft = Submission::factory()->create(['disease_id' => $mondo->id, 'original_disease_id' => $mondo->id,
            'inheritance_id' => $moi->id, 'is_most_recent' => true,
            'submission_data' => ['disease' => ['id' => $mondo->curie]]]);

        return [$peer, $draft, $mondo, $omim];
    }

    public function test_cross_namespace_overlap_warns_without_changing_duplicate_policy_or_records(): void
    {
        [$peer, $draft] = $this->world();
        $before = $draft->fresh()->getRawOriginal();
        $duplicate = SubmissionDuplicateDetection::checkForDuplicates($draft->submitter_id, $draft->gene_id,
            $draft->original_disease_id, $draft->inheritance_id, $draft->id);
        $this->assertFalse($duplicate['has_blocking_duplicate']);
        Warnings::attach(collect([$draft]));
        $this->assertSame($peer->sid, $draft->mondo_relationship_warning['peers'][0]['sid']);
        $this->assertNull($draft->submission_errors);
        $this->assertSame($before, $draft->fresh()->getRawOriginal());
    }

    public function test_scope_and_same_sgc_versions_do_not_produce_false_peers(): void
    {
        [$peer, $draft] = $this->world();
        $candidate = Warnings::candidate($draft);
        foreach ([['sid' => $draft->sid], ['submitter_id' => 999], ['gene_id' => 999], ['inheritance_id' => 999], ['mondo' => null]] as $change) {
            $group = Warnings::groups([array_merge(Warnings::candidate($peer), $change)]);
            $this->assertNull(Warnings::compare($candidate, $group));
        }
        $peer->update(['status' => Submission::STATUS_UNPUBLISHED]);
        Warnings::attach(collect([$draft]));
        $this->assertStringContainsString('already unpublished', $draft->mondo_relationship_warning['message']);
        $peer->update(['is_live' => false, 'is_most_recent' => false]);
        Warnings::attach(collect([$draft]));
        $this->assertNull($draft->mondo_relationship_warning);
        $peer->update(['is_most_recent' => true]);
        $peer->delete();
        Warnings::attach(collect([$draft]));
        $this->assertNull($draft->mondo_relationship_warning);
    }

    public function test_submitted_id_duplicate_rules_preserve_status_scope_and_self_version_exclusions(): void
    {
        [$peer, $draft] = $this->world();
        $row = ['gene_id' => $draft->gene_id, 'original_disease_id' => $draft->original_disease_id,
            'disease_id' => $draft->disease_id, 'inheritance_id' => $draft->inheritance_id,
            'row_index' => 13, 'exclude_submission_id' => $draft->id, 'sid' => $draft->sid];
        $single = fn () => SubmissionDuplicateDetection::checkForDuplicates($draft->submitter_id,
            $draft->gene_id, $draft->original_disease_id, $draft->inheritance_id, $draft->id);
        $batch = fn () => SubmissionDuplicateDetection::checkForDuplicatesBatch($draft->submitter_id, [$row])[13];
        // Sharing only the MONDO term is not a duplicate.
        $this->assertEmpty($single()['duplicates']);
        $this->assertEmpty($batch()['duplicates']);
        DB::table('submissions')->where('id', $peer->id)->update(['original_disease_id' => $draft->original_disease_id]);
        foreach (SubmissionDuplicateDetection::getBlockingStatuses() as $status) {
            $peer->update(['status' => $status, 'is_live' => $status === Submission::STATUS_PUBLISHED]);
            $this->assertTrue($single()['has_blocking_duplicate'], $status);
            $this->assertTrue($batch()['has_blocking_duplicate'], $status);
        }
        $peer->update(['status' => Submission::STATUS_UNPUBLISHED, 'is_live' => true]);
        $this->assertFalse($single()['has_blocking_duplicate']);
        $this->assertTrue($single()['has_unpublished_duplicate']);
        $this->assertTrue($batch()['has_unpublished_duplicate']);
        $peer->update(['is_live' => false]);
        $this->assertEmpty($single()['duplicates']);
        $this->assertEmpty($batch()['duplicates']);
        $peer->update(['status' => Submission::STATUS_PUBLISHED, 'is_live' => true]);
        foreach (['gene_id' => Gene::factory()->create()->id,
            'inheritance_id' => Inheritance::factory()->create()->id,
            'submitter_id' => \App\Models\Submitter::factory()->create()->id] as $field => $differentId) {
            $old = $peer->$field;
            // A nonmatching key must not be matched just because the disease agrees.
            DB::table('submissions')->where('id', $peer->id)->update([$field => $differentId]);
            $this->assertEmpty($single()['duplicates']);
            $this->assertEmpty($batch()['duplicates']);
            DB::table('submissions')->where('id', $peer->id)->update([$field => $old]);
        }
        DB::table('submissions')->where('id', $peer->id)->update(['sid' => $draft->sid, 'version_number' => 2]);
        $this->assertEmpty($single()['duplicates']);
        $this->assertEmpty($batch()['duplicates']);
        DB::table('submissions')->where('id', $peer->id)->update(['sid' => 'SGC-888888']);
        $peer->delete();
        $this->assertEmpty($single()['duplicates']);
        $this->assertEmpty($batch()['duplicates']);
    }

    public function test_batch_matches_submitted_disease_only_not_shared_mondo(): void
    {
        [$peer, $draft] = $this->world();
        $row = ['gene_id' => $draft->gene_id, 'original_disease_id' => $peer->original_disease_id,
            'disease_id' => $peer->disease_id, 'inheritance_id' => $draft->inheritance_id, 'row_index' => 13,
            'exclude_submission_id' => $draft->id];
        $result = SubmissionDuplicateDetection::checkForDuplicatesBatch($draft->submitter_id, [$row])[13];
        $this->assertCount(1, $result['blocking_duplicates']);
        $rows = [$row, array_merge($row, ['row_index' => 14, 'original_disease_id' => $draft->original_disease_id]),
            array_merge($row, ['row_index' => 15, 'disease_id' => null])];
        $results = SubmissionDuplicateDetection::checkForDuplicatesBatch($draft->submitter_id, $rows);
        $this->assertSame([15], $results[13]['batch_duplicate_rows']);
        $this->assertFalse($results[14]['has_batch_duplicate']);
        $this->assertFalse($results[14]['has_blocking_duplicate']);
        $this->assertSame([13], $results[15]['batch_duplicate_rows']);
    }

    public function test_live_published_assertions_sharing_only_mondo_do_not_block_each_other_on_republish(): void
    {
        [$peer, $draft, $mondo] = $this->world();
        $other = Submission::factory()->create(['submitter_id' => $peer->submitter_id, 'gene_id' => $peer->gene_id,
            'inheritance_id' => $peer->inheritance_id, 'disease_id' => $mondo->id, 'original_disease_id' => $mondo->id,
            'is_live' => true, 'is_most_recent' => true, 'status' => Submission::STATUS_PUBLISHED,
            'submission_data' => ['disease' => ['id' => $mondo->curie]]]);
        $draft->delete();
        foreach ([[$peer, $other], [$other, $peer]] as [$republished, $sibling]) {
            // Portal/processing path: the republished assertion's own live version is excluded.
            $single = SubmissionDuplicateDetection::checkForDuplicates($republished->submitter_id, $republished->gene_id,
                $republished->original_disease_id, $republished->inheritance_id, $republished->id);
            $this->assertEmpty($single['duplicates'], $republished->sid.' vs '.$sibling->sid);
        }
        // Workbook path: two "R" rows for the pair are neither intra-file nor existing duplicates.
        $rows = [];
        foreach ([$peer, $other] as $i => $republished) {
            $rows[] = ['gene_id' => $republished->gene_id, 'original_disease_id' => $republished->original_disease_id,
                'disease_id' => $republished->disease_id, 'inheritance_id' => $republished->inheritance_id,
                'row_index' => 13 + $i, 'exclude_submission_id' => $republished->id, 'sid' => $republished->sid];
        }
        $this->assertSame([], SubmissionDuplicateDetection::intraBatchDuplicateGroups($rows));
        foreach (SubmissionDuplicateDetection::checkForDuplicatesBatch($peer->submitter_id, $rows) as $result) {
            $this->assertFalse($result['has_batch_duplicate']);
            $this->assertEmpty($result['duplicates']);
        }
        // The pair is still reported as a non-blocking shared-MONDO warning.
        $warnings = Warnings::check(array_map(fn ($row) => $row + ['submitter_id' => $peer->submitter_id,
            'mondo' => $mondo->curie], $rows));
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('non-blocking', $warnings[0]['message']);
    }

    public function test_incoming_rows_warn_and_query_count_is_bounded(): void
    {
        [$peer, $draft] = $this->world();
        $first = Warnings::candidate($draft);
        unset($first['id'], $first['sid']);
        $first['row_index'] = 13;
        $second = array_merge($first, ['row_index' => 14, 'original_disease_id' => $peer->original_disease_id]);
        DB::enableQueryLog();
        $warnings = Warnings::check([$first, $second]);
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('Workbook row 14', $warnings[0]['message']);
        $this->assertStringContainsString('Workbook row 13', $warnings[1]['message']);
    }

    public function test_api_candidates_use_normal_validation_including_missing_source_rejection(): void
    {
        [$peer, $draft, $mondo] = $this->world();
        $gene = Gene::first();
        $moi = Inheritance::first();
        $record = ['action' => 'new', 'gene' => ['id' => $gene->hgnc_id], 'moi' => ['id' => $moi->curie],
            'disease' => ['id' => $mondo->curie]];
        $this->assertCount(1, Warnings::incoming($draft->submitter_id, [$record]));
        $mondo->update(['xrefs' => ['omim_id' => ['999999']]]);
        $record['disease']['id'] = 'OMIM:999999';
        $this->assertSame([], Warnings::incoming($draft->submitter_id, [$record]));
    }

    public function test_public_api_returns_nonblocking_relationship_advice_and_rejects_submitted_id_duplicates(): void
    {
        [$peer, $draft, $mondo, $omim] = $this->world();
        $draft->delete();
        $user = $peer->user;
        $user->update(['submitter_id' => $peer->submitter_id, 'api_token' => 'relationship-test-token', 'status' => 1]);
        $record = fn ($disease) => ['action' => 'new', 'gene' => ['id' => $peer->gene->hgnc_id],
            'moi' => ['id' => $peer->inheritance->curie], 'disease' => ['id' => $disease->curie]];
        $post = fn ($action, $data) => $this->actingAs($user)->withHeader('GEN-API-KEY', 'relationship-test-token')
            ->postJson('/api/submit', ['action' => $action, 'submitter' => ['id' => $peer->submitter->curie], 'data' => $data]);
        $count = \App\Models\Job::count();

        // Same MONDO as a live published peer, different submitted ID: warning only.
        $post('check', [$record($mondo)])->assertOk()->assertJsonPath('success', 'true')
            ->assertJsonPath('warnings.0.type', 'shared_mondo_relationship')->assertJsonPath('warnings.0.row', 1);
        $this->assertSame($count, \App\Models\Job::count());

        // Same submitted ID as the live published peer: rejected before any write.
        foreach (['check', 'create'] as $action) {
            $post($action, [$record($omim)])->assertStatus(422)->assertJsonPath('success', 'false')
                ->assertJsonPath('errors.0.type', 'duplicate_submission');
        }
        $this->assertSame($count, \App\Models\Job::count());
        $peer->update(['status' => Submission::STATUS_UNPUBLISHED]);
        $post('check', [$record($omim)])->assertOk()->assertJsonPath('warnings.0.type', 'unpublished_duplicate');

        // Within one request: shared MONDO warns; a repeated submitted ID is rejected.
        $peer->delete();
        $post('check', [$record($mondo), $record($omim)])->assertOk()
            ->assertJsonCount(2, 'warnings')->assertJsonPath('warnings.0.type', 'shared_mondo_relationship');
        $post('check', [$record($omim), $record($omim)])->assertStatus(422)
            ->assertJsonCount(2, 'errors')->assertJsonPath('errors.0.type', 'duplicate_submission');
    }

    public function test_public_api_duplicate_check_trims_identifiers_like_record_validation(): void
    {
        [$peer, $draft, $mondo, $omim] = $this->world();
        $draft->delete();
        $user = $peer->user;
        $user->update(['submitter_id' => $peer->submitter_id, 'api_token' => 'relationship-test-token', 'status' => 1]);
        // Record validation trims these IDs, so the padded record would be stored as a duplicate.
        $record = ['action' => 'new', 'gene' => ['id' => ' '.$peer->gene->hgnc_id.' '],
            'moi' => ['id' => ' '.$peer->inheritance->curie.' '], 'disease' => ['id' => $omim->curie]];

        $this->actingAs($user)->withHeader('GEN-API-KEY', 'relationship-test-token')
            ->postJson('/api/submit', ['action' => 'check', 'submitter' => ['id' => $peer->submitter->curie], 'data' => [$record]])
            ->assertStatus(422)->assertJsonPath('errors.0.type', 'duplicate_submission');
    }

    public function test_listing_resolution_queries_are_bounded_for_distinct_sources_and_targets(): void
    {
        [$peer, $draft] = $this->world();
        $submissions = collect();
        // Exercise MONDO self, MONDO exact matches, Orphanet direct and OMIM bridge paths.
        for ($i = 1; $i <= 100; $i++) {
            $number = (string) (200000 + $i);
            $mondo = Disease::factory()->mondo()->create(['curie' => 'MONDO:'.$number,
                'xrefs' => ['omim_id' => [$number]]]);
            $source = match ($i % 4) {
                0 => $mondo,
                1 => Disease::factory()->create(['curie' => 'OMIM:'.$number, 'type' => Disease::TYPE_OMIM]),
                default => Disease::factory()->create(['curie' => 'Orphanet:'.$number, 'type' => Disease::TYPE_ORPHANET,
                    'xrefs' => $i % 4 === 2 ? ['mondo_id' => [$mondo->curie]] : ['omim_id' => [$number]]]),
            };
            $submission = $draft->replicate();
            $submission->id = 1000 + $i;
            $submission->sid = 'SGC-'.(200000 + $i);
            $submission->submission_data = ['disease' => ['id' => $source->curie]];
            $submission->setRelation('disease', $mondo);
            $submission->setRelation('job', $draft->job);
            $submissions->push($submission);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        \App\Services\SubmissionDiseaseAdvice::attach($submissions);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(8, count($queries));
        $this->assertTrue($submissions->every(fn ($s) => $s->mondo_relationship_warning === null));
    }

    public function test_audit_distinguishes_stored_current_and_both_without_mutation(): void
    {
        [$peer, $draft, $mondo] = $this->world();
        $run = (new DiseaseOntologyAudit())->run();
        $finding = DiseaseAuditFinding::where('run_id', $run->id)->where('submission_id', $draft->id)->firstOrFail();
        $this->assertContains('shared_stored_mondo_relationship', $finding->cases);
        $this->assertContains('shared_current_mondo_relationship', $finding->cases);
        $other = Disease::factory()->mondo()->create(['curie' => 'MONDO:999']);
        DB::table('submissions')->where('id', $peer->id)->update(['disease_id' => $other->id]);
        $run = (new DiseaseOntologyAudit())->run();
        $finding = DiseaseAuditFinding::where('run_id', $run->id)->where('submission_id', $draft->id)->firstOrFail();
        $this->assertNotContains('shared_stored_mondo_relationship', $finding->cases);
        $this->assertContains('shared_current_mondo_relationship', $finding->cases);
        DB::table('submissions')->where('id', $peer->id)->update(['disease_id' => $mondo->id, 'submission_data' => json_encode(['disease' => ['id' => 'OMIM:888888']])]);
        $run = (new DiseaseOntologyAudit())->run();
        $finding = DiseaseAuditFinding::where('run_id', $run->id)->where('submission_id', $draft->id)->firstOrFail();
        $this->assertContains('shared_stored_mondo_relationship', $finding->cases);
        $this->assertNotContains('shared_current_mondo_relationship', $finding->cases);
    }
}
