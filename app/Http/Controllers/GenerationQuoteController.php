<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\Wallet;
use App\Services\GenerationEstimateService;
use App\Services\GenerationPricingService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationQuoteController extends Controller
{
    public function __invoke(
        Request $request,
        GenerationEstimateService $estimates,
        GenerationPricingService $pricing,
        WalletService $wallets
    ): JsonResponse {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'image_size' => [
                'required',
                'in:1024x1024,1536x1024,1024x1536',
            ],
            'quality' => [
                'required',
                'in:low,medium,high',
            ],
            'reference_image_urls' => [
                'sometimes',
                'array',
                'max:'.Generation::MAX_REFERENCE_IMAGES,
            ],
            'reference_image_urls.*' => [
                'required',
                'url:http,https',
                'max:2048',
            ],
        ]);

        $estimate = $estimates->estimate(
            $validated['prompt'],
            $validated['image_size'],
            $validated['quality'],
            $validated['reference_image_urls'] ?? []
        );

        $wallets->ensureWallet($request->user());

        $wallet = Wallet::query()
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        $available = $wallet->available_units;
        $reserved = $wallet->reserved_units;
        $charge = $estimate['estimated_charge_units'];

        return response()->json([
            'estimated_charge_units' => $charge,
            'estimated_charge_usd' => $pricing->formatUsd($charge),
            'available_units' => $available,
            'available_usd' => $pricing->formatUsd($available),
            'reserved_units' => $reserved,
            'reserved_usd' => $pricing->formatUsd($reserved),
            'has_sufficient_balance' => $available >= $charge,
        ]);
    }
}
