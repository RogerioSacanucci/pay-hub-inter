<?php

namespace Tests\Feature;

use App\Models\CartpandaShop;
use App\Models\PayoutLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPayoutsIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->adminToken = $admin->createToken('auth')->plainTextToken;
    }

    public function test_admin_lists_all_payouts(): void
    {
        PayoutLog::factory()->count(3)->create();

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/payouts');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'amount', 'type', 'note', 'shop_name', 'admin_email', 'created_at', 'user' => ['id', 'name', 'email']]],
                'meta' => ['total', 'page', 'per_page', 'pages'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1);
    }

    public function test_filter_by_user_id(): void
    {
        $targetUser = User::factory()->create();
        PayoutLog::factory()->for($targetUser)->count(2)->create();
        PayoutLog::factory()->create();

        $response = $this->withToken($this->adminToken)
            ->getJson("/api/admin/payouts?user_id={$targetUser->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_filter_by_shop_id(): void
    {
        $shop = CartpandaShop::factory()->create();
        PayoutLog::factory()->forShop($shop)->count(2)->create();
        PayoutLog::factory()->create();

        $response = $this->withToken($this->adminToken)
            ->getJson("/api/admin/payouts?shop_id={$shop->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_filter_by_type(): void
    {
        PayoutLog::factory()->withdrawal()->count(2)->create();
        PayoutLog::factory()->adjustment()->create();

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/payouts?type=withdrawal');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_filter_by_date_range(): void
    {
        PayoutLog::factory()->create(['created_at' => '2026-01-15 10:00:00']);
        PayoutLog::factory()->create(['created_at' => '2026-02-15 10:00:00']);
        PayoutLog::factory()->create(['created_at' => '2026-03-15 10:00:00']);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/payouts?date_from=2026-02-01&date_to=2026-02-28');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_regular_user_receives_403(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/payouts');

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_receives_401(): void
    {
        $response = $this->getJson('/api/admin/payouts');

        $response->assertUnauthorized();
    }
}
