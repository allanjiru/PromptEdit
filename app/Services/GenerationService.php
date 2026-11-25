<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class GenerationService
{
    public function __construct(
        private readonly WalletService $wallets
    ) {}

    /**
     * Create a queued generation and reserve credits atomically.
     *
     * The estimate must come from trusted backend pricing,
     * not a value submitted by the browser.
     *
     * @param  list<string>  $referenceUrls
     */
    public function create(
        User $user,
        string $requestKey,
        string $prompt,
        string $size,
        string $quality,
        array $referenceUrls,
        int $estimatedChargeUnits
    ): Generation {
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages([
                'request_key' => 'A valid request UUID is required.',
            ]);
        }

        if ($estimatedChargeUnits <= 0) {
            throw new LogicException(
                'Estimated reservation must be positive.'
            );
        }

        if (trim($prompt) === '') {
            throw ValidationException::withMessages([
                'prompt' => 'A prompt is required.',
            ]);
        }

        // Required before reserving funds.
        $this->wallets->ensureWallet($user);

        return DB::transaction(function () use (
            $user,
            $requestKey,
            $prompt,
            $size,
            $quality,
            $referenceUrls,
            $estimatedChargeUnits
        ) {
            // Keep lock order consistent: wallet, then generation.
            $wallet = $user->wallet()
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Generation::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->user_id !== $user->id ||
                    $existing->prompt !== $prompt ||
                    $existing->image_size !== $size ||
                    $existing->quality !== $quality ||
                    ($existing->reference_image_urls ?? []) !== $referenceUrls ||
                    $existing->estimated_charge_units !== $estimatedChargeUnits
                ) {
                    throw new LogicException(
                        'Request key reused with different parameters.'
                    );
                }

                return $existing;
            }

            $generation = Generation::create([
                'user_id' => $user->id,
                'request_key' => $requestKey,
                'prompt' => $prompt,
                'image_size' => $size,
                'quality' => $quality,
                'reference_image_urls' => $referenceUrls,
            ]);

            $generation->provider_request_payload = [
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceUrls,
                'metadata' => [
                    'promptedit_generation_id' => (string) $generation->id,
                    'promptedit_request_key' => $requestKey,
                ],
            ];

            // Financial fields are service-controlled.
            $generation->estimated_charge_units =
                $estimatedChargeUnits;
            $generation->status = Generation::STATUS_QUEUED;
            $generation->save();

            // Nested transaction participates in this outer transaction.
            $this->wallets->reserve(
                $generation,
                $estimatedChargeUnits
            );

            return $generation->refresh();
        }, 3);
    }

    /**
     * Attach a provider job identifier after submission.
     */
    public function attachProviderJob(
        Generation $generation,
        string $providerJobId
    ): Generation {
        if (trim($providerJobId) === '') {
            throw new LogicException(
                'Provider job ID is required.'
            );
        }

        return $this->withLockedGeneration(
            $generation,
            function (Generation $locked) use ($providerJobId) {
                if ($locked->isTerminal()) {
                    throw new LogicException(
                        'Cannot attach a job to a terminal generation.'
                    );
                }

                if ($locked->provider_job_id !== null &&
                    $locked->provider_job_id !== $providerJobId) {
                    throw new LogicException(
                        'Generation already has a different provider job.'
                    );
                }

                $locked->provider_job_id = $providerJobId;
                $locked->submitted_at ??= now();
                $locked->save();

                return $locked;
            }
        );
    }

    /**
     * Mark an existing queued generation as processing.
     */
    public function markProcessing(
        Generation $generation
    ): Generation {
        return $this->withLockedGeneration(
            $generation,
            function (Generation $locked) {
                if ($locked->status ===
                    Generation::STATUS_PROCESSING) {
                    return $locked;
                }

                if ($locked->status !==
                    Generation::STATUS_QUEUED) {
                    throw new LogicException(
                        'Only queued generations can start processing.'
                    );
                }

                $locked->status = Generation::STATUS_PROCESSING;
                $locked->save();

                return $locked;
            }
        );
    }

    /**
     * Finalize a successful provider job and its charge.
     *
     * @param  list<string>  $resultImageUrls
     * @param  array<string, int>  $providerUsage
     * @param  array<string, string>  $providerRates
     */
    public function succeed(
        Generation $generation,
        int $providerCostUnits,
        array $resultImageUrls,
        array $providerUsage = [],
        array $providerRates = []
    ): Generation {
        if ($providerCostUnits < 0) {
            throw new LogicException(
                'Provider cost cannot be negative.'
            );
        }

        if (empty($resultImageUrls)) {
            throw new LogicException(
                'A successful generation needs a result image.'
            );
        }

        return $this->withLockedGeneration(
            $generation,
            function (Generation $locked) use (
                $providerCostUnits,
                $resultImageUrls,
                $providerUsage,
                $providerRates
            ) {
                if ($locked->isCompleted()) {
                    if (
                        $locked->provider_cost_units !==
                            $providerCostUnits ||
                        ($locked->result_image_urls ?? []) !==
                            $resultImageUrls ||
                        ($providerUsage !== [] &&
                            ($locked->provider_usage ?? []) !== $providerUsage) ||
                        ($providerRates !== [] &&
                            ($locked->provider_rates ?? []) !== $providerRates)
                    ) {
                        throw new LogicException(
                            'Conflicting success callback.'
                        );
                    }

                    return $locked;
                }

                if ($locked->isFailed()) {
                    throw new LogicException(
                        'Cannot succeed a failed generation.'
                    );
                }

                $charge = $this->wallets->settleSuccess(
                    $locked,
                    $providerCostUnits
                );

                $locked->provider_cost_units =
                    $providerCostUnits;
                $locked->customer_charge_units =
                    $charge->metadata['customer_charge_units'];
                $locked->result_image_urls = $resultImageUrls;
                $locked->provider_usage = $providerUsage ?: null;
                $locked->provider_rates = $providerRates ?: null;
                $locked->status =
                    Generation::STATUS_COMPLETED;
                $locked->completed_at = now();
                $locked->error_message = null;
                $locked->save();

                return $locked;
            }
        );
    }

    /**
     * Fail a generation and release its entire reservation.
     */
    public function fail(
        Generation $generation,
        string $reason
    ): Generation {
        return $this->withLockedGeneration(
            $generation,
            function (Generation $locked) use ($reason) {
                if ($locked->isFailed()) {
                    return $locked;
                }

                if ($locked->isCompleted()) {
                    throw new LogicException(
                        'Cannot fail a completed generation.'
                    );
                }

                $this->wallets->releaseFailure($locked);

                $locked->status = Generation::STATUS_FAILED;
                $locked->error_message = $reason;
                $locked->failed_at = now();
                $locked->save();

                return $locked;
            }
        );
    }

    /**
     * Lock wallet first, then generation, for all state changes.
     *
     * Both the lifecycle change and any financial operation
     * share the same database transaction.
     */
    private function withLockedGeneration(
        Generation $generation,
        callable $callback
    ): Generation {
        return DB::transaction(function () use (
            $generation,
            $callback
        ) {
            DB::table('wallets')
                ->where('user_id', $generation->user_id)
                ->lockForUpdate()
                ->first()
                ?? throw new LogicException(
                    'Generation wallet does not exist.'
                );

            $locked = Generation::query()
                ->whereKey($generation->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $callback($locked);
        }, 3);
    }
}
