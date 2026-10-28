<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Services\TemplateRenderer;
use App\Support\TemplateFields;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class DocxRenderingTest extends TestCase
{
    public function test_converter_receives_filled_native_document_and_private_profile_is_cleaned(): void
    {
        Storage::fake('local');
        config(['hr.docx_binary' => PHP_BINARY]);
        $template = $this->template();
        $directory = null;
        Process::fake(function (PendingProcess $process) use (&$directory) {
            $source = $process->command[array_key_last($process->command)];
            $directory = dirname($source);
            $zip = new ZipArchive;
            $zip->open($source);
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertStringContainsString('Anna', $xml);
            $this->assertStringNotContainsString('{{full_name}}', $xml);
            file_put_contents($directory.'/document.pdf', "%PDF-1.4\n%%EOF");

            return Process::result();
        });
        $pdf = app(TemplateRenderer::class)->render($template, TemplateFields::sample($template));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertDirectoryDoesNotExist($directory);
        Process::assertRan(fn (PendingProcess $process): bool => in_array('--headless', $process->command, true) && in_array('pdf:writer_pdf_Export', $process->command, true));
        Storage::disk('local')->assertExists($template->source_path);
    }

    public function test_converter_failure_is_reported_without_falling_back_to_plain_text(): void
    {
        Storage::fake('local');
        config(['hr.docx_binary' => PHP_BINARY]);
        $template = $this->template();
        Process::fake(['*' => Process::result(exitCode: 1)]);
        try {
            app(TemplateRenderer::class)->render($template, TemplateFields::sample($template));
            $this->fail('Nie można przyjąć nieudanego renderowania DOCX.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }
        Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('--headless', $process->command, true), 1);
    }

    public function test_real_docx_preview_preserves_native_layout_and_can_be_activated(): void
    {
        if (! is_executable(config('hr.docx_binary'))) {
            $this->markTestSkipped('Wymagany skonfigurowany LibreOffice do rzeczywistego testu DOCX.');
        }
        Storage::fake('local');
        $template = $this->template();
        $this->actingAs($template->company->owner)->post(route('companies.templates.preview', [$template->company, $template]), ['revision' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $template->refresh();
        $this->get(route('companies.templates.preview-file', [$template->company, $template]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = Storage::disk('local')->get($template->preview_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        $path = (PHP_OS_FAMILY === 'Darwin' ? '/private/tmp' : sys_get_temp_dir()).'/kadrelio-template-rendered.pdf';
        file_put_contents($path, $pdf);
        $textBinary = dirname(config('hr.pdf_merge_binary')).'/pdftotext';
        if (is_executable($textBinary)) {
            $text = Process::run([$textBinary, $path, '-'])->output();
            $this->assertStringContainsString('Anna', $text);
            $this->assertStringContainsString('NAGLOWEK', $text);
            $this->assertStringContainsString('STOPKA', $text);
            $this->assertStringContainsString('TABELA', $text);
            $this->assertStringNotContainsString('{{', $text);
        }
        $this->post(route('companies.templates.activate', [$template->company, $template]), ['revision' => $template->revision, 'preview_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('active', $template->fresh()->status);
    }

    private function template(): DocumentTemplate
    {
        $path = tempnam(sys_get_temp_dir(), 'kadrelio-native-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:p><w:r><w:t>PRZEGLAD WZORU</w:t></w:r></w:p><w:tbl><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="8"/><w:left w:val="single" w:sz="8"/><w:bottom w:val="single" w:sz="8"/><w:right w:val="single" w:sz="8"/><w:insideV w:val="single" w:sz="8"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="4000"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="4000" w:type="dxa"/></w:tcPr><w:p><w:r><w:t>TABELA</w:t></w:r></w:p></w:tc><w:tc><w:tcPr><w:tcW w:w="4000" w:type="dxa"/></w:tcPr><w:p><w:r><w:t>{{full_</w:t></w:r><w:r><w:t>name}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr><w:headerReference w:type="default" r:id="rIdHeader"/><w:footerReference w:type="default" r:id="rIdFooter"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720"/></w:sectPr></w:body></w:document>');
        $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdHeader" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
        $zip->addFromString('word/header1.xml', '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>NAGLOWEK {{company_name}}</w:t></w:r></w:p></w:hdr>');
        $zip->addFromString('word/footer1.xml', '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>STOPKA</w:t></w:r></w:p></w:ftr>');
        $zip->close();
        $content = file_get_contents($path);
        unlink($path);
        Storage::disk('local')->put('templates/native.docx', $content);

        return DocumentTemplate::factory()->create(['status' => 'draft', 'format' => 'docx', 'body' => '{{full_name}} {{company_name}}', 'source_path' => 'templates/native.docx', 'source_checksum' => hash('sha256', $content), 'placeholders' => ['{{full_name}}', '{{company_name}}']]);
    }
}
