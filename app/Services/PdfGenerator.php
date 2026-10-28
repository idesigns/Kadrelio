<?php

namespace App\Services;

use App\Models\WorkflowRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PdfGenerator
{
    public function render(string $html): string
    {
        $binary = config('hr.pdf_binary');
        if (! is_file($binary) || ! is_executable($binary)) {
            throw ValidationException::withMessages(['document' => 'Generator PDF wymaga skonfigurowania HR_PDF_BINARY (Chrome lub Chromium).']);
        }
        $directory = storage_path('app/private/pdf-tmp/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            File::put($directory.'/document.html', $html);
            try {
                $result = Process::timeout(45)->run([
                    $binary, '--headless', '--disable-gpu', '--disable-background-networking', '--no-first-run', '--no-default-browser-check', '--no-pdf-header-footer',
                    '--user-data-dir='.$directory.'/browser', '--print-to-pdf='.$directory.'/document.pdf', 'file://'.$directory.'/document.html',
                ]);
            } catch (\Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['document' => 'Generator PDF nie mógł się uruchomić. Sprawdź HR_PDF_BINARY i uprawnienia procesu.']);
            }
            if (! $result->successful() || ! is_file($directory.'/document.pdf')) {
                throw ValidationException::withMessages(['document' => 'Nie udało się wygenerować PDF. Sprawdź konfigurację generatora.']);
            }

            return File::get($directory.'/document.pdf');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function save(WorkflowRecord $record, bool $statement = false): string
    {
        $record->refresh();
        abort_if($statement && ! $record->signed_at, 404);
        $column = $statement ? 'statement_pdf_path' : 'pdf_path';
        if ($record->{$column} && Storage::disk('local')->exists($record->{$column})) {
            if (! $statement && ! empty($record->data['document_parts']) && ! hash_equals($record->document_hash ?? '', hash('sha256', Storage::disk('local')->get($record->{$column})))) {
                throw ValidationException::withMessages(['document' => 'Zawartość zapisanego PDF zmieniła się. Pobieranie zostało zatrzymane.']);
            }

            return $record->{$column};
        }
        if (! $statement && ! empty($record->data['document_parts'])) {
            throw ValidationException::withMessages(['document' => 'Brakuje oryginalnego PDF wygenerowanego z plików wzorów. Skontaktuj się z administratorem.']);
        }
        $html = $statement ? view('documents.statement', ['record' => $record->loadMissing(['company', 'employee'])])->render() : $record->document_html;
        if (! $html) {
            throw ValidationException::withMessages(['document' => 'Najpierw wygeneruj dokument na podstawie szablonów.']);
        }
        $path = 'documents/'.$record->company_id.'/'.Str::uuid().'.pdf';
        if (! Storage::disk('local')->put($path, $this->render($html))) {
            throw new \RuntimeException('Nie udało się zapisać dokumentu PDF.');
        }
        try {
            $savedPath = DB::transaction(function () use ($record, $statement, $column, $html, $path): string {
                $locked = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
                $currentHtml = $statement ? view('documents.statement', ['record' => $locked->loadMissing(['company', 'employee'])])->render() : $locked->document_html;
                if (! hash_equals(hash('sha256', $html), hash('sha256', $currentHtml ?? ''))) {
                    throw ValidationException::withMessages(['document' => 'Dokument zmienił się podczas generowania PDF. Pobierz go ponownie.']);
                }
                if ($locked->{$column} && Storage::disk('local')->exists($locked->{$column})) {
                    return $locked->{$column};
                }
                $locked->{$column} = $path;
                $locked->save();

                return $path;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($savedPath !== $path) {
            Storage::disk('local')->delete($path);
        }
        $record->refresh();

        return $savedPath;
    }
}
