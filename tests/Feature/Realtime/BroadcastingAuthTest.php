<?php

namespace Tests\Feature\Realtime;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * The order-completion socket is private per user_ref. End-customers carry a
 * core-issued bearer token, never a Laravel session/Sanctum user — proving
 * this works end-to-end (not just Event::fake()) requires a real broadcaster
 * that actually enforces channel authorization; the null/log drivers used
 * elsewhere in the suite no-op auth() entirely, so this test switches on the
 * real Pusher broadcaster (signing is a local HMAC, no network call).
 */
class BroadcastingAuthTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    /**
     * Broadcast::channel() registers routes/channels.php's callback on
     * whichever broadcaster is default AT BOOT TIME. Switching
     * broadcasting.default via config() after the app has already booted
     * (e.g. inside setUp()) resolves a brand new, unregistered broadcaster
     * instance — every channel would then 403 regardless of authorization,
     * for the wrong reason. The env vars must be in place before
     * bootstrap/app.php runs, so this is set before parent::createApplication().
     */
    public function createApplication()
    {
        foreach ([
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_KEY' => 'test-key',
            'PUSHER_APP_SECRET' => 'test-secret',
            'PUSHER_APP_ID' => 'test-app',
            'PUSHER_APP_CLUSTER' => 'mt1',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        return parent::createApplication();
    }

    public function test_the_owning_user_is_authorized_for_their_own_orders_channel(): void
    {
        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->post('/api/marketplace/broadcasting/auth', [
                'channel_name' => 'private-orders.user-1',
                'socket_id' => '1234.5678',
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_user_is_forbidden_from_another_users_orders_channel(): void
    {
        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->post('/api/marketplace/broadcasting/auth', [
                'channel_name' => 'private-orders.someone-else',
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_a_missing_bearer_token_is_rejected_at_the_auth_core_boundary(): void
    {
        $this->post('/api/marketplace/broadcasting/auth', [
            'channel_name' => 'private-orders.user-1',
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }
}
