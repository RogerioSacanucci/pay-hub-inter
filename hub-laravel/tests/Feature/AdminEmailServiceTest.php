<?php

namespace Tests\Feature;

use App\Models\EmailServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminEmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->token = $admin->createToken('auth')->plainTextToken;
    }

    // ── Logs: single instance ────────────────────────────────────

    public function test_logs_proxies_to_single_instance(): void
    {
        Http::preventStrayRequests();

        $instance = EmailServiceInstance::factory()->create([
            'name' => 'Instance A',
            'url' => 'https://email-a.example.com',
            'active' => true,
        ]);

        Http::fake([
            'email-a.example.com/api.php*' => Http::response([
                'data' => [
                    ['id' => 1, 'email' => 'a@test.com', 'status' => 'sent', 'created_at' => '2026-04-01 10:00:00'],
                ],
                'meta' => ['total' => 1, 'page' => 1, 'per_page' => 25],
            ]),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/email-service/logs?instance_id={$instance->id}");

        $response->assertOk()
            ->assertJsonPath('data.0.instance_name', 'Instance A')
            ->assertJsonPath('data.0.email', 'a@test.com');
    }

    // ── Logs: aggregated ─────────────────────────────────────────

    public function test_logs_aggregates_all_instances_when_no_instance_id(): void
    {
        Http::preventStrayRequests();

        $instanceA = EmailServiceInstance::factory()->create([
            'name' => 'Instance A',
            'url' => 'https://email-a.example.com',
            'active' => true,
        ]);
        $instanceB = EmailServiceInstance::factory()->create([
            'name' => 'Instance B',
            'url' => 'https://email-b.example.com',
            'active' => true,
        ]);

        Http::fake([
            'email-a.example.com/api.php*' => Http::response([
                'data' => [
                    ['id' => 1, 'email' => 'a@test.com', 'status' => 'sent', 'created_at' => '2026-04-01 12:00:00'],
                ],
                'meta' => ['total' => 1],
            ]),
            'email-b.example.com/api.php*' => Http::response([
                'data' => [
                    ['id' => 2, 'email' => 'b@test.com', 'status' => 'sent', 'created_at' => '2026-04-01 13:00:00'],
                ],
                'meta' => ['total' => 1],
            ]),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/logs');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 25);

        // Should be sorted by created_at DESC — Instance B item first
        $this->assertEquals('b@test.com', $response->json('data.0.email'));
        $this->assertEquals('a@test.com', $response->json('data.1.email'));
        $this->assertEquals('Instance B', $response->json('data.0.instance_name'));
        $this->assertEquals('Instance A', $response->json('data.1.instance_name'));
    }

    public function test_logs_ignores_failed_instances(): void
    {
        Http::preventStrayRequests();

        $healthy = EmailServiceInstance::factory()->create([
            'name' => 'Healthy',
            'url' => 'https://healthy.example.com',
            'active' => true,
        ]);
        $broken = EmailServiceInstance::factory()->create([
            'name' => 'Broken',
            'url' => 'https://broken.example.com',
            'active' => true,
        ]);

        Http::fake([
            'healthy.example.com/api.php*' => Http::response([
                'data' => [['id' => 1, 'email' => 'ok@test.com', 'created_at' => '2026-04-01']],
                'meta' => ['total' => 1],
            ]),
            'broken.example.com/api.php*' => Http::response([], 500),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/logs');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.instance_name', 'Healthy');
    }

    public function test_logs_excludes_inactive_instances(): void
    {
        Http::preventStrayRequests();

        $active = EmailServiceInstance::factory()->create([
            'name' => 'Active',
            'url' => 'https://active.example.com',
            'active' => true,
        ]);
        EmailServiceInstance::factory()->create([
            'name' => 'Inactive',
            'url' => 'https://inactive.example.com',
            'active' => false,
        ]);

        Http::fake([
            'active.example.com/api.php*' => Http::response([
                'data' => [['id' => 1, 'email' => 'a@test.com', 'created_at' => '2026-04-01']],
                'meta' => ['total' => 1],
            ]),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/logs');

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        Http::assertSentCount(1);
    }

    public function test_logs_returns_empty_when_no_active_instances(): void
    {
        Http::preventStrayRequests();

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/logs');

        $response->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    // ── Stats: single instance ───────────────────────────────────

    public function test_stats_proxies_to_single_instance(): void
    {
        Http::preventStrayRequests();

        $instance = EmailServiceInstance::factory()->create([
            'url' => 'https://email-a.example.com',
            'active' => true,
        ]);

        Http::fake([
            'email-a.example.com/api.php*' => Http::response([
                'data' => [
                    'total' => 100,
                    'failures' => 5,
                    'corrections' => 2,
                    'success_rate' => 95.0,
                    'chart' => [],
                ],
            ]),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/email-service/stats?instance_id={$instance->id}");

        $response->assertOk()
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.success_rate', 95);
    }

    // ── Stats: aggregated ────────────────────────────────────────

    public function test_stats_aggregates_all_instances(): void
    {
        Http::preventStrayRequests();

        EmailServiceInstance::factory()->create([
            'url' => 'https://email-a.example.com',
            'active' => true,
        ]);
        EmailServiceInstance::factory()->create([
            'url' => 'https://email-b.example.com',
            'active' => true,
        ]);

        Http::fake([
            'email-a.example.com/api.php*' => Http::response([
                'data' => [
                    'total' => 100,
                    'failures' => 10,
                    'corrections' => 5,
                    'chart' => [
                        ['date' => '2026-04-01', 'sent' => 50, 'failed' => 5, 'corrections' => 2],
                        ['date' => '2026-04-02', 'sent' => 50, 'failed' => 5, 'corrections' => 3],
                    ],
                ],
            ]),
            'email-b.example.com/api.php*' => Http::response([
                'data' => [
                    'total' => 200,
                    'failures' => 20,
                    'corrections' => 10,
                    'chart' => [
                        ['date' => '2026-04-01', 'sent' => 100, 'failed' => 10, 'corrections' => 5],
                        ['date' => '2026-04-03', 'sent' => 100, 'failed' => 10, 'corrections' => 5],
                    ],
                ],
            ]),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/stats');

        $response->assertOk()
            ->assertJsonPath('data.total', 300)
            ->assertJsonPath('data.failures', 30)
            ->assertJsonPath('data.corrections', 15)
            ->assertJsonPath('data.success_rate', 90);

        $chart = $response->json('data.chart');
        $this->assertCount(3, $chart);

        // Chart sorted by date
        $this->assertEquals('2026-04-01', $chart[0]['date']);
        $this->assertEquals(150, $chart[0]['sent']);
        $this->assertEquals(15, $chart[0]['failed']);
        $this->assertEquals(7, $chart[0]['corrections']);

        $this->assertEquals('2026-04-02', $chart[1]['date']);
        $this->assertEquals(50, $chart[1]['sent']);

        $this->assertEquals('2026-04-03', $chart[2]['date']);
        $this->assertEquals(100, $chart[2]['sent']);
    }

    public function test_stats_ignores_failed_instances(): void
    {
        Http::preventStrayRequests();

        EmailServiceInstance::factory()->create([
            'url' => 'https://healthy.example.com',
            'active' => true,
        ]);
        EmailServiceInstance::factory()->create([
            'url' => 'https://broken.example.com',
            'active' => true,
        ]);

        Http::fake([
            'healthy.example.com/api.php*' => Http::response([
                'data' => ['total' => 100, 'failures' => 5, 'corrections' => 2, 'chart' => []],
            ]),
            'broken.example.com/api.php*' => Http::response([], 500),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/stats');

        $response->assertOk()
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.failures', 5);
    }

    public function test_stats_returns_zeros_when_no_active_instances(): void
    {
        Http::preventStrayRequests();

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/stats');

        $response->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.success_rate', 0);
    }

    // ── Users ────────────────────────────────────────────────────

    public function test_users_requires_instance_id(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/admin/email-service/users');

        $response->assertStatus(400)
            ->assertJsonPath('error', 'instance_id is required');
    }

    public function test_users_proxies_to_instance(): void
    {
        Http::preventStrayRequests();

        $instance = EmailServiceInstance::factory()->create([
            'url' => 'https://email-a.example.com',
            'active' => true,
        ]);

        Http::fake([
            'email-a.example.com/api.php*' => Http::response([
                'data' => [
                    ['id' => 1, 'email' => 'user@test.com', 'status' => 'active'],
                ],
                'meta' => ['total' => 1],
            ]),
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/email-service/users?instance_id={$instance->id}");

        $response->assertOk()
            ->assertJsonPath('data.0.email', 'user@test.com');
    }

    // ── Auth ─────────────────────────────────────────────────────

    public function test_non_admin_cannot_access_email_service(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/email-service/logs')->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/email-service/stats')->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/email-service/users')->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_email_service(): void
    {
        $this->getJson('/api/admin/email-service/logs')->assertUnauthorized();
        $this->getJson('/api/admin/email-service/stats')->assertUnauthorized();
        $this->getJson('/api/admin/email-service/users')->assertUnauthorized();
    }
}
