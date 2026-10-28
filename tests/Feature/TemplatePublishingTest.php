<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\PdfGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class TemplatePublishingTest extends TestCase
{
    public function test_docx_is_kept_private_with_suggested_mapping_and_cannot_be_activated_from_upload(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $file = $this->docx('Umowa z &lt;&lt;column_firstrow:PESEL strony umowy&gt;&gt;');
        $this->actingAs($company->owner)->post(route('companies.templates.store', $company), $this->data(['agreement_type' => 'b2b', 'file' => $file, 'body' => null, 'status' => 'active', 'company_id' => 999, 'version' => 99]))->assertSessionHasNoErrors()->assertRedirect();
        $template = $company->templates()->firstOrFail();
        $this->assertSame('draft', $template->status);
        $this->assertSame(1, $template->version);
        $this->assertSame($company->id, $template->company_id);
        $this->assertSame('contractor_tax_id', $template->field_mapping['<<column_firstrow:PESEL strony umowy>>']);
        Storage::disk('local')->assertExists($template->source_path);
        $this->assertSame(hash('sha256', $file->getContent()), $template->source_checksum);
        $this->assertNull($template->preview_path);
    }

    public function test_preview_is_private_and_activation_requires_that_preview_then_replaces_only_matching_cohort(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $old = DocumentTemplate::factory()->for($company)->create();
        $otherType = DocumentTemplate::factory()->for($company)->create(['agreement_type' => 'b2b']);
        $otherMarket = DocumentTemplate::factory()->for($company)->create(['market' => 'RO']);
        $draft = $this->draft($company);
        $this->post(route('companies.templates.activate', [$company, $draft]), ['revision' => 0, 'preview_confirmed' => 1])->assertSessionHasErrors('preview');
        $pdf = $this->pdf();
        $this->mock(PdfGenerator::class)->shouldReceive('render')->once()->withArgs(fn (string $html): bool => str_contains($html, 'Anna Przykładowa'))->andReturn($pdf);
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertTrue($draft->hasCurrentPreview());
        Storage::disk('local')->assertExists($draft->preview_path);
        $this->get(route('companies.templates.preview-file', [$company, $draft]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(route('companies.templates.activate', [$company, $draft]), ['revision' => $draft->revision, 'preview_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame('archived', $old->fresh()->status);
        $this->assertSame('active', $otherType->fresh()->status);
        $this->assertSame('active', $otherMarket->fresh()->status);
    }

    public function test_updating_active_template_creates_draft_version_without_replacing_active_body(): void
    {
        $company = Company::factory()->create();
        $active = DocumentTemplate::factory()->for($company)->create(['body' => 'Obowiązująca treść {{full_name}}']);
        $this->actingAs($company->owner)->patch(route('companies.templates.update', [$company, $active]), $this->data(['body' => 'Nowa treść {{full_name}}', 'revision' => 0]))->assertSessionHasNoErrors();
        $draft = $company->templates()->latest('id')->firstOrFail();
        $this->assertNotSame($active->id, $draft->id);
        $this->assertSame(2, $draft->version);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('Obowiązująca treść {{full_name}}', $active->fresh()->body);
        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame($active->fresh()->series_key, $draft->series_key);
    }

    public function test_editing_draft_invalidates_old_preview_and_stale_updates_are_rejected(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $draft = $this->draft($company);
        $this->mock(PdfGenerator::class)->shouldReceive('render')->once()->andReturn($this->pdf());
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->patch(route('companies.templates.update', [$company, $draft]), $this->data(['body' => 'Korekta {{full_name}}', 'revision' => $draft->revision]))->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertFalse($draft->hasCurrentPreview());
        $this->get(route('companies.templates.preview-file', [$company, $draft]))->assertNotFound();
        $this->patch(route('companies.templates.update', [$company, $draft]), $this->data(['body' => 'Stare okno', 'revision' => 0]))->assertConflict();
        $this->assertSame('Korekta {{full_name}}', $draft->fresh()->body);
        $this->post(route('companies.templates.activate', [$company, $draft]), ['revision' => $draft->revision, 'preview_confirmed' => 1])->assertSessionHasErrors('preview');
    }

    public function test_unknown_field_is_explicitly_mapped_before_preview(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $draft = $this->draft($company, ['body' => 'Dla <<column_firstrow:Osoba podpisująca>>']);
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasErrors('field_mapping');
        $this->patch(route('companies.templates.update', [$company, $draft]), $this->data(['body' => $draft->body, 'field_mapping' => ['full_name'], 'revision' => 0]))->assertSessionHasNoErrors();
        $this->mock(PdfGenerator::class)->shouldReceive('render')->once()->withArgs(fn (string $html): bool => str_contains($html, 'Dla Anna Przykładowa'))->andReturn($this->pdf());
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => $draft->fresh()->revision])->assertSessionHasNoErrors();
    }

    public function test_new_file_rebuilds_mapping_instead_of_applying_previous_field_positions(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $draft = $this->draft($company);
        $this->patch(route('companies.templates.update', [$company, $draft]), $this->data(['revision' => 0, 'file' => $this->docx('{{company_name}}'), 'field_mapping' => ['full_name']]))->assertSessionHasNoErrors();
        $this->assertSame('company_name', $draft->fresh()->field_mapping['{{company_name}}']);
    }

    public function test_missing_docx_converter_returns_error_and_keeps_draft_and_original(): void
    {
        Storage::fake('local');
        config(['hr.docx_binary' => '/missing/soffice']);
        $company = Company::factory()->create();
        $draft = $this->draft($company, ['file' => $this->docx('{{full_name}}'), 'body' => null]);
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasErrors('document');
        $this->assertSame('draft', $draft->fresh()->status);
        Storage::disk('local')->assertExists($draft->source_path);
        $this->assertNull($draft->preview_path);
    }

    public function test_source_change_after_preview_prevents_activation(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $draft = $this->draft($company, ['file' => UploadedFile::fake()->createWithContent('zalacznik.pdf', $this->pdf()), 'body' => null, 'kind' => 'attachment']);
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasNoErrors();
        $draft->refresh();
        Storage::disk('local')->put($draft->source_path, $this->pdf().'changed');
        $this->post(route('companies.templates.activate', [$company, $draft]), ['revision' => $draft->revision, 'preview_confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_changed_preview_cannot_be_opened_or_activated(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $draft = $this->draft($company, ['file' => UploadedFile::fake()->createWithContent('zalacznik.pdf', $this->pdf()), 'body' => null, 'kind' => 'attachment']);
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasNoErrors();
        $draft->refresh();
        Storage::disk('local')->put($draft->preview_path, $this->pdf().'changed');
        $this->get(route('companies.templates.preview-file', [$company, $draft]))->assertNotFound();
        $this->post(route('companies.templates.activate', [$company, $draft]), ['revision' => $draft->revision, 'preview_confirmed' => 1])->assertSessionHasErrors('preview');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    #[TestWith(['company_tax_id'])]
    #[TestWith(['email'])]
    public function test_required_template_data_cannot_be_explicitly_left_blank(string $field): void
    {
        $company = Company::factory()->create();
        $draft = $this->draft($company, ['body' => '{{'.$field.'}}']);
        $this->patch(route('companies.templates.update', [$company, $draft]), $this->data(['body' => $draft->body, 'revision' => 0, 'field_mapping' => ['__blank']]))->assertSessionHasNoErrors();
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => $draft->fresh()->revision])->assertSessionHasErrors('field_mapping');
        $this->assertNull($draft->fresh()->preview_path);
    }

    #[TestWith(['Nie jest PDF-em'])]
    #[TestWith(["%PDF-1.4\n/Encrypt 2 0 R\n%%EOF"])]
    #[TestWith(["%PDF-1.4\n/JavaScript (alert(1))\n%%EOF"])]
    public function test_invalid_or_active_pdf_is_not_saved_as_template(string $contents): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $this->actingAs($company->owner)->post(route('companies.templates.store', $company), $this->data(['file' => UploadedFile::fake()->createWithContent('wzor.pdf', $contents), 'body' => null]))->assertSessionHasErrors('file');
        $this->assertSame(0, $company->templates()->count());
    }

    public function test_unclosed_placeholder_cannot_be_saved(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($company->owner)->post(route('companies.templates.store', $company), $this->data(['body' => 'Umowa dla {{full_name']))->assertSessionHasErrors('body');
        $this->assertSame(0, $company->templates()->count());
    }

    #[TestWith(['preview'])]
    #[TestWith(['activate'])]
    #[TestWith(['archive'])]
    #[TestWith(['source'])]
    #[TestWith(['preview-file'])]
    public function test_regular_user_cannot_manage_or_download_template_sources(string $action): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['role' => 'user']);
        $company->users()->attach($user);
        $template = DocumentTemplate::factory()->for($company)->create();
        $this->actingAs($user);
        $url = route('companies.templates.'.$action, [$company, $template]);
        ($action === 'source' || $action === 'preview-file' ? $this->get($url) : $this->post($url, ['revision' => 0, 'preview_confirmed' => 1]))->assertForbidden();
    }

    public function test_nested_company_binding_hides_another_company_template(): void
    {
        $company = Company::factory()->create();
        $foreign = DocumentTemplate::factory()->create();
        $this->actingAs($company->owner)->get(route('companies.templates.edit', [$company, $foreign]))->assertNotFound();
        $this->post(route('companies.templates.activate', [$company, $foreign]), ['revision' => 0, 'preview_confirmed' => 1])->assertNotFound();
        $this->get(route('companies.templates.source', [$company, $foreign]))->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $template = DocumentTemplate::factory()->create();
        $this->get(route('companies.templates.source', [$template->company, $template]))->assertRedirect(route('login'));
    }

    public function test_library_and_editor_escape_names_and_support_extended_document_kinds(): void
    {
        $company = Company::factory()->create();
        $draft = $this->draft($company, ['name' => '<script>alert(1)</script>', 'kind' => 'monitoring']);
        $this->get(route('companies.templates.index', $company))->assertOk()->assertSee('Monitoring')->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('companies.templates.edit', [$company, $draft]))->assertOk()->assertSee('Wersja robocza')->assertSee('Rynek')->assertDontSee('<script>alert(1)</script>', false);
    }

    private function draft(Company $company, array $overrides = []): DocumentTemplate
    {
        $this->actingAs($company->owner)->post(route('companies.templates.store', $company), $this->data($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return $company->templates()->latest('id')->firstOrFail();
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'Wzór PL', 'kind' => 'contract', 'agreement_type' => 'mandate', 'market' => 'PL', 'language' => 'PL', 'body' => 'Dane: {{full_name}}'], $overrides);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";
    }

    private function docx(string $text): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kadrelio-docx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('wzor.docx', $contents);
    }
}
