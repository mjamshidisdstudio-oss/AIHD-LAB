<?php

namespace Tests\Feature\Auth;

use App\Contracts\TokenAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * A missing bearer token is rejected without ever calling the core — no
 * wasted call, and (per SubmitOrderApiTest) no coin call can follow it.
 */
class AuthenticateWithCoreTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_bearer_token_is_rejected_without_calling_the_authenticator(): void
    {
        $authenticator = Mockery::mock(TokenAuthenticator::class);
        $authenticator->shouldNotReceive('authenticate');
        $this->app->instance(TokenAuthenticator::class, $authenticator);
        Http::fake(); // any outbound call would be recorded and fail the assertion

        $this->postJson('/api/orders', [])->assertStatus(401);

        Http::assertNothingSent();
        // Mockery verifies authenticate(0) on tearDown.
    }
}
