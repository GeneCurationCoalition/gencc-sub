<?php

namespace App\Http\Controllers;

use App\Models\DiseaseAuditFinding;
use App\Models\DiseaseAuditRun;
use App\Services\DiseaseOntologyAudit;
use App\Services\DiseaseOntologySources;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DiseaseOntologyAuditController extends Controller
{
    public function index(Request $request)
    {
        [$run, $filters, $query] = $this->report($request);
        $base = DiseaseAuditFinding::where('run_id', $run?->id);

        return Inertia::render('Admin/DiseaseOntologyAudit', [
            'run' => $run,
            'sourcesChanged' => $run !== null && DiseaseOntologySources::changedSince($run->source_metadata),
            'latestAttempt' => DiseaseAuditRun::orderByDesc('id')->first(),
            'filters' => $filters,
            'caseLabels' => DiseaseOntologyAudit::CASES,
            'findings' => (clone $query)->orderBy('sid')->orderBy('version_number')->orderBy('id')
                ->paginate(25)->withQueryString(),
            'counts' => [
                'versions' => (clone $query)->count(),
                'sgc_ids' => (clone $query)->distinct()->count('sid'),
                'identifiers' => (clone $query)->distinct()->count('normalized_id'),
                'submitters' => (clone $query)->distinct()->count('submitter_id'),
            ],
            'submitters' => (clone $base)->select('submitter_id', 'submitter_name')->distinct()->orderBy('submitter_name')->get(),
            'namespaces' => (clone $base)->whereNotNull('namespace')->distinct()->orderBy('namespace')->pluck('namespace'),
            'submissionStates' => (clone $base)->distinct()->orderBy('submission_status')->pluck('submission_status'),
            'jobStates' => (clone $base)->whereNotNull('job_status')->distinct()->orderBy('job_status')->pluck('job_status'),
        ]);
    }

    public function export(Request $request)
    {
        [$run, , $query] = $this->report($request);
        abort_unless($run, 404, 'No successful disease audit is available.');

        return response()->streamDownload(function () use ($query, $run) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['scan_id', 'submission_id', 'sgc_id', 'version', 'job', 'submitter',
                'submission_status', 'job_status', 'submitted_id', 'normalized_id', 'stored_mondo',
                'current_mondo', 'cases', 'accepted_now', 'validation_error', 'evidence']);
            foreach ($query->orderBy('id')->lazyById(500) as $finding) {
                $values = [$run->id, $finding->submission_id, $finding->sid, $finding->version_number,
                    $finding->job_slug, $finding->submitter_name, $finding->submission_status,
                    $finding->job_status, $finding->submitted_id, $finding->normalized_id,
                    $finding->stored_curie, $finding->current_curie, implode('; ', $finding->cases),
                    $finding->evidence['accepted'] ? 'yes' : 'no',
                    $finding->evidence['validation_error'], json_encode($finding->evidence)];
                // Submitted values and organization names can be spreadsheet formulas.
                fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value)
                    ? "'".$value : $value, $values));
            }
            fclose($stream);
        }, "disease-ontology-audit-{$run->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function report(Request $request): array
    {
        abort_unless($request->user()?->isGenccAdmin(), 403);
        $filters = $request->validate([
            'run' => ['nullable', 'integer', 'min:1'],
            'case' => ['nullable', Rule::in(array_keys(DiseaseOntologyAudit::CASES))],
            'submitter' => ['nullable', 'integer'],
            'namespace' => ['nullable', 'string', 'max:255'],
            'submission_status' => ['nullable', 'string', 'max:80'],
            'job_status' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
        $runs = DiseaseAuditRun::where('status', DiseaseAuditRun::SUCCEEDED);
        $run = ! empty($filters['run']) ? $runs->findOrFail($filters['run']) : $runs->orderByDesc('id')->first();
        $query = DiseaseAuditFinding::where('run_id', $run?->id);
        if (! empty($filters['case'])) {
            abort_unless($run && array_key_exists($filters['case'], $run->case_counts ?? []), 422,
                'This finding type was not captured in this scan. Run a new disease audit.');
            $query->whereJsonContains('cases', $filters['case']);
        }
        foreach (['submitter' => 'submitter_id', 'namespace' => 'namespace',
            'submission_status' => 'submission_status', 'job_status' => 'job_status'] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                foreach (['sid', 'job_slug', 'submitted_id', 'normalized_id', 'stored_curie', 'current_curie'] as $column) {
                    $q->orWhere($column, 'like', $search);
                }
            });
        }

        return [$run, $filters, $query];
    }
}
