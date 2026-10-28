<?php

namespace Tests\Feature;

use App\Models\WorkflowRecord;
use App\Services\PdfGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PdfGeneratorTest extends TestCase
{
    public function test_chromium_produces_a_real_pdf_with_polish_text(): void
    {
        if (! is_executable(config('hr.pdf_binary'))) {
            $this->markTestSkipped('Brak lokalnego Chrome/Chromium do testu integracyjnego.');
        }

        $pdf = app(PdfGenerator::class)->render('<!DOCTYPE html><html lang="pl"><meta charset="utf-8"><body><h1>Zażółć gęślą jaźń</h1><p>Oświadczenie testowe — imię i nazwisko.</p></body></html>');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
    }

    public function test_signature_statement_is_rendered_and_stored_as_a_private_pdf(): void
    {
        if (! is_executable(config('hr.pdf_binary'))) {
            $this->markTestSkipped('Brak lokalnego Chrome/Chromium do testu integracyjnego.');
        }
        Storage::fake('local');
        $image = imagecreatetruecolor(300, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 20, 65, 210, 20, imagecolorallocate($image, 30, 30, 50));
        ob_start();
        imagepng($image);
        $signature = 'data:image/png;base64,'.base64_encode(ob_get_clean());
        $record = WorkflowRecord::factory()->signed()->create(['data' => ['agreement_type' => 'b2b', 'company_name' => 'Firma testowa', 'signer_name' => 'Anna Testowa', 'identifier_label' => 'NIP', 'signer_identifier' => '1234567890', 'signature_image' => $signature, 'signed_ip' => '127.0.0.1']]);

        $path = app(PdfGenerator::class)->save($record, true);

        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, $record->fresh()->statement_pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($path));
        $this->actingAs($record->company->owner)->get(route('companies.records.document', [$record->company, $record, 'preview' => 1, 'statement' => 1]))->assertOk()->assertSee('NIP')->assertSee('1234567890')->assertSee('Anna Testowa')->assertSee('umowę B2B');
    }

    public function test_missing_renderer_returns_actionable_validation_error(): void
    {
        config(['hr.pdf_binary' => '/missing/chromium']);
        $this->expectException(ValidationException::class);

        app(PdfGenerator::class)->render('<html><body>Test</body></html>');
    }
}
