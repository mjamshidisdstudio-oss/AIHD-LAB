<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_log_viewer(): void
    {
        $this->get('/log-viewer')->assertForbidden();
    }

    public function test_admin_can_hand_off_via_sanctum_token_query_param(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin-log-viewer');

        $this->get('/log-viewer?token='.$token->plainTextToken)
            ->assertRedirect('/log-viewer');

        $this->followRedirects($this->get('/log-viewer?token='.$token->plainTextToken))
            ->assertOk();
    }

    public function test_non_admin_token_is_rejected(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $token = $user->createToken('not-admin');

        $this->get('/log-viewer?token='.$token->plainTextToken)->assertForbidden();
    }
}
