<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationHistorySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function generation(User $user, string $prompt): Generation
    {
        return Generation::create([
            'user_id' => $user->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => $prompt,
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ]);
    }

    public function test_unauthenticated_history_request_is_rejected(): void
    {
        $this->get(route('generations.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_only_their_history(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->generation($user, 'Visible generation');
        $this->generation($other, 'Private generation');

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/History')
                ->has('generations.data', 1)
                ->where(
                    'generations.data.0.prompt',
                    'Visible generation'
                )
            );
    }

    public function test_history_image_urls_are_relative_and_hide_storage_paths(): void
    {
        $user = User::factory()->create();
        $generation = $this->generation($user, 'Private image');
        $storagePath = "generations/{$user->id}/{$generation->id}/image-0.png";

        $generation->result_image_urls = [
            $storagePath,
            'https://pixelforge.promptedit.dev/images/temporary.png',
        ];
        $generation->save();

        URL::forceRootUrl('http://localhost:8000');

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'generations.data.0.images',
                    ['/generations/'.$generation->id.'/images/0']
                )
            );
    }

    public function test_user_cannot_access_another_users_private_image(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $generation = $this->generation($owner, 'Private image');
        $path = "generations/{$owner->id}/{$generation->id}/image-0.png";

        Storage::disk('local')->put($path, 'private image');
        $generation->result_image_urls = [$path];
        $generation->save();

        $this->actingAs($other)
            ->get(route('generations.images.show', [
                'generation' => $generation,
                'index' => 0,
            ]))
            ->assertForbidden();
    }
}
