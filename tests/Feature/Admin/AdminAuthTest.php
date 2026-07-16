<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_log_in_and_receive_a_bearer_token(): void
    {
        User::factory()->admin()->create([
            'email' => 'ops@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/admin/login', [
            'email' => 'ops@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/services')
            ->assertOk();
    }

    public function test_a_non_admin_cannot_log_in(): void
    {
        User::factory()->create([
            'is_admin' => false,
            'email' => 'shopper@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/admin/login', [
            'email' => 'shopper@example.com',
            'password' => 'correct-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->admin()->create([
            'email' => 'ops@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/admin/login', [
            'email' => 'ops@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/logout')->assertNoContent();
    }
}
