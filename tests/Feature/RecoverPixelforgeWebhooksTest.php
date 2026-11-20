<?php

namespace Tests\Feature;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoverPixelforgeWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private function event(
        string $status = ProviderWebhookEvent::STATUS_RECEIVED,
        array $attributes = []
    ): ProviderWebhookEvent {
        static $sequence = 0;
        $sequence++;

        $event = ProviderWebhookEvent::create([
            'provider_event_id' => "evt_recovery_{$sequence}",
            'provider_job_id' => "gen_recovery_{$sequence}",
            'event_type' => 'generation.updated',
            'payload' => [
                'id' => "evt_recovery_{$sequence}",
                'type' => 'generation.updated',
                'data' => [
                    'id' => "gen_recovery_{$sequence}",
                ],
            ],
            'status' => $status,
        ]);

        DB::table('provider_webhook_events')
            ->where('id', $event->id)
            ->update(array_merge([
                'status' => $status,
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ], $attributes));

        return $event->refresh();
    }

    public function test_stale_received_event_is_redispatched(): void
    {
        Bus::fake();

        $event = $this->event();

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertDispatched(
            ProcessPixelforgeWebhook::class,
            fn ($job) => $job->eventId === $event->id
        );

        $event->refresh();

        $this->assertSame(1, $event->recovery_attempts);
        $this->assertNotNull($event->last_attempted_at);
        $this->assertNotNull($event->next_retry_at);
        $this->assertNotNull($event->recovery_claimed_until);
    }

    public function test_failed_event_is_redispatched(): void
    {
        Bus::fake();

        $event = $this->event(
            ProviderWebhookEvent::STATUS_FAILED
        );

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertDispatched(
            ProcessPixelforgeWebhook::class,
            fn ($job) => $job->eventId === $event->id
        );
    }

    public function test_processed_event_is_not_redispatched(): void
    {
        Bus::fake();

        $event = $this->event(
            ProviderWebhookEvent::STATUS_PROCESSED
        );

        $this->assertDatabaseHas('provider_webhook_events', [
            'id' => $event->id,
            'status' => ProviderWebhookEvent::STATUS_PROCESSED,
        ]);

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertNotDispatched(
            ProcessPixelforgeWebhook::class
        );

    }

    public function test_recent_event_is_not_redispatched(): void
    {
        Bus::fake();

        $event = $this->event();

        DB::table('provider_webhook_events')
            ->where('id', $event->id)
            ->update(['created_at' => now()]);

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertNotDispatched(
            ProcessPixelforgeWebhook::class
        );
    }

    public function test_active_claim_prevents_redispatch(): void
    {
        Bus::fake();

        $this->event(
            ProviderWebhookEvent::STATUS_RECEIVED,
            ['recovery_claimed_until' => now()->addMinutes(5)]
        );

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertNotDispatched(
            ProcessPixelforgeWebhook::class
        );
    }

    public function test_future_retry_time_prevents_redispatch(): void
    {
        Bus::fake();

        $this->event(
            ProviderWebhookEvent::STATUS_FAILED,
            ['next_retry_at' => now()->addMinutes(10)]
        );

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertNotDispatched(
            ProcessPixelforgeWebhook::class
        );
    }

    public function test_expired_claim_is_recoverable(): void
    {
        Bus::fake();

        $event = $this->event(
            ProviderWebhookEvent::STATUS_FAILED,
            [
                'recovery_claimed_until' => now()->subMinute(),
                'next_retry_at' => now()->subMinute(),
            ]
        );

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertDispatched(
            ProcessPixelforgeWebhook::class,
            fn ($job) => $job->eventId === $event->id
        );
    }

    public function test_repeated_command_does_not_immediately_redispatch(): void
    {
        Bus::fake();

        $this->event();

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        $this->artisan('pixelforge:recover-webhooks')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(
            ProcessPixelforgeWebhook::class,
            1
        );
    }

    public function test_limit_is_respected(): void
    {
        Bus::fake();

        $this->event();
        $this->event();
        $this->event();

        $this->artisan(
            'pixelforge:recover-webhooks',
            ['--limit' => 2]
        )->assertSuccessful();

        Bus::assertDispatchedTimes(
            ProcessPixelforgeWebhook::class,
            2
        );
    }
}
