<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MondayAsset;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\MondayUpdate;
use App\Models\User;
use App\Models\WorkflowRecord;
use App\Services\HrWorkflow;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MondayAccessTest extends TestCase
{
    public function test_attached_originals_are_accessible_from_the_regular_contract_board(): void
    {
        $record = WorkflowRecord::factory()->create();
        $board = MondayBoard::factory()->for($record->company)->create();
        $item = MondayItem::factory()->for($board, 'board')->for($record->employee)->create(['workflow_record_id' => $record->id]);
        $file = MondayAsset::factory()->create(['monday_import_batch_id' => $board->monday_import_batch_id, 'path' => 'monday/private/contract.pdf']);
        $item->assets()->attach($file, ['column_key' => 'E']);

        $this->actingAs($record->company->owner)->get(route('companies.board', [$record->company, 'contracts']))->assertOk()
            ->assertSee('Pliki (1)')->assertSee(route('monday.items.show', [$board, $item]).'#files', false);
    }

    public function test_company_member_can_browse_search_and_read_every_column_and_comment(): void
    {
        $item = MondayItem::factory()->create();
        $member = User::factory()->create(['role' => 'user']);
        $item->board->company->users()->attach($member);
        MondayUpdate::factory()->create(['monday_item_id' => $item->id, 'monday_import_batch_id' => $item->board->monday_import_batch_id]);

        $this->actingAs($member)->get(route('monday.index'))->assertOk()->assertSee($item->board->name);
        $this->get(route('companies.board', [$item->board->company, 'contracts']))->assertOk()->assertDontSee('Historia z Monday')->assertDontSee('Dane z Monday')->assertDontSee('Tablice z Monday')->assertDontSee(route('monday.boards.show', $item->board));
        $this->get(route('monday.boards.show', $item->board))->assertOk()->assertSee('Anna Kowalska');
        $this->get(route('monday.boards.show', [$item->board, 'q' => '44051401458']))->assertOk()->assertSee('Anna Kowalska');
        $this->get(route('monday.boards.show', [$item->board, 'q' => 'brak-dopasowania']))->assertOk()->assertDontSee('Anna Kowalska')->assertSee('Brak wpisów');
        $this->get(route('monday.items.show', [$item->board, $item]))->assertOk()->assertSee('44051401458')->assertSee('Komentarz do umowy')
            ->assertDontSee('data-edit-field', false)->assertDontSee('data-inline-edit', false);
    }

    public function test_employee_shows_one_document_list_and_keeps_profile_review_accessible(): void
    {
        $employee = Employee::factory()->completed()->create(['personal_data' => ['profile_review_required' => true]]);
        $board = MondayBoard::factory()->for($employee->company)->create(['kind' => 'profiles']);
        $profile = MondayItem::factory()->for($board, 'board')->for($employee)->create();
        $record = WorkflowRecord::factory()->for($employee)->for($employee->company)->create();
        MondayItem::factory()->for($employee)->create(['monday_board_id' => MondayBoard::factory()->for($employee->company), 'workflow_record_id' => $record->id]);

        $this->actingAs($employee->company->owner)->get(route('companies.employees.show', [$employee->company, $employee]))->assertOk()
            ->assertSee('Dokumenty pracownika')->assertSee('Sprawdź wersje danych')->assertSee(route('monday.items.show', [$board, $profile]), false)
            ->assertSee(route('companies.records.show', [$employee->company, $record]), false)->assertDontSee('Historia z Monday')->assertDontSee('monday-history');
        $this->get(route('monday.items.show', [$board, $profile]))->assertOk()->assertSee('Wersje danych pracownika')->assertSee('Komentarze')->assertDontSee('Komentarze z Monday')->assertDontSee('Identyfikator wpisu Monday');
    }

    public function test_equipment_boards_are_available_once_within_the_company_navigation(): void
    {
        $company = Company::factory()->create();
        $equipment = MondayBoard::factory()->for($company)->create(['kind' => 'equipment_assignment', 'name' => 'Powierzenia sprzętu']);
        $otherCompanyBoard = MondayBoard::factory()->create(['kind' => 'equipment_return', 'name' => 'Sprzęt innej firmy']);

        $this->actingAs($company->owner)->get(route('companies.board', [$company, 'contracts']))->assertOk()
            ->assertSee('Powierzenia sprzętu')->assertSee(route('monday.boards.show', $equipment), false)
            ->assertDontSee(route('monday.boards.show', $otherCompanyBoard), false)->assertDontSee('Tablice z Monday');
        $this->get(route('monday.boards.show', $equipment))->assertOk()->assertSee('Powierzenia sprzętu')->assertDontSee('Dane z Monday')->assertDontSee('Obieg kadrowy');
    }

    public function test_imported_data_requires_authentication_and_is_hidden_from_other_companies(): void
    {
        $item = MondayItem::factory()->create();
        $this->get(route('monday.items.show', [$item->board, $item]))->assertRedirect(route('login'));
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('monday.index'))->assertOk()->assertDontSee($item->board->name);
        $this->get(route('monday.boards.show', $item->board))->assertNotFound();
        $this->get(route('monday.items.show', [$item->board, $item]))->assertNotFound();
        $this->get(route('monday.boards.source', $item->board))->assertNotFound();
        $this->get(route('monday.boards.export', $item->board))->assertNotFound();
        $this->patch(route('monday.items.update', [$item->board, $item]), $this->edit())->assertNotFound();
    }

    public function test_nested_items_cannot_be_accessed_through_another_board(): void
    {
        $item = MondayItem::factory()->create();
        $other = MondayBoard::factory()->for($item->board->company)->create();

        $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$other, $item]))->assertNotFound();
    }

    public function test_unassigned_boards_are_visible_only_to_global_admin(): void
    {
        $item = MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->state(['company_id' => null])]);
        $manager = User::factory()->create();
        $this->actingAs($manager)->get(route('monday.items.show', [$item->board, $item]))->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('monday.items.show', [$item->board, $item]))->assertOk();
        $this->get(route('monday.index'))->assertOk()->assertSee('Pozostałe tablice konta');
    }

    public function test_field_changes_preserve_source_and_are_audited_without_outgoing_actions(): void
    {
        Mail::fake();
        Queue::fake();
        $item = MondayItem::factory()->create();
        $source = $item->source_data;

        $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$item->board, $item]))->assertOk()
            ->assertSee('Edytuj: Nazwa')->assertSee('Edytuj: Status umowy')->assertDontSee('Edytuj: PESEL Pracownika');
        $this->actingAs($item->board->company->owner)->patch(route('monday.items.update', [$item->board, $item]), $this->edit())->assertRedirect();

        $item->refresh();
        $this->assertSame('Rozwiązana', $item->fields['B']);
        $this->assertSame('Rozwiązana', $item->status);
        $this->assertSame('Aktualne umowy', $item->group_name);
        $this->assertSame($source, $item->source_data);
        $this->assertSame(1, $item->revision);
        $revision = $item->revisions->sole();
        $this->assertSame('Podpisana', $revision->changes['fields']['B']['before']);
        $this->assertSame('Rozwiązana', $revision->changes['fields']['B']['after']);
        $this->assertSame($item->board->company->owner_id, $revision->user_id);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_user_role_cannot_edit_imported_fields(): void
    {
        $item = MondayItem::factory()->create();
        $member = User::factory()->create(['role' => 'user']);
        $item->board->company->users()->attach($member);

        $this->actingAs($member)->patch(route('monday.items.update', [$item->board, $item]), $this->edit())->assertForbidden();
        $this->post(route('monday.items.relation', [$item->board, $item]), ['revision' => 0, 'parent_id' => $item->id, 'reason' => 'Sprawdzenie dostępu'])->assertForbidden();
        $this->post(route('monday.items.profile', [$item->board, $item]), ['revision' => 0, 'reason' => 'Sprawdzenie dostępu'])->assertForbidden();
        $this->post(route('monday.items.review', [$item->board, $item]), ['revision' => 0, 'reason' => 'Sprawdzenie dostępu'])->assertForbidden();

        $this->assertSame('Podpisana', $item->fresh()->status);
        $this->assertDatabaseCount('monday_revisions', 0);
    }

    public function test_identity_columns_and_unknown_columns_cannot_be_changed(): void
    {
        $item = MondayItem::factory()->create();
        $this->actingAs($item->board->company->owner);

        $this->patch(route('monday.items.update', [$item->board, $item]), array_replace($this->edit(), ['fields' => ['C' => '99999999999']]))->assertUnprocessable();
        $this->patch(route('monday.items.update', [$item->board, $item]), array_replace($this->edit(), ['fields' => ['ZZ' => 'forged']]))->assertUnprocessable();

        $this->assertSame('44051401458', $item->fresh()->fields['C']);
        $this->assertDatabaseCount('monday_revisions', 0);
    }

    public function test_stale_changes_are_rejected_instead_of_overwriting_another_manager(): void
    {
        $item = MondayItem::factory()->create(['revision' => 2]);

        $this->actingAs($item->board->company->owner)->patch(route('monday.items.update', [$item->board, $item]), $this->edit())->assertConflict();

        $this->assertSame('Podpisana', $item->fresh()->status);
        $this->assertDatabaseCount('monday_revisions', 0);
    }

    public function test_manual_links_require_same_company_import_and_correct_document_kind(): void
    {
        $item = MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->state(['kind' => 'amendments']), 'relation_state' => 'unmatched']);
        $foreign = MondayItem::factory()->create();
        $sameCompanyWrongBatch = MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->for($item->board->company)]);
        $valid = MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->for($item->board->company)->state(['monday_import_batch_id' => $item->board->monday_import_batch_id, 'kind' => 'contracts'])]);
        $this->actingAs($item->board->company->owner);
        $payload = ['revision' => 0, 'parent_id' => $foreign->id, 'reason' => 'Sprawdzono dokument źródłowy'];

        $this->post(route('monday.items.relation', [$item->board, $item]), $payload)->assertNotFound();
        $this->post(route('monday.items.relation', [$item->board, $item]), array_replace($payload, ['parent_id' => $sameCompanyWrongBatch->id]))->assertNotFound();
        $this->post(route('monday.items.relation', [$item->board, $item]), array_replace($payload, ['parent_id' => $valid->id]))->assertRedirect();

        $this->assertSame($valid->id, $item->fresh()->parent_id);
        $this->assertSame('manual', $item->fresh()->relation_state);
        $this->assertDatabaseCount('monday_revisions', 1);
    }

    public function test_choosing_a_profile_clears_old_values_and_keeps_all_versions(): void
    {
        $board = MondayBoard::factory()->create(['kind' => 'profiles']);
        $employee = Employee::factory()->for($board->company)->create(['personal_data' => ['profile_review_required' => true, 'bank_account' => 'old-account']]);
        $headers = ['A' => 'Name', 'B' => 'Imię/Name*', 'C' => 'E-mail', 'D' => 'Numer konta bankowego/account number'];
        $profile = MondayItem::factory()->create(['monday_board_id' => $board->id, 'employee_id' => $employee->id, 'name' => 'Kowalska', 'source_data' => ['headers' => $headers, 'cells' => [], 'date1904' => false], 'fields' => ['A' => 'Kowalska', 'B' => 'Anna', 'C' => 'nowy@example.test', 'D' => '']]);
        MondayItem::factory()->create(['monday_board_id' => $board->id, 'employee_id' => $employee->id]);

        $this->actingAs($board->company->owner)->post(route('monday.items.profile', [$board, $profile]), ['revision' => 0, 'reason' => 'Potwierdzono aktualne dane'])->assertRedirect();

        $employee->refresh();
        $this->assertSame('nowy@example.test', $employee->email);
        $this->assertArrayNotHasKey('bank_account', $employee->personal_data);
        $this->assertFalse($employee->personal_data['profile_review_required']);
        $this->assertSame($profile->id, $employee->personal_data['monday_profile_id']);
        $this->assertSame(2, $employee->mondayItems->count());
        $this->assertDatabaseCount('monday_revisions', 1);
    }

    public function test_original_files_are_private_scoped_and_missing_assets_have_no_fake_download(): void
    {
        Storage::fake('local');
        $item = MondayItem::factory()->create();
        $file = MondayAsset::factory()->create(['monday_import_batch_id' => $item->board->monday_import_batch_id, 'path' => 'monday/private/original.pdf']);
        Storage::disk('local')->put($file->path, '%PDF original');
        $item->assets()->attach($file, ['column_key' => 'E']);
        $foreignFile = MondayAsset::factory()->create();
        $missing = MondayAsset::factory()->create(['monday_import_batch_id' => $item->board->monday_import_batch_id]);
        $item->assets()->attach($missing, ['column_key' => 'F']);
        $this->actingAs($item->board->company->owner);

        $this->get(route('monday.items.asset', [$item->board, $item, $file]))->assertOk()->assertDownload('umowa.pdf');
        $this->get(route('monday.items.asset', [$item->board, $item, $foreignFile]))->assertNotFound();
        $this->get(route('monday.items.asset', [$item->board, $item, $missing]))->assertNotFound();
        $this->get(route('monday.boards.export', $item->board))->assertOk()->assertDownload();
    }

    public function test_source_html_is_escaped_in_values_names_and_comments(): void
    {
        $item = MondayItem::factory()->create(['name' => '<script>name()</script>', 'fields' => ['A' => '<script>name()</script>', 'B' => '<img src=x onerror=alert(1)>']]);
        MondayUpdate::factory()->create(['monday_item_id' => $item->id, 'monday_import_batch_id' => $item->board->monday_import_batch_id, 'payload' => ['headers' => ['A' => 'User', 'B' => 'Update Content'], 'values' => ['A' => '<script>user()</script>', 'B' => '<script>comment()</script>']]]);

        $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$item->board, $item]))->assertOk()->assertSee('&lt;script&gt;name()', false)->assertSee('&lt;script&gt;comment()', false)->assertDontSee('<script>name()', false)->assertDontSee('<script>comment()', false)->assertDontSee('<img src=x', false);
    }

    public function test_historical_records_cannot_be_regenerated_emailed_or_signed_as_new_documents(): void
    {
        Mail::fake();
        Queue::fake();
        $record = WorkflowRecord::factory()->sent()->create(['data' => ['monday_source' => 'historical'], 'signed_at' => null]);
        $item = MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->for($record->company), 'workflow_record_id' => $record->id]);

        $this->assertFalse($record->canBeSigned());
        $this->actingAs($record->company->owner)->get(route('companies.records.show', [$record->company, $record]))->assertRedirect(route('monday.items.show', [$item->board, $item]));
        $this->post(route('companies.records.generate', [$record->company, $record]))->assertConflict();
        $this->post(route('companies.records.send', [$record->company, $record]))->assertConflict();
        $this->get(route('companies.records.document', [$record->company, $record]))->assertConflict();
        $this->get(route('signing.show', $record->signing_token))->assertGone();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function edit(): array
    {
        return ['revision' => 0, 'fields' => ['B' => 'Rozwiązana'], 'reason' => 'Sprawdzono aktualny status'];
    }

    public function test_review_clears_the_review_queue_without_changing_source_facts(): void
    {
        $item = MondayItem::factory()->create(['issues' => ['nip_in_pesel']]);
        $source = $item->source_data;
        $this->assertSame(1, $item->board->items()->needsReview()->count());

        $this->actingAs($item->board->company->owner)->post(route('monday.items.review', [$item->board, $item]), ['revision' => 0, 'reason' => 'Potwierdzono identyfikator NIP'])->assertRedirect();

        $this->assertSame(0, $item->board->items()->needsReview()->count());
        $this->assertNotNull($item->fresh()->reviewed_at);
        $this->assertSame(['nip_in_pesel'], $item->fresh()->issues);
        $this->assertSame($source, $item->fresh()->source_data);
        $this->assertTrue($item->revisions->sole()->changes['review']);
    }

    public function test_imported_company_forms_are_inactive_until_hr_and_privacy_settings_are_completed(): void
    {
        Mail::fake();
        $company = Company::factory()->create(['privacy_url' => '']);

        $this->get(route('forms.profile', $company->public_token))->assertStatus(503)->assertSee('Formularz jeszcze nieaktywny');
        $this->get(route('forms.additional', [$company->public_token, 'multisport']))->assertStatus(503);
        $this->post(route('forms.additional', [$company->public_token, 'multisport']), [])->assertStatus(503);

        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_unknown_monday_agreement_type_is_not_assumed_to_be_a_mandate_for_new_documents(): void
    {
        $record = WorkflowRecord::factory()->create(['status' => 'signed', 'data' => ['monday_source' => 'legacy']]);
        MondayItem::factory()->create(['monday_board_id' => MondayBoard::factory()->for($record->company), 'workflow_record_id' => $record->id]);
        $this->assertSame('Typ umowy do ustalenia', $record->agreementLabel());

        try {
            app(HrWorkflow::class)->create($record->company, 'amendment', ['contract_id' => $record->id], $record->company->owner);
            $this->fail('Nieustalony typ umowy powinien zatrzymać tworzenie nowego dokumentu.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contract_id', $exception->errors());
        }

        $this->assertDatabaseCount('workflow_records', 1);
    }
}
