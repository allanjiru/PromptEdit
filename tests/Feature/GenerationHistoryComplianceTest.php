<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationHistoryComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_returns_stored_estimate_and_settled_customer_charge(): void
    {
        $user = User::factory()->create();

        $completed = $this->generation($user, 'Completed', [
            'status' => Generation::STATUS_COMPLETED,
            'estimated_charge_units' => 86_268_751,
            'customer_charge_units' => 59_450_003,
            'result_image_urls' => [
                "generations/{$user->id}/1/image-0.png",
            ],
        ]);

        $pending = $this->generation($user, 'Pending', [
            'estimated_charge_units' => 12_345_678,
            'customer_charge_units' => null,
        ]);

        $failed = $this->generation($user, 'Failed', [
            'status' => Generation::STATUS_FAILED,
            'estimated_charge_units' => 22_000_001,
            'customer_charge_units' => null,
        ]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'generations.data.2.estimated_charge_usd',
                    '0.086268751'
                )
                ->where(
                    'generations.data.2.charge_usd',
                    '0.059450003'
                )
                ->where('generations.data.2.id', $completed->id)
                ->where(
                    'generations.data.1.estimated_charge_usd',
                    '0.012345678'
                )
                ->where('generations.data.1.charge_usd', null)
                ->where('generations.data.1.id', $pending->id)
                ->where('generations.data.0.status', 'failed')
                ->where('generations.data.0.charge_usd', null)
                ->where('generations.data.0.id', $failed->id)
            );
    }

    public function test_history_is_private_newest_first_and_paginated(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $ids = collect(range(1, 13))
            ->map(fn (int $number) => $this->generation(
                $user,
                "Generation {$number}",
                ['estimated_charge_units' => $number]
            )->id)
            ->all();

        $this->generation($other, 'Private', [
            'estimated_charge_units' => 999,
        ]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.total', 13)
                ->where('generations.per_page', 12)
                ->where('generations.current_page', 1)
                ->has('generations.data', 12)
                ->where('generations.data.0.id', end($ids))
                ->where('generations.data.11.id', $ids[1])
            );

        $this->actingAs($user)
            ->get(route('generations.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $ids[0])
            );
    }

    private function generation(
        User $user,
        string $prompt,
        array $attributes = []
    ): Generation {
        $generation = Generation::create([
            'user_id' => $user->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => $prompt,
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ]);

        $generation->forceFill($attributes)->save();

        return $generation->refresh();
    }
}
