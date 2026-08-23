<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_reports_whether_google_is_configured(): void
    {
        // The prop key has to match the Vue prop name exactly — a snake_case key
        // would silently read as undefined and hide the connect button.
        $user = User::factory()->create();

        config(['mail_providers.google.client_id' => null, 'mail_providers.google.client_secret' => null]);

        $this->actingAs($user)->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('Accounts/Index')
            ->where('googleConfigured', false));

        config([
            'mail_providers.google.client_id' => 'id',
            'mail_providers.google.client_secret' => 'secret',
        ]);

        $this->actingAs($user)->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->where('googleConfigured', true));
    }

    public function test_a_client_id_with_no_secret_does_not_count_as_configured(): void
    {
        config(['mail_providers.google.client_id' => 'id', 'mail_providers.google.client_secret' => null]);

        $this->actingAs(User::factory()->create())->get('/accounts')
            ->assertInertia(fn (Assert $page) => $page->where('googleConfigured', false));
    }
}
