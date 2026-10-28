<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! config('hr.demo_enabled')) {
            $this->command?->error('Dane demonstracyjne są dostępne wyłącznie lokalnie z HR_DEMO_ENABLED=true.');

            return;
        }
        if (Company::where('is_demo', true)->exists()) {
            $this->command?->info('Przestrzeń demonstracyjna już istnieje. Nie zmieniono danych.');

            return;
        }
        $manager = User::firstOrCreate(['email' => 'demo@kadrelio.test'], ['name' => 'Wiktoria Kowalska', 'password' => Str::random(40)]);
        $members = collect([$manager]);
        foreach (['Anna Nowicka', 'Michał Kaczmarek'] as $index => $name) {
            $member = new User(['name' => $name, 'email' => 'zespol'.($index + 1).'@kadrelio.test', 'password' => Str::random(40)]);
            $member->role = 'user';
            $member->save();
            $members->push($member);
        }
        foreach ([['OTC 7 sp. z o.o.', 'green', 'https://otc7.pl/rodo.pdf'], ['Approval Center sp. z o.o.', 'purple', 'https://approval.center/rodo.pdf']] as $companyIndex => [$name, $color, $privacy]) {
            $company = new Company(['name' => $name, 'color' => $color, 'hr_email' => 'hr-demo@kadrelio.test', 'privacy_url' => $privacy, 'address' => 'Przestrzeń demonstracyjna - dane przykładowe']);
            $company->owner_id = $manager->id;
            $company->public_token = Str::random(64);
            $company->is_demo = true;
            $company->save();
            $company->users()->attach($members->pluck('id'));
            $names = $companyIndex === 0 ? [['Anna', 'Kowalska'], ['Jan', 'Nowak'], ['Marta', 'Zielińska'], ['Piotr', 'Wiśniewski'], ['Ewa', 'Stępień'], ['Tomasz', 'Bąk'], ['Katarzyna', 'Nowak'], ['Dawid', 'Lewandowski'], ['Szymon', 'Zalewski'], ['Julia', 'Wójcik'], ['Michał', 'Kamiński'], ['Aleksandra', 'Mazur']] : [['Natalia', 'Sikora'], ['Adam', 'Wróbel'], ['Karolina', 'Lis']];
            foreach ($names as $index => [$first, $last]) {
                $employee = Employee::factory()->completed()->create(['company_id' => $company->id, 'first_name' => $first, 'last_name' => $last, 'email' => 'osoba'.($index + 1).'@firma-demo.test', 'pesel' => str_pad((string) ($index + 1), 11, '0', STR_PAD_LEFT), 'pesel_hash' => Employee::identityHash(str_pad((string) ($index + 1), 11, '0', STR_PAD_LEFT)), 'phone' => '000 000 000', 'department' => ['p1', 'p2', 'p3', 'p4', 'p5', 'P2 BG'][$index % 6]]);
                $status = $index < 3 ? 'draft' : ($index < 6 ? 'sent' : ($index < 9 ? 'signed' : 'awaiting_data'));
                if ($status === 'awaiting_data') {
                    $employee->profile_completed_at = null;
                    $employee->save();
                }
                $html = '<!DOCTYPE html><html lang="pl"><meta charset="utf-8"><body style="font-family:Arial;padding:40px"><h1>Dokument demonstracyjny</h1><p>Ten dokument służy wyłącznie do prezentacji systemu Kadrelio.</p><p>Dane przykładowe: '.e($employee->fullName()).'</p><p>Właściwe treści umów i załączników należy dodać w szablonach firmy.</p></body></html>';
                $record = WorkflowRecord::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'created_by' => $members[$index % 3]->id, 'status' => $status, 'language' => $index === 5 ? 'BG' : ($index === 8 ? 'RO' : 'PL'), 'department' => $employee->department, 'concluded_at' => '2026-10-01', 'starts_at' => '2026-10-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'ends_at' => '2027-09-30', 'generated_at' => in_array($status, ['sent', 'signed']) ? now()->subDays(2) : null, 'document_html' => in_array($status, ['sent', 'signed']) ? $html : null, 'document_hash' => hash('sha256', $html), 'signing_expires_at' => now()->addDays(30), 'signed_at' => $status === 'signed' ? now()->subDay() : null, 'data' => ['reporter_name' => $manager->name, 'reporter_email' => $manager->email, 'signer_name' => $employee->fullName(), 'signer_pesel' => $employee->pesel, 'company_name' => $company->name, 'demo' => true]]);
            }
        }
        $this->command?->info('Dodano dwie firmy z przykładowym zespołem i obiegiem dokumentów.');
    }
}
