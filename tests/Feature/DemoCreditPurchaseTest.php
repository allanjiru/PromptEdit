<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GenerationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoCreditPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo_credits.enabled' => true,
            'demo_credits.max_total_units' => 100_000_000_000,
            'demo_credits.rate_limit_per_minute' => 10,
        ]);

        $this->user = User::factory()->create();
        RateLimiter::clear(
            'demo-credits:'.$this->user->getAuthIdentifier().'|127.0.0.1'
        );
    }

    public function test_unauthenticated_users_cannot_purchase_demo_credits(): void
    {
        $this->postJson(route('wallet.demo-credits.store'), $this->purchase())
            ->assertUnauthorized();

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_authenticated_user_can_purchase_a_valid_package(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('starter'))
            ->assertOk()
            ->assertJsonPath('package', 'starter')
            ->assertJsonPath('credited_units', 5_000_000_000)
            ->assertJsonPath('wallet.available_units', 5_000_000_000)
            ->assertJsonPath('wallet.reserved_units', 0);
    }

    public function test_invalid_package_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('custom-999'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('package');

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_browser_supplied_financial_and_ownership_fields_are_rejected(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), [
                ...$this->purchase('starter'),
                'amount' => -100,
                'amount_units' => 900_000_000_000,
                'wallet_id' => 999,
                'user_id' => $other->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount',
                'amount_units',
                'wallet_id',
                'user_id',
            ]);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_purchase_credits_only_the_authenticated_wallet_exactly(): void
    {
        $other = User::factory()->create();
        app(WalletService::class)->ensureWallet($other);

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('standard'))
            ->assertOk();

        $this->assertSame(
            10_000_000_000,
            $this->user->wallet()->firstOrFail()->available_units
        );
        $this->assertSame(
            0,
            $other->wallet()->firstOrFail()->available_units
        );
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $this->user->wallet()->firstOrFail()->id,
            'available_delta_units' => 10_000_000_000,
            'reserved_delta_units' => 0,
        ]);
    }

    public function test_duplicate_request_key_returns_same_transaction_without_double_credit(): void
    {
        $payload = $this->purchase('plus');

        $first = $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $payload)
            ->assertOk();
        $second = $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $payload)
            ->assertOk();

        $this->assertSame(
            $first->json('transaction_id'),
            $second->json('transaction_id')
        );
        $this->assertSame(
            20_000_000_000,
            $this->user->wallet()->firstOrFail()->available_units
        );
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_reusing_a_request_key_with_another_package_is_rejected(): void
    {
        $requestKey = (string) Str::uuid();

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), [
                'package' => 'starter',
                'request_key' => $requestKey,
            ])->assertOk();

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), [
                'package' => 'plus',
                'request_key' => $requestKey,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_key');

        $this->assertSame(
            5_000_000_000,
            $this->user->wallet()->firstOrFail()->available_units
        );
    }

    public function test_repeated_submissions_cannot_double_credit_and_distinct_purchases_do(): void
    {
        $duplicate = $this->purchase('starter');

        foreach ([$duplicate, $duplicate, $this->purchase('standard')] as $payload) {
            $this->actingAs($this->user)
                ->postJson(route('wallet.demo-credits.store'), $payload)
                ->assertOk();
        }

        $this->assertSame(
            15_000_000_000,
            $this->user->wallet()->firstOrFail()->available_units
        );
        $this->assertSame(
            2,
            WalletTransaction::query()
                ->where('type', WalletTransaction::TYPE_DEMO_CREDIT)
                ->count()
        );
    }

    public function test_purchase_endpoint_is_rate_limited(): void
    {
        config(['demo_credits.rate_limit_per_minute' => 2]);

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase())
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase())
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase())
            ->assertTooManyRequests();

        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    public function test_cumulative_cap_is_enforced_without_partial_mutation(): void
    {
        config(['demo_credits.max_total_units' => 10_000_000_000]);

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('standard'))
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('starter'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('package');

        $wallet = $this->user->wallet()->firstOrFail();
        $this->assertSame(10_000_000_000, $wallet->available_units);
        $this->assertSame(0, $wallet->reserved_units);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_disabled_demo_purchases_cannot_grant_credits(): void
    {
        config(['demo_credits.enabled' => false]);

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('demo_credits');

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_studio_exposes_zero_wallet_and_server_defined_packages(): void
    {
        $this->actingAs($this->user)
            ->get(route('studio.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('studio/Index')
                ->where('wallet.available_units', 0)
                ->where('wallet.reserved_units', 0)
                ->where('demoCredits.enabled', true)
                ->has('demoCredits.packages', 3)
                ->where('demoCredits.packages.0.id', 'starter')
                ->where('demoCredits.packages.2.amount_units', 20_000_000_000));
    }

    public function test_funding_allows_a_previously_unaffordable_generation(): void
    {
        Queue::fake();
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $payload = [
            'request_key' => (string) Str::uuid(),
            'prompt' => 'A reviewer-ready image',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ];
        $quote = $this->actingAs($this->user)
            ->postJson(route('generations.quote'), collect($payload)
                ->except('request_key')
                ->all())
            ->assertOk()
            ->json();

        $this->actingAs($this->user)
            ->postJson(route('generations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wallet');

        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('starter'))
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson(route('generations.store'), $payload)
            ->assertAccepted()
            ->assertJsonPath(
                'wallet.reserved_units',
                $quote['estimated_charge_units']
            );
    }

    public function test_generation_settlement_formula_remains_unchanged(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('wallet.demo-credits.store'), $this->purchase('starter'))
            ->assertOk();

        $generation = app(GenerationService::class)->create(
            $this->user,
            (string) Str::uuid(),
            'Settlement integrity',
            '1024x1024',
            'medium',
            [],
            30_000_000
        );
        app(GenerationService::class)->succeed(
            $generation,
            19_500_000,
            ['https://example.com/result.png']
        );

        $wallet = $this->user->wallet()->firstOrFail()->fresh();
        $this->assertSame(4_975_625_000, $wallet->available_units);
        $this->assertSame(0, $wallet->reserved_units);
        $this->assertDatabaseHas('wallet_transactions', [
            'generation_id' => $generation->id,
            'type' => WalletTransaction::TYPE_GENERATION_CHARGE,
            'reserved_delta_units' => -24_375_000,
        ]);
    }

    /** @return array{package: string, request_key: string} */
    private function purchase(string $package = 'starter'): array
    {
        return [
            'package' => $package,
            'request_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function pricing(): array
    {
        return [
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'medium' => ['1024x1024' => 1100],
                ],
                'reasoning' => [
                    'medium' => ['max' => 2000],
                ],
            ],
        ];
    }
}
