<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class TemplateManagementTest extends TestCase
{
    public function test_manager_saves_and_updates_company_templates(): void
    {
        $company = Company::factory()->create();
        $data = ['name' => 'Umowa PL', 'kind' => 'contract', 'agreement_type' => 'mandate', 'language' => 'PL', 'department' => 'p1', 'body' => 'Umowa z {{full_name}} dla {{company_name}}.'];

        $this->actingAs($company->owner)->post(route('companies.templates.store', $company), $data + ['company_id' => 999])->assertRedirect();

        $template = $company->templates()->firstOrFail();
        $this->assertSame($data['body'], $template->body);
        $this->assertSame('draft', $template->status);
        $this->patch(route('companies.templates.update', [$company, $template]), array_replace($data, ['body' => 'Nowa treść {{full_name}}', 'revision' => $template->revision]))->assertRedirect();
        $this->assertSame('Nowa treść {{full_name}}', $template->fresh()->body);
    }

    public function test_user_cannot_modify_templates(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $company = Company::factory()->create();
        $company->users()->attach($user);

        $this->actingAs($user)->post(route('companies.templates.store', $company), ['name' => 'Nieuprawniony'])->assertForbidden();
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_docx_import_joins_placeholder_fragments_and_ignores_markup(): void
    {
        $company = Company::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'kadrelio-template-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Umowa z {{full_</w:t></w:r><w:r><w:t>name}}</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        try {
            $file = new UploadedFile($path, 'szablon.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

            $this->actingAs($company->owner)->post(route('companies.templates.store', $company), ['name' => 'Importowany szablon', 'kind' => 'contract', 'agreement_type' => 'mandate', 'language' => 'PL', 'file' => $file])->assertSessionHasNoErrors()->assertRedirect();

            $this->assertSame('Umowa z {{full_name}}', $company->templates()->firstOrFail()->body);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
