<?php

namespace Tests\Unit\Services;

use App\Services\AffiliateRouter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AffiliateRouterTokenTest extends TestCase
{
    private AffiliateRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new AffiliateRouter;
    }

    public function test_mint_token_returns_distinct_strings(): void
    {
        $a = $this->router->mintToken(42);
        $b = $this->router->mintToken(42);

        $this->assertNotSame($a, $b);
    }

    public function test_decode_token_round_trips(): void
    {
        $token = $this->router->mintToken(99);

        $decoded = $this->router->decodeToken($token);

        $this->assertNotNull($decoded);
        $this->assertSame(99, $decoded['uid']);
    }

    public function test_decode_token_rejects_garbage(): void
    {
        $this->assertNull($this->router->decodeToken('clearly-not-a-token'));
    }

    public function test_decode_token_rejects_expired(): void
    {
        Carbon::setTestNow(now());
        $token = $this->router->mintToken(7);

        Carbon::setTestNow(now()->addSeconds(601));

        $this->assertNull($this->router->decodeToken($token));

        Carbon::setTestNow();
    }
}
