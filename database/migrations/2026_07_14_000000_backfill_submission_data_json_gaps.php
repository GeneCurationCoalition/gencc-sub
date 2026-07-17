<?php

use App\Models\Submission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repairs existing submission JSON so the public export and query/publish views show the correct
 * values for rows written before the accompanying code fixes. Two independent repairs:
 *
 *  1. Issue #114 gaps: older portal releases froze empty live placeholders into
 *     original_submission_data. Only that frozen snapshot needs repair; live submission_data may
 *     intentionally retain placeholders because FK-backed values are derived at release time.
 *  2. mechanism comment key: older rows stored the mechanism comment under the singular key
 *     `comment`; the canonical key is now `comments`. Preserve an existing plural value, otherwise
 *     move the singular value, then always remove the superseded singular key.
 *
 * Guarded/idempotent: only patches exact placeholders / single-key mechanism cases, never clobbers
 * legitimately-populated values, and re-running is a no-op. All submission scans are chunked to
 * keep migration memory bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $genes = DB::table('genes')->select('id', 'hgnc_id', 'symbol')->get()->keyBy('id');
        $inheritances = DB::table('inheritances')->select('id', 'curie', 'name')->get()->keyBy('id');
        $classifications = DB::table('classifications')->select('id', 'curie', 'name')->get()->keyBy('id');
        $submitters = DB::table('submitters')->select('id', 'curie', 'name')->get()->keyBy('id');

        $issue114PatchedCount = 0;
        $mechanismPatchedCount = 0;

        // Only live, published portal rows can both contain the #114 placeholder and appear in the
        // public release export. Future releases are repaired by GenccRelease::frozenSnapshotFor().
        DB::table('submissions')
            ->where('type', Submission::TYPE_PORTAL_SUBMISSION)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->where('is_live', true)
            ->whereNotNull('original_submission_data')
            ->select('id', 'sid', 'version_number', 'gene_id', 'inheritance_id',
                'classification_id', 'submitter_id', 'local_key', 'original_submission_data')
            ->chunkById(500, function ($rows) use ($genes, $inheritances, $classifications, $submitters, &$issue114PatchedCount) {
                foreach ($rows as $row) {
                    $originalData = json_decode($row->original_submission_data);
                    if (! $this->patchIssue114Gaps($originalData, $row, $genes, $inheritances, $classifications, $submitters)) {
                        continue;
                    }

                    DB::table('submissions')->where('id', $row->id)->update([
                        'original_submission_data' => json_encode($originalData),
                    ]);
                    $issue114PatchedCount++;
                    Log::info("Migration: patched original_submission_data on submission {$row->sid}.".($row->version_number ?? 1));
                }
            }, 'id');

        // The mechanism key mismatch affects live UI reads and is independent of submission type.
        // Scan in chunks because JSON blobs can be large; the exact mutation guard remains in PHP.
        DB::table('submissions')
            ->whereNotNull('submission_data')
            ->select('id', 'sid', 'version_number', 'submission_data', 'original_submission_data')
            ->chunkById(500, function ($rows) use (&$mechanismPatchedCount) {
                foreach ($rows as $row) {
                    $update = [];
                    $submissionData = json_decode($row->submission_data);
                    $originalData = $row->original_submission_data !== null
                        ? json_decode($row->original_submission_data)
                        : null;

                    if ($this->canonicalizeMechanismComment($submissionData)) {
                        $update['submission_data'] = json_encode($submissionData);
                    }
                    if ($originalData !== null && $this->canonicalizeMechanismComment($originalData)) {
                        $update['original_submission_data'] = json_encode($originalData);
                    }
                    if (empty($update)) {
                        continue;
                    }

                    DB::table('submissions')->where('id', $row->id)->update($update);
                    $mechanismPatchedCount++;
                    Log::info("Migration: normalized mechanism comment on submission {$row->sid}.".($row->version_number ?? 1));
                }
            }, 'id');

        Log::info("Migration complete: repaired {$issue114PatchedCount} release snapshot(s) and normalized {$mechanismPatchedCount} mechanism comment row(s).");
    }

    /** Mutates $data (stdClass) in place; returns true if anything changed. */
    private function patchIssue114Gaps($data, $row, $genes, $inheritances, $classifications, $submitters): bool
    {
        if ($data === null) {
            return false;
        }
        $changed = false;

        if ($this->isGap($data->gene ?? null, ['id' => '', 'symbol' => '']) && $row->gene_id && isset($genes[$row->gene_id])) {
            $g = $genes[$row->gene_id];
            $data->gene = (object) ['id' => $g->hgnc_id, 'symbol' => $g->symbol];
            $changed = true;
        }

        if ($this->isGap($data->moi ?? null, ['id' => '', 'name' => '']) && $row->inheritance_id && isset($inheritances[$row->inheritance_id])) {
            $m = $inheritances[$row->inheritance_id];
            $data->moi = (object) ['id' => $m->curie, 'name' => $m->name];
            $changed = true;
        }

        if ($this->isGap($data->classification ?? null, ['id' => '', 'name' => '']) && $row->classification_id && isset($classifications[$row->classification_id])) {
            $c = $classifications[$row->classification_id];
            $data->classification = (object) ['id' => $c->curie, 'name' => $c->name];
            $changed = true;
        }

        if ($this->isAdditionalInfoGap($data->additional_information ?? null) && $row->submitter_id && isset($submitters[$row->submitter_id])) {
            $s = $submitters[$row->submitter_id];
            $data->additional_information = (object) [
                'submitter_curie' => $s->curie,
                'submitter_title' => $s->name,
                'submitted_as_submission_id' => $row->local_key ?? '',
            ];
            $changed = true;
        }

        return $changed;
    }

    /**
     * Move a mechanism comment stored under the legacy singular key `comment` to the canonical
     * plural `comments` when needed, then always remove the superseded singular key.
     */
    private function canonicalizeMechanismComment($data): bool
    {
        if (! isset($data->mechanism) || ! is_object($data->mechanism)) {
            return false;
        }
        $mech = $data->mechanism;
        if (! property_exists($mech, 'comment')) {
            return false;
        }

        $singular = $mech->comment;
        $plural = $mech->comments ?? null;

        if (is_string($singular) && $singular !== '' && ($plural === null || $plural === '')) {
            $mech->comments = $singular;
        }

        unset($mech->comment);
        $data->mechanism = $mech;

        return true;
    }

    /**
     * True only if $value is exactly the known placeholder shape (same keys, same empty
     * values, no extras) - never a loose emptiness check, so a legitimately-edited value
     * can't be misdetected as a gap.
     */
    private function isGap($value, array $placeholderShape): bool
    {
        if ($value === null) {
            return false; // truly absent key: leave untouched, do not fabricate
        }
        $asArray = json_decode(json_encode($value), true);
        if (! is_array($asArray)) {
            return false;
        }
        foreach ($placeholderShape as $key => $expectedEmpty) {
            if (! array_key_exists($key, $asArray) || $asArray[$key] !== $expectedEmpty) {
                return false;
            }
        }

        return count($asArray) === count($placeholderShape);
    }

    /**
     * The additional_information placeholder is literally [{"key":"values"}] - a sequential
     * array with one throwaway element - unlike every other gap shape, which is an object.
     * That structural mismatch (array vs. object) is itself the discriminator.
     */
    private function isAdditionalInfoGap($value): bool
    {
        if ($value === null) {
            return false;
        }
        $decoded = json_decode(json_encode($value), true);

        return is_array($decoded)
            && array_keys($decoded) === [0]
            && is_array($decoded[0])
            && $decoded[0] == ['key' => 'values'];
    }

    public function down(): void
    {
        // Intentionally a no-op: the prior state was a known-bad placeholder / legacy key,
        // not meaningful data, so there is nothing valid to restore.
    }
};
