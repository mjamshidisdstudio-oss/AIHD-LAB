<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSystemLogsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;
    private string|null $originalLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('logs/laravel.log');
        $this->originalLog = is_file($this->logPath) ? file_get_contents($this->logPath) : null;

        if (!is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalLog !== null) {
            file_put_contents($this->logPath, $this->originalLog);
        } elseif (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_fetch_system_logs(): void
    {
        $this->getJson('/api/admin/logs/system')->assertUnauthorized();
    }

    public function test_admin_can_fetch_a_sanitized_tail_and_filter_by_query(): void
    {
        $this->actingAsAdmin();

        $superSecret = 'supersecret-token-1234567890';

        $contents = implode("\n", [
            '[2026-07-21 12:00:00] INFO boot',
            '[2026-07-21 12:01:00] ERROR FetchError: [GET] "https://api.revivoto.ai/api/marketplace/services": 503 undefined',
            "Authorization: Bearer {$superSecret}",
            '[2026-07-21 12:02:00] DEBUG some_other_debug_line',
            '[2026-07-21 12:03:00] ERROR another_failure',
        ]) . "\n";

        file_put_contents($this->logPath, $contents);

        $filtered = $this->getJson('/api/admin/logs/system?limit=10&q=marketplace%2Fservices')->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertStringContainsString('marketplace/services', $filtered->json('data.0'));

        $all = $this->getJson('/api/admin/logs/system?limit=10')->assertOk();

        $this->assertNotSame($superSecret, $all->json('data.2'));
        $this->assertTrue(collect($all->json('data'))->some(fn ($l) => str_contains((string) $l, '[REDACTED]')));
    }

    public function test_limit_is_clamped_to_a_reasonable_range(): void
    {
        $this->actingAsAdmin();

        file_put_contents($this->logPath, str_repeat("line\n", 100));

        $response = $this->getJson('/api/admin/logs/system?limit=10000')->assertOk();
        $this->assertLessThanOrEqual(500, count($response->json('data')));
    }
}

