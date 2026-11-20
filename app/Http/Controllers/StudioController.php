<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\DemoCreditService;
use App\Services\GenerationPricingService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function __invoke(
        Request $request,
        WalletService $wallets,
        DemoCreditService $demoCredits,
        GenerationPricingService $pricing
    ): Response {
        $wallets->ensureWallet($request->user());
        $wallet = Wallet::query()
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        return Inertia::render('studio/Index', [
            'wallet' => [
                'available_units' => $wallet->available_units,
                'available_usd' => $pricing->formatUsd(
                    $wallet->available_units
                ),
                'reserved_units' => $wallet->reserved_units,
                'reserved_usd' => $pricing->formatUsd(
                    $wallet->reserved_units
                ),
            ],
            'demoCredits' => [
                'enabled' => $demoCredits->enabled(),
                'packages' => $demoCredits->publicPackages(),
            ],
        ]);
    }
}
