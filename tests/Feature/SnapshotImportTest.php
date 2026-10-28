<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\MondayAsset;
use App\Models\MondayBoard;
use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use App\Models\MondayRevision;
use App\Models\MondayUpdate;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class SnapshotImportTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_dry_run_checks_snapshot_without_writing_rows_or_files(): void
    {
        [$directory] = $this->snapshot();
        Mail::fake();
        Queue::fake();

        $this->artisan('kadrelio:import-snapshot', ['path' => $directory])->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('monday_items', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_import_preserves_ids_corrections_encrypted_values_relations_and_files_without_runtime_data(): void
    {
        [$directory, $expected] = $this->snapshot();
        Mail::fake();
        Queue::fake();
        $hash = hash_file('sha256', $directory.'/database.sqlite');

        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->assertSuccessful();

        $this->assertImported($expected);
        $this->assertSame($hash, hash_file('sha256', $directory.'/database.sqlite'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('cache', 0);
        $next = User::factory()->create();
        $this->assertGreaterThan($expected['user_id'], $next->id);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_existing_business_data_prevents_import_without_overwriting_it(): void
    {
        [$directory] = $this->snapshot();
        $user = User::factory()->create(['name' => 'Istniejący użytkownik']);

        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('Baza docelowa nie jest pusta')->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Istniejący użytkownik', $user->fresh()->name);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_repeating_completed_import_is_refused_instead_of_merging_or_replaying_it(): void
    {
        [$directory, $expected] = $this->snapshot();
        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->assertSuccessful();
        Employee::findOrFail($expected['employee_id'])->forceFill(['email' => 'produkcyjna-poprawka@example.test'])->save();

        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->assertFailed();

        $this->assertDatabaseCount('employees', 1);
        $this->assertSame('produkcyjna-poprawka@example.test', Employee::findOrFail($expected['employee_id'])->email);
    }

    public function test_wrong_app_key_is_detected_before_copying_any_data(): void
    {
        [$directory] = $this->snapshot();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        app()->forgetInstance('encrypter');

        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('APP_KEY jest inny')->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_changed_snapshot_and_missing_document_are_rejected(): void
    {
        [$directory] = $this->snapshot();
        File::append($directory.'/database.sqlite', 'zmiana');
        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('nie zgadza się z manifestem')->assertFailed();
        $this->assertDatabaseCount('users', 0);

        [$other] = $this->snapshot();
        File::delete($other.'/private/monday/skan.pdf');
        $this->artisan('kadrelio:import-snapshot', ['path' => $other, '--apply' => true])->expectsOutputToContain('Brakuje pliku')->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_path_traversal_and_symlinks_are_rejected(): void
    {
        [$directory] = $this->snapshot();
        $manifest = json_decode(File::get($directory.'/manifest.json'), true);
        $manifest['files'][] = ['path' => 'private/../outside', 'size' => 1, 'sha256' => str_repeat('a', 64)];
        File::put($directory.'/manifest.json', json_encode($manifest));
        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('Nieprawidłowa ścieżka')->assertFailed();

        [$other] = $this->snapshot();
        File::move($other.'/private/monday/skan.pdf', $other.'/outside.pdf');
        symlink($other.'/outside.pdf', $other.'/private/monday/skan.pdf');
        $this->artisan('kadrelio:import-snapshot', ['path' => $other, '--apply' => true])->expectsOutputToContain('nie mogą zawierać dowiązań')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_different_file_is_preserved_and_identical_files_can_be_reused(): void
    {
        [$directory] = $this->snapshot();
        Storage::disk('local')->put('monday/skan.pdf', 'Istniejący plik');
        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('Istniejący plik docelowy różni się')->assertFailed();
        $this->assertSame('Istniejący plik', Storage::disk('local')->get('monday/skan.pdf'));
        $this->assertDatabaseCount('users', 0);

        [$other, $expected] = $this->snapshot();
        foreach (File::allFiles($other.'/private') as $file) {
            Storage::disk('local')->put($file->getRelativePathname(), File::get($file->getPathname()));
        }
        File::deleteDirectory($other.'/private');
        $this->artisan('kadrelio:import-snapshot', ['path' => $other, '--apply' => true])->assertSuccessful();
        $this->assertImported($expected);
    }

    public function test_database_error_rolls_back_all_inserted_rows(): void
    {
        [$directory] = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_snapshot BEFORE INSERT ON monday_items BEGIN SELECT RAISE(ABORT, 'test rejection'); END");

        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('Import danych został cofnięty')->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('workflow_records', 0);
        $this->assertDatabaseCount('monday_items', 0);
    }

    public function test_enabled_mail_and_production_without_maintenance_prevent_import(): void
    {
        [$directory] = $this->snapshot();
        config(['hr.mail_enabled' => true]);
        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('HR_MAIL_ENABLED=false')->assertFailed();
        config(['hr.mail_enabled' => false]);
        app()['env'] = 'production';
        $this->artisan('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true])->expectsOutputToContain('trybu konserwacji')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_actual_mysql_import_preserves_json_unicode_and_foreign_keys(): void
    {
        $socket = getenv('KADRELIO_MYSQL_TEST_SOCKET');
        if (! $socket) {
            $this->markTestSkipped('Rzeczywisty MySQL wymaga odizolowanego KADRELIO_MYSQL_TEST_SOCKET.');
        }
        $this->assertStringStartsWith('/tmp/kadrelio-mysql-check-', $socket);
        [$directory, $expected] = $this->snapshot();
        $admin = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertStringContainsString('kadrelio-mysql-check-', $admin->query('SELECT @@datadir')->fetchColumn());
        $database = 'kadrelio_test_'.bin2hex(random_bytes(6));
        $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.snapshot_mysql' => ['driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'prefix' => '']]);
        $this->artisan('migrate', ['--database' => 'snapshot_mysql', '--force' => true, '--no-interaction' => true])->assertSuccessful();
        DB::setDefaultConnection('snapshot_mysql');
        Mail::fake();
        Queue::fake();
        try {
            $exit = Artisan::call('kadrelio:import-snapshot', ['path' => $directory, '--apply' => true]);
            $this->assertSame(0, $exit, Artisan::output());
            $this->assertImported($expected);
            $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);
            $this->assertSame(0, DB::table('jobs')->count());
            Mail::assertNothingOutgoing();
            Queue::assertNothingPushed();
        } finally {
            DB::setDefaultConnection('sqlite');
            DB::purge('snapshot_mysql');
        }
    }

    private function assertImported(array $expected): void
    {
        $this->assertSame(1, DB::table('companies')->count());
        $this->assertSame($expected['password'], User::findOrFail($expected['user_id'])->password);
        $employee = Employee::findOrFail($expected['employee_id']);
        $this->assertSame('poprawka@example.test', $employee->email);
        $this->assertSame('00111122223333444455556666', $employee->personal_data['bank_account']);
        $this->assertSame($expected['encrypted'], DB::table('employees')->where('id', $employee->id)->value('personal_data'));
        $this->assertSame('44051401458', $employee->pesel);
        $this->assertSame($expected['contract_id'], WorkflowRecord::findOrFail($expected['amendment_id'])->contract_id);
        $this->assertSame('2026-10-28', WorkflowRecord::findOrFail($expected['contract_id'])->concluded_at->format('Y-m-d'));
        $item = MondayItem::findOrFail($expected['child_id']);
        $this->assertSame($expected['parent_id'], $item->parent_id);
        $this->assertSame('Poprawiony status', $item->fields['B']);
        $this->assertSame('Podpisana', $item->source_data['values']['B']);
        $this->assertSame(1, $item->revisions()->count());
        $this->assertSame(1, $item->updates()->count());
        $report = MondayImportBatch::firstOrFail()->report;
        $this->assertSame('Zażółć gęślą', $report['z']);
        $this->assertSame('trzy', $report['a']['C']);
        $this->assertSame('jeden', $report['a']['A']);
        $this->assertSame($expected['user_id'], DB::table('company_user')->value('user_id'));
        $this->assertSame('oryginalny plik', Storage::disk('local')->get('monday/skan.pdf'));
        $this->assertSame('legitymacja', Storage::disk('local')->get('employees/legitymacja.png'));
    }

    private function snapshot(): array
    {
        $this->travelTo('2026-10-28 12:00:00');
        Storage::fake('local');
        $directory = sys_get_temp_dir().'/kadrelio-snapshot-test-'.Str::uuid();
        File::ensureDirectoryExists($directory.'/private', 0700);
        $this->directories[] = $directory;
        $owner = User::factory()->create();
        $company = Company::factory()->for($owner, 'owner')->create();
        $company->users()->attach($owner);
        $employee = Employee::factory()->completed()->for($company)->create(['email' => 'poprawka@example.test', 'pesel' => '44051401458', 'pesel_hash' => Employee::identityHash('44051401458')]);
        $personal = $employee->personal_data;
        $personal['attachments'] = [['path' => 'employees/legitymacja.png']];
        $employee->personal_data = $personal;
        $employee->save();
        $amendment = WorkflowRecord::factory()->for($company)->for($employee)->create(['kind' => 'amendment', 'status' => 'ready']);
        $contract = WorkflowRecord::factory()->signed()->for($company)->for($employee)->create();
        $amendment->forceFill(['contract_id' => $contract->id])->save();
        $batch = MondayImportBatch::factory()->create(['owner_id' => $owner->id, 'report' => ['z' => 'Zażółć gęślą', 'a' => ['C' => 'trzy', 'A' => 'jeden']]]);
        $board = MondayBoard::factory()->for($company)->create(['monday_import_batch_id' => $batch->id, 'workbook_path' => 'monday/źródło.xlsx']);
        $child = MondayItem::factory()->for($board, 'board')->create(['employee_id' => $employee->id, 'workflow_record_id' => $amendment->id]);
        $parent = MondayItem::factory()->for($board, 'board')->create(['employee_id' => $employee->id, 'workflow_record_id' => $contract->id]);
        $fields = $child->fields;
        $fields['B'] = 'Poprawiony status';
        $child->forceFill(['parent_id' => $parent->id, 'fields' => $fields, 'revision' => 1])->save();
        MondayRevision::factory()->create(['monday_item_id' => $child->id, 'user_id' => $owner->id]);
        MondayUpdate::factory()->create(['monday_import_batch_id' => $batch->id, 'monday_item_id' => $child->id]);
        $asset = MondayAsset::factory()->create(['monday_import_batch_id' => $batch->id, 'name' => 'Zażółć.pdf', 'path' => 'monday/skan.pdf']);
        $child->assets()->attach($asset, ['column_key' => 'F']);
        DocumentTemplate::factory()->for($company)->create(['status' => 'draft', 'source_path' => 'templates/wzór.docx']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);
        DB::table('sessions')->insert(['id' => 'stara-sesja', 'payload' => 'dane', 'last_activity' => 1]);
        DB::table('cache')->insert(['key' => 'stary-cache', 'value' => 'dane', 'expiration' => 1]);
        $expected = ['user_id' => $owner->id, 'employee_id' => $employee->id, 'password' => $owner->password, 'encrypted' => $employee->getRawOriginal('personal_data'), 'contract_id' => $contract->id, 'amendment_id' => $amendment->id, 'child_id' => $child->id, 'parent_id' => $parent->id];
        DB::statement('VACUUM INTO ?', [$directory.'/database.sqlite']);
        $counts = [];
        foreach (DB::getSchemaBuilder()->getTableListing('main', false) as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $files = [['path' => 'database.sqlite', 'size' => filesize($directory.'/database.sqlite'), 'sha256' => hash_file('sha256', $directory.'/database.sqlite')]];
        foreach (['monday/skan.pdf' => 'oryginalny plik', 'monday/źródło.xlsx' => 'arkusz źródłowy', 'templates/wzór.docx' => 'szablon', 'employees/legitymacja.png' => 'legitymacja'] as $path => $content) {
            File::ensureDirectoryExists(dirname($directory.'/private/'.$path), 0700);
            File::put($directory.'/private/'.$path, $content);
            $files[] = ['path' => 'private/'.$path, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        File::put($directory.'/manifest.json', json_encode(['format' => 'kadrelio-sqlite-transfer-v1', 'tables' => $counts, 'files' => $files], JSON_UNESCAPED_UNICODE));
        DB::purge('sqlite');
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        config(['hr.mail_enabled' => false]);

        return [$directory, $expected];
    }
}
