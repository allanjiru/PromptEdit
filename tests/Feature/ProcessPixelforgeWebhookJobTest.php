<?php

namespace Tests\Feature;

use App\Jobs\PersistGenerationImages;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\ProviderWebhookEvent;
use App\Models\User;
use App\Services\GenerationImagePersistenceDispatcher;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeResultProcessor;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    private function generation(string $status, array $images = []): Generation
    {
        $generation = Generation::create([
            'user_id' => User::factory()->create()->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => 'Webhook image',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ]);

        $generation->provider_job_id = 'gen_job_test123';
        $generation->status = $status;
        $generation->result_image_urls = $images;
        $generation->save();

        return $generation->refresh();
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
            ->handle(
                $client,
                $processor,
                app(GenerationImagePersistenceDispatcher::class)
            );

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

        $job->handle(
            $client,
            $processor,
            app(GenerationImagePersistenceDispatcher::class)
        );

        $this->assertDatabaseHas(
            'provider_webhook_events',
            [
                'id' => $event->id,
                'status' => ProviderWebhookEvent::STATUS_RECEIVED,
            ]
        );
    }

    public function test_successful_webhook_dispatches_image_persistence_once(): void
    {
        Queue::fake();

        $event = $this->createEvent();
        $generation = $this->generation(
            Generation::STATUS_COMPLETED,
            ['https://pixelforge.promptedit.dev/images/webhook.png']
        );

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->andReturn(['status' => 'succeeded']);

        $processor = Mockery::mock(PixelforgeResultProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturn($generation);

        $job = new ProcessPixelforgeWebhook($event->id);
        $dispatcher = app(GenerationImagePersistenceDispatcher::class);

        $job->handle($client, $processor, $dispatcher);
        $job->handle($client, $processor, $dispatcher);

        Queue::assertPushedOn('images', PersistGenerationImages::class);
        Queue::assertPushed(PersistGenerationImages::class, 1);
    }

    public function test_failed_webhook_does_not_dispatch_image_persistence(): void
    {
        Queue::fake();

        $event = $this->createEvent();
        $generation = $this->generation(Generation::STATUS_FAILED);

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->andReturn(['status' => 'failed']);

        $processor = Mockery::mock(PixelforgeResultProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturn($generation);

        (new ProcessPixelforgeWebhook($event->id))->handle(
            $client,
            $processor,
            app(GenerationImagePersistenceDispatcher::class)
        );

        Queue::assertNotPushed(PersistGenerationImages::class);
    }

    public function test_completed_webhook_without_downloadable_images_is_skipped(): void
    {
        Queue::fake();

        $event = $this->createEvent();
        $generation = $this->generation(
            Generation::STATUS_COMPLETED,
            ['generations/1/1/image-0.png']
        );

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->andReturn(['status' => 'succeeded']);

        $processor = Mockery::mock(PixelforgeResultProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturn($generation);

        (new ProcessPixelforgeWebhook($event->id))->handle(
            $client,
            $processor,
            app(GenerationImagePersistenceDispatcher::class)
        );

        Queue::assertNotPushed(PersistGenerationImages::class);
    }

    public function test_duplicate_successful_webhook_settles_wallet_once(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->addDemoCredits(
            $user,
            10_000_000_000,
            (string) Str::uuid()
        );

        $generation = app(GenerationService::class)->create(
            $user,
            (string) Str::uuid(),
            'Webhook settlement',
            '1024x1024',
            'medium',
            [],
            100_000_000
        );
        app(GenerationService::class)->attachProviderJob(
            $generation,
            'gen_job_test123'
        );

        $event = $this->createEvent();
        $payload = [
            'id' => 'gen_job_test123',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 1000,
                'input_image_tokens' => 0,
                'output_tokens' => 2000,
                'reasoning_tokens' => 0,
                'total_tokens' => 3000,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/settled.png',
        ];

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->andReturn($payload);

        $job = new ProcessPixelforgeWebhook($event->id);
        $dispatcher = app(GenerationImagePersistenceDispatcher::class);

        $job->handle(
            $client,
            app(PixelforgeResultProcessor::class),
            $dispatcher
        );

        $walletAfterFirst = $user->wallet->fresh();

        $job->handle(
            $client,
            app(PixelforgeResultProcessor::class),
            $dispatcher
        );

        $walletAfterDuplicate = $user->wallet->fresh();

        $this->assertSame(
            $walletAfterFirst->available_units,
            $walletAfterDuplicate->available_units
        );
        $this->assertSame(0, $walletAfterDuplicate->reserved_units);
        $this->assertSame(
            106_250_000,
            $generation->refresh()->customer_charge_units
        );
        Queue::assertPushed(PersistGenerationImages::class, 1);
    }
}
