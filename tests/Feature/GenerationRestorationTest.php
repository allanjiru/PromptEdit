<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GenerationRestorationTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_generation_belongs_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $older = $this->createGeneration($user, [
            'prompt' => 'Older generation',
        ]);
        $this->createGeneration($otherUser, [
            'prompt' => 'Another user generation',
        ]);
        $latest = $this->createGeneration($user, [
            'prompt' => 'Latest generation',
        ]);

        $this->actingAs($user)
            ->getJson(route('generations.latest'))
            ->assertOk()
            ->assertJsonPath('generation.id', $latest->id)
            ->assertJsonPath('generation.prompt', 'Latest generation')
            ->assertJsonMissing([
                'id' => $older->id,
            ]);
    }

    public function test_latest_endpoint_returns_complete_restoration_state(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'prompt' => 'Restore this prompt',
            'image_size' => '1536x1024',
            'quality' => 'high',
            'reference_image_urls' => [
                'https://example.test/reference.png',
            ],
            'status' => Generation::STATUS_FAILED,
            'submission_status' => Generation::SUBMISSION_ACCEPTED,
            'error_message' => 'Provider rejected the request.',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('generations.latest'))
            ->assertOk()
            ->assertJsonPath('generation.id', $generation->id)
            ->assertJsonPath(
                'generation.request_key',
                $generation->request_key
            )
            ->assertJsonPath('generation.prompt', 'Restore this prompt')
            ->assertJsonPath('generation.image_size', '1536x1024')
            ->assertJsonPath('generation.quality', 'high')
            ->assertJsonPath(
                'generation.reference_image_urls.0',
                'https://example.test/reference.png'
            )
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath(
                'generation.submission_status',
                'accepted'
            )
            ->assertJsonPath(
                'generation.estimated_charge_units',
                86_268_750
            )
            ->assertJsonPath(
                'generation.estimated_charge_usd',
                '0.086268750'
            )
            ->assertJsonPath('generation.customer_charge_usd', null)
            ->assertJsonPath('generation.result_image_urls', [])
            ->assertJsonPath(
                'generation.error_message',
                'Provider rejected the request.'
            )
            ->assertJsonPath('generation.completed_at', null);
    }

    public function test_completed_generation_returns_only_authenticated_local_image_urls(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_COMPLETED,
            'customer_charge_units' => 59_450_000,
            'result_image_urls' => [
                'generations/'.$user->id.'/pending/image-0.png',
                'https://pixelforge.promptedit.dev/images/temporary.png',
            ],
            'completed_at' => now(),
        ]);

        $generation->result_image_urls = [
            'generations/'.$user->id.'/'.$generation->id.'/image-0.png',
            'https://pixelforge.promptedit.dev/images/temporary.png',
        ];
        $generation->save();

        $expectedUrl = route('generations.images.show', [
            'generation' => $generation->id,
            'index' => 0,
        ], absolute: false);

        $this->actingAs($user)
            ->getJson(route('generations.latest'))
            ->assertOk()
            ->assertJsonPath(
                'generation.customer_charge_usd',
                '0.059450000'
            )
            ->assertJsonPath('generation.result_image_urls', [
                $expectedUrl,
            ]);
    }

    public function test_image_urls_are_relative_and_independent_of_app_url(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_COMPLETED,
            'result_image_urls' => [],
            'completed_at' => now(),
        ]);
        $storagePath = 'generations/'.$user->id.'/'.$generation->id.'/image-0.png';

        $generation->result_image_urls = [
            $storagePath,
            'https://pixelforge.promptedit.dev/images/temporary.png',
        ];
        $generation->save();

        URL::forceRootUrl('http://localhost:8000');

        $urls = $this->actingAs($user)
            ->getJson('/generations/latest')
            ->assertOk()
            ->json('generation.result_image_urls');

        $this->assertSame(
            ['/generations/'.$generation->id.'/images/0'],
            $urls
        );
        $this->assertStringNotContainsString('localhost:8000', $urls[0]);
        $this->assertStringNotContainsString($storagePath, $urls[0]);
        $this->assertStringNotContainsString('image-0.png', $urls[0]);
        $this->assertStringNotContainsString('storage', $urls[0]);
    }

    public function test_authenticated_image_url_serves_private_image_without_exposing_storage_path(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_COMPLETED,
            'result_image_urls' => [],
            'completed_at' => now(),
        ]);
        $storagePath = 'generations/'.$user->id.'/'.$generation->id.'/image-0.png';

        Storage::disk('local')->put($storagePath, 'private image');

        $generation->result_image_urls = [$storagePath];
        $generation->save();

        URL::forceRootUrl('http://localhost:8000');

        $url = $this->actingAs($user)
            ->getJson('/generations/latest')
            ->assertOk()
            ->json('generation.result_image_urls.0');

        $this->assertSame('/generations/'.$generation->id.'/images/0', $url);

        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_another_users_generation_cannot_be_accessed(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $generation = $this->createGeneration($owner);

        $this->actingAs($otherUser)
            ->getJson(route('generations.show', $generation))
            ->assertNotFound();
    }

    public function test_empty_generation_history_returns_null_latest_generation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('generations.latest'))
            ->assertOk()
            ->assertExactJson([
                'generation' => null,
            ]);
    }

    public function test_latest_and_status_endpoints_share_status_fields(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user, [
            'status' => Generation::STATUS_COMPLETED,
            'submission_status' => Generation::SUBMISSION_ACCEPTED,
            'customer_charge_units' => 59_450_000,
            'result_image_urls' => [],
            'completed_at' => now(),
        ]);

        $latest = $this->actingAs($user)
            ->getJson(route('generations.latest'))
            ->assertOk()
            ->json('generation');

        $status = $this->actingAs($user)
            ->getJson(route('generations.show', $generation))
            ->assertOk()
            ->json();

        foreach ([
            'id',
            'status',
            'submission_status',
            'estimated_charge_units',
            'estimated_charge_usd',
            'customer_charge_usd',
            'result_image_urls',
            'error_message',
            'completed_at',
        ] as $field) {
            $this->assertSame($status[$field], $latest[$field]);
        }
    }

    private function createGeneration(
        User $user,
        array $attributes = []
    ): Generation {
        $generation = new Generation;

        $generation->forceFill(array_merge([
            'user_id' => $user->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => 'A generated landscape',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
            'result_image_urls' => null,
            'status' => Generation::STATUS_QUEUED,
            'submission_status' => Generation::SUBMISSION_PENDING,
            'estimated_charge_units' => 86_268_750,
            'customer_charge_units' => null,
            'error_message' => null,
            'completed_at' => null,
            'failed_at' => null,
        ], $attributes));

        $generation->save();

        return $generation;
    }
}
