<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rename the legacy mechanism.comment JSON member to mechanism.comments.
 *
 * Both submission JSON columns are historical submission documents and must use the same shape.
 * If a document already contains both keys, the canonical plural value wins and the singular key
 * is removed. This makes the migration safe to rerun without retaining a compatibility fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $patchedRows = 0;
        $patchedDocuments = 0;
        $conflictingDocuments = 0;

        DB::table('submissions')
            ->select(['id', 'submission_data', 'original_submission_data'])
            ->chunkById(500, function ($rows) use (&$patchedRows, &$patchedDocuments, &$conflictingDocuments) {
                DB::transaction(function () use ($rows, &$patchedRows, &$patchedDocuments, &$conflictingDocuments) {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach (['submission_data', 'original_submission_data'] as $column) {
                            $document = $this->decodeDocument($row->{$column});
                            if ($document === null) {
                                continue;
                            }

                            $conflicted = false;
                            if (! $this->canonicalizeMechanismComments($document, $conflicted)) {
                                continue;
                            }

                            $updates[$column] = json_encode($document);
                            $patchedDocuments++;
                            $conflictingDocuments += (int) $conflicted;
                        }

                        if ($updates === []) {
                            continue;
                        }

                        DB::table('submissions')->where('id', $row->id)->update($updates);
                        $patchedRows++;
                    }
                });
            }, 'id');

        Log::info(
            "Mechanism comments migration repaired {$patchedDocuments} document(s) "
            . "across {$patchedRows} submission row(s); {$conflictingDocuments} plural value(s) retained."
        );
    }

    private function decodeDocument(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function canonicalizeMechanismComments(array &$document, bool &$conflicted): bool
    {
        if (! isset($document['mechanism']) || ! is_array($document['mechanism'])) {
            return false;
        }

        $mechanism = &$document['mechanism'];
        if (! array_key_exists('comment', $mechanism)) {
            return false;
        }

        $conflicted = array_key_exists('comments', $mechanism);
        if (! $conflicted) {
            $mechanism['comments'] = $mechanism['comment'];
        }

        unset($mechanism['comment']);

        return true;
    }

    public function down(): void
    {
        // This data cleanup is intentionally irreversible. The application only supports comments.
    }
};
