<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    #[DataProvider('permissions')]
    public function test_only_the_named_administrator_has_permission(string $email, string $role, bool $allowed): void
    {
        $user = User::factory()->make(['email' => $email, 'role' => $role]);

        $this->assertSame($allowed, Gate::forUser($user)->allows('viewLogViewer'));
    }

    public static function permissions(): array
    {
        return [
            'named administrator' => ['admin@kadrelio.pl', 'admin', true],
            'case insensitive email' => ['ADMIN@KADRELIO.PL', 'admin', true],
            'other administrator' => ['other@kadrelio.pl', 'admin', false],
            'named manager' => ['admin@kadrelio.pl', 'manager', false],
            'named regular user' => ['admin@kadrelio.pl', 'user', false],
        ];
    }

    public function test_guests_must_log_in_before_viewing_the_page(): void
    {
        $this->get('/log-viewer')->assertRedirect(route('login'));
        $this->getJson('/log-viewer/api/files')->assertUnauthorized();
        $this->getJson('/log-viewer/api/logs')->assertUnauthorized();
        $this->getJson('/log-viewer/api/files/example/download')->assertUnauthorized();
    }

    public function test_other_administrators_cannot_access_the_page_or_api(): void
    {
        $user = User::factory()->create(['email' => 'other@kadrelio.pl', 'role' => 'admin']);

        $this->actingAs($user)->get('/log-viewer')->assertForbidden();
        $this->getJson('/log-viewer/api/files')->assertForbidden();
        $this->getJson('/log-viewer/api/logs')->assertForbidden();
        $this->getJson('/log-viewer/api/files/example/download')->assertForbidden();
    }

    public function test_named_administrator_can_open_the_page_and_api_in_production(): void
    {
        $user = User::factory()->create(['email' => 'admin@kadrelio.pl', 'role' => 'admin']);
        $this->app->instance('env', 'production');
        config(['log-viewer.include_files' => []]);

        $this->actingAs($user)->get('/log-viewer')->assertOk()
            ->assertSee('Log Viewer')->assertSee('window.LogViewer', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/log-viewer/api/files')->assertOk()->assertExactJson([]);
        $this->getJson('/log-viewer/api/logs')->assertOk()->assertJsonPath('logs', [])
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_named_administrator_cannot_delete_log_files_or_folders(): void
    {
        $user = User::factory()->make(['email' => 'admin@kadrelio.pl', 'role' => 'admin']);

        $this->assertFalse(Gate::forUser($user)->allows('deleteLogFile'));
        $this->assertFalse(Gate::forUser($user)->allows('deleteLogFolder'));
    }

    public function test_named_administrator_can_read_log_contents_without_deleting_files(): void
    {
        $user = User::factory()->create(['email' => 'admin@kadrelio.pl', 'role' => 'admin']);
        Storage::fake('local');
        Storage::disk('local')->put('log-viewer.log', "[2026-10-30 12:00:00] testing.ERROR: Log Viewer test entry\n");
        config(['log-viewer.include_files' => [Storage::disk('local')->path('log-viewer.log')]]);

        $file = $this->actingAs($user)->getJson('/log-viewer/api/files')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.name', 'log-viewer.log')
            ->assertJsonPath('0.can_delete', false)->json('0.identifier');
        $this->getJson('/log-viewer/api/logs?file='.urlencode($file))->assertOk()
            ->assertJsonPath('logs.0.message', 'Log Viewer test entry');
        $this->deleteJson('/log-viewer/api/files/'.$file)->assertForbidden();

        Storage::disk('local')->assertExists('log-viewer.log');
    }
}
