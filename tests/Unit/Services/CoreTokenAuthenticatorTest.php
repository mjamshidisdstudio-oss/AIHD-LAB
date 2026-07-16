<?php

namespace Tests\Unit\Services;

use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Core\CoreServiceUnavailableException;
use App\Services\Auth\CoreTokenAuthenticator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real TokenAuthenticator implementation's HTTP-facing behavior in
 * isolation. The middleware's short-circuit on a missing token, and its
 * "no coin call on rejection" guarantee, are covered by
 * AuthenticateWithCoreTokenTest/SubmitOrderApiTest.
 */
class CoreTokenAuthenticatorTest extends TestCase
{
    public function test_a_valid_token_resolves_the_user_ref(): void
    {
        Http::fake(['*/verify-token' => Http::response(['user_ref' => 'user-42'])]);

        $userRef = app(CoreTokenAuthenticator::class)->authenticate('some-token');

        $this->assertSame('user-42', $userRef);
        Http::assertSent(fn ($request) => $request->url() === config('core.base_url').'/verify-token'
            && $request['token'] === 'some-token'
            && $request->hasHeader('Authorization', 'Bearer '.config('core.credential')));
    }

    public function test_a_401_response_raises_invalid_token_exception(): void
    {
        Http::fake(['*/verify-token' => Http::response(['message' => 'rejected'], 401)]);

        $this->expectException(InvalidTokenException::class);

        app(CoreTokenAuthenticator::class)->authenticate('bad-token');
    }

    public function test_an_unreachable_core_raises_core_service_unavailable(): void
    {
        Http::fake(['*/verify-token' => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(CoreServiceUnavailableException::class);

        app(CoreTokenAuthenticator::class)->authenticate('some-token');
    }
}
