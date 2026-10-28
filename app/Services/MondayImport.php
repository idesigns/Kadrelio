<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MondayAsset;
use App\Models\MondayBoard;
use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use App\Models\MondayUpdate;
use App\Models\User;
use App\Support\MondayFields;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MondayImport
{
    public function __construct(private MondayWorkbook $workbooks, private MondayProjector $projector) {}

    public function inspect(string $directory): array
    {
        $root = realpath($directory);
        if (! $root || ! is_dir($root.'/boards')) {
            throw new RuntimeException('Podaj katalog eksportu zawierający folder boards.');
        }
        $manifest = [];
        $totals = ['boards' => 0, 'items' => 0, 'updates' => 0, 'team' => 0];
        $boardIds = [];
        foreach (['boards', 'updates', 'team'] as $folder) {
            $files = glob($root.'/'.$folder.'/*.xlsx') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $path) {
                if (is_link($path) || ! str_starts_with(realpath($path), $root.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('Eksport nie może zawierać dowiązań do plików.');
                }
                $relative = $folder.'/'.basename($path);
                if ($folder === 'boards') {
                    if (! preg_match('/^(\d+)_/', basename($path), $parts) || isset($boardIds[$parts[1]])) {
                        throw new RuntimeException('Nieprawidłowy lub powtórzony identyfikator tablicy.');
                    }
                    $boardIds[$parts[1]] = true;
                    $board = $this->workbooks->board($path);
                    $count = count($board['items']);
                    $totals['boards']++;
                    $totals['items'] += $count;
                    $manifest[$relative] = ['sha256' => hash_file('sha256', $path), 'count' => $count, 'board_id' => $parts[1]];
                } else {
                    $count = count($this->workbooks->table($path)['rows']);
                    $totals[$folder] += $count;
                    $manifest[$relative] = ['sha256' => hash_file('sha256', $path), 'count' => $count];
                }
            }
        }
        if ($totals['boards'] === 0) {
            throw new RuntimeException('Nie znaleziono tablic XLSX.');
        }

        return ['manifest' => $manifest, 'totals' => $totals, 'fingerprint' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR))];
    }

    public function run(string $directory, string $key, User $owner, array $inspection, array $companyIds = []): MondayImportBatch
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $key) || ! $owner->canCreateCompanies()) {
            throw new RuntimeException('Nieprawidłowy klucz importu lub właściciel bez uprawnień.');
        }
        $existing = MondayImportBatch::where('source_key', $key)->first();
        if ($existing) {
            if (! hash_equals($existing->fingerprint, $inspection['fingerprint'])) {
                throw new RuntimeException('Ten klucz należy do innego zestawu plików. Zachowano poprzedni import; użyj nowego klucza dla nowego eksportu.');
            }

            return $existing;
        }
        $root = realpath($directory);
        foreach ($inspection['manifest'] as $relative => $file) {
            if (! hash_equals($file['sha256'], hash_file('sha256', $root.'/'.$relative))) {
                throw new RuntimeException('Pliki eksportu zmieniły się od analizy.');
            }
        }

        return DB::transaction(function () use ($root, $key, $owner, $inspection, $companyIds): MondayImportBatch {
            $batch = new MondayImportBatch;
            $batch->forceFill(['source_key' => $key, 'owner_id' => $owner->id, 'fingerprint' => $inspection['fingerprint'], 'manifest' => $inspection['manifest']])->save();
            $companies = [];
            $itemsBySource = [];
            $assets = [];
            foreach ($inspection['manifest'] as $relative => $file) {
                $this->copySource($root.'/'.$relative, 'monday/'.$key.'/source/'.$relative);
                if (! str_starts_with($relative, 'boards/')) {
                    continue;
                }
                [$companyKey, $kind] = config('monday.boards.'.$file['board_id'], [null, 'other']);
                if ($companyKey && ! isset($companies[$companyKey])) {
                    $companies[$companyKey] = $this->company($companyKey, $owner, $companyIds[$companyKey] ?? null);
                }
                $source = $this->workbooks->board($root.'/'.$relative);
                $board = new MondayBoard;
                $board->forceFill(['monday_import_batch_id' => $batch->id, 'company_id' => $companyKey ? $companies[$companyKey]->id : null, 'source_id' => $file['board_id'], 'kind' => $kind, 'name' => $source['title'], 'columns' => $source['columns'], 'groups' => $source['groups'], 'checksum' => $file['sha256'], 'workbook_path' => 'monday/'.$key.'/source/'.$relative])->save();
                foreach ($source['items'] as $position => $row) {
                    $item = $this->item($board, $row, $source['date1904']);
                    $sourceKey = $file['board_id'].':'.$row['id'];
                    $itemsBySource[$sourceKey] = $item->id;
                    $this->assets($batch, $item, $row['values'], $assets);
                }
            }
            $team = [];
            foreach ($inspection['manifest'] as $relative => $file) {
                if (str_starts_with($relative, 'team/')) {
                    $team[$relative] = $this->workbooks->table($root.'/'.$relative);
                }
                if (! str_starts_with($relative, 'updates/')) {
                    continue;
                }
                $table = $this->workbooks->table($root.'/'.$relative);
                $boardSourceId = explode('_', basename($relative))[0];
                foreach ($table['rows'] as $row) {
                    $value = fn (string $label): string => MondayFields::value($table['headers'], $row['values'], $label);
                    $update = new MondayUpdate;
                    $update->forceFill(['monday_import_batch_id' => $batch->id, 'monday_item_id' => $itemsBySource[$boardSourceId.':'.$value('Item ID')] ?? null, 'source_id' => $value('Post ID') ?: hash('sha256', $relative.':'.$row['row']), 'payload' => ['headers' => $table['headers'], ...$row], 'posted_at' => $this->timestamp($value('Created At'))])->save();
                }
            }
            $batch->team = $team;
            $batch->save();
            $this->linkItems($batch);
            $this->projector->run($batch);
            $batch->status = 'completed';
            $batch->report = $this->verify($batch);
            if (! $batch->report['balanced']) {
                throw new RuntimeException('Niezgodne liczby po imporcie. Transakcja została wycofana.');
            }
            $batch->save();

            return $batch;
        });
    }

    public function verify(MondayImportBatch $batch): array
    {
        $boards = $batch->boards()->withCount('items')->get();
        $expected = ['boards' => 0, 'items' => 0, 'updates' => 0, 'team' => 0];
        foreach ($batch->manifest as $relative => $file) {
            $folder = explode('/', $relative)[0];
            if ($folder === 'boards') {
                $expected['boards']++;
                $expected['items'] += $file['count'];
            } else {
                $expected[$folder] += $file['count'];
            }
        }
        $actual = ['boards' => $boards->count(), 'items' => $boards->sum('items_count'), 'updates' => $batch->updates()->count(), 'team' => collect($batch->team ?? [])->sum(fn (array $table): int => count($table['rows']))];
        $boardCountsMatch = $boards->every(function (MondayBoard $board) use ($batch): bool {
            $source = collect($batch->manifest)->first(fn (array $file): bool => ($file['board_id'] ?? null) === $board->source_id);

            return $source !== null && $board->items_count === $source['count'];
        });
        $items = MondayItem::whereIn('monday_board_id', $boards->modelKeys());

        return ['expected' => $expected, 'actual' => $actual, 'balanced' => $expected === $actual && $boardCountsMatch, 'relations' => (clone $items)->select('relation_state')->selectRaw('COUNT(*) as total')->groupBy('relation_state')->pluck('total', 'relation_state')->all(), 'items_with_issues' => (clone $items)->whereNotNull('issues')->count(), 'unlinked_updates' => $batch->updates()->whereNull('monday_item_id')->count(), 'assets' => $batch->assets()->count(), 'available_assets' => $batch->assets()->whereNotNull('path')->count(), 'employees' => (clone $items)->whereNotNull('employee_id')->distinct()->count('employee_id'), 'workflows' => (clone $items)->whereNotNull('workflow_record_id')->count()];
    }

    public function verifySource(MondayImportBatch $batch): array
    {
        $result = ['checked_items' => 0, 'checked_updates' => 0, 'checked_team_rows' => 0, 'mismatches' => []];
        foreach ($batch->boards as $board) {
            if (! Storage::disk('local')->exists($board->workbook_path)) {
                $result['mismatches'][] = 'board:'.$board->source_id.':missing_source';

                continue;
            }
            $source = $this->workbooks->board(Storage::disk('local')->path($board->workbook_path));
            foreach ($board->items()->select(['id', 'source_id', 'source_data'])->lazyById(150) as $item) {
                $expected = $source['items'][$item->source_id] ?? null;
                if ($expected === null || $item->source_data !== [...$expected, 'date1904' => $source['date1904']]) {
                    $result['mismatches'][] = 'item:'.$board->source_id.':'.$item->source_id;
                }
                $result['checked_items']++;
            }
        }
        $team = $batch->team ?? [];
        foreach ($batch->manifest as $relative => $file) {
            $path = 'monday/'.$batch->source_key.'/source/'.$relative;
            if (str_starts_with($relative, 'boards/')) {
                continue;
            }
            if (! Storage::disk('local')->exists($path)) {
                $result['mismatches'][] = 'source:'.$relative.':missing';

                continue;
            }
            $table = $this->workbooks->table(Storage::disk('local')->path($path));
            if (str_starts_with($relative, 'team/')) {
                if (($team[$relative] ?? null) !== $table) {
                    $result['mismatches'][] = 'team:'.$relative;
                }
                $result['checked_team_rows'] += count($table['rows']);

                continue;
            }
            foreach ($table['rows'] as $row) {
                $sourceId = MondayFields::value($table['headers'], $row['values'], 'Post ID') ?: hash('sha256', $relative.':'.$row['row']);
                $update = $batch->updates()->where('source_id', $sourceId)->first();
                if (! $update || $update->payload !== ['headers' => $table['headers'], ...$row]) {
                    $result['mismatches'][] = 'update:'.$sourceId;
                }
                $result['checked_updates']++;
            }
        }

        return $result;
    }

    private function company(string $key, User $owner, ?int $companyId): Company
    {
        if ($companyId) {
            $company = Company::visibleTo($owner)->whereKey($companyId)->firstOrFail();
            if ($company->is_demo || $company->mondayBoards()->exists() || $company->employees()->exists() || $company->records()->exists()) {
                throw new RuntimeException('Firma docelowa musi być pusta, bez danych demonstracyjnych i bez innego importu.');
            }

            return $company;
        }
        $company = new Company(config('monday.companies.'.$key));
        $company->owner()->associate($owner);
        $company->hr_email = $owner->email;
        $company->privacy_url = '';
        $company->public_token = Str::random(64);
        $company->save();
        $company->users()->attach($owner);

        return $company;
    }

    private function item(MondayBoard $board, array $row, bool $date1904): MondayItem
    {
        $identity = MondayFields::identity($row['headers'], $row['values']);
        $value = fn (string ...$labels): string => MondayFields::value($row['headers'], $row['values'], ...$labels);
        $rawDate = match ($board->kind) {
            'contracts' => $value('Data zawarcia umowy formularz'),
            'amendments', 'amendment_statements' => $value('Data zawarcia aneksu'),
            'terminations' => $value('Umowa zostaje rozwiązana ze skutkiem na dzień:'),
            'termination_statements' => $value('Data rozwiązania umowy'),
            'profiles' => $value('Data wypełnienia formularza'),
            default => $value('Data oświadczenia', 'Data wpłynęcia formularza', 'Data szkolenia', 'Data'),
        };
        $status = $value('Status umowy', 'Status');
        $issues = [];
        if (! in_array($board->kind, ['other', 'links'], true)) {
            $type = MondayFields::identityType($identity);
            if ($type !== 'pesel') {
                $issues[] = $type === 'nip' ? 'nip_in_pesel' : 'identity_'.$type;
            }
        }
        $email = $value('E-mail strony umowy', 'E-mail', 'Adres e-mail');
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'email_invalid';
        }
        $date = MondayFields::date($rawDate, $date1904);
        if ($rawDate !== '' && ! $date) {
            $issues[] = 'date_unverified';
        }
        if ($board->kind === 'contracts' && (($row['group'] === 'Aktualne umowy' && $status === 'Rozwiązana') || ($row['group'] === 'Umowy rozwiązane' && $status === 'Podpisana'))) {
            $issues[] = 'status_conflict';
        }
        $item = new MondayItem;
        $item->forceFill(['monday_board_id' => $board->id, 'source_id' => $row['id'], 'position' => $row['row'], 'group_name' => $row['group'], 'name' => $row['values']['A'] ?? '', 'status' => $status ?: null, 'identity_hash' => $identity !== '' ? Employee::identityHash($identity) : null, 'document_date' => $date, 'original_date' => MondayFields::date($value('Data pierwotnej umowy'), $date1904), 'source_data' => [...$row, 'date1904' => $date1904], 'fields' => $row['values'], 'issues' => $issues ?: null])->save();

        return $item;
    }

    private function assets(MondayImportBatch $batch, MondayItem $item, array $values, array &$cache): void
    {
        foreach ($values as $column => $value) {
            preg_match_all('~https?://[^\s<>"\']+/resources/(\d+)/[^\s<>"\']+~u', $value, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $resourceId = $match[1];
                if (! isset($cache[$resourceId])) {
                    $asset = new MondayAsset;
                    $asset->forceFill(['monday_import_batch_id' => $batch->id, 'source_id' => $resourceId, 'name' => rawurldecode(basename(parse_url($match[0], PHP_URL_PATH))), 'source_url' => $match[0]])->save();
                    $cache[$resourceId] = $asset->id;
                }
                DB::table('monday_asset_item')->insertOrIgnore(['monday_item_id' => $item->id, 'monday_asset_id' => $cache[$resourceId], 'column_key' => $column]);
            }
        }
    }

    private function linkItems(MondayImportBatch $batch): void
    {
        $boards = $batch->boards()->whereNotNull('company_id')->get();
        foreach ($boards as $board) {
            $targetKind = match ($board->kind) {
                'amendments', 'terminations', 'contract_statements' => 'contracts',
                'amendment_statements' => 'amendments',
                'termination_statements' => 'terminations',
                default => null,
            };
            if (! $targetKind) {
                continue;
            }
            $target = $boards->first(fn (MondayBoard $candidate): bool => $candidate->company_id === $board->company_id && $candidate->kind === $targetKind);
            $board->items()->lazyById(200)->each(function (MondayItem $item) use ($board, $target): void {
                $date = in_array($board->kind, ['amendments', 'terminations'], true) ? $item->original_date : $item->document_date;
                if (! $item->identity_hash || ($board->kind !== 'contract_statements' && ! $date)) {
                    $item->relation_state = 'missing';
                } else {
                    $candidates = $target?->items()->where('identity_hash', $item->identity_hash)->when($board->kind !== 'contract_statements', fn ($query) => $query->whereDate('document_date', $date))->limit(2)->get();
                    $count = $candidates?->count() ?? 0;
                    $item->relation_state = match ($count) {
                        0 => 'unmatched', 1 => 'matched', default => 'ambiguous'
                    };
                    $item->parent_id = $count === 1 ? $candidates->first()->id : null;
                }
                $item->save();
            });
        }
    }

    private function timestamp(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function copySource(string $source, string $destination): void
    {
        $stream = fopen($source, 'rb');
        try {
            if (! $stream || ! Storage::disk('local')->put($destination, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Nie udało się zapisać prywatnej kopii pliku źródłowego.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
