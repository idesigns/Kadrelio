<?php

namespace App\Console\Commands;

use App\Models\MondayImportBatch;
use App\Services\MondayAssetImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportMondayAssets extends Command
{
    protected $signature = 'monday:assets {path : Katalog assets}
        {--key=monday-26363471}
        {--map= : Opcjonalna mapa JSON resource_id do ścieżki względnej}
        {--commit : Skopiuj i podłącz dopasowane pliki}
        {--force : Zezwól na zapis na produkcji}';

    protected $description = 'Dołącza oryginalne pliki Monday po identyfikatorach zasobów, bez pobierania z internetu';

    public function handle(MondayAssetImport $importer): int
    {
        if ($this->option('commit') && app()->isProduction() && ! $this->option('force')) {
            $this->error('Zapis assets na produkcji wymaga --force.');

            return self::FAILURE;
        }
        $batch = MondayImportBatch::where('source_key', $this->option('key'))->first();
        if (! $batch) {
            $this->error('Najpierw zaimportuj tablice.');

            return self::FAILURE;
        }
        $lock = Cache::lock('monday-import', 7200);
        if (! $lock->get()) {
            $this->error('Inny import Monday jest w toku.');

            return self::FAILURE;
        }
        try {
            $report = $importer->run($batch, $this->argument('path'), (bool) $this->option('commit'), $this->option('map'));
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $report['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception::class === \RuntimeException::class ? $exception->getMessage() : 'Nie udało się dołączyć assets. Sprawdź katalog i mapę plików.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
