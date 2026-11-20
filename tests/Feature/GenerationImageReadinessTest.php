<?php

namespace Tests\Feature;

use App\Jobs\PersistGenerationImages;
use App\Models\Generation;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GenerationImageReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function completedGeneration(): Generation
    {
        $user = User::factory()->create();
        app(WalletService::class)->addDemoCredits(
            $user,
            1_000_000_000,
            (string) Str::uuid()
        );
        $generation = Generation::create([
            'user_id' => $user->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => 'Prepare the image',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ]);
        $generation->status = Generation::STATUS_COMPLETED;
        $generation->estimated_charge_units = 100_000_000;
        $generation->customer_charge_units = 80_000_000;
        $generation->result_image_urls = [
            'https://pixelforge.promptedit.dev/images/preparing.png',
        ];
        $generation->completed_at = now();
        $generation->save();

        return $generation->refresh();
    }

    public function test_completed_generation_distinguishes_image_readiness(): void
    {
        $generation = $this->completedGeneration();

        $this->actingAs($generation->user)
            ->getJson("/generations/{$generation->id}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('images_ready', false)
            ->assertJsonPath('expected_image_count', 1)
            ->assertJsonPath('result_image_urls', []);

        $generation->result_image_urls = [
            "generations/{$generation->user_id}/{$generation->id}/image-0.png",
        ];
        $generation->save();

        $this->actingAs($generation->user)
            ->getJson("/generations/{$generation->id}")
            ->assertOk()
            ->assertJsonPath('images_ready', true)
            ->assertJsonPath(
                'result_image_urls.0',
                "/generations/{$generation->id}/images/0"
            );
    }

    public function test_retry_only_dispatches_local_persistence(): void
    {
        Queue::fake();
        $generation = $this->completedGeneration();
        $before = $generation->user->wallet->fresh();

        $this->actingAs($generation->user)
            ->postJson("/generations/{$generation->id}/images/retry")
            ->assertOk()
            ->assertJsonPath('images_ready', false);

        Queue::assertPushedOn('images', PersistGenerationImages::class);
        $after = $generation->user->wallet->fresh();
        $this->assertSame($before->available_units, $after->available_units);
        $this->assertSame($before->reserved_units, $after->reserved_units);
    }
}
