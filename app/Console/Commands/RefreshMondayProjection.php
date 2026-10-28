<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\MondayItem;
use App\Models\WorkflowRecord;
use App\Services\MondayProjector;
use App\Support\ProfileSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RefreshMondayProjection extends Command
{
    protected $signature = 'monday:refresh-projection {--apply : Zapisz uzupełnienia zamiast samej analizy}';

    protected $description = 'Uzupełnia brakujące odpowiedzi wybranych profili i statusy nieprzejętych dokumentów bez wysyłki e-mail';

    public function handle(MondayProjector $projector): int
    {
        $mailEnabled = config('hr.mail_enabled');
        config(['hr.mail_enabled' => false]);
        $report = ['applied' => (bool) $this->option('apply'), 'profiles_updated' => 0, 'answers_added' => 0, 'profiles_without_selection' => 0, 'invalid_selections' => 0, 'statuses_updated' => []];
        try {
            foreach (Employee::lazyById(100) as $employee) {
                DB::transaction(function () use ($employee, $projector, &$report): void {
                    $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                    $selected = $employee->personal_data['monday_profile_id'] ?? null;
                    if (! $selected) {
                        $report['profiles_without_selection']++;

                        return;
                    }
                    $item = MondayItem::whereKey($selected)->where('employee_id', $employee->id)->whereHas('board', fn (Builder $query): Builder => $query->where('company_id', $employee->company_id)->where('kind', 'profiles'))->first();
                    if (! $item) {
                        $report['invalid_selections']++;

                        return;
                    }
                    $missing = array_diff_key(ProfileSource::values($item), $employee->personal_data ?? [], array_flip(['first_name', 'last_name', 'email', 'phone', 'pesel']));
                    if ($missing === []) {
                        return;
                    }
                    if ($this->option('apply')) {
                        $projector->applyProfile($employee, $item, onlyMissing: true);
                    }
                    $report['profiles_updated']++;
                    $report['answers_added'] += count($missing);
                }, 5);
            }
            foreach (WorkflowRecord::whereHas('mondayItem')->with('mondayItem')->lazyById(100) as $record) {
                DB::transaction(function () use ($record, $projector, &$report): void {
                    $record = WorkflowRecord::whereKey($record->id)->with('mondayItem')->lockForUpdate()->firstOrFail();
                    if (! $record->isImported() || $record->signed_at || $record->generated_at) {
                        return;
                    }
                    $status = $projector->sourceStatus($record->mondayItem, $record->kind);
                    if ($status === $record->status) {
                        return;
                    }
                    $change = $record->kind.':'.$record->status.'->'.$status;
                    if ($this->option('apply')) {
                        $record->forceFill(['status' => $status])->save();
                    }
                    $report['statuses_updated'][$change] = ($report['statuses_updated'][$change] ?? 0) + 1;
                }, 5);
            }
        } finally {
            config(['hr.mail_enabled' => $mailEnabled]);
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
