<?php

namespace App\Console\Commands;

use App\Models\AdminLog;
use App\Services\AdminProgressTracker;
use App\Services\DiseaseOntologyAudit;
use Illuminate\Console\Command;
use Throwable;

class AuditDiseaseOntologies extends Command
{
    protected $signature = 'audit:disease-ontologies';

    protected $description = 'Save a read-only disease audit of live and most recent submission versions';

    public function handle(DiseaseOntologyAudit $audit): int
    {
        // Reported for every run, so the admin dashboard also shows scheduled and deploy runs.
        AdminProgressTracker::start(AdminLog::OP_AUDIT_DISEASES, ['scan' => 'Scanning submissions']);
        AdminProgressTracker::updatePhase(AdminLog::OP_AUDIT_DISEASES, 'scan', 0, 1, 'Scanning submissions...');
        try {
            $run = $audit->run();
            $summary = "Audit {$run->id}: {$run->examined_count} versions examined; {$run->affected_count} with findings.";
            $this->info($summary);
            AdminProgressTracker::complete(AdminLog::OP_AUDIT_DISEASES, $summary);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());
            AdminProgressTracker::fail(AdminLog::OP_AUDIT_DISEASES, $e->getMessage());

            return self::FAILURE;
        }
    }
}
