<?php

namespace Bizzsol\ApprovalMatrix\Console;

use Bizzsol\ApprovalMatrix\Services\CoverageReport;
use Illuminate\Console\Command;

/** php artisan approval:coverage procurement.requisition [--amount=50000] [--all] */
class CoverageCommand extends Command
{
    protected $signature = 'approval:coverage {document_type : e.g. procurement.requisition} {--amount= : typical document amount} {--all : also list the ones that are fine}';

    protected $description = 'Check that a document type has a workflow and an approver for every requester';

    public function handle(CoverageReport $report): int
    {
        $type = $this->argument('document_type');
        if (! isset(config('approvalmatrix.document_types')[$type])) {
            $this->error("Unknown document type [{$type}]. Known: ".implode(', ', array_keys(config('approvalmatrix.document_types'))));

            return self::FAILURE;
        }

        $result = $report->report($type, $this->option('amount') !== null ? (float) $this->option('amount') : null);
        $rows = collect($result['rows'])->when(! $this->option('all'), fn ($c) => $c->where('status', '!=', CoverageReport::OK));

        if ($rows->isNotEmpty()) {
            $this->table(['Requester', 'Unit', 'Department', 'Problem'], $rows->map(fn ($r) => [$r['name'], $r['unit'], $r['department'], $r['problem'] ?? 'ok'])->all());
        }
        $s = $result['summary'];
        $this->line("{$s['total']} requesters: {$s['ok']} ok, {$s['no-workflow']} without a workflow, {$s['no-approver']} with a step nobody can approve");

        return $s['no-workflow'] + $s['no-approver'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
