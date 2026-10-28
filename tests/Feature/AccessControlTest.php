<?php

namespace Tests\Feature;

use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public function test_registration_always_creates_a_manager(): void
    {
        config(['hr.registration_enabled' => true]);

        $this->post('/register', ['name' => 'Anna Testowa', 'email' => 'ANNA@example.test', 'password' => 'Strongpass123', 'password_confirmation' => 'Strongpass123', 'role' => 'admin'])
            ->assertRedirect(route('companies.create'));

        $this->assertDatabaseHas('users', ['email' => 'anna@example.test', 'role' => 'manager']);
        $this->assertAuthenticatedAs(User::where('email', 'anna@example.test')->firstOrFail());
    }

    public function test_paused_registration_shows_contact_and_creates_no_account(): void
    {
        config(['hr.registration_enabled' => false]);

        $this->get(route('register'))->assertOk()->assertSee('Rejestracja jest chwilowo wstrzymana')->assertSee('mailto:kontakt@kadrelio.pl')->assertDontSee('name="password"', false);
        $this->post('/register', ['name' => 'Anna Testowa', 'email' => 'anna@example.test', 'password' => 'Strongpass123', 'password_confirmation' => 'Strongpass123'])
            ->assertRedirect(route('register'));

        $this->assertDatabaseMissing('users', ['email' => 'anna@example.test']);
        $this->assertGuest();
    }

    public function test_manager_creates_a_company_and_becomes_its_owner(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)->post(route('companies.store'), ['name' => 'Nowa Firma', 'hr_email' => 'hr@example.test', 'privacy_url' => 'https://example.test/rodo.pdf', 'color' => 'purple', 'owner_id' => 999])->assertRedirect();

        $company = Company::where('name', 'Nowa Firma')->firstOrFail();
        $this->assertSame($manager->id, $company->owner_id);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $manager->id]);
    }

    public function test_user_cannot_create_companies_or_invite_members(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $company = Company::factory()->create();
        $company->users()->attach($user);
        Mail::fake();

        $this->actingAs($user)->get(route('companies.create'))->assertForbidden();
        $this->post(route('companies.store'), [])->assertForbidden();
        $this->post(route('companies.members.store', $company), ['name' => 'Nowa Osoba', 'email' => 'new@example.test'])->assertForbidden();
        $this->get(route('companies.edit', $company))->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_other_company_and_nested_resources_return_404(): void
    {
        $manager = User::factory()->create();
        $company = Company::factory()->create();
        $record = WorkflowRecord::factory()->for($company)->create();
        $template = DocumentTemplate::factory()->for($company)->create();

        $this->actingAs($manager)->get(route('companies.board', [$company, 'contracts']))->assertNotFound();
        $this->get(route('companies.records.show', [$company, $record]))->assertNotFound();
        $this->get(route('companies.records.document', [$company, $record]))->assertNotFound();
        $this->get(route('companies.employees.show', [$company, $record->employee]))->assertNotFound();
        $this->get(route('companies.templates.edit', [$company, $template]))->assertNotFound();
        $this->post(route('companies.records.store', [$company, 'contract']), [])->assertNotFound();
    }

    public function test_scoped_binding_blocks_resources_from_another_company(): void
    {
        $manager = User::factory()->create();
        $company = Company::factory()->for($manager, 'owner')->create();
        $otherRecord = WorkflowRecord::factory()->create();
        $otherTemplate = DocumentTemplate::factory()->create();

        $this->actingAs($manager)->get(route('companies.records.show', [$company, $otherRecord]))->assertNotFound();
        $this->get(route('companies.records.document', [$company, $otherRecord]))->assertNotFound();
        $this->get(route('companies.employees.show', [$company, $otherRecord->employee]))->assertNotFound();
        $this->get(route('companies.templates.edit', [$company, $otherTemplate]))->assertNotFound();
    }

    public function test_assigned_user_sees_only_assigned_companies(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $company = Company::factory()->create(['name' => 'Widoczna Firma']);
        $other = Company::factory()->create(['name' => 'Ukryta Firma']);
        $company->users()->attach($user);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Widoczna Firma')->assertDontSee('Ukryta Firma');
        $this->get(route('companies.board', [$company, 'contracts']))->assertOk();
        $this->get(route('companies.board', [$other, 'contracts']))->assertNotFound();
    }

    public function test_admin_sees_every_company_and_manages_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)->get(route('companies.board', [$company, 'contracts']))->assertOk();
        $this->patch(route('admin.users.update', $user), ['role' => 'manager'])->assertRedirect();
        $this->assertSame('manager', $user->fresh()->role);
        $this->patch(route('admin.users.update', $admin), ['role' => 'user'])->assertUnprocessable();
    }

    public function test_manager_cannot_change_global_roles(): void
    {
        $manager = User::factory()->create();
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($manager)->patch(route('admin.users.update', $user), ['role' => 'admin'])->assertForbidden();
        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_invitation_acceptance_creates_user_only_in_invited_company(): void
    {
        $company = Company::factory()->create();
        $invitation = new CompanyInvitation;
        $invitation->company_id = $company->id;
        $invitation->name = 'Zaproszona Osoba';
        $invitation->email = 'invite@example.test';
        $invitation->token = Str::random(64);
        $invitation->expires_at = now()->addDay();
        $invitation->save();

        $this->get(route('invitations.show', $invitation->token))->assertOk()->assertSee('Akceptuję zaproszenie');
        $this->post(route('invitations.show', $invitation->token), ['password' => 'Strongpass123', 'password_confirmation' => 'Strongpass123', 'role' => 'admin'])->assertRedirect();

        $user = User::where('email', $invitation->email)->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertSame([$company->id], $user->companies->modelKeys());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->post(route('invitations.show', $invitation->token), [])->assertGone();
    }

    public function test_inviting_existing_account_does_not_reset_password_or_grant_access_before_acceptance(): void
    {
        $company = Company::factory()->create();
        $existing = User::factory()->create();
        Mail::fake();

        $this->actingAs($company->owner)->post(route('companies.members.store', $company), ['name' => 'Nowe Imię', 'email' => $existing->email, 'password' => 'Changed12345', 'role' => 'admin'])->assertRedirect();

        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id, 'user_id' => $existing->id]);
        $this->assertSame($existing->password, $existing->fresh()->password);
        $invitation = $company->invitations()->firstOrFail();
        $this->get(route('invitations.show', $invitation->token))->assertOk()->assertSee('Wyloguj się i zmień konto');
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo($existing->email));
    }

    public function test_private_routes_require_login(): void
    {
        $company = Company::factory()->create();

        $this->get(route('companies.board', [$company, 'contracts']))->assertRedirect(route('login'));
        $this->post(route('companies.records.store', [$company, 'contract']), [])->assertRedirect(route('login'));
    }

    public function test_queued_invitation_link_is_encrypted_in_the_database(): void
    {
        $company = Company::factory()->create();
        config(['queue.default' => 'database']);

        $this->actingAs($company->owner)->post(route('companies.members.store', $company), ['name' => 'Osoba Zaproszona', 'email' => 'secret@example.test'])->assertRedirect();

        $invitation = $company->invitations()->firstOrFail();
        $payload = DB::table('jobs')->value('payload');
        $this->assertNotNull($payload);
        $this->assertStringNotContainsString($invitation->token, $payload);
        $this->assertStringNotContainsString('secret@example.test', $payload);
    }

    public function test_demo_login_cannot_bypass_password_authentication(): void
    {
        config(['hr.demo_enabled' => true]);

        $this->post('/demo')->assertNotFound();
        $this->assertGuest();
    }
}
