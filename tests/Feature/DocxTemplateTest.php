<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Services\DocxTemplate;
use App\Support\TemplateFields;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class DocxTemplateTest extends TestCase
{
    public function test_fills_split_fields_in_tables_headers_and_footers_without_changing_layout_or_images(): void
    {
        $source = $this->docx('<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tr><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Zażółć {{full_</w:t></w:r><w:r><w:t>name}} - {{full_name}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>', [
            'word/header1.xml' => '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>&lt;&lt;column_firstrow:Nazwa firmy&gt;&gt;</w:t></w:r></w:p></w:hdr>',
            'word/footer1.xml' => '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>{{company_name}}</w:t></w:r></w:p></w:ftr>',
            'word/media/image1.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZD8AAAAASUVORK5CYII='),
        ]);
        $destination = tempnam(sys_get_temp_dir(), 'kadrelio-filled-');
        $originalHash = hash_file('sha256', $source);
        try {
            $inspected = app(DocxTemplate::class)->inspect($source);
            $this->assertCount(3, $inspected['placeholders']);
            app(DocxTemplate::class)->fill($source, $destination, ['{{full_name}}' => 'Anna <Testowa> & Syn', '<<column_firstrow:Nazwa firmy>>' => 'Firma A', '{{company_name}}' => 'Firma A']);
            $zip = new ZipArchive;
            $zip->open($destination);
            $document = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('w:tblW w:w="5000"', $document);
            $this->assertStringContainsString('<w:b/>', $document);
            $this->assertStringContainsString('Anna &lt;Testowa&gt; &amp; Syn', $document);
            $this->assertStringNotContainsString('{{', $document);
            $this->assertStringContainsString('Firma A', $zip->getFromName('word/header1.xml'));
            $this->assertStringContainsString('Firma A', $zip->getFromName('word/footer1.xml'));
            $this->assertSame('<styles>original-style</styles>', $zip->getFromName('word/styles.xml'));
            $this->assertSame(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZD8AAAAASUVORK5CYII='), $zip->getFromName('word/media/image1.png'));
            $zip->close();
            $this->assertSame($originalHash, hash_file('sha256', $source));
        } finally {
            unlink($source);
            unlink($destination);
        }
    }

    public function test_repeated_fields_with_different_lengths_and_multiline_values_keep_surrounding_text(): void
    {
        $source = $this->docx('<w:p><w:r><w:t>Przed {{first_name}} / {{first_name}} po</w:t></w:r></w:p><w:p><w:r><w:t>{{current_address}}</w:t></w:r></w:p>');
        $destination = tempnam(sys_get_temp_dir(), 'kadrelio-filled-');
        try {
            app(DocxTemplate::class)->fill($source, $destination, ['{{first_name}}' => 'A', '{{current_address}}' => "Ulica 1\nWarszawa"]);
            $zip = new ZipArchive;
            $zip->open($destination);
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertStringContainsString('Przed A / A po', $xml);
            $this->assertStringContainsString('<w:br/>', $xml);
            $this->assertStringContainsString('Warszawa', $xml);
        } finally {
            unlink($source);
            unlink($destination);
        }
    }

    #[DataProvider('unsafeParts')]
    public function test_rejects_active_content_external_resources_and_zip_paths(string $name, string $content): void
    {
        $source = $this->docx('<w:p><w:r><w:t>Wzór</w:t></w:r></w:p>', [$name => $content]);
        try {
            $this->expectException(ValidationException::class);
            app(DocxTemplate::class)->inspect($source);
        } finally {
            unlink($source);
        }
    }

    public static function unsafeParts(): array
    {
        return [
            ['word/vbaProject.bin', 'macro'],
            ['word/embeddings/oleObject1.bin', 'object'],
            ['../outside.xml', '<x/>'],
            ['word/_rels/document.xml.rels', '<Relationships><Relationship Type="image" TargetMode="External" Target="https://example.com/image.png"/></Relationships>'],
            ['word/header1.xml', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///must-not-read">]><x>&x;</x>'],
        ];
    }

    public function test_rejects_field_split_across_paragraphs(): void
    {
        $source = $this->docx('<w:p><w:r><w:t>{{full_</w:t></w:r></w:p><w:p><w:r><w:t>name}}</w:t></w:r></w:p>');
        try {
            $this->expectException(ValidationException::class);
            app(DocxTemplate::class)->inspect($source);
        } finally {
            unlink($source);
        }
    }

    public function test_monday_labels_map_to_native_data_and_b2b_identifier(): void
    {
        $this->assertSame('contractor_tax_id', TemplateFields::suggest('<<column_firstrow:PESEL strony umowy>>', 'b2b'));
        $this->assertSame('concluded_at', TemplateFields::suggest('<<column_firstrow:Data zawarcia umowy formularz>>'));
        $this->assertSame('company_name', TemplateFields::suggest('<<column_firstrow:Nazwa firmy>>'));
        $template = new DocumentTemplate(['body' => 'Firma {{company_name}} - {{full_name}}']);
        $this->assertSame(['{{company_name}}' => 'Firma A', '{{full_name}}' => 'Anna'], TemplateFields::replacements($template, ['company_name' => 'Firma A', 'full_name' => 'Anna']));
    }

    public function test_docugen_table_macros_are_not_silently_rendered_as_fields(): void
    {
        $template = new DocumentTemplate(['body' => '<<table_body>>']);
        $this->expectException(ValidationException::class);
        TemplateFields::replacements($template, ['full_name' => 'Anna']);
    }

    private function docx(string $body, array $parts = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kadrelio-docx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>');
        $zip->addFromString('word/styles.xml', '<styles>original-style</styles>');
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
