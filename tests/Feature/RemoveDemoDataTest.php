<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use Tests\TestCase;

class RemoveDemoDataTest extends TestCase
{
    public function test_preview_does_not_remove_demo_data(): void
    {
        $company = Company::factory()->create(['is_demo' => true]);
        $record = WorkflowRecord::factory()->for($company)->create();

        $this->artisan('demo:remove')->assertSuccessful();

        $this->assertModelExists($company);
        $this->assertModelExists($record);
    }

    public function test_cleanup_removes_demo_and_preserves_real_data_and_reused_accounts(): void
    {
        $owner = User::factory()->create(['email' => 'demo@kadrelio.test']);
        $unused = User::factory()->create(['email' => 'zespol1@kadrelio.test', 'role' => 'user']);
        $company = Company::factory()->for($owner, 'owner')->create(['is_demo' => true]);
        $company->users()->attach([$owner->id, $unused->id]);
        $contract = WorkflowRecord::factory()->for($company)->create();
        $child = WorkflowRecord::factory()->for($company)->for($contract->employee)->create(['kind' => 'amendment', 'contract_id' => $contract->id]);
        $real = Company::factory()->create();
        $real->users()->attach($owner);
        $realRecord = WorkflowRecord::factory()->for($real)->create();
        $source = MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->for($real)]);

        $this->artisan('demo:remove', ['--commit' => true])->assertSuccessful();

        $this->assertModelMissing($company);
        $this->assertModelMissing($contract);
        $this->assertModelMissing($child);
        $this->assertModelMissing($contract->employee);
        $this->assertModelMissing($unused);
        $this->assertModelExists($owner);
        $this->assertModelExists($real);
        $this->assertModelExists($realRecord);
        $this->assertModelExists($source);
        $this->assertSame([$real->id], $owner->companies()->pluck('companies.id')->all());
    }

    public function test_cleanup_refuses_to_remove_demo_linked_to_imported_data(): void
    {
        $company = Company::factory()->create(['is_demo' => true]);
        $record = WorkflowRecord::factory()->for($company)->create();
        $source = MondayItem::factory()->for($record->employee)->create();

        $this->artisan('demo:remove', ['--commit' => true])->expectsOutputToContain('Nicze')->assertFailed();

        $this->assertModelExists($company);
        $this->assertModelExists($record);
        $this->assertModelExists($source);
    }
}
