<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowMail;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\WorkflowRecord;
use App\Services\HrWorkflow;
use App\Services\PdfGenerator;
use App\Services\TemplateRenderer;
use App\Services\TemplateSet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TemplateSetTest extends TestCase
{
    public function test_uses_only_active_matching_market_type_company_and_department(): void
    {
        $company = Company::factory()->create();
        $record = WorkflowRecord::factory()->for($company)->create(['language' => 'RO', 'department' => 'p2']);
        $generic = DocumentTemplate::factory()->for($company)->create(['market' => 'RO', 'language' => 'PL', 'package_kinds' => ['contract']]);
        $specific = DocumentTemplate::factory()->for($company)->create(['market' => 'RO', 'language' => 'PL', 'department' => 'P2', 'package_kinds' => ['contract', 'monitoring']]);
        $monitoring = DocumentTemplate::factory()->for($company)->create(['kind' => 'monitoring', 'market' => 'RO', 'language' => 'PL']);
        DocumentTemplate::factory()->for($company)->create(['status' => 'draft', 'market' => 'RO', 'language' => 'PL', 'department' => 'P2']);
        DocumentTemplate::factory()->for($company)->create(['status' => 'archived', 'market' => 'RO', 'language' => 'PL', 'department' => 'P2']);
        DocumentTemplate::factory()->for($company)->create(['market' => 'RO', 'agreement_type' => 'b2b', 'department' => 'P2']);
        DocumentTemplate::factory()->create(['market' => 'RO', 'department' => 'P2']);
        $set = app(TemplateSet::class)->forRecord($record);
        $this->assertSame([$specific->id, $monitoring->id], array_map(fn (DocumentTemplate $template): int => $template->id, $set['templates']));
        $this->assertSame([], $set['missing']);
        $this->assertNotSame($generic->id, $set['templates'][0]->id);
    }

    public function test_declared_package_blocks_generation_when_extra_document_is_missing(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'draft']);
        DocumentTemplate::factory()->for($company)->create(['package_kinds' => ['contract', 'regulations']]);
        $blockers = app(HrWorkflow::class)->generationBlockers($record);
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('regulamin', $blockers[0]);
    }

    public function test_full_native_pdf_set_is_snapshotted_and_template_activation_does_not_change_existing_document(): void
    {
        if (! is_executable(config('hr.pdf_merge_binary'))) {
            $this->markTestSkipped('Brak pdfunite do testu łączenia rzeczywistych PDF.');
        }
        Storage::fake('local');
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'draft']);
        $primary = $this->pdfTemplate($company, 'contract', 'CONTRACT', ['contract', 'monitoring']);
        $this->pdfTemplate($company, 'monitoring', 'MONITORING');
        app(HrWorkflow::class)->generate($record);
        $record->refresh();
        $pdf = Storage::disk('local')->get($record->pdf_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('~/Count\s+2\b~', $pdf);
        $this->assertSame(hash('sha256', $pdf), $record->document_hash);
        $this->assertCount(2, $record->data['document_parts']);
        $originalPath = $record->pdf_path;
        $this->actingAs($company->owner)->patch(route('companies.templates.update', [$company, $primary]), ['name' => 'Nowa umowa', 'kind' => 'contract', 'agreement_type' => 'mandate', 'market' => 'PL', 'language' => 'PL', 'revision' => 0, 'file' => UploadedFile::fake()->createWithContent('nowa.pdf', $this->pdf('NEW CONTRACT'))])->assertSessionHasNoErrors();
        $draft = $company->templates()->latest('id')->firstOrFail();
        $this->post(route('companies.templates.preview', [$company, $draft]), ['revision' => 0])->assertSessionHasNoErrors();
        $this->post(route('companies.templates.activate', [$company, $draft]), ['revision' => $draft->fresh()->revision, 'preview_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('archived', $primary->fresh()->status);
        $this->assertSame($originalPath, app(PdfGenerator::class)->save($record));
        $this->assertSame($pdf, Storage::disk('local')->get($originalPath));
        $this->get(route('companies.records.document', [$company, $record, 'preview' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Storage::disk('local')->assertExists($primary->source_path);
        Queue::fake([SendWorkflowMail::class]);
        $record->forceFill(['status' => 'sent', 'signing_expires_at' => now()->addDays(30)])->save();
        $image = imagecreatetruecolor(300, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 20, 65, 220, 20, imagecolorallocate($image, 30, 30, 50));
        ob_start();
        imagepng($image);
        $signature = 'data:image/png;base64,'.base64_encode(ob_get_clean());
        $this->post(route('signing.show', $record->signing_token), ['full_name' => $employee->fullName(), 'pesel' => $employee->pesel, 'signature_name' => $employee->fullName(), 'signature_image' => $signature, 'accepted' => '1', 'document_hash' => $record->document_hash])->assertSessionHasNoErrors()->assertRedirect(route('forms.success'));
        $this->assertSame(hash('sha256', $pdf), $record->fresh()->data['signed_document_hash']);
        $this->assertSame($pdf, Storage::disk('local')->get($originalPath));
        Queue::assertPushed(SendWorkflowMail::class);
    }

    public function test_missing_original_pdf_is_not_replaced_with_plain_text_after_generation(): void
    {
        Storage::fake('local');
        $record = WorkflowRecord::factory()->ready()->create(['pdf_path' => 'documents/missing.pdf', 'data' => ['document_parts' => [['path' => 'part.pdf']]]]);
        $this->expectException(ValidationException::class);
        app(PdfGenerator::class)->save($record);
    }

    #[TestWith(['unchanged'])]
    #[TestWith(['employee'])]
    #[TestWith(['record'])]
    #[TestWith(['template'])]
    public function test_native_rendering_releases_database_lock_and_rechecks_inputs_before_saving(string $change): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'draft']);
        $template = $this->pdfTemplate($company, 'contract', 'CONTRACT', ['contract']);
        $pdf = $this->pdf('RENDERED');
        $renderer = $this->partialMock(TemplateRenderer::class);
        $renderer->shouldReceive('snapshot')->once()->andReturnUsing(function (array $templates, array $values, int $companyId) use ($change, $company, $employee, $record, $template, $pdf): array {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($company->id, $companyId);
            if ($change === 'employee') {
                $employee->update(['last_name' => 'Zmieniona podczas konwersji']);
            } elseif ($change === 'record') {
                $record->update(['starts_at' => '2026-11-02']);
            } elseif ($change === 'template') {
                $template->update(['status' => 'archived']);
                DocumentTemplate::factory()->for($company)->create(['package_kinds' => ['contract']]);
            }
            Storage::disk('local')->put('documents/rendered.pdf', $pdf);

            return ['path' => 'documents/rendered.pdf', 'parts' => [], 'checksum' => hash('sha256', $pdf)];
        });
        $response = $this->actingAs($company->owner)->post(route('companies.records.generate', [$company, $record]));
        $record->refresh();
        if ($change === 'unchanged') {
            $response->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('ready', $record->status);
            $this->assertSame(hash('sha256', $pdf), $record->document_hash);
            $this->assertSame('documents/rendered.pdf', $record->pdf_path);
        } else {
            $response->assertConflict();
            $this->assertSame('draft', $record->status);
            $this->assertNull($record->generated_at);
            $this->assertNull($record->pdf_path);
            $this->assertNull($record->document_html);
        }
    }

    public function test_changed_native_pdf_does_not_match_the_document_to_sign(): void
    {
        Storage::fake('local');
        $record = WorkflowRecord::factory()->ready()->create(['pdf_path' => 'documents/changed.pdf', 'document_hash' => hash('sha256', 'original'), 'data' => ['document_parts' => [['path' => 'part.pdf']]]]);
        Storage::disk('local')->put($record->pdf_path, 'changed');
        $this->expectException(ValidationException::class);
        app(PdfGenerator::class)->save($record);
    }

    private function pdfTemplate(Company $company, string $kind, string $text, ?array $package = null): DocumentTemplate
    {
        $pdf = $this->pdf($text);
        $path = 'templates/'.$company->id.'/'.$kind.'.pdf';
        Storage::disk('local')->put($path, $pdf);

        return DocumentTemplate::factory()->for($company)->create(['kind' => $kind, 'body' => '', 'format' => 'pdf', 'source_path' => $path, 'source_name' => $kind.'.pdf', 'source_checksum' => hash('sha256', $pdf), 'package_kinds' => $package]);
    }

    private function pdf(string $text): string
    {
        $stream = 'BT /F1 14 Tf 50 750 Td ('.$text.') Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
