<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\ProviderWebhookEvent;
use App\Services\PixelforgeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PixelforgeWebhookVerifier $verifier
    ): JsonResponse {
        // Authentication must happen before persistence.
        $verifier->verify($request);

        $payload = json_decode(
            $request->getContent(),
            true
        );

        $validated = Validator::make(
            $payload ?? [],
            [
                'id' => ['required', 'string', 'max:255'],
                'type' => ['required', 'string', 'max:255'],
                'data' => ['required', 'array'],
                'data.id' => [
                    'required',
                    'string',
                    'regex:/^gen_[A-Za-z0-9]+$/',
                ],
            ]
        )->validate();

        $event = DB::transaction(function () use (
            $validated,
            $payload
        ) {
            return ProviderWebhookEvent::query()
                ->firstOrCreate(
                    [
                        'provider_event_id' => $validated['id'],
                    ],
                    [
                        'provider_job_id' =>
                            $validated['data']['id'],
                        'event_type' => $validated['type'],
                        'payload' => $payload,
                        'status' =>
                            ProviderWebhookEvent::STATUS_RECEIVED,
                    ]
                );
        });

        if (! $event->isProcessed()) {
            ProcessPixelforgeWebhook::dispatch($event->id)
                ->onQueue('webhooks')
                ->afterCommit();
        }

        return response()->json([
            'received' => true,
        ], 200);
    }
}
