<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\TemplateFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TemplateManager
{
    public function save(Company $company, array $data, ?UploadedFile $file = null, ?DocumentTemplate $original = null): DocumentTemplate
    {
        $upload = $file ? $this->upload($company, $file) : null;

        return DB::transaction(function () use ($company, $data, $original, $upload): DocumentTemplate {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $base = $original ? $company->templates()->whereKey($original->id)->lockForUpdate()->firstOrFail() : null;
            if ($base) {
                abort_unless($base->revision === (int) $data['revision'], 409, 'Wzór zmienił się w innym oknie. Odśwież stronę.');
            }
            $newVersion = ! $base || $base->status !== 'draft';
            if ($base && $newVersion && ! $base->series_key) {
                $base->series_key = (string) Str::uuid();
                $base->save();
            }
            $template = $newVersion ? ($base?->replicate() ?? new DocumentTemplate) : $base;
            $before = $template->fingerprint();
            $template->company()->associate($company);
            $template->fill(collect($data)->only(['name', 'kind', 'agreement_type', 'language', 'market', 'department'])->all());
            if ($upload) {
                $template->forceFill($upload);
            } elseif (! $base || ($base->format ?? 'text') === 'text') {
                $template->format = 'text';
                $template->body = $data['body'] ?? '';
                $template->placeholders = TemplateFields::tokens($template->body);
            }
            if ($template->format === 'text' && trim($template->body) === '') {
                throw ValidationException::withMessages(['body' => 'Uzupełnij treść wzoru lub wybierz plik DOCX / PDF.']);
            }
            $tokens = $template->placeholders ?? [];
            if ($template->format === 'text') {
                $remaining = str_replace($tokens, '', $template->body);
                if (str_contains($remaining, '{{') || str_contains($remaining, '<<')) {
                    throw ValidationException::withMessages(['body' => 'Wzór zawiera niedomknięte pole. Popraw nawiasy pola przed zapisaniem.']);
                }
            }
            if (count($tokens) > 300) {
                throw ValidationException::withMessages(['file' => 'Wzór zawiera więcej niż 300 różnych pól.']);
            }
            $mapping = [];
            $posted = $upload ? [] : ($data['field_mapping'] ?? []);
            foreach ($posted as $index => $value) {
                if (! ctype_digit((string) $index) || ! array_key_exists((int) $index, $tokens)) {
                    throw ValidationException::withMessages(['field_mapping' => 'Lista pól zmieniła się. Zapisz plik i przypisz pola ponownie.']);
                }
            }
            foreach ($tokens as $index => $token) {
                $mapping[$token] = array_key_exists($index, $posted) ? $posted[$index] : ((! $upload && $base?->agreement_type === $template->agreement_type ? ($base?->field_mapping[$token] ?? null) : null) ?? TemplateFields::suggest($token, $template->agreement_type));
            }
            $template->field_mapping = $mapping;
            $primary = in_array($template->kind, ['contract', 'amendment', 'termination'], true);
            $allowedComponents = array_diff(array_keys(TemplateFields::kinds()), ['contract', 'amendment', 'termination', 'multisport', 'equipment_assignment', 'equipment_return']);
            $components = $data['package_kinds'] ?? (($base?->kind === $template->kind ? $base?->package_kinds : null) ?? ($template->kind === 'contract' ? ['declaration', 'attachment'] : []));
            if ($primary && array_diff($components, [$template->kind, ...$allowedComponents])) {
                throw ValidationException::withMessages(['package_kinds' => 'Zestaw może zawierać dokument główny i jego załączniki.']);
            }
            $template->package_kinds = $primary ? array_values(array_unique([$template->kind, ...$components])) : null;
            $template->status = 'draft';
            $newSeries = ! $base || $base->getRawOriginal('kind') !== $template->kind;
            $template->series_key = $newSeries ? (string) Str::uuid() : ($base->series_key ?? (string) Str::uuid());
            $template->version = $newSeries ? 1 : $base->version;
            if ($newVersion && ! $newSeries) {
                $template->version = ($company->templates()->where('series_key', $template->series_key)->max('version') ?? 0) + 1;
            }
            $template->revision = $newVersion ? 0 : $base->revision + 1;
            if ($newVersion || ! hash_equals($before, $template->fingerprint())) {
                $template->forceFill(['preview_path' => null, 'preview_fingerprint' => null, 'preview_checksum' => null, 'previewed_at' => null]);
            }
            $template->forceFill(['activated_at' => null, 'activated_by' => null])->save();

            return $template;
        });
    }

    public function preview(DocumentTemplate $template, int $revision): void
    {
        abort_unless($template->revision === $revision && $template->status === 'draft', 409, 'Odśwież wersję roboczą przed podglądem.');
        $fingerprint = $template->fingerprint();
        $pdf = app(TemplateRenderer::class)->render($template, TemplateFields::sample($template));
        app(TemplateRenderer::class)->validatePdf($pdf);
        $path = 'templates/'.$template->company_id.'/previews/'.Str::uuid().'.pdf';
        if (! Storage::disk('local')->put($path, $pdf)) {
            throw new \RuntimeException('Nie można zapisać podglądu PDF.');
        }
        DB::transaction(function () use ($template, $revision, $fingerprint, $pdf, $path): void {
            $locked = DocumentTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft' && $locked->revision === $revision && hash_equals($fingerprint, $locked->fingerprint()), 409, 'Wzór zmienił się podczas generowania podglądu. Wygeneruj go ponownie.');
            $locked->forceFill(['preview_path' => $path, 'preview_fingerprint' => $fingerprint, 'preview_checksum' => hash('sha256', $pdf), 'previewed_at' => now(), 'revision' => $revision + 1])->save();
        });
    }

    public function activate(DocumentTemplate $template, User $user, int $revision): void
    {
        DB::transaction(function () use ($template, $user, $revision): void {
            Company::whereKey($template->company_id)->lockForUpdate()->firstOrFail();
            $locked = DocumentTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft' && $locked->revision === $revision, 409, 'Wzór zmienił się. Odśwież stronę przed aktywacją.');
            $disk = Storage::disk('local');
            if (! $locked->hasCurrentPreview() || ! $disk->exists($locked->preview_path) || ! hash_equals($locked->preview_checksum ?? '', hash('sha256', $disk->get($locked->preview_path)))) {
                throw ValidationException::withMessages(['preview' => 'Najpierw wygeneruj i sprawdź aktualny podgląd PDF.']);
            }
            if ($locked->format !== 'text') {
                app(TemplateRenderer::class)->source($locked);
            }
            TemplateFields::replacements($locked, TemplateFields::sample($locked));
            $active = $locked->company->templates()->where('status', 'active')->where('kind', $locked->kind)->where('agreement_type', $locked->agreement_type)
                ->whereRaw('COALESCE(market, language) = ?', [$locked->market ?: $locked->language]);
            if ($locked->department) {
                $active->whereRaw('LOWER(department) = ?', [mb_strtolower($locked->department)]);
            } else {
                $active->whereNull('department');
            }
            $active->update(['status' => 'archived']);
            $locked->forceFill(['status' => 'active', 'activated_at' => now(), 'activated_by' => $user->id, 'revision' => $revision + 1])->save();
        });
    }

    private function upload(Company $company, UploadedFile $file): array
    {
        $format = strtolower($file->getClientOriginalExtension());
        if ($format === 'docx') {
            $content = app(DocxTemplate::class)->inspect($file->getRealPath());
        } elseif ($format === 'pdf') {
            app(TemplateRenderer::class)->validatePdf(file_get_contents($file->getRealPath()));
            $content = ['body' => '', 'placeholders' => []];
        } else {
            $body = file_get_contents($file->getRealPath());
            if (! mb_check_encoding($body, 'UTF-8') || mb_strlen($body) > 150000) {
                throw ValidationException::withMessages(['file' => 'Plik TXT musi mieć kodowanie UTF-8 i do 150 000 znaków.']);
            }
            $format = 'text';
            $content = ['body' => $body, 'placeholders' => TemplateFields::tokens($body)];
        }
        $path = $file->storeAs('templates/'.$company->id.'/sources', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        if (! $path) {
            throw new \RuntimeException('Nie można zapisać oryginału wzoru.');
        }

        return $content + ['format' => $format, 'source_path' => $path, 'source_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''), 'source_checksum' => hash_file('sha256', $file->getRealPath())];
    }
}
