<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_mode_off_leaves_visitors_unauthenticated(): void
    {
        config(['auth.guest_mode' => false]);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user', null)
            ->assertJsonPath('guest_mode', false);

        $this->assertGuest();
    }

    public function test_guest_mode_on_signs_visitors_in_automatically(): void
    {
        config(['auth.guest_mode' => true]);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', config('auth.guest_user.email'))
            ->assertJsonPath('guest_mode', true);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => config('auth.guest_user.email')]);
    }

    public function test_guest_mode_reuses_the_same_account_across_requests(): void
    {
        config(['auth.guest_mode' => true]);

        $this->getJson('/api/user');
        $this->assertDatabaseCount('users', 1);

        // A second, unauthenticated request (simulating a different visitor)
        // should sign in as the same guest account, not create another one.
        $this->flushSession();
        $this->getJson('/api/user');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_can_upload_and_chat_without_registering(): void
    {
        config(['auth.guest_mode' => true]);

        $this->getJson('/api/documents')->assertOk()->assertJson([]);

        $response = $this->postJson('/api/conversations', []);
        $response->assertCreated();
    }
}
