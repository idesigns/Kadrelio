<?php

namespace App\Console\Commands;

use App\Services\SnapshotImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ImportSnapshot extends Command
{
    protected $signature = 'kadrelio:import-snapshot {path : Katalog z database.sqlite, manifest.json i private}
        {--apply : Zapisz dane i brakujące pliki zamiast samej analizy}';

    protected $description = 'Przenosi aktualny stan Kadrelio z kopii SQLite do pustej bazy MySQL bez kasowania danych i wysyłki poczty';

    public function handle(SnapshotImport $importer): int
    {
        $lock = null;
        try {
            if ($this->option('apply')) {
                $lock = Cache::lock('kadrelio-snapshot-import', 7200);
                if (! $lock->get()) {
                    throw new RuntimeException('Inny import paczki jest w toku.');
                }
            }
            $this->info('Sprawdzanie bazy i sum kontrolnych plików. Duża paczka może wymagać kilku minut.');
            $report = $importer->run($this->argument('path'), (bool) $this->option('apply'));
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (QueryException $exception) {
            $this->error('Błąd bazy danych (SQLSTATE '.$exception->getCode().'). Import danych został cofnięty; nie kasowano istniejących wpisów.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Nieprawidłowa lub niedostępna paczka. Import danych nie został zapisany.');

            return self::FAILURE;
        } finally {
            $lock?->release();
        }
    }
}
