<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MondayAsset;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use App\Services\MondayProjector;
use App\Support\ProfileSource;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MondayProjectionRefreshTest extends TestCase
{
    public function test_selected_profile_backfill_preserves_corrections_and_is_idempotent_without_mail(): void
    {
        Mail::fake();
        Queue::fake();
        $employee = Employee::factory()->completed()->create(['email' => 'poprawiony@example.test']);
        $profile = $this->profile($employee);
        $employee->personal_data = array_merge($employee->personal_data, ['monday_profile_id' => $profile->id, 'father_name' => 'Poprawiony ojciec', 'profile_review_required' => false]);
        $employee->save();
        $original = $profile->source_data;
        $before = $employee->getRawOriginal('personal_data');
        $this->artisan('monday:refresh-projection')->assertSuccessful();
        $this->assertSame($before, $employee->fresh()->getRawOriginal('personal_data'));
        $this->assertArrayNotHasKey('is_student', $employee->fresh()->personal_data);
        $this->artisan('monday:refresh-projection', ['--apply' => true])->assertSuccessful();
        $employee->refresh();
        $this->assertSame('1', $employee->personal_data['is_student']);
        $this->assertSame('0', $employee->personal_data['is_unemployed']);
        $this->assertSame('female', $employee->personal_data['gender']);
        $this->assertSame('half', $employee->personal_data['working_time']);
        $this->assertSame('2001-01-01', $employee->personal_data['birth_date']);
        $this->assertSame('Inna miejscowość', $employee->personal_data['registered_city']);
        $this->assertSame('Poprawiony ojciec', $employee->personal_data['father_name']);
        $this->assertSame('poprawiony@example.test', $employee->email);
        $this->assertFalse($employee->personal_data['profile_review_required']);
        $before = $employee->getRawOriginal('personal_data');
        $this->artisan('monday:refresh-projection', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $employee->fresh()->getRawOriginal('personal_data'));
        $this->assertSame($original, $profile->fresh()->source_data);
        $this->assertSame($profile->fields, $profile->fresh()->fields);
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_backfill_does_not_choose_unverified_profile_or_accept_foreign_selection(): void
    {
        $employee = Employee::factory()->create(['personal_data' => ['profile_review_required' => true]]);
        $this->profile($employee);
        $other = Employee::factory()->create();
        $foreign = $this->profile($other);
        $employee->personal_data = ['profile_review_required' => true, 'monday_profile_id' => $foreign->id];
        $employee->save();
        $this->artisan('monday:refresh-projection', ['--apply' => true])->assertSuccessful();
        $this->assertArrayNotHasKey('is_student', $employee->fresh()->personal_data);
        $this->assertTrue($employee->fresh()->personal_data['profile_review_required']);
        $this->assertNull($other->fresh()->profile_completed_at);
        $this->assertNull($employee->fresh()->profile_completed_at);
    }

    public function test_full_profile_projection_maps_answers_and_preserves_unrecognized_options(): void
    {
        $employee = Employee::factory()->create();
        $profile = $this->profile($employee);
        $fields = $profile->fields;
        $fields['D'] = 'Do wyjaśnienia';
        $profile->fields = $fields;
        $profile->save();
        app(MondayProjector::class)->applyProfile($employee, $profile);
        $this->assertSame('Do wyjaśnienia', $employee->fresh()->personal_data['is_student']);
        $this->assertSame('Maria', $employee->fresh()->personal_data['second_name']);
        $this->assertSame('Nowak', $employee->fresh()->personal_data['family_name']);
        $this->assertSame('Jan', $employee->fresh()->personal_data['father_name']);
        $this->assertSame('1', ProfileSource::decode('other_mandate', 'Yes'));
        $this->assertSame('0', ProfileSource::decode('has_disability', 'Nie / No'));
    }

    public function test_status_refresh_uses_source_without_inventing_signatures_or_changing_active_workflow(): void
    {
        Mail::fake();
        Queue::fake();
        $company = Company::factory()->create();
        foreach (['amendment' => 'Wygenerowane', 'termination' => 'Wygenerowana'] as $kind => $sourceStatus) {
            $board = MondayBoard::factory()->for($company)->create(['kind' => $kind === 'amendment' ? 'amendments' : 'terminations']);
            $record = WorkflowRecord::factory()->for($company)->create(['kind' => $kind, 'status' => 'draft', 'data' => ['monday_source' => 'test']]);
            $item = MondayItem::factory()->for($board, 'board')->create(['workflow_record_id' => $record->id, 'employee_id' => $record->employee_id, 'status' => $sourceStatus]);
            $original = $item->source_data;
            $token = $record->signing_token;
            $this->artisan('monday:refresh-projection', ['--apply' => true])->assertSuccessful();
            $record->refresh();
            $this->assertSame('ready', $record->status);
            $this->assertSame($sourceStatus, $record->statusLabel());
            $this->assertTrue($record->isImported());
            $this->assertFalse($record->canBeSigned());
            $this->assertNull($record->signed_at);
            $this->assertNull($record->generated_at);
            $this->assertSame($token, $record->signing_token);
            $this->assertSame($original, $item->fresh()->source_data);
            $record->forceFill(['data' => ['monday_source' => 'test', 'monday_workflow_active' => true], 'status' => 'sent'])->save();
            $this->artisan('monday:refresh-projection', ['--apply' => true])->assertSuccessful();
            $this->assertSame('sent', $record->fresh()->status);
        }
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_historical_statements_and_files_are_visible_without_native_signatures_or_guessed_relations(): void
    {
        $company = Company::factory()->create();
        $board = MondayBoard::factory()->for($company)->create(['kind' => 'contract_statements']);
        $item = MondayItem::factory()->for($board, 'board')->create(['name' => 'Anna Archiwalna', 'status' => 'Złożone', 'relation_state' => 'ambiguous', 'document_date' => '2025-06-01']);
        $asset = MondayAsset::factory()->create(['monday_import_batch_id' => $board->monday_import_batch_id, 'path' => 'statement.pdf']);
        $item->assets()->attach($asset, ['column_key' => 'F']);
        $native = WorkflowRecord::factory()->signed()->for($company)->create();
        $url = route('companies.board', [$company, 'statements']);
        $this->actingAs($company->owner)->get($url)->assertOk()->assertSee('Anna Archiwalna')->assertSee('01.06.2025')->assertSee('Kilka dopasowań')->assertSee('Pliki (1)')->assertSee(route('monday.items.show', [$board, $item]), false)->assertSee($native->employee->fullName());
        $this->get($url.'?kind=termination')->assertOk()->assertDontSee('Anna Archiwalna')->assertDontSee($native->employee->fullName());
        $this->get($url.'?q=Archiwalna')->assertOk()->assertSee('Anna Archiwalna')->assertDontSee($native->employee->fullName());
        $this->get($url.'?status=submitted')->assertOk()->assertSee('Anna Archiwalna')->assertDontSee($native->employee->fullName());
        $this->assertDatabaseCount('workflow_records', 1);
        $this->assertNull($item->fresh()->parent_id);
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_source_statement_groups_paginate_independently_and_return_only_requested_ajax_group(): void
    {
        $company = Company::factory()->create();
        $board = MondayBoard::factory()->for($company)->create(['kind' => 'contract_statements']);
        MondayItem::factory()->count(52)->for($board, 'board')->create(['status' => 'Złożone']);
        $other = MondayBoard::factory()->for($company)->create(['kind' => 'amendment_statements']);
        MondayItem::factory()->for($other, 'board')->create(['name' => 'Aneks unikalny', 'status' => 'Podpisane']);
        $url = route('companies.board', [$company, 'statements']).'?page_source_contract=2';
        $response = $this->actingAs($company->owner)->get($url)->assertOk()->assertSee('data-total="53"', false)->assertSee('Aneks unikalny');
        $this->assertSame(3, substr_count($response->getContent(), 'data-export-row'));
        $response = $this->withHeader('X-Board-Group', 'source_contract')->get($url)->assertOk()->assertDontSee('Aneks unikalny')->assertDontSee('<html', false)->assertSee('data-current-page="2"', false);
        $this->assertSame(2, substr_count($response->getContent(), 'data-export-row'));
        $this->withHeader('X-Board-Group', 'source_termination')->get($url)->assertNotFound();
    }

    private function profile(Employee $employee): MondayItem
    {
        $board = MondayBoard::factory()->for($employee->company)->create(['kind' => 'profiles']);
        $headers = ['A' => 'Name', 'B' => 'Imię/Name*', 'C' => 'E-mail', 'D' => ProfileSource::labels()['is_student'][0], 'E' => 'Jestem zarejestrowany/a jako osoba bezrobotna', 'F' => 'Płeć/Gender', 'G' => 'Jaki wymiar etatu', 'H' => 'Data urodzenia/Date of birth', 'I' => 'Miejscowość zameldowania/place of residence', 'J' => 'Drugie imię/Second name', 'K' => 'Nazwisko rodowe/Family name', 'L' => "Imię ojca/Father's name"];
        $values = ['A' => 'Testowa', 'B' => 'Anna', 'C' => 'stary@example.test', 'D' => 'Tak', 'E' => 'Nie', 'F' => 'Kobieta', 'G' => '1/2', 'H' => '01.01.2001', 'I' => 'Inna miejscowość', 'J' => 'Maria', 'K' => 'Nowak', 'L' => 'Jan'];

        return MondayItem::factory()->for($board, 'board')->create(['employee_id' => $employee->id, 'name' => 'Testowa', 'source_data' => ['headers' => $headers, 'values' => $values, 'date1904' => false], 'fields' => $values]);
    }
}
