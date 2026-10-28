<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Throwable;

class SnapshotImport
{
    private const RuntimeTables = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'sessions'];

    public function run(string $directory, bool $apply = false): array
    {
        if (config('hr.mail_enabled')) {
            throw new RuntimeException('Najpierw ustaw HR_MAIL_ENABLED=false i odśwież konfigurację.');
        }
        if ($apply && app()->isProduction() && ! app()->isDownForMaintenance()) {
            throw new RuntimeException('Zapis wymaga trybu konserwacji: php artisan down.');
        }
        $target = DB::connection();
        if (! in_array($target->getDriverName(), ['mysql', 'mariadb'], true) && ! (app()->runningUnitTests() && $target->getDriverName() === 'sqlite')) {
            throw new RuntimeException('Docelowa baza musi korzystać z DB_CONNECTION=mysql lub mariadb.');
        }
        $directory = realpath($directory);
        if (! $directory || ! is_dir($directory)) {
            throw new RuntimeException('Podaj katalog rozpakowanej paczki.');
        }
        $manifestPath = $this->safePath($directory, 'manifest.json');
        if (! is_file($manifestPath) || filesize($manifestPath) > 16 * 1024 * 1024) {
            throw new RuntimeException('Brakuje poprawnego manifest.json.');
        }
        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? '') !== 'kadrelio-sqlite-transfer-v1' || ! is_array($manifest['files'] ?? null) || ! is_array($manifest['tables'] ?? null)) {
            throw new RuntimeException('Nieobsługiwany format paczki.');
        }
        $files = $this->manifestFiles($manifest['files']);
        $database = $this->safePath($directory, 'database.sqlite');
        if (! isset($files['database.sqlite']) || ! $this->matches($database, $files['database.sqlite'])) {
            throw new RuntimeException('Kopia database.sqlite nie zgadza się z manifestem.');
        }
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Do odczytu kopii potrzebne jest rozszerzenie pdo_sqlite. Baza docelowa pozostaje MySQL.');
        }
        $source = DB::build(['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'options' => [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]]);
        try {
            if (array_values((array) $source->selectOne('PRAGMA quick_check')) !== ['ok'] || $source->select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Kopia SQLite ma niespójne dane.');
            }
            $tables = $this->tables($source);
            $targetTables = $this->tables($target);
            if (array_keys($tables) !== array_keys($targetTables)) {
                throw new RuntimeException('Schemat bazy jest inny niż w paczce. Uruchom aktualne migracje.');
            }
            $counts = [];
            foreach ($tables as $table => $definition) {
                $columns = array_column($definition['columns'], 'name');
                $targetColumns = array_column($targetTables[$table]['columns'], 'name');
                sort($columns);
                sort($targetColumns);
                if ($columns !== $targetColumns) {
                    throw new RuntimeException('Niezgodne kolumny tabeli '.$table.'. Uruchom aktualne migracje.');
                }
                $counts[$table] = $source->table($table)->count();
                if ($counts[$table] !== ($manifest['tables'][$table] ?? null)) {
                    throw new RuntimeException('Niezgodna liczba wpisów tabeli '.$table.'.');
                }
            }
            $this->assertEmpty($target, array_keys($tables));
            $this->assertKey($source);
            $order = $this->tableOrder($targetTables);
            $privateFiles = $this->checkFiles($directory, $files);
            $this->assertReferencedFiles($source, $files);
            if ($apply) {
                $this->copyFiles($privateFiles);
                $target->transaction(function () use ($source, $target, $tables, $targetTables, $order): void {
                    $this->assertEmpty($target, array_keys($tables));
                    $this->insertRows($source, $target, $targetTables, $order);
                    foreach ($tables as $table => $definition) {
                        $types = array_column($targetTables[$table]['columns'], 'type_name', 'name');
                        if ($this->fingerprint($source, $table, $types) !== $this->fingerprint($target, $table, $types)) {
                            throw new RuntimeException('Kontrola danych po zapisie nie powiodła się dla '.$table.'. Transakcja została cofnięta.');
                        }
                    }
                });
            }

            return ['applied' => $apply, 'target_driver' => $target->getDriverName(), 'tables' => $counts, 'private_files' => count($privateFiles), 'runtime_tables_imported' => false, 'mail_enabled' => false, 'verified' => true];
        } finally {
            $source->disconnect();
        }
    }

    private function manifestFiles(array $entries): array
    {
        $files = [];
        foreach ($entries as $entry) {
            $path = $entry['path'] ?? '';
            if (! is_string($path) || ($path !== 'database.sqlite' && ! str_starts_with($path, 'private/')) || isset($files[$path]) || ! is_int($entry['size'] ?? null) || $entry['size'] < 0 || ! preg_match('/^[a-f0-9]{64}$/', $entry['sha256'] ?? '')) {
                throw new RuntimeException('Nieprawidłowa lista plików w manifeście.');
            }
            $files[$path] = $entry;
        }

        return $files;
    }

    private function tables(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $tables = [];
        foreach ($schema->getTables($connection->getDriverName() === 'sqlite' ? 'main' : $connection->getDatabaseName()) as $table) {
            $name = $table['name'];
            if (in_array($name, self::RuntimeTables, true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $name) || (isset($table['engine']) && strtolower($table['engine']) !== 'innodb')) {
                throw new RuntimeException('Nieobsługiwany schemat tabeli. W MySQL wymagany jest InnoDB.');
            }
            $tables[$name] = ['columns' => $schema->getColumns($name), 'foreign_keys' => $schema->getForeignKeys($name)];
        }
        ksort($tables);

        return $tables;
    }

    private function assertEmpty(Connection $target, array $tables): void
    {
        foreach ([...$tables, 'jobs', 'failed_jobs'] as $table) {
            if ($target->table($table)->exists()) {
                throw new RuntimeException('Baza docelowa nie jest pusta ('.$table.'). Niczego nie nadpisano.');
            }
        }
    }

    private function assertKey(Connection $source): void
    {
        $encrypted = $source->table('employees')->whereNotNull('pesel')->value('pesel');
        if ($encrypted === null) {
            $encrypted = $source->table('monday_items')->value('fields');
        }
        if ($encrypted !== null) {
            try {
                (new Encrypter(app('encrypter')->getKey(), config('app.cipher')))->decryptString($encrypted);
            } catch (Throwable) {
                throw new RuntimeException('APP_KEY jest inny niż w lokalnej aplikacji. Import został zatrzymany.');
            }
        }
    }

    private function tableOrder(array $tables): array
    {
        $order = [];
        $pending = array_keys($tables);
        while ($pending !== []) {
            $before = count($pending);
            foreach ($pending as $index => $table) {
                $parents = array_unique(array_column($tables[$table]['foreign_keys'], 'foreign_table'));
                if (array_diff($parents, [$table, ...$order]) === []) {
                    $order[] = $table;
                    unset($pending[$index]);
                }
            }
            if ($before === count($pending)) {
                throw new RuntimeException('Schemat zawiera nieobsługiwany cykl powiązań.');
            }
        }

        return $order;
    }

    private function checkFiles(string $directory, array $files): array
    {
        $privateRoot = Storage::disk('local')->path('');
        $checked = [];
        foreach ($files as $path => $entry) {
            if ($path === 'database.sqlite') {
                continue;
            }
            $destination = $this->safePath($privateRoot, substr($path, 8));
            $source = $this->safePath($directory, $path);
            $destinationMatches = false;
            if (file_exists($destination)) {
                $destinationMatches = $this->matches($destination, $entry);
                if (! $destinationMatches) {
                    throw new RuntimeException('Istniejący plik docelowy różni się od paczki. Niczego nie nadpisano.');
                }
            }
            if (! $this->matches($source, $entry)) {
                if (! $destinationMatches) {
                    throw new RuntimeException('Brakuje pliku z paczki lub jego suma kontrolna jest nieprawidłowa.');
                }
                $source = $destination;
            }
            $checked[] = [...$entry, 'source' => $source, 'destination' => $destination];
        }

        return $checked;
    }

    private function assertReferencedFiles(Connection $source, array $files): void
    {
        foreach (['monday_assets' => ['path'], 'monday_boards' => ['workbook_path'], 'document_templates' => ['source_path', 'preview_path'], 'workflow_records' => ['pdf_path', 'statement_pdf_path']] as $table => $columns) {
            foreach ($source->table($table)->select($columns)->cursor() as $row) {
                foreach ((array) $row as $path) {
                    if ($path && ! isset($files['private/'.$path])) {
                        throw new RuntimeException('Manifest nie obejmuje wszystkich dokumentów zapisanych w bazie.');
                    }
                }
            }
        }
        foreach ($source->table('employees')->whereNotNull('personal_data')->select('personal_data')->cursor() as $row) {
            $personal = json_decode(app('encrypter')->decryptString($row->personal_data), true, flags: JSON_THROW_ON_ERROR);
            foreach ($personal['attachments'] ?? [] as $file) {
                if (! isset($files['private/'.($file['path'] ?? '')])) {
                    throw new RuntimeException('Manifest nie obejmuje załączników profilu pracownika.');
                }
            }
        }
    }

    private function copyFiles(array $files): void
    {
        foreach ($files as $entry) {
            if (file_exists($entry['destination'])) {
                continue;
            }
            File::ensureDirectoryExists(dirname($entry['destination']), 0750);
            $input = fopen($entry['source'], 'rb');
            if (! $input) {
                throw new RuntimeException('Nie można odczytać pliku prywatnego.');
            }
            $output = @fopen($entry['destination'], 'xb');
            if (! $output) {
                fclose($input);
                throw new RuntimeException('Nie można zapisać pliku prywatnego. Sprawdź uprawnienia katalogu storage.');
            }
            chmod($entry['destination'], 0640);
            try {
                if (stream_copy_to_stream($input, $output) !== $entry['size']) {
                    throw new RuntimeException('Nie udało się skopiować całego pliku prywatnego.');
                }
            } catch (Throwable $exception) {
                unlink($entry['destination']);
                throw $exception;
            } finally {
                fclose($input);
                fclose($output);
            }
            if (! $this->matches($entry['destination'], $entry)) {
                unlink($entry['destination']);
                throw new RuntimeException('Suma kontrolna skopiowanego pliku jest nieprawidłowa.');
            }
        }
    }

    private function insertRows(Connection $source, Connection $target, array $tables, array $order): void
    {
        $deferred = [];
        foreach ($order as $table) {
            $selfColumns = [];
            foreach ($tables[$table]['foreign_keys'] as $foreignKey) {
                if ($foreignKey['foreign_table'] === $table) {
                    foreach ($foreignKey['columns'] as $column) {
                        if (! collect($tables[$table]['columns'])->firstWhere('name', $column)['nullable']) {
                            throw new RuntimeException('Samopowiązanie wymaga obsługi dla '.$table.'.');
                        }
                        $selfColumns[] = $column;
                    }
                }
            }
            $source->table($table)->orderBy('id')->chunkById(100, function ($rows) use ($target, $table, $selfColumns, &$deferred): void {
                $values = [];
                foreach ($rows as $row) {
                    $row = (array) $row;
                    foreach ($selfColumns as $column) {
                        if ($row[$column] !== null) {
                            $deferred[$table][$row['id']][$column] = $row[$column];
                            $row[$column] = null;
                        }
                    }
                    $values[] = $row;
                }
                $target->table($table)->insert($values);
            });
        }
        foreach ($deferred as $table => $rows) {
            foreach ($rows as $id => $columns) {
                $target->table($table)->where('id', $id)->update($columns);
            }
        }
    }

    private function fingerprint(Connection $connection, string $table, array $types): string
    {
        $hash = hash_init('sha256');
        foreach ($connection->table($table)->lazyById(100) as $row) {
            $row = (array) $row;
            ksort($row);
            foreach ($row as $column => &$value) {
                if ($value !== null) {
                    $value = match ($types[$column]) {
                        'json' => $this->normalizedJson(json_decode($value, true, flags: JSON_THROW_ON_ERROR)),
                        'date' => substr((string) $value, 0, 10),
                        default => (string) $value,
                    };
                }
            }
            unset($value);
            hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }

    private function normalizedJson(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->normalizedJson(...), $value);
        }

        return $value;
    }

    private function safePath(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            throw new RuntimeException('Nieprawidłowa ścieżka w paczce.');
        }
        $path = rtrim($root, '/');
        foreach (explode('/', $relative) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                throw new RuntimeException('Nieprawidłowa ścieżka w paczce.');
            }
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Paczka i docelowe dokumenty nie mogą zawierać dowiązań.');
            }
        }

        return $path;
    }

    private function matches(string $path, array $entry): bool
    {
        return is_file($path) && filesize($path) === $entry['size'] && hash_equals($entry['sha256'], hash_file('sha256', $path));
    }
}
