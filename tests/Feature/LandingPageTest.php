<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_guest_sees_landing_page_with_account_links(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Kadry, które po prostu')
            ->assertSee(route('register'))
            ->assertSee(route('login'));
    }

    public function test_signed_in_user_is_redirected_to_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
    }
}
