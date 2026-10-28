<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use App\Services\MondayImport;
use App\Services\OutgoingMail;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class VerifyMondayImport extends Command
{
    protected $signature = 'monday:verify {--key=monday-26363471} {--files : Sprawdź sumy kontrolne źródeł i assets} {--production : Sprawdź także gotowość do pełnego uruchomienia produkcyjnego}';

    protected $description = 'Sprawdza zgodność importu Monday i integralność prywatnych plików';

    public function handle(MondayImport $importer): int
    {
        $batch = MondayImportBatch::where('source_key', $this->option('key'))->first();
        if (! $batch) {
            $this->error('Nie znaleziono importu.');

            return self::FAILURE;
        }
        $report = $importer->verify($batch);
        $invalidFiles = [];
        if ($this->option('files')) {
            foreach ($batch->manifest as $relative => $file) {
                if (! $this->matches('monday/'.$batch->source_key.'/source/'.$relative, $file['sha256'])) {
                    $invalidFiles[] = $relative;
                }
            }
            foreach ($batch->assets()->whereNotNull('path')->lazyById(200) as $asset) {
                if (! $this->matches($asset->path, $asset->checksum)) {
                    $invalidFiles[] = 'asset:'.$asset->source_id;
                }
            }
        }
        $blockers = $this->option('production') ? $this->productionBlockers($batch, $report) : [];
        $integrity = $this->option('files') ? $importer->verifySource($batch) : null;
        $this->line(json_encode([...$report, 'invalid_files' => $invalidFiles, ...($integrity ? ['source_integrity' => $integrity] : []), ...($this->option('production') ? ['production_ready' => $blockers === [], 'production_blockers' => $blockers] : [])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $report['balanced'] && $report['unlinked_updates'] === 0 && $invalidFiles === [] && ($integrity['mismatches'] ?? []) === [] && $blockers === [] ? self::SUCCESS : self::FAILURE;
    }

    private function productionBlockers(MondayImportBatch $batch, array $report): array
    {
        $boards = $batch->boards()->get(['id', 'company_id']);
        $items = MondayItem::whereIn('monday_board_id', $boards->modelKeys());
        $companies = Company::whereIn('id', $boards->pluck('company_id')->filter()->unique())->withCount(['templates' => fn (Builder $query): Builder => $query->where('status', 'active')])->get();
        $employeeIds = (clone $items)->whereNotNull('employee_id')->distinct()->pluck('employee_id');
        $profilesToReview = Employee::whereIn('id', $employeeIds)->select(['id', 'personal_data'])->cursor()->filter(fn (Employee $employee): bool => $employee->personal_data['profile_review_required'] ?? false)->count();
        $settingsMissing = $companies->filter(fn (Company $company): bool => ! $company->tax_id || ! $company->address || ! filter_var($company->privacy_url, FILTER_VALIDATE_URL) || ! filter_var($company->hr_email, FILTER_VALIDATE_EMAIL) || str_ends_with($company->hr_email, '.test'))->modelKeys();
        $checks = [
            'missing_assets' => $report['assets'] - $report['available_assets'],
            'unresolved_relationships' => (clone $items)->whereIn('relation_state', ['unmatched', 'ambiguous', 'missing'])->count(),
            'unreviewed_source_data' => (clone $items)->whereNotNull('issues')->whereNull('reviewed_at')->count(),
            'profiles_without_verified_current_version' => $profilesToReview,
            'companies_missing_settings' => $settingsMissing,
            'companies_without_templates' => $companies->where('templates_count', 0)->modelKeys(),
            'https_app_url_required' => ! str_starts_with(config('app.url'), 'https://') || str_ends_with(parse_url(config('app.url'), PHP_URL_HOST) ?? '', '.test'),
            'debug_must_be_disabled' => (bool) config('app.debug'),
            'outgoing_mail_must_be_configured' => in_array(config('mail.default'), ['log', 'array'], true) || str_ends_with(config('mail.from.address', ''), '@example.com'),
            'outgoing_mail_disabled' => ! app(OutgoingMail::class)->enabled(),
        ];

        return array_filter($checks, fn (mixed $value): bool => is_array($value) ? $value !== [] : (bool) $value);
    }

    private function matches(string $path, string $checksum): bool
    {
        if (! Storage::disk('local')->exists($path)) {
            return false;
        }
        $stream = Storage::disk('local')->readStream($path);
        if (! $stream) {
            return false;
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_equals($checksum, hash_final($hash));
        } finally {
            fclose($stream);
        }
    }
}
