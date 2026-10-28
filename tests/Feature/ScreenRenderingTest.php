<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\WorkflowRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScreenRenderingTest extends TestCase
{
    #[DataProvider('boards')]
    public function test_company_board_renders(string $board): void
    {
        $company = Company::factory()->create();
        WorkflowRecord::factory()->for($company)->create();

        $this->actingAs($company->owner)->get(route('companies.board', [$company, $board]))->assertOk()->assertSee('Kadrelio');
    }

    public static function boards(): array
    {
        return array_map(fn (string $board): array => [$board], ['links', 'requests', 'employees', 'contracts', 'amendments', 'terminations', 'statements', 'changes', 'multisport']);
    }

    #[DataProvider('recordStatuses')]
    public function test_record_details_render_at_each_step(string $status): void
    {
        $record = WorkflowRecord::factory()->create(['status' => $status]);

        $this->actingAs($record->company->owner)->get(route('companies.records.show', [$record->company, $record]))->assertOk()->assertSee($record->employee->fullName());
    }

    public static function recordStatuses(): array
    {
        return [['awaiting_data'], ['draft'], ['ready'], ['sent'], ['signed'], ['not_concluded']];
    }

    public function test_employee_and_settings_pages_render(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $template = DocumentTemplate::factory()->for($company)->create();

        $this->actingAs($company->owner)->get(route('companies.employees.show', [$company, $employee]))->assertOk();
        $this->get(route('companies.edit', $company))->assertOk();
        $this->get(route('companies.members.index', $company))->assertOk();
        $this->get(route('companies.templates.index', $company))->assertOk();
        $this->get(route('companies.templates.create', $company))->assertOk();
        $this->get(route('companies.templates.edit', [$company, $template]))->assertOk();
    }

    public function test_internal_and_public_forms_render(): void
    {
        $company = Company::factory()->create();
        $record = WorkflowRecord::factory()->sent()->for($company)->create();

        $this->actingAs($company->owner)->get(route('companies.records.create', [$company, 'contract']))->assertOk();
        $this->get(route('companies.records.create', [$company, 'amendment']))->assertOk();
        $this->get(route('companies.records.create', [$company, 'termination']))->assertOk();
        $this->get(route('forms.additional', [$company->public_token, 'data_change']))->assertOk();
        $this->get(route('forms.additional', [$company->public_token, 'multisport']))->assertOk();
        $this->get(route('signing.show', $record->signing_token))->assertOk();
        $this->get(route('onboarding.show', $record->employee->form_token))->assertOk();
    }
}
