<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowMail;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\MondayAsset;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MondayControlsTest extends TestCase
{
    #[TestWith(['Generuj dokument'])]
    #[TestWith(['GENEROWANIE'])]
    #[TestWith(['Generuj rozwiązanie umowy'])]
    #[TestWith(['Generuj'])]
    #[TestWith(['Generator'])]
    #[TestWith(['Przycisk'])]
    public function test_action_columns_open_document_controls_and_cannot_be_edited_as_text(string $label): void
    {
        $item = $this->importedContract();
        $source = $item->source_data;
        $source['headers']['E'] = $label;
        $item->source_data = $source;
        $item->board->forceFill(['columns' => $source['headers']])->save();
        $item->save();
        $this->templates($item->board->company);

        $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$item->board, $item]))->assertOk()
            ->assertSee('id="document-actions"', false)->assertSee('Generuj dokumenty')
            ->assertSee(route('companies.records.generate', [$item->board->company, $item->record]), false)
            ->assertDontSee('aria-label="Edytuj: '.$label.'"', false);
        $this->patch(route('monday.items.update', [$item->board, $item]), ['revision' => 0, 'fields' => ['E' => 'Podmieniony przycisk'], 'reason' => 'Korekta przycisku'])->assertUnprocessable();
        $this->assertSame('Generuj', $item->fresh()->fields['E']);
    }

    public function test_open_imported_document_can_be_generated_sent_and_signed_without_replacing_original_files(): void
    {
        Queue::fake();
        Storage::fake('local');
        $item = $this->importedContract();
        $company = $item->board->company;
        $this->templates($company);
        $source = $item->source_data;
        $original = MondayAsset::factory()->create(['monday_import_batch_id' => $item->board->monday_import_batch_id, 'path' => 'original.pdf']);
        Storage::disk('local')->put('original.pdf', '%PDF original');
        $item->assets()->attach($original, ['column_key' => 'F']);
        $record = $item->record;

        $this->actingAs($company->owner)->post(route('companies.records.generate', [$company, $record]), ['revision' => 0])
            ->assertSessionHasNoErrors()->assertRedirect(route('companies.records.show', [$company, $record]));
        $record->refresh();
        $this->assertFalse($record->isImported());
        $this->assertSame('ready', $record->status);
        $this->assertSame('Wygenerowana', $item->fresh()->fields['B']);
        $this->assertStringContainsString(e($record->employee->fullName()), $record->document_html);
        $this->get(route('companies.board', [$company, 'contracts']))->assertOk()->assertSee(route('companies.records.document', [$company, $record]), false)->assertDontSee(route('monday.items.show', [$item->board, $item]).'#files', false);
        $this->get(route('companies.records.show', [$company, $record]))->assertOk()->assertSee('Wyślij do podpisu');
        $this->get(route('companies.records.document', [$company, $record, 'preview' => 1]))->assertOk()->assertSee($record->employee->fullName());
        $this->post(route('companies.records.send', [$company, $record]))->assertRedirect();
        $this->assertSame('Wysłana do podpisu', $item->fresh()->status);
        $record->refresh();
        $this->post(route('signing.show', $record->signing_token), $this->signatureData($record))->assertRedirect(route('forms.success'));
        $this->assertSame('signed', $record->fresh()->status);
        $this->assertSame('Podpisana', $item->fresh()->fields['B']);
        $this->assertSame($source, $item->fresh()->source_data);
        $this->assertSame('%PDF original', Storage::disk('local')->get('original.pdf'));
        $this->assertTrue($item->assets()->whereKey($original->id)->exists());
        Queue::assertPushed(SendWorkflowMail::class, 2);
    }

    public function test_missing_templates_show_the_reason_and_do_not_activate_imported_workflow(): void
    {
        $item = $this->importedContract();
        $source = $item->source_data;
        $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$item->board, $item]))->assertOk()
            ->assertSee('Brakuje szablonu')->assertSee('Szablony dokumentów');
        $this->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasErrors('document');
        $this->assertTrue($item->record->fresh()->isImported());
        $this->assertNull($item->record->fresh()->generated_at);
        $this->assertSame($source, $item->fresh()->source_data);
    }

    public function test_signed_or_stale_imports_cannot_be_regenerated(): void
    {
        $item = $this->importedContract();
        $this->templates($item->board->company);
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 9])->assertConflict();
        $item->record->forceFill(['status' => 'signed'])->save();
        $this->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertConflict();
        $this->assertTrue($item->record->fresh()->isImported());
    }

    public function test_existing_statement_prevents_overwriting_an_imported_document(): void
    {
        $item = $this->importedContract();
        $this->templates($item->board->company);
        $board = MondayBoard::factory()->for($item->board->company)->create(['monday_import_batch_id' => $item->board->monday_import_batch_id, 'kind' => 'contract_statements']);
        MondayItem::factory()->for($board, 'board')->create(['parent_id' => $item->id]);
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasErrors('document');
        $this->assertTrue($item->record->fresh()->isImported());
    }

    public function test_read_only_member_and_other_company_cannot_activate_imported_workflow(): void
    {
        $item = $this->importedContract();
        $user = User::factory()->create(['role' => 'user']);
        $item->board->company->users()->attach($user);
        $this->actingAs($user)->get(route('monday.items.show', [$item->board, $item]))->assertOk()->assertDontSee('action="'.route('companies.records.generate', [$item->board->company, $item->record]).'"', false);
        $this->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertNotFound();
        $this->assertTrue($item->record->fresh()->isImported());
    }

    public function test_file_fields_download_only_the_assets_assigned_to_that_column(): void
    {
        $item = $this->importedContract();
        $first = MondayAsset::factory()->create(['monday_import_batch_id' => $item->board->monday_import_batch_id, 'path' => 'contract.pdf']);
        $other = MondayAsset::factory()->create(['monday_import_batch_id' => $item->board->monday_import_batch_id, 'path' => 'other.pdf']);
        $item->assets()->attach($first, ['column_key' => 'F']);
        $item->assets()->attach($other, ['column_key' => 'J']);
        $response = $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$item->board, $item]))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $links = (new \DOMXPath($document))->query('//div[@data-field-column="F"]//a');
        $this->assertCount(1, $links);
        $this->assertSame(route('monday.items.asset', [$item->board, $item, $first]), $links->item(0)->getAttribute('href'));
    }

    public function test_type_and_status_use_selects_reject_arbitrary_choices_and_preserve_empty_file_fields(): void
    {
        $item = $this->importedContract('Wybierz typ umowy');
        $this->actingAs($item->board->company->owner)->get(route('monday.items.show', [$item->board, $item]))->assertOk()
            ->assertSee('Nie wybrano typu umowy')->assertSee('<select name="fields[C]"', false)->assertSee('<select name="fields[B]"', false)
            ->assertDontSee('aria-label="Edytuj: Umowa i załączniki"', false);
        $this->patch(route('monday.items.update', [$item->board, $item]), ['revision' => 0, 'fields' => ['C' => 'Dowolny tekst'], 'reason' => 'Wybór typu'])->assertUnprocessable();
        $this->patch(route('monday.items.update', [$item->board, $item]), ['revision' => 0, 'fields' => ['F' => 'https://example.test/document.pdf'], 'reason' => 'Wklejenie pliku'])->assertUnprocessable();
        $this->patch(route('monday.items.update', [$item->board, $item]), ['revision' => 0, 'fields' => ['C' => 'Umowa zlecenie Bułgaria'], 'reason' => 'Wybór typu'])->assertRedirect();
        $this->assertSame('BG', $item->record->fresh()->language);
    }

    public function test_editing_a_generated_import_invalidates_its_snapshot_and_cannot_forge_a_signature(): void
    {
        $item = $this->importedContract();
        $this->templates($item->board->company);
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasNoErrors();
        $revision = $item->fresh()->revision;
        $this->patch(route('monday.items.update', [$item->board, $item]), ['revision' => $revision, 'fields' => ['B' => 'Podpisana'], 'reason' => 'Zmiana statusu'])->assertUnprocessable();
        $this->patch(route('monday.items.update', [$item->board, $item]), ['revision' => $revision, 'fields' => ['D' => 'p2'], 'reason' => 'Zmiana działu'])->assertRedirect();
        $this->assertSame('draft', $item->record->fresh()->status);
        $this->assertSame('p2', $item->record->fresh()->department);
        $this->assertNull($item->record->fresh()->document_html);
        $this->assertFalse($item->record->fresh()->isImported());
    }

    public function test_b2b_generation_cannot_fall_back_to_a_mandate_template(): void
    {
        $item = $this->importedContract('Umowa B2B Polska');
        $this->templates($item->board->company);
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasErrors('document');
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($item->board->company)->create(['kind' => $kind, 'agreement_type' => 'b2b', 'body' => 'Dokument B2B {{contractor_tax_id}} dla {{full_name}}']);
        }
        $this->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringContainsString('Dokument B2B 1234567890', $item->record->fresh()->document_html);
        $this->assertStringNotContainsString('Dokument zlecenia', $item->record->fresh()->document_html);
    }

    public function test_profile_correction_remains_consistent_when_another_source_field_is_edited(): void
    {
        $employee = Employee::factory()->completed()->create(['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'old@example.test']);
        $board = MondayBoard::factory()->for($employee->company)->create(['kind' => 'profiles']);
        $bankAccount = str_repeat('1', 26);
        $fields = ['A' => 'Kowalska', 'B' => 'Anna', 'C' => 'old@example.test', 'D' => '123456789', 'E' => $bankAccount];
        $item = MondayItem::factory()->for($board, 'board')->for($employee)->create(['name' => 'Kowalska', 'fields' => $fields, 'source_data' => ['headers' => ['A' => 'Name', 'B' => 'Imię/Name*', 'C' => 'E-mail', 'D' => 'Telefon/phone number', 'E' => 'Numer konta bankowego/account number'], 'values' => $fields, 'cells' => [], 'date1904' => false]]);
        $employee->update(['personal_data' => ['monday_profile_id' => $item->id, 'profile_review_required' => false, 'street' => 'Nowa ulica', 'bank_account' => $bankAccount]]);
        $source = $item->source_data;
        $this->actingAs($employee->company->owner)->patch(route('companies.employees.update', [$employee->company, $employee]), ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'correct@example.test', 'street' => 'Nowa ulica'])->assertRedirect();
        $this->assertSame('correct@example.test', $item->fresh()->fields['C']);
        $this->assertSame($bankAccount, $employee->fresh()->personal_data['bank_account']);
        $this->patch(route('monday.items.update', [$board, $item]), ['revision' => $item->fresh()->revision, 'fields' => ['D' => '987654321'], 'reason' => 'Korekta telefonu'])->assertRedirect();
        $this->assertSame('correct@example.test', $employee->fresh()->email);
        $this->assertSame('987654321', $employee->fresh()->phone);
        $this->assertSame('Nowa ulica', $employee->fresh()->personal_data['street']);
        $this->assertSame($bankAccount, $item->fresh()->fields['E']);
        $this->assertSame($source, $item->fresh()->source_data);
    }

    public function test_signing_a_termination_updates_the_visible_imported_contract_status_and_group(): void
    {
        Queue::fake();
        $item = $this->importedContract();
        $contract = $item->record;
        $contract->forceFill(['status' => 'signed'])->save();
        $termination = WorkflowRecord::factory()->sent()->for($contract->company)->for($contract->employee)->create(['kind' => 'termination', 'contract_id' => $contract->id, 'ends_at' => '2026-11-30']);
        $this->post(route('signing.show', $termination->signing_token), $this->signatureData($termination) + ['effective_date' => '2026-11-30'])->assertRedirect(route('forms.success'));
        $this->assertSame('terminated', $contract->fresh()->status);
        $this->assertSame('Rozwiązana', $item->fresh()->status);
        $this->assertSame('Rozwiązana', $item->fresh()->fields['B']);
        $this->assertSame('Umowy rozwiązane', $item->fresh()->group_name);
        Queue::assertPushed(SendWorkflowMail::class);
    }

    #[TestWith(['Zgłoszenie nowej umowy', 'contract'])]
    #[TestWith(['Fomularz zgloszenia aneksu', 'amendment'])]
    #[TestWith(['Rozwiązanie umowy', 'termination'])]
    public function test_imported_form_links_use_the_local_company_forms(string $name, string $kind): void
    {
        $board = MondayBoard::factory()->create(['kind' => 'links', 'columns' => ['A' => 'Name', 'B' => 'Link']]);
        $fields = ['A' => $name, 'B' => 'https://forms.monday.com/forms/old-form'];
        $item = MondayItem::factory()->for($board, 'board')->create(['name' => $name, 'fields' => $fields, 'source_data' => ['headers' => $board->columns, 'values' => $fields, 'cells' => [], 'date1904' => false]]);
        $this->actingAs($board->company->owner)->get(route('monday.items.show', [$board, $item]))->assertOk()
            ->assertSee(route('companies.records.create', [$board->company, $kind]), false)->assertDontSee($fields['B']);
        $this->assertSame($fields['B'], $item->fresh()->source_data['values']['B']);
    }

    public function test_inactive_form_links_cannot_be_opened_or_copied_as_if_ready(): void
    {
        $company = Company::factory()->create(['privacy_url' => '']);
        $this->actingAs($company->owner)->get(route('companies.board', [$company, 'links']))->assertOk()
            ->assertSee('Formularz wymaga uzupełnienia ustawień firmy.')->assertDontSee('data-copy=', false);
    }

    public function test_read_only_member_cannot_send_or_withdraw_a_continued_document(): void
    {
        $item = $this->importedContract();
        $this->templates($item->board->company);
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasNoErrors();
        $user = User::factory()->create(['role' => 'user']);
        $item->board->company->users()->attach($user);
        $this->actingAs($user)->get(route('companies.records.show', [$item->board->company, $item->record]))->assertOk()
            ->assertDontSee('action="'.route('companies.records.send', [$item->board->company, $item->record]).'"', false);
        $this->post(route('companies.records.send', [$item->board->company, $item->record]))->assertForbidden();
        $this->patch(route('companies.records.update', [$item->board->company, $item->record]), ['status' => 'not_concluded'])->assertForbidden();
    }

    public function test_b2b_selection_does_not_turn_a_personal_identifier_into_a_tax_id(): void
    {
        $item = $this->importedContract();
        $this->actingAs($item->board->company->owner)->patch(route('monday.items.update', [$item->board, $item]), ['revision' => 0, 'fields' => ['C' => 'Umowa B2B Polska'], 'reason' => 'Wybór B2B'])->assertRedirect();
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($item->board->company)->create(['kind' => $kind, 'agreement_type' => 'b2b']);
        }
        $this->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => $item->fresh()->revision])->assertSessionHasErrors('document');
        $this->assertTrue($item->record->fresh()->isImported());
    }

    public function test_employee_document_list_uses_document_date_instead_of_import_date(): void
    {
        $item = $this->importedContract();
        $item->record->forceFill(['concluded_at' => '2024-03-15'])->save();
        $this->actingAs($item->board->company->owner)->get(route('companies.employees.show', [$item->board->company, $item->employee]))->assertOk()->assertSee('15.03.2024');
    }

    public function test_source_department_case_does_not_break_template_selection_or_filtering(): void
    {
        $item = $this->importedContract();
        $fields = $item->fields;
        $fields['D'] = 'P1';
        $item->fields = $fields;
        $item->save();
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($item->board->company)->create(['kind' => $kind, 'department' => 'p1', 'body' => 'Właściwy wzór P1']);
            DocumentTemplate::factory()->for($item->board->company)->create(['kind' => $kind, 'body' => 'Ogólny wzór']);
        }
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasNoErrors();
        $this->assertStringContainsString('Właściwy wzór P1', $item->record->fresh()->document_html);
        $this->assertStringNotContainsString('Ogólny wzór', $item->record->fresh()->document_html);
        $this->get(route('companies.board', [$item->board->company, 'contracts', 'department' => 'p1']))->assertOk()->assertSee($item->employee->fullName());
    }

    public function test_generation_from_a_drawer_keeps_the_current_document_in_the_drawer(): void
    {
        $item = $this->importedContract();
        $company = $item->board->company;
        $this->templates($company);
        $this->actingAs($company->owner)->get(route('monday.items.show', [$item->board, $item, 'panel' => 1]))->assertOk()->assertSee('name="panel" value="1"', false);
        $this->post(route('companies.records.generate', [$company, $item->record]), ['revision' => 0, 'panel' => 1])->assertRedirect(route('companies.records.show', [$company, $item->record, 'panel' => 1]));
        $this->get(route('companies.records.show', [$company, $item->record, 'panel' => 1]))->assertOk()->assertSee('panel-body');
    }

    public function test_a_continued_document_cannot_be_reassigned_to_another_original_contract(): void
    {
        $contract = $this->importedContract();
        $board = MondayBoard::factory()->for($contract->board->company)->create(['kind' => 'amendments', 'monday_import_batch_id' => $contract->board->monday_import_batch_id]);
        $record = WorkflowRecord::factory()->ready()->for($contract->board->company)->for($contract->employee)->create(['kind' => 'amendment', 'contract_id' => $contract->workflow_record_id, 'data' => ['monday_source' => 'controls', 'monday_workflow_active' => true]]);
        $item = MondayItem::factory()->for($board, 'board')->for($contract->employee)->create(['workflow_record_id' => $record->id, 'parent_id' => $contract->id]);
        $replacement = MondayItem::factory()->for($contract->board, 'board')->create();
        $this->actingAs($board->company->owner)->post(route('monday.items.relation', [$board, $item]), ['revision' => 0, 'parent_id' => $replacement->id, 'reason' => 'Podmiana umowy'])->assertConflict();
        $this->assertSame($contract->id, $item->fresh()->parent_id);
        $this->get(route('monday.items.show', [$board, $item]))->assertOk()->assertDontSee('action="'.route('monday.items.relation', [$board, $item]).'"', false);
    }

    public function test_imported_child_uses_its_original_contract_language_for_readiness_and_generation(): void
    {
        $contract = $this->importedContract('UZ Rumunia');
        $company = $contract->board->company;
        $contract->record->forceFill(['status' => 'signed'])->save();
        $board = MondayBoard::factory()->for($company)->create(['kind' => 'terminations', 'monday_import_batch_id' => $contract->board->monday_import_batch_id, 'columns' => ['A' => 'Name', 'B' => 'Status', 'C' => 'Generuj rozwiązanie umowy']]);
        $record = WorkflowRecord::factory()->for($company)->for($contract->employee)->create(['kind' => 'termination', 'status' => 'draft', 'contract_id' => $contract->workflow_record_id, 'language' => 'PL', 'ends_at' => '2026-11-30', 'data' => ['monday_source' => 'controls']]);
        $fields = ['A' => $contract->name, 'B' => 'W przygotowaniu', 'C' => 'Generuj'];
        $item = MondayItem::factory()->for($board, 'board')->for($contract->employee)->create(['workflow_record_id' => $record->id, 'parent_id' => $contract->id, 'status' => 'W przygotowaniu', 'document_date' => '2026-11-30', 'fields' => $fields, 'source_data' => ['headers' => $board->columns, 'values' => $fields, 'cells' => [], 'date1904' => false]]);
        DocumentTemplate::factory()->for($company)->create(['kind' => 'termination', 'language' => 'RO', 'body' => 'Rozwiązanie RO {{ends_at}}']);
        $response = $this->actingAs($company->owner)->get(route('monday.items.show', [$board, $item]))->assertOk();
        $this->assertSame([], $response->viewData('generationBlockers'));
        $this->get(route('companies.board', [$company, 'contracts']))->assertOk()->assertSee('UZ Rumunia (RO)');
        $this->post(route('companies.records.generate', [$company, $record]), ['revision' => 0])->assertSessionHasNoErrors();
        $this->assertSame('RO', $record->fresh()->language);
    }

    public function test_missing_document_dates_prevent_generating_an_unusable_imported_contract(): void
    {
        $item = $this->importedContract();
        $fields = $item->fields;
        $fields['H'] = '';
        $item->fields = $fields;
        $item->save();
        $this->templates($item->board->company);
        $this->actingAs($item->board->company->owner)->post(route('companies.records.generate', [$item->board->company, $item->record]), ['revision' => 0])->assertSessionHasErrors('document');
        $this->assertTrue($item->record->fresh()->isImported());
        $this->assertNull($item->record->fresh()->generated_at);
    }

    private function importedContract(string $type = 'Umowa zlecenie Ogólna Polska'): MondayItem
    {
        $employee = Employee::factory()->completed()->create();
        $record = WorkflowRecord::factory()->for($employee)->for($employee->company)->create(['status' => 'draft', 'data' => ['monday_source' => 'controls']]);
        $headers = ['A' => 'Name', 'B' => 'Status umowy', 'C' => 'Typ Umowy', 'D' => 'Załącznik UZ', 'E' => 'Generuj dokument', 'F' => 'Umowa i załączniki', 'G' => 'Data zawarcia umowy formularz', 'H' => 'Data rozpoczęcia umowy formularz', 'I' => 'Data zakończenia umowy formularz', 'J' => 'PESEL strony umowy'];
        $fields = ['A' => $employee->fullName(), 'B' => 'W przygotowaniu', 'C' => $type, 'D' => 'p1', 'E' => 'Generuj', 'F' => '', 'G' => today()->toDateString(), 'H' => today()->toDateString(), 'I' => today()->addYear()->toDateString(), 'J' => str_contains($type, 'B2B') ? '1234567890' : $employee->pesel];
        $board = MondayBoard::factory()->for($employee->company)->create(['columns' => $headers, 'groups' => ['Nowe zgłoszenia', 'Aktualne umowy', 'Umowy rozwiązane']]);

        return MondayItem::factory()->for($board, 'board')->for($employee)->create(['name' => $employee->fullName(), 'status' => 'W przygotowaniu', 'group_name' => 'Nowe zgłoszenia', 'workflow_record_id' => $record->id, 'document_date' => today(), 'fields' => $fields, 'source_data' => ['headers' => $headers, 'values' => $fields, 'cells' => [], 'date1904' => false]]);
    }

    private function templates(Company $company): void
    {
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($company)->create(['kind' => $kind, 'body' => 'Dokument zlecenia {{full_name}}.']);
        }
    }

    private function signatureData(WorkflowRecord $record): array
    {
        $image = imagecreatetruecolor(300, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 20, 65, 210, 20, imagecolorallocate($image, 30, 30, 50));
        ob_start();
        imagepng($image);
        $signature = 'data:image/png;base64,'.base64_encode(ob_get_clean());

        return ['full_name' => $record->employee->fullName(), 'pesel' => $record->employee->pesel, 'signature_name' => $record->employee->fullName(), 'signature_image' => $signature, 'accepted' => '1', 'document_hash' => $record->document_hash];
    }
}
