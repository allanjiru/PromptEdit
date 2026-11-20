<?php

namespace Tests\Feature;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationReferenceImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        Http::fake([
            '*/pricing' => Http::response($this->pricing()),
        ]);
        Queue::fake();

        $this->user = User::factory()->create();
        app(WalletService::class)->addDemoCredits(
            $this->user,
            10_000_000_000,
            'reference-tests:credit'
        );
    }

    public function test_quote_and_submission_accept_the_same_multiple_references(): void
    {
        $references = [
            'https://example.com/one.png',
            'https://example.com/two.png',
            'https://example.com/three.png',
            'https://example.com/four.png',
        ];
        $payload = $this->payload($references);

        $quote = $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $payload)
            ->assertOk()
            ->json();

        $submission = $this->actingAs($this->user)
            ->postJson(route('generations.store'), [
                ...$payload,
                'request_key' => (string) Str::uuid(),
            ])
            ->assertAccepted()
            ->assertJsonPath('estimated_charge_units', $quote['estimated_charge_units'])
            ->json();

        $generation = Generation::findOrFail($submission['id']);

        $this->assertSame($references, $generation->reference_image_urls);
        $this->assertSame(
            $references,
            $generation->provider_request_payload['reference_images']
        );
        Queue::assertPushed(SubmitPixelforgeGeneration::class);
    }

    public function test_quote_and_submission_accept_no_references(): void
    {
        $payload = $this->payload([]);

        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $payload)
            ->assertOk();

        $response = $this->actingAs($this->user)
            ->postJson(route('generations.store'), [
                ...$payload,
                'request_key' => (string) Str::uuid(),
            ])
            ->assertAccepted();

        $this->assertSame(
            [],
            Generation::findOrFail($response->json('id'))->reference_image_urls
        );
    }

    public function test_quote_and_submission_reject_excessive_references(): void
    {
        $payload = $this->payload(array_map(
            fn (int $index) => "https://example.com/{$index}.png",
            range(1, Generation::MAX_REFERENCE_IMAGES + 1)
        ));

        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_image_urls');

        $this->actingAs($this->user)
            ->postJson(route('generations.store'), [
                ...$payload,
                'request_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_image_urls');
    }

    public function test_quote_and_submission_reject_invalid_reference_urls(): void
    {
        $payload = $this->payload(['ftp://example.com/not-supported.png']);

        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_image_urls.0');

        $this->actingAs($this->user)
            ->postJson(route('generations.store'), [
                ...$payload,
                'request_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_image_urls.0');
    }

    private function payload(array $references): array
    {
        return [
            'prompt' => 'A reference-aware image',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => $references,
        ];
    }

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
