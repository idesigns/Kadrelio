<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\WorkloadRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    #[DataProvider('permissions')]
    public function test_only_the_named_administrator_has_permission(string $email, string $role, bool $allowed): void
    {
        $user = User::factory()->make(['email' => $email, 'role' => $role]);

        $this->assertSame($allowed, Gate::forUser($user)->allows('viewHorizon'));
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

    public function test_guests_cannot_view_dashboard_or_jobs_or_retry_them(): void
    {
        Queue::fake();

        $this->get('/horizon')->assertRedirect(route('login'));
        $this->getJson('/horizon/api/stats')->assertUnauthorized();
        $this->getJson('/horizon/api/jobs/pending')->assertUnauthorized();
        $this->postJson('/horizon/api/jobs/retry/example')->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    #[DataProvider('environments')]
    public function test_other_administrators_cannot_view_or_retry_jobs_in_any_environment(string $environment): void
    {
        $user = User::factory()->create(['email' => 'other@kadrelio.pl', 'role' => 'admin']);
        $this->app->instance('env', $environment);
        $this->withSession(['_token' => 'horizon-access-test']);
        Queue::fake();

        $this->actingAs($user)->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
        $this->getJson('/horizon/api/jobs/pending')->assertForbidden();
        $this->postJson('/horizon/api/jobs/retry/example', ['_token' => 'horizon-access-test'])->assertForbidden();

        Queue::assertNothingPushed();
    }

    #[DataProvider('environments')]
    public function test_named_administrator_can_open_dashboard_and_workload_api(string $environment): void
    {
        $user = User::factory()->create(['email' => 'admin@kadrelio.pl', 'role' => 'admin']);
        $this->app->instance('env', $environment);
        $workload = [['name' => 'default', 'length' => 1, 'wait' => 0, 'processes' => 1]];
        $this->mock(WorkloadRepository::class)->shouldReceive('get')->once()->andReturn($workload);

        $this->actingAs($user)->get('/horizon')->assertOk()->assertSee('Horizon - Kadrelio')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/horizon/api/workload')->assertOk()->assertExactJson($workload)
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public static function environments(): array
    {
        return [['local'], ['production']];
    }
}
