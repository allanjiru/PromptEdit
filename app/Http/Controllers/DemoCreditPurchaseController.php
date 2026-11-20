<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\DemoCreditService;
use App\Services\GenerationPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoCreditPurchaseController extends Controller
{
    public function __invoke(
        Request $request,
        DemoCreditService $demoCredits,
        GenerationPricingService $pricing
    ): JsonResponse {
        $validated = $request->validate([
            'package' => ['required', 'string', 'max:40'],
            'request_key' => ['required', 'uuid'],
            'amount' => ['prohibited'],
            'amount_units' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);

        $transaction = $demoCredits->purchase(
            $request->user(),
            $validated['package'],
            $validated['request_key']
        );
        $wallet = Wallet::query()
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        return response()->json([
            'transaction_id' => $transaction->id,
            'package' => $validated['package'],
            'credited_units' => $transaction->available_delta_units,
            'credited_usd' => $pricing->formatUsd(
                $transaction->available_delta_units
            ),
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
        ]);
    }
}
