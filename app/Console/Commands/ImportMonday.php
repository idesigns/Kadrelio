<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MondayImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportMonday extends Command
{
    protected $signature = 'monday:import {path : Katalog eksportu z boards, updates i team}
        {--key=monday-26363471 : Stały identyfikator tego eksportu}
        {--owner= : ID właściciela importowanych firm}
        {--company=* : Opcjonalne mapowanie pustej firmy, np. otc:12}
        {--commit : Zapisz import; bez tej opcji wykonuje tylko analizę}
        {--force : Zezwól na zapis w środowisku produkcyjnym}
        {--json : Zwróć raport JSON}';

    protected $description = 'Przenosi pełny eksport Monday bez wysyłki e-maili i bez usuwania danych';

    public function handle(MondayImport $importer): int
    {
        try {
            $inspection = $importer->inspect($this->argument('path'));
            if (! $this->option('commit')) {
                $this->report(['mode' => 'dry_run', ...$inspection['totals'], 'fingerprint' => $inspection['fingerprint']]);

                return self::SUCCESS;
            }
            if (app()->isProduction() && ! $this->option('force')) {
                $this->error('Zapis na produkcji wymaga --force. Analiza została wykonana bez zmian w bazie.');

                return self::FAILURE;
            }
            $owner = User::find($this->option('owner'));
            if (! $owner || ! $owner->canCreateCompanies()) {
                $this->error('Podaj --owner=ID istniejącego administratora lub managera.');

                return self::FAILURE;
            }
            $companyIds = [];
            foreach ($this->option('company') as $mapping) {
                if (! preg_match('/^(otc|approval):([1-9]\d*)$/', $mapping, $parts) || isset($companyIds[$parts[1]])) {
                    $this->error('Mapowanie firmy musi mieć postać otc:ID albo approval:ID, bez powtórzeń.');

                    return self::FAILURE;
                }
                $companyIds[$parts[1]] = (int) $parts[2];
            }
            if (count(array_unique($companyIds)) !== count($companyIds)) {
                $this->error('Każda firma Monday musi mieć oddzielną firmę docelową.');

                return self::FAILURE;
            }
            $lock = Cache::lock('monday-import', 7200);
            if (! $lock->get()) {
                $this->error('Inny import Monday jest w toku.');

                return self::FAILURE;
            }
            try {
                $batch = $importer->run($this->argument('path'), $this->option('key'), $owner, $inspection, $companyIds);
                $report = $importer->verify($batch);
                $this->report(['mode' => 'committed', 'key' => $batch->source_key, ...$report]);

                return $report['balanced'] ? self::SUCCESS : self::FAILURE;
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            $this->error($exception::class === \RuntimeException::class ? $exception->getMessage() : 'Import przerwany. Sprawdź strukturę eksportu i konfigurację bazy.');

            return self::FAILURE;
        }
    }

    private function report(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        $this->info($report['mode'] === 'dry_run' ? 'Analiza zakończona. Nie zapisano nic w bazie.' : 'Import zakończony. Nie wysłano żadnych wiadomości.');
        foreach ($report as $key => $value) {
            $this->line($key.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true)));
        }
    }
}
