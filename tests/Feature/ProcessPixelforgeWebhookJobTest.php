<?php

namespace Tests\Feature;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\ProviderWebhookEvent;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProcessPixelforgeWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(
        string $status = ProviderWebhookEvent::STATUS_RECEIVED
    ): ProviderWebhookEvent {
        $event = ProviderWebhookEvent::create([
            'provider_event_id' => 'evt_job_test123',
            'provider_job_id' => 'gen_job_test123',
            'event_type' => 'generation.updated',
            'payload' => [
                'id' => 'evt_job_test123',
                'type' => 'generation.updated',
                'data' => [
                    'id' => 'gen_job_test123',
                ],
            ],
        ]);

        DB::table('provider_webhook_events')
            ->where('id', $event->id)
            ->update(['status' => $status]);

        return $event->refresh();
    }

    public function test_processed_event_is_skipped(): void
    {
        $event = $this->createEvent(
            ProviderWebhookEvent::STATUS_PROCESSED
        );

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldNotReceive('retrieveGeneration');

        $processor = Mockery::mock(
            PixelforgeResultProcessor::class
        );
        $processor->shouldNotReceive('process');

        (new ProcessPixelforgeWebhook($event->id))
            ->handle($client, $processor);

        $this->assertDatabaseHas(
            'provider_webhook_events',
            [
                'id' => $event->id,
                'status' => ProviderWebhookEvent::STATUS_PROCESSED,
            ]
        );
    }

    public function test_missing_generation_does_not_call_provider(): void
    {
        $event = $this->createEvent();

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldNotReceive('retrieveGeneration');

        $processor = Mockery::mock(
            PixelforgeResultProcessor::class
        );
        $processor->shouldNotReceive('process');

        $job = Mockery::mock(
            ProcessPixelforgeWebhook::class,
            [$event->id]
        )->makePartial();

        $job->shouldReceive('release')
            ->once()
            ->with(30);

        $job->handle($client, $processor);

        $this->assertDatabaseHas(
            'provider_webhook_events',
            [
                'id' => $event->id,
                'status' => ProviderWebhookEvent::STATUS_RECEIVED,
            ]
        );
    }
}
