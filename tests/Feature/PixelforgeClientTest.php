<?php

namespace Tests\Feature;

use App\Services\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.pixelforge.base_url',
            'https://pixelforge.promptedit.dev/v1'
        );

        config()->set(
            'services.pixelforge.api_key',
            'test-api-key'
        );

        Http::preventStrayRequests();
    }

    public function test_it_submits_with_correct_payload_and_idempotency(): void
    {
        Http::fake([
            'pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_TEST123',
                'status' => 'queued',
            ], 202),
        ]);

        $client = app(PixelforgeClient::class);

        $result = $client->createGeneration(
            'request-123',
            'A mountain landscape',
            '1024x1024',
            'high',
            ['https://example.com/reference.png'],
            ['order_id' => '123']
        );

        $this->assertSame('gen_TEST123', $result['id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer test-api-key'
                )
                && $request->hasHeader(
                    'Idempotency-Key',
                    'request-123'
                )
                && $request['prompt'] ===
                    'A mountain landscape'
                && $request['size'] === '1024x1024'
                && $request['quality'] === 'high'
                && $request['reference_images'] === [
                    'https://example.com/reference.png',
                ]
                && $request['metadata'] === [
                    'order_id' => '123',
                ];
        });
    }

    public function test_it_retrieves_provider_generation(): void
    {
        Http::fake([
            'pixelforge.promptedit.dev/v1/generations/gen_TEST123' => Http::response([
                'id' => 'gen_TEST123',
                'status' => 'processing',
            ]),
        ]);

        $result = app(PixelforgeClient::class)
            ->retrieveGeneration('gen_TEST123');

        $this->assertSame(
            'processing',
            $result['status']
        );
    }

    public function test_it_fetches_pricing(): void
    {
        Http::fake([
            'pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'currency' => 'usd',
                'unit' => 'per_1m_tokens',
                'rates' => [
                    'output_tokens' => '40.00',
                ],
            ]),
        ]);

        $result = app(PixelforgeClient::class)->pricing();

        $this->assertSame(
            '40.00',
            $result['rates']['output_tokens']
        );
    }

    public function test_it_fetches_usage_for_date_range(): void
    {
        Http::fake([
            'pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'data' => [],
            ]),
        ]);

        app(PixelforgeClient::class)->usage(
            '2026-11-01',
            '2026-11-17'
        );

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->data()['start_date'] ===
                '2026-11-01'
            && $request->data()['end_date'] ===
                '2026-11-17'
        );
    }

    public function test_it_rejects_invalid_provider_ids(): void
    {
        $this->expectException(\LogicException::class);

        app(PixelforgeClient::class)
            ->retrieveGeneration('../admin');
    }

    public function test_it_propagates_provider_errors(): void
    {
        Http::fake([
            'pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'code' => 'overloaded',
                    'message' => 'Try again later.',
                ],
            ], 503),
        ]);

        $this->expectException(
            RequestException::class
        );

        app(PixelforgeClient::class)->createGeneration(
            'request-123',
            '[overloaded] test',
            '1024x1024',
            'medium'
        );
    }

    public function test_submission_replay_uses_identical_payload(): void
    {
        config([
            'services.pixelforge.api_key' => 'test-key',
            'services.pixelforge.base_url' => 'https://pixelforge.example/v1',
        ]);

        $payload = [
            'prompt' => 'A mountain landscape',
            'size' => '1024x1024',
            'quality' => 'high',
            'reference_images' => [],
            'metadata' => [
                'promptedit_generation_id' => '42',
            ],
        ];

        Http::fake([
            'pixelforge.example/*' => Http::response([
                'id' => 'gen_replay123',
                'object' => 'generation',
                'status' => 'queued',
            ], 202),
        ]);

        $client = app(PixelforgeClient::class);

        $first = $client->createGenerationFromPayload(
            'stable-request-key',
            $payload
        );

        $second = $client->createGenerationFromPayload(
            'stable-request-key',
            $payload
        );

        $this->assertSame($first, $second);

        Http::assertSentCount(2);

        foreach (Http::recorded() as [$request, $response]) {
            $this->assertSame(
                'stable-request-key',
                $request->header('Idempotency-Key')[0]
            );

            $this->assertSame(
                $payload,
                $request->data()
            );
        }
    }
}
