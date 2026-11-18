<?php

namespace Tests\Feature;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.pixelforge.webhook_secret',
            self::SECRET
        );

        Bus::fake();
    }

    private function payload(
        string $eventId = 'evt_test123',
        string $providerId = 'gen_test123'
    ): array {
        return [
            'id' => $eventId,
            'type' => 'generation.succeeded',
            'data' => [
                'id' => $providerId,
                'status' => 'succeeded',
            ],
        ];
    }

    private function sendWebhook(
        array $payload,
        ?string $secret = self::SECRET,
        ?int $timestamp = null
    ) {
        $timestamp ??= time();

        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        );

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($secret !== null) {
            $signature = hash_hmac(
                'sha256',
                $timestamp.'.'.$body,
                $secret
            );

            $headers['Pixelforge-Signature'] =
                "t={$timestamp},v1={$signature}";
        }

        return $this->call(
            'POST',
            '/api/webhooks/pixelforge',
            [],
            [],
            [],
            array_merge(
                ['CONTENT_TYPE' => 'application/json'],
                $this->serverHeaders($headers)
            ),
            $body
        );
    }

    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(
                str_replace('-', '_', $name)
            );

            $server[$key] = $value;
        }

        return $server;
    }

    public function test_valid_webhook_is_stored_and_queued(): void
    {
        $this->sendWebhook($this->payload())
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseHas(
            'provider_webhook_events',
            [
                'provider_event_id' => 'evt_test123',
                'provider_job_id' => 'gen_test123',
                'status' => ProviderWebhookEvent::STATUS_RECEIVED,
            ]
        );

        Bus::assertDispatched(
            ProcessPixelforgeWebhook::class,
            function ($job) {
                return $job->queue === 'webhooks';
            }
        );
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->sendWebhook(
            $this->payload(),
            null
        )->assertForbidden();

        $this->assertDatabaseCount(
            'provider_webhook_events',
            0
        );

        Bus::assertNotDispatched(
            ProcessPixelforgeWebhook::class
        );
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->sendWebhook(
            $this->payload(),
            'incorrect-secret'
        )->assertForbidden();

        $this->assertDatabaseCount(
            'provider_webhook_events',
            0
        );
    }

    public function test_expired_signature_is_rejected(): void
    {
        $this->sendWebhook(
            $this->payload(),
            self::SECRET,
            time() - 600
        )->assertForbidden();

        $this->assertDatabaseCount(
            'provider_webhook_events',
            0
        );
    }

    public function test_future_signature_is_rejected(): void
    {
        $this->sendWebhook(
            $this->payload(),
            self::SECRET,
            time() + 600
        )->assertForbidden();
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $this->sendWebhook([
            'id' => 'evt_invalid',
            'type' => 'generation.succeeded',
        ])->assertUnprocessable();

        $this->assertDatabaseCount(
            'provider_webhook_events',
            0
        );
    }

    public function test_duplicate_event_is_stored_once(): void
    {
        $payload = $this->payload();

        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();

        $this->assertDatabaseCount(
            'provider_webhook_events',
            1
        );
    }

    public function test_processed_event_is_not_queued_again(): void
    {
        $payload = $this->payload();

        $this->sendWebhook($payload)->assertOk();

        Bus::assertDispatchedTimes(
            ProcessPixelforgeWebhook::class,
            1
        );

        $updated = ProviderWebhookEvent::query()
            ->where('provider_event_id', 'evt_test123')
            ->update([
                'status' => ProviderWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

        $this->assertSame(1, $updated);

        $this->assertDatabaseHas('provider_webhook_events', [
            'provider_event_id' => 'evt_test123',
            'status' => ProviderWebhookEvent::STATUS_PROCESSED,
        ]);

        $this->sendWebhook($payload)->assertOk();

        Bus::assertDispatchedTimes(
            ProcessPixelforgeWebhook::class,
            1
        );
    }

    public function test_duplicate_event_dispatches_only_once(): void
    {
        $payload = $this->payload();

        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();

        $this->assertDatabaseCount(
            'provider_webhook_events',
            1
        );

        Bus::assertDispatchedTimes(
            ProcessPixelforgeWebhook::class,
            1
        );
    }

    public function test_conflicting_duplicate_event_is_rejected(): void
    {
        $payload = $this->payload();

        $this->sendWebhook($payload)->assertOk();

        $conflicting = $payload;
        $conflicting['data']['id'] = 'gen_different123';

        $this->sendWebhook($conflicting)
            ->assertStatus(409);

        $this->assertDatabaseCount(
            'provider_webhook_events',
            1
        );

        Bus::assertDispatchedTimes(
            ProcessPixelforgeWebhook::class,
            1
        );
    }

}
