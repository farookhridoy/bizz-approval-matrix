<?php

namespace Bizzsol\ApprovalMatrix\Console;

use Bizzsol\ApprovalMatrix\Events\ApprovalOverdue;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Illuminate\Console\Command;

/**
 * Fires ApprovalOverdue for every pending assignment that has waited longer than its step's `sla_hours`, once per
 * assignment (reminded_at), or again every N hours with --repeat-hours. The consuming app turns the event into a notification.
 *
 *   php artisan approval:remind [--repeat-hours=24] [--dry-run]
 */
class RemindOverdue extends Command
{
    protected $signature = 'approval:remind {--repeat-hours=0 : remind again after this many hours (0 = only once)} {--dry-run : list, do not fire or mark}';

    protected $description = 'Remind approvers whose pending approvals are past the SLA of their step';

    public function handle(): int
    {
        $repeat = max(0, (int) $this->option('repeat-hours'));
        $fired = 0;

        ApprovalAction::query()
            ->where('action', ApprovalAction::PENDING)->whereNotNull('assigned_to')
            ->whereHas('request', fn ($q) => $q->where('status', ApprovalRequest::PENDING)->whereColumn('approval_requests.current_level', 'approval_actions.level'))
            ->when($repeat > 0,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('reminded_at')->orWhere('reminded_at', '<=', now()->subHours($repeat))),
                fn ($q) => $q->whereNull('reminded_at'))
            ->with('request')->orderBy('id')->chunkById(200, function ($actions) use (&$fired) {
                foreach ($actions as $action) {
                    $sla = (int) ($action->request->stepAt((int) $action->level)['sla_hours'] ?? 0);
                    $waited = (int) floor($action->created_at->diffInMinutes(now()) / 60);
                    if ($sla < 1 || $waited < $sla) {
                        continue;
                    }
                    $this->line(sprintf('request #%d level %d -> user %d waited %dh (SLA %dh)', $action->request_id, $action->level, $action->assigned_to, $waited, $sla));
                    if (! $this->option('dry-run')) {
                        $action->forceFill(['reminded_at' => now()])->save();
                        event(new ApprovalOverdue($action->request, $action, $waited, $sla));
                    }
                    $fired++;
                }
            });

        $this->info("{$fired} overdue approval".($fired === 1 ? '' : 's').($this->option('dry-run') ? ' (dry run)' : ' reminded'));

        return self::SUCCESS;
    }
}
