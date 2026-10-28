<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Support\TemplateFields;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TemplateRenderer
{
    public function text(DocumentTemplate $template, array $values): string
    {
        return strtr($template->body, TemplateFields::replacements($template, $values));
    }

    public function available(string $format): bool
    {
        return $format !== 'docx' || is_executable(config('hr.docx_binary'));
    }

    public function render(DocumentTemplate $template, array $values): string
    {
        $format = $template->format ?? 'text';
        if ($format === 'text') {
            return app(PdfGenerator::class)->render(view('documents.template', ['template' => $template, 'body' => $this->text($template, $values)])->render());
        }
        $source = $this->source($template);
        if ($format === 'pdf') {
            return Storage::disk('local')->get($template->source_path);
        }
        if (! $this->available('docx')) {
            throw ValidationException::withMessages(['document' => 'Podgląd DOCX wymaga uruchomienia konwertera przez administratora. Wzór pozostaje zapisany jako wersja robocza.']);
        }
        $directory = storage_path('app/private/pdf-tmp/'.Str::uuid());
        File::ensureDirectoryExists($directory.'/profile/user', 0700);
        try {
            app(DocxTemplate::class)->fill($source, $directory.'/document.docx', TemplateFields::replacements($template, $values));
            File::put($directory.'/profile/user/registrymodifications.xcu', '<?xml version="1.0"?><oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item></oor:items>');
            try {
                $result = Process::timeout(90)->run([config('hr.docx_binary'), '-env:UserInstallation=file://'.$directory.'/profile', '--headless', '--norestore', '--nodefault', '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $directory, $directory.'/document.docx']);
            } catch (\Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['document' => 'Nie udało się uruchomić podglądu DOCX. Skontaktuj się z administratorem.']);
            }
            if (! $result->successful() || ! is_file($directory.'/document.pdf')) {
                throw ValidationException::withMessages(['document' => 'Nie można utworzyć PDF z tego DOCX. Otwórz plik w Wordzie, zapisz ponownie i sprawdź wzór.']);
            }
            $pdf = File::get($directory.'/document.pdf');
            $this->validatePdf($pdf);

            return $pdf;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function source(DocumentTemplate $template): string
    {
        $disk = Storage::disk('local');
        if (! $template->source_path || ! $disk->exists($template->source_path) || ! hash_equals($template->source_checksum ?? '', hash('sha256', $disk->get($template->source_path)))) {
            throw ValidationException::withMessages(['file' => 'Brakuje oryginału wzoru lub jego zawartość zmieniła się. Wgraj nową wersję pliku.']);
        }

        return $disk->path($template->source_path);
    }

    public function snapshot(array $templates, array $values, int $companyId): array
    {
        $parts = [];
        foreach ($templates as $template) {
            $pdf = $this->render($template, $values);
            $this->validatePdf($pdf);
            $path = 'documents/'.$companyId.'/'.Str::uuid().'.pdf';
            if (! Storage::disk('local')->put($path, $pdf)) {
                throw new \RuntimeException('Nie można zapisać części dokumentu.');
            }
            $parts[] = ['path' => $path, 'checksum' => hash('sha256', $pdf), 'template_id' => $template->id, 'version' => $template->version ?? 1, 'name' => $template->name, 'kind' => $template->kind];
        }
        $pdf = $this->snapshotPdf($parts);
        $path = 'documents/'.$companyId.'/'.Str::uuid().'.pdf';
        if (! Storage::disk('local')->put($path, $pdf)) {
            throw new \RuntimeException('Nie można zapisać pełnego dokumentu.');
        }

        return ['path' => $path, 'parts' => $parts, 'checksum' => hash('sha256', $pdf)];
    }

    public function snapshotPdf(array $parts): string
    {
        $contents = [];
        foreach ($parts as $part) {
            $disk = Storage::disk('local');
            if (! $disk->exists($part['path'])) {
                throw ValidationException::withMessages(['document' => 'Brakuje zapisanej części dokumentu. Skontaktuj się z administratorem.']);
            }
            $pdf = $disk->get($part['path']);
            if (! hash_equals($part['checksum'], hash('sha256', $pdf))) {
                throw ValidationException::withMessages(['document' => 'Zapisana część dokumentu zmieniła się. Pobieranie zostało zatrzymane.']);
            }
            $contents[] = $pdf;
        }

        return $this->merge($contents);
    }

    public function merge(array $documents): string
    {
        if (count($documents) === 1) {
            $this->validatePdf($documents[0]);

            return $documents[0];
        }
        if ($documents === [] || ! is_executable(config('hr.pdf_merge_binary'))) {
            throw ValidationException::withMessages(['document' => 'Łączenie pełnego zestawu PDF wymaga konfiguracji przez administratora.']);
        }
        $directory = storage_path('app/private/pdf-tmp/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            $paths = [];
            foreach ($documents as $index => $pdf) {
                $this->validatePdf($pdf);
                $paths[] = $path = $directory.'/part-'.$index.'.pdf';
                File::put($path, $pdf);
            }
            try {
                $result = Process::timeout(60)->run([config('hr.pdf_merge_binary'), ...$paths, $directory.'/document.pdf']);
            } catch (\Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['document' => 'Nie udało się połączyć dokumentów PDF. Skontaktuj się z administratorem.']);
            }
            if (! $result->successful() || ! is_file($directory.'/document.pdf')) {
                throw ValidationException::withMessages(['document' => 'Nie można połączyć PDF-ów. Sprawdź, czy pliki są poprawne i nie są zabezpieczone hasłem.']);
            }
            $pdf = File::get($directory.'/document.pdf');
            $this->validatePdf($pdf);

            return $pdf;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function validatePdf(string $pdf): void
    {
        if (! str_starts_with($pdf, '%PDF-') || ! str_contains(substr($pdf, -2048), '%%EOF')) {
            throw ValidationException::withMessages(['file' => 'Plik nie jest poprawnym dokumentem PDF.']);
        }
        if (preg_match('~/(?:JavaScript|JS|Launch|EmbeddedFiles|Encrypt)\b~', $pdf)) {
            throw ValidationException::withMessages(['file' => 'PDF zawiera aktywne elementy lub osadzone pliki. Zapisz zwykły PDF.']);
        }
    }
}
