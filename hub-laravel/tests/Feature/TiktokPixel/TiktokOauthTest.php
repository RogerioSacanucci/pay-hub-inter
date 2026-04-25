<?php

namespace Tests\Feature\TiktokPixel;

use App\Models\TiktokEventLog;
use App\Models\TiktokOauthConnection;
use App\Models\TiktokPixel;
use App\Models\User;
use App\Services\TiktokEventsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiktokOauthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.tiktok.app_id', 'test_app');
        Config::set('services.tiktok.app_secret', 'test_secret');
        Config::set('services.tiktok.oauth_redirect', 'https://hub.test/api/tiktok/oauth/callback');
        Config::set('services.tiktok.oauth_authorize_url', 'https://business-api.tiktok.com/portal/auth');
        Config::set('services.tiktok.open_api_base', 'https://business-api.tiktok.com/open_api/v1.3');
    }

    public function test_start_returns_authorize_url_with_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/tiktok/oauth/start')
            ->assertOk();

        $url = $response->json('authorize_url');
        $this->assertStringStartsWith('https://business-api.tiktok.com/portal/auth?', $url);
        $this->assertStringContainsString('app_id=test_app', $url);
        $this->assertStringContainsString('state=', $url);

        $this->assertSame(1, DB::table('tiktok_oauth_states')->where('user_id', $user->id)->count());
    }

    public function test_index_lists_only_authenticated_user_connections(): void
    {
        $user = User::factory()->create();
        TiktokOauthConnection::factory()->for($user)->create(['bc_name' => 'BC do user']);
        TiktokOauthConnection::factory()->create(['bc_name' => 'BC alheia']);

        $this->actingAs($user)
            ->getJson('/api/tiktok/oauth/connections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bc_name', 'BC do user');
    }

    public function test_admin_can_filter_connections_by_user_id(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        TiktokOauthConnection::factory()->for($other)->create();
        TiktokOauthConnection::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->getJson('/api/tiktok/oauth/connections?user_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $other->id);
    }

    public function test_destroy_returns_403_for_other_users_connection(): void
    {
        $user = User::factory()->create();
        $other = TiktokOauthConnection::factory()->create();

        Http::fake();

        $this->actingAs($user)
            ->deleteJson("/api/tiktok/oauth/connections/{$other->id}")
            ->assertForbidden();
    }

    public function test_pixel_store_rejects_connection_from_other_user(): void
    {
        $user = User::factory()->create();
        $otherUserConnection = TiktokOauthConnection::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tiktok-pixels', [
                'pixel_code' => 'CFOO12345',
                'tiktok_oauth_connection_id' => $otherUserConnection->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tiktok_oauth_connection_id']);
    }

    public function test_pixel_store_accepts_own_connection(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/tiktok-pixels', [
                'pixel_code' => 'COKAY12345',
                'tiktok_oauth_connection_id' => $conn->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.oauth_connection.id', $conn->id);
    }

    public function test_service_prefers_oauth_token_over_per_pixel_token(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'access_token' => 'OAUTH_TOKEN_BC',
        ]);
        $pixel = TiktokPixel::factory()
            ->for($user)
            ->for($conn, 'oauthConnection')
            ->create([
                'access_token' => 'PIXEL_TOKEN_NARROW',
                'pixel_code' => 'CWITHOAUTH',
            ]);

        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'], 200),
        ]);

        app(TiktokEventsService::class)->sendPurchaseEvent(collect([$pixel]), $this->orderPayload());

        Http::assertSent(function ($request) {
            return $request->header('Access-Token')[0] === 'OAUTH_TOKEN_BC';
        });

        $this->assertSame(1, TiktokEventLog::count());
    }

    public function test_service_skips_pixel_when_oauth_connection_belongs_to_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Force a corrupt state: a pixel belonging to user, but referencing another user's connection.
        $foreignConn = TiktokOauthConnection::factory()->for($other)->create();
        $pixel = TiktokPixel::factory()->for($user)->create([
            'tiktok_oauth_connection_id' => $foreignConn->id,
        ]);

        Http::fake();

        app(TiktokEventsService::class)->sendPurchaseEvent(collect([$pixel]), $this->orderPayload());

        Http::assertNothingSent();
        $this->assertSame(0, TiktokEventLog::count());
    }

    public function test_callback_redirects_with_error_when_state_invalid(): void
    {
        Config::set('services.tiktok.dashboard_url', 'https://app.test');

        $this->getJson('/api/tiktok/oauth/callback?state=bogus&auth_code=xyz')
            ->assertRedirect();
    }

    public function test_callback_consumes_state_and_creates_connection(): void
    {
        Config::set('services.tiktok.dashboard_url', 'https://app.test');

        $user = User::factory()->create();
        $state = bin2hex(random_bytes(16));
        DB::table('tiktok_oauth_states')->insert([
            'state' => $state,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/oauth2/access_token/' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => [
                    'access_token' => 'NEW_OAUTH_TOKEN',
                    'advertiser_ids' => ['7629530770574426128'],
                    'scope' => ['user.info.basic', 4, 10],
                ],
            ], 200),
            'business-api.tiktok.com/open_api/v1.3/bc/get/' => Http::response([
                'code' => 0,
                'data' => ['list' => [['bc_id' => '7629530797501841409', 'bc_info' => ['name' => 'Risqui Oficial']]]],
            ], 200),
        ]);

        $this->get("/api/tiktok/oauth/callback?state={$state}&auth_code=fake_code")
            ->assertRedirect();

        $this->assertSame(0, DB::table('tiktok_oauth_states')->where('state', $state)->count());
        $conn = TiktokOauthConnection::where('user_id', $user->id)->first();
        $this->assertNotNull($conn);
        $this->assertSame('NEW_OAUTH_TOKEN', $conn->access_token);
        $this->assertSame('Risqui Oficial', $conn->bc_name);
        $this->assertContains('user.info.basic', $conn->scope);
        $this->assertContains('4', $conn->scope);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(): array
    {
        return [
            'id' => 99001,
            'checkout_params' => ['ttclid' => 'TT_OAUTH_TEST'],
            'customer' => ['id' => 1, 'email' => 'a@b.com', 'phone' => '+1'],
            'payment' => ['actual_price_paid' => 10.0, 'actual_price_paid_currency' => 'USD'],
            'line_items' => [['sku' => 'X', 'title' => 'X', 'quantity' => 1, 'actual_price_paid' => 10.0]],
            'browser_ip' => '1.1.1.1',
            'user_agent' => 'test',
            'processed_at' => '2026-04-25 10:00:00',
            'thank_you_page' => 'https://x.test',
        ];
    }
}
