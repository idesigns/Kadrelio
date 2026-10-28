<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\MondayBoard;
use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use App\Models\MondayRevision;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RemoveDemoData extends Command
{
    protected $signature = 'demo:remove {--commit : Usuń wyłącznie dane oznaczone jako demo} {--force : Zezwól na zapis na produkcji}';

    protected $description = 'Usuwa przykładowe firmy i dane, zachowując firmy rzeczywiste oraz import';

    public function handle(): int
    {
        $companies = Company::where('is_demo', true)->withCount(['employees', 'records'])->get();
        $report = ['companies' => $companies->count(), 'employees' => $companies->sum('employees_count'), 'records' => $companies->sum('records_count')];
        if (! $this->option('commit')) {
            $this->line(json_encode(['mode' => 'dry_run', ...$report]));

            return self::SUCCESS;
        }
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Usunięcie demo na produkcji wymaga --force.');

            return self::FAILURE;
        }
        try {
            $removedUsers = DB::transaction(function () use ($companies): int {
                $companyIds = $companies->modelKeys();
                $employeeIds = $companies->flatMap(fn (Company $company) => $company->employees()->pluck('id'));
                $recordIds = WorkflowRecord::whereIn('company_id', $companyIds)->pluck('id');
                if (MondayBoard::whereIn('company_id', $companyIds)->exists()
                    || MondayItem::whereIn('employee_id', $employeeIds)->orWhereIn('workflow_record_id', $recordIds)->exists()
                    || WorkflowRecord::whereNotIn('company_id', $companyIds)->whereIn('contract_id', $recordIds)->exists()) {
                    throw new RuntimeException('Demo ma powiązania z rzeczywistymi danymi. Niczego nie usunięto.');
                }
                WorkflowRecord::whereIn('company_id', $companyIds)->update(['contract_id' => null]);
                WorkflowRecord::whereIn('company_id', $companyIds)->delete();
                foreach ($companies as $company) {
                    $company->employees()->delete();
                    $company->templates()->delete();
                    $company->invitations()->delete();
                    $company->users()->detach();
                    $company->delete();
                }
                $removed = 0;
                foreach (User::whereIn('email', ['demo@kadrelio.test', 'zespol1@kadrelio.test', 'zespol2@kadrelio.test'])->where('role', '!=', 'admin')->get() as $user) {
                    if ($user->companies()->exists() || Company::where('owner_id', $user->id)->exists()
                        || WorkflowRecord::where('created_by', $user->id)->exists() || MondayImportBatch::where('owner_id', $user->id)->exists()
                        || MondayRevision::where('user_id', $user->id)->exists() || MondayItem::where('reviewed_by', $user->id)->exists()) {
                        continue;
                    }
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                    $user->delete();
                    $removed++;
                }

                return $removed;
            });
            $this->line(json_encode(['mode' => 'committed', ...$report, 'demo_users' => $removedUsers]));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
