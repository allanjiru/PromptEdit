<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class DemoCreditService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly GenerationPricingService $pricing
    ) {}

    /**
     * @return array<int, array{id: string, label: string, amount_units: int, amount_usd: string}>
     */
    public function publicPackages(): array
    {
        return collect($this->configuredPackages())
            ->map(fn (array $package, string $id): array => [
                'id' => $id,
                'label' => (string) $package['label'],
                'amount_units' => (int) $package['amount_units'],
                'amount_usd' => $this->pricing->formatUsd(
                    (int) $package['amount_units']
                ),
            ])
            ->values()
            ->all();
    }

    public function enabled(): bool
    {
        return (bool) config('demo_credits.enabled', false);
    }

    public function purchase(
        User $user,
        string $packageId,
        string $requestKey
    ): WalletTransaction {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'demo_credits' => 'Simulated credit purchases are disabled.',
            ]);
        }

        $packages = $this->configuredPackages();
        $package = $packages[$packageId] ?? null;

        if ($package === null) {
            throw ValidationException::withMessages([
                'package' => 'Select a valid demo credit package.',
            ]);
        }

        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages([
                'request_key' => 'A valid purchase request UUID is required.',
            ]);
        }

        $amountUnits = (int) $package['amount_units'];
        $ledgerKey = "demo-credit:user:{$user->getKey()}:{$requestKey}";

        try {
            return $this->wallets->addDemoCredits(
                $user,
                $amountUnits,
                $ledgerKey,
                $this->maxTotalUnits(),
                "Simulated {$package['label']} credit purchase",
                [
                    'simulated' => true,
                    'package_id' => $packageId,
                    'purchase_request_key' => $requestKey,
                ]
            );
        } catch (LogicException $exception) {
            if ($exception->getMessage() === 'Idempotency key reused with different parameters.') {
                throw ValidationException::withMessages([
                    'request_key' => 'This purchase request key was already used for another package.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, array{label: string, amount_units: int}>
     */
    private function configuredPackages(): array
    {
        /** @var array<string, array{label: string, amount_units: int}> $packages */
        $packages = config('demo_credits.packages', []);

        return $packages;
    }

    private function maxTotalUnits(): int
    {
        $maximum = (int) config('demo_credits.max_total_units', 0);

        if ($maximum <= 0) {
            throw new LogicException('Demo credit cap must be positive.');
        }

        return $maximum;
    }
}
