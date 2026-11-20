<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationImageStorageService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GenerationImagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE_URL =
        'https://pixelforge.promptedit.dev/images/result.png';

    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'.
            'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
    }

    private function completedGeneration(array $urls = [self::IMAGE_URL]): Generation
    {
        $generation = Generation::create([
            'user_id' => User::factory()->create()->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => 'Persist this image',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ]);

        $generation->status = Generation::STATUS_COMPLETED;
        $generation->result_image_urls = $urls;
        $generation->completed_at = now();
        $generation->save();

        return $generation->refresh();
    }

    public function test_first_persistence_stores_png_and_updates_path(): void
    {
        Storage::fake('local');
        Http::fake([self::IMAGE_URL => Http::response($this->png())]);

        $generation = $this->completedGeneration();

        app(GenerationImageStorageService::class)->persist($generation);

        $path = "generations/{$generation->user_id}/{$generation->id}/image-0.png";

        Storage::disk('local')->assertExists($path);
        $this->assertSame(
            [$path],
            $generation->refresh()->result_image_urls
        );
        Http::assertSentCount(1);
    }

    public function test_repeated_persistence_is_idempotent(): void
    {
        Storage::fake('local');
        Http::fake([self::IMAGE_URL => Http::response($this->png())]);

        $generation = $this->completedGeneration();
        $storage = app(GenerationImageStorageService::class);

        $storage->persist($generation);
        $storage->persist($generation);

        Http::assertSentCount(1);
        $this->assertCount(1, $generation->refresh()->result_image_urls);
    }

    public function test_existing_valid_file_is_preserved_without_download(): void
    {
        Storage::fake('local');
        Http::fake();

        $generation = $this->completedGeneration();
        $path = "generations/{$generation->user_id}/{$generation->id}/image-0.png";
        Storage::disk('local')->put($path, $this->png());

        app(GenerationImageStorageService::class)->persist($generation);

        Http::assertNothingSent();
        $this->assertSame(
            [$path],
            $generation->refresh()->result_image_urls
        );
    }

    public function test_failed_download_remains_recoverable(): void
    {
        Storage::fake('local');
        Http::fake([self::IMAGE_URL => Http::response('', 503)]);

        $generation = $this->completedGeneration();

        try {
            app(GenerationImageStorageService::class)->persist($generation);
            $this->fail('Expected the failed download to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Unable to download generated image.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            [self::IMAGE_URL],
            $generation->refresh()->result_image_urls
        );
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_partial_failure_preserves_completed_progress(): void
    {
        Storage::fake('local');
        $secondUrl =
            'https://pixelforge.promptedit.dev/images/second.png';
        Http::fake([
            self::IMAGE_URL => Http::response($this->png()),
            $secondUrl => Http::response('', 503),
        ]);

        $generation = $this->completedGeneration([
            self::IMAGE_URL,
            $secondUrl,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(GenerationImageStorageService::class)->persist($generation);
        } finally {
            $fresh = $generation->refresh();
            $this->assertStringStartsWith(
                'generations/',
                $fresh->result_image_urls[0]
            );
            $this->assertSame($secondUrl, $fresh->result_image_urls[1]);
        }
    }

    public function test_concurrent_attempt_exits_while_storage_lock_is_held(): void
    {
        Storage::fake('local');
        Http::fake([self::IMAGE_URL => Http::response($this->png())]);

        $generation = $this->completedGeneration();
        $storage = app(GenerationImageStorageService::class);
        $lock = Cache::lock($storage->lockKey($generation->id), 150);
        $this->assertTrue($lock->get());

        try {
            $storage->persist($generation);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(
            [self::IMAGE_URL],
            $generation->refresh()->result_image_urls
        );

        $storage->persist($generation);
        Http::assertSentCount(1);
    }

    public function test_persistence_retries_do_not_change_wallet_balances(): void
    {
        Storage::fake('local');
        Http::fake([self::IMAGE_URL => Http::response($this->png())]);

        $generation = $this->completedGeneration();
        app(WalletService::class)->addDemoCredits(
            $generation->user,
            1_000_000_000,
            (string) Str::uuid()
        );
        $before = $generation->user->wallet->fresh();
        $transactionsBefore = $generation->walletTransactions()->count();

        $storage = app(GenerationImageStorageService::class);
        $storage->persist($generation);
        $storage->persist($generation);

        $after = $generation->user->wallet->fresh();
        $this->assertSame($before->available_units, $after->available_units);
        $this->assertSame($before->reserved_units, $after->reserved_units);
        $this->assertSame(
            $transactionsBefore,
            $generation->walletTransactions()->count()
        );
    }
}
