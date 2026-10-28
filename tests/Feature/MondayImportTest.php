<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use App\Services\MondayAssetImport;
use App\Services\MondayImport;
use App\Support\MondayFields;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class MondayImportTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_dry_run_reads_the_export_without_writing_database_or_private_files(): void
    {
        Storage::fake('local');
        $path = $this->export();

        $this->artisan('monday:import', ['path' => $path, '--json' => true])->assertSuccessful();

        $this->assertDatabaseCount('monday_import_batches', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_preserves_all_columns_versions_groups_files_and_comments_without_outgoing_actions(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $owner = User::factory()->create();
        $path = $this->export();
        $importer = app(MondayImport::class);

        $batch = $importer->run($path, 'synthetic-export', $owner, $importer->inspect($path));

        $report = $importer->verify($batch);
        $this->assertTrue($report['balanced']);
        $this->assertSame(['boards' => 7, 'items' => 10, 'updates' => 1, 'team' => 1], $report['actual']);
        $this->assertSame(0, $report['unlinked_updates']);
        $this->assertSame(1, $report['assets']);
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workflow_records', 4);
        $profile = MondayItem::where('source_id', '10001')->firstOrFail();
        $this->assertSame('01234567890', $profile->fields['C']);
        $this->assertSame('Pierwsza wartość', $profile->fields['G']);
        $this->assertSame('Druga wartość', $profile->fields['H']);
        $this->assertSame('Duplikat nagłówka', $profile->source_data['headers']['G']);
        $this->assertSame('Duplikat nagłówka', $profile->source_data['headers']['H']);
        $this->assertContains('duplicate_profile', $profile->issues);
        $this->assertTrue($profile->employee->personal_data['profile_review_required']);
        $this->assertNull($profile->employee->profile_completed_at);
        $otherProfile = MondayItem::where('source_id', '20001')->firstOrFail();
        $this->assertNotSame($profile->employee_id, $otherProfile->employee_id);
        $this->assertSame($profile->employee->pesel_hash, $otherProfile->employee->pesel_hash);
        $amendment = MondayItem::where('source_id', '40001')->firstOrFail();
        $this->assertSame('matched', $amendment->relation_state);
        $this->assertSame('30001', $amendment->parent->source_id);
        $this->assertSame($amendment->parent->workflow_record_id, $amendment->record->contract_id);
        $this->assertSame('ambiguous', MondayItem::where('source_id', '60001')->firstOrFail()->relation_state);
        $contract = MondayItem::where('source_id', '30001')->firstOrFail();
        $this->assertSame('Podpisana', $contract->status);
        $this->assertSame('signed', $contract->record->status);
        $this->assertNull($contract->record->signed_at);
        $this->assertNull($contract->record->signing_expires_at);
        $this->assertSame('Komentarz <script>test</script>', $contract->updates->first()->payload['values']['F']);
        $this->assertStringNotContainsString('01234567890', DB::table('monday_items')->where('id', $profile->id)->value('source_data'));
        Storage::disk('local')->assertExists($contract->board->workbook_path);
        $this->assertSame(hash_file('sha256', $path.'/boards/1722942637_contracts.xlsx'), hash_file('sha256', Storage::disk('local')->path($contract->board->workbook_path)));
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_repeating_import_keeps_local_edits_and_does_not_duplicate_rows(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $path = $this->export();
        $importer = app(MondayImport::class);
        $inspection = $importer->inspect($path);
        $batch = $importer->run($path, 'repeat', $owner, $inspection);
        $item = MondayItem::where('source_id', '30001')->firstOrFail();
        $item->fields = array_merge($item->fields, ['A' => 'Zmiana lokalna']);
        $item->save();

        $second = $importer->run($path, 'repeat', $owner, $inspection);

        $this->assertSame($batch->id, $second->id);
        $this->assertDatabaseCount('monday_items', 10);
        $this->assertDatabaseCount('workflow_records', 4);
        $this->assertDatabaseCount('employees', 2);
        $this->assertSame('Zmiana lokalna', $item->fresh()->fields['A']);
    }

    public function test_changed_export_cannot_overwrite_a_completed_import(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $path = $this->export();
        $importer = app(MondayImport::class);
        $importer->run($path, 'immutable', $owner, $importer->inspect($path));
        $inspection = $importer->inspect($path);
        $inspection['fingerprint'] = str_repeat('a', 64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ten klucz należy do innego zestawu plików');
        $importer->run($path, 'immutable', $owner, $inspection);
    }

    public function test_assets_are_attached_only_by_resource_id_and_conflicting_content_is_preserved(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $path = $this->export();
        $importer = app(MondayImport::class);
        $batch = $importer->run($path, 'assets', $owner, $importer->inspect($path));
        mkdir($path.'/assets');
        file_put_contents($path.'/assets/987654321_document.pdf', '%PDF synthetic original');
        $assets = app(MondayAssetImport::class);

        $preview = $assets->run($batch, $path.'/assets', false);
        $this->assertSame(1, $preview['matched']);
        $this->assertNull($batch->assets()->first()->path);
        $first = $assets->run($batch, $path.'/assets', true);
        $this->assertSame(0, $first['missing']);
        $asset = $batch->assets()->first();
        $this->assertSame('%PDF synthetic original', Storage::disk('local')->get($asset->path));
        $repeat = $assets->run($batch, $path.'/assets', true);
        $this->assertSame(1, $repeat['already_present']);
        file_put_contents($path.'/assets/987654321_document.pdf', '%PDF different');
        $conflict = $assets->run($batch, $path.'/assets', true);
        $this->assertSame(['987654321'], $conflict['conflicts']);
        $this->assertSame('%PDF synthetic original', Storage::disk('local')->get($asset->path));
    }

    public function test_source_data_is_preserved_when_dates_or_identifiers_need_review(): void
    {
        $this->assertSame('nip', MondayFields::identityType('5260250274'));
        $this->assertSame('pesel', MondayFields::identityType('44051401458'));
        $this->assertSame('unverified', MondayFields::identityType('01234567890'));
        $this->assertSame('2024-02-29', MondayFields::date('29.02.2024'));
        $this->assertSame('2024-02-29', MondayFields::date('29022024'));
        $this->assertSame('2024-02-29', MondayFields::date('29/02/2024'));
        $this->assertSame('1904-01-02', MondayFields::date('00001', true));
        $this->assertNull(MondayFields::date('31.02.2024'));
        $this->assertNull(MondayFields::date('ww'));
        $this->assertNull(MondayFields::date('czas nieokreślony'));
    }

    public function test_import_refuses_a_demo_target_and_rolls_back_database_changes(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $company = Company::factory()->for($owner, 'owner')->create(['is_demo' => true]);
        $path = $this->export();

        $this->artisan('monday:import', ['path' => $path, '--commit' => true, '--owner' => $owner->id, '--company' => ['otc:'.$company->id]])->assertFailed();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('monday_import_batches', 0);
        $this->assertDatabaseCount('monday_items', 0);
        $this->assertTrue((bool) $company->fresh()->is_demo);
    }

    public function test_production_readiness_reports_missing_assets_and_configuration(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $path = $this->export();
        $importer = app(MondayImport::class);
        $importer->run($path, 'production-check', $owner, $importer->inspect($path));

        $this->artisan('monday:verify', ['--key' => 'production-check', '--files' => true])->assertSuccessful();
        $this->artisan('monday:verify', ['--key' => 'production-check', '--production' => true])->expectsOutputToContain('"production_ready": false')->assertFailed();
        foreach (Company::all() as $company) {
            DocumentTemplate::factory()->for($company)->create(['status' => 'draft']);
        }
        config(['hr.mail_enabled' => false]);
        $this->assertSame(1, Artisan::call('monday:verify', ['--key' => 'production-check', '--production' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(Company::orderBy('id')->pluck('id')->all(), collect($report['production_blockers']['companies_without_templates'])->sort()->values()->all());
        $this->assertTrue($report['production_blockers']['outgoing_mail_disabled']);
    }

    public function test_verification_detects_changed_original_cells_even_when_counts_match(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $path = $this->export();
        $importer = app(MondayImport::class);
        $batch = $importer->run($path, 'source-integrity', $owner, $importer->inspect($path));
        $this->assertSame([], $importer->verifySource($batch)['mismatches']);
        $item = MondayItem::where('source_id', '10001')->firstOrFail();
        $source = $item->source_data;
        $source['values']['A'] = 'Uszkodzona wartość';
        $item->source_data = $source;
        $item->save();

        $this->assertTrue($importer->verify($batch)['balanced']);
        $this->assertSame(['item:1701529304:10001'], $importer->verifySource($batch)['mismatches']);
        $this->artisan('monday:verify', ['--key' => 'source-integrity', '--files' => true])->assertFailed();
    }

    public function test_review_migration_generates_no_drop_statements_for_sqlite(): void
    {
        $migration = require database_path('migrations/2026_10_21_151823_add_review_to_monday_items_table.php');

        $queries = DB::pretend(fn () => $migration->up());

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('drop ', mb_strtolower($query['query']));
        }
    }

    public function test_complete_export_is_balanced_when_explicitly_provided(): void
    {
        $path = getenv('MONDAY_EXPORT_PATH');
        if (! $path) {
            $this->markTestSkipped('Pełny eksport uruchamiany przez MONDAY_EXPORT_PATH.');
        }
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $importer = app(MondayImport::class);

        $batch = $importer->run($path, 'full-export-test', $owner, $importer->inspect($path));

        $report = $importer->verify($batch);
        $this->assertTrue($report['balanced']);
        $this->assertSame(['boards' => 37, 'items' => 7845, 'updates' => 1621, 'team' => 8], $report['actual']);
        $this->assertSame(0, $report['unlinked_updates']);
        $this->assertSame(13717, $report['assets']);
        $this->assertSame(3181, $report['workflows']);
        $this->assertSame(0, WorkflowRecord::whereNotNull('signed_at')->count());
        $this->assertSame(0, WorkflowRecord::whereNotNull('signing_expires_at')->count());
        $this->assertSame(0, WorkflowRecord::whereNotNull('generated_at')->count());
        $this->actingAs($owner)->get(route('monday.index'))->assertOk();
        foreach ($batch->boards as $board) {
            $this->get(route('monday.boards.show', $board))->assertOk();
            $item = $board->items()->first();
            if ($item) {
                $this->get(route('monday.items.show', [$board, $item]))->assertOk();
            }
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function export(): string
    {
        $root = sys_get_temp_dir().'/kadrelio-monday-test-'.bin2hex(random_bytes(8));
        $this->directories[] = $root;
        foreach (['boards', 'updates', 'team'] as $folder) {
            mkdir($root.'/'.$folder, 0700, true);
        }
        $profileHeader = ['A' => 'Name', 'B' => 'Imię/Name*', 'C' => 'PESEL/Personal ID number', 'D' => 'E-mail', 'E' => 'Data wypełnienia formularza', 'F' => 'Item ID (auto generated)', 'G' => 'Duplikat nagłówka', 'H' => 'Duplikat nagłówka'];
        $this->workbook($root.'/boards/1701529304_profiles.xlsx', [['A' => 'OTC Baza'], ['A' => 'Pracownicy'], $profileHeader, ['A' => 'Kowalska', 'B' => 'Anna', 'C' => '01234567890', 'D' => 'anna@example.test', 'E' => '01.01.2024', 'F' => '10001', 'G' => 'Pierwsza wartość', 'H' => 'Druga wartość'], ['A' => 'Kowalska', 'B' => 'Anna', 'C' => '01234567890', 'D' => 'anna.nowy@example.test', 'E' => '01.01.2025', 'F' => '10002']]);
        $this->workbook($root.'/boards/1884552786_profiles.xlsx', [['A' => 'Approval Baza'], ['A' => 'Pracownicy'], $profileHeader, ['A' => 'Kowalska', 'B' => 'Anna', 'C' => '01234567890', 'D' => 'anna@example.test', 'E' => '01.01.2024', 'F' => '20001']]);
        $this->workbook($root.'/boards/1722942637_contracts.xlsx', [['A' => 'OTC Umowy'], ['A' => 'Aktualne umowy'], ['A' => 'Name', 'B' => 'PESEL strony umowy', 'C' => 'Data zawarcia umowy formularz', 'D' => 'Status umowy', 'E' => 'Umowa i załączniki', 'F' => 'Item ID (auto generated)', 'G' => 'Imię'], ['A' => 'Anna Kowalska', 'B' => '01234567890', 'C' => '01.02.2024', 'D' => 'Podpisana', 'E' => 'https://example.monday.com/protected_static/1/resources/987654321/document.pdf', 'F' => '30001', 'G' => 'Anna'], ['A' => 'Anna Kowalska', 'B' => '01234567890', 'C' => '01.02.2025', 'D' => 'Podpisana', 'F' => '30002', 'G' => 'Anna']]);
        $this->workbook($root.'/boards/1844437397_amendments.xlsx', [['A' => 'Aneksy'], ['A' => 'Wygenerowane'], ['A' => 'Name', 'B' => 'PESEL Pracownika', 'C' => 'Data pierwotnej umowy', 'D' => 'Data zawarcia aneksu', 'E' => 'Item ID (auto generated)'], ['A' => 'Anna Kowalska', 'B' => '01234567890', 'C' => '01.02.2024', 'D' => '01.03.2024', 'E' => '40001']]);
        $this->workbook($root.'/boards/1844740123_terminations.xlsx', [['A' => 'Rozwiązania'], ['A' => 'Wygenerowane'], ['A' => 'Name', 'B' => 'PESEL Pracownika', 'C' => 'Data pierwotnej umowy', 'D' => 'Umowa zostaje rozwiązana ze skutkiem na dzień:', 'E' => 'Item ID (auto generated)'], ['A' => 'Anna Kowalska', 'B' => '01234567890', 'C' => '01.02.2024', 'D' => '01.04.2024', 'E' => '50001']]);
        $this->workbook($root.'/boards/1842559266_statements.xlsx', [['A' => 'Oświadczenia'], ['A' => 'Podpisane'], ['A' => 'Name', 'B' => 'PESEL Pracownika', 'C' => 'Data oświadczenia', 'D' => 'Item ID (auto generated)'], ['A' => 'Anna Kowalska', 'B' => '01234567890', 'C' => '01.02.2024', 'D' => '60001']]);
        $this->workbook($root.'/boards/999999999_other.xlsx', [['A' => 'Pozostałe'], ['A' => 'Historia'], ['A' => 'Name', 'B' => 'Item ID (auto generated)'], ['A' => 'Szkolenie', 'B' => '70001'], ['A' => 'Inny wpis', 'B' => '70002']]);
        $this->workbook($root.'/updates/1722942637_updates.xlsx', [['A' => 'Updates'], ['A' => 'Item ID', 'B' => 'Post ID', 'C' => 'User', 'D' => 'Created At', 'E' => 'Content Type', 'F' => 'Update Content'], ['A' => '30001', 'B' => '80001', 'C' => 'HR', 'D' => '2024-02-01T10:00:00Z', 'E' => 'update', 'F' => 'Komentarz <script>test</script>']]);
        $this->workbook($root.'/team/team.xlsx', [['A' => 'Team'], ['A' => 'Nazwa', 'B' => 'Status użytkownika', 'C' => 'Typ użytkownika'], ['A' => 'Historyczne konto', 'B' => 'Dezaktywowany', 'C' => 'Administrator']]);

        return $root;
    }

    private function workbook(string $path, array $rows): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $xml .= '<row r="'.$number.'">';
            foreach ($row as $column => $value) {
                $xml .= '<c r="'.$column.$number.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="false"/><sheets><sheet name="Board" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
    }
}
