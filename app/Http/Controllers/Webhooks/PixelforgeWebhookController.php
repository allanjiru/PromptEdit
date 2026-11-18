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
use Illuminate\Validation\Rule;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PixelforgeWebhookVerifier $verifier
    ): JsonResponse {
        // Verify the signature against the raw request body.
        $verifier->verify($request);

        $payload = json_decode(
            $request->getContent(),
            true
        );

        $base = Validator::make(
            $payload ?? [],
            [
                'id' => [
                    'required',
                    'string',
                    'regex:/^evt_[A-Za-z0-9]+$/',
                    'max:255',
                ],
                'object' => ['required', 'in:event'],
                'type' => ['required', 'string', 'max:80'],
            ]
        )->validate();

        // A signed dashboard test event needs no generation job.
        if ($base['type'] === 'ping') {
            return response()->json([
                'received' => true,
            ]);
        }

        $validated = Validator::make(
            $payload,
            [
                'type' => [
                    'required',
                    Rule::in([
                        'generation.processing',
                        'generation.succeeded',
                        'generation.failed',
                    ]),
                ],
                'data' => ['required', 'array'],
                'data.object' => ['required', 'array'],
                'data.object.id' => [
                    'required',
                    'string',
                    'regex:/^gen_[A-Za-z0-9]+$/',
                ],
                'data.object.object' => [
                    'required',
                    'in:generation',
                ],
            ]
        )->validate();

        $eventId = $base['id'];
        $eventType = $validated['type'];
        $providerJobId = $validated['data']['object']['id'];

        $inserted = DB::select(
            <<<'SQL'
                INSERT INTO provider_webhook_events (
                    provider_event_id,
                    provider_job_id,
                    event_type,
                    payload,
                    status,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?, ?, ?, CAST(? AS json),
                    ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (provider_event_id)
                DO NOTHING
                RETURNING id
            SQL,
            [
                $eventId,
                $providerJobId,
                $eventType,
                json_encode($payload, JSON_THROW_ON_ERROR),
                ProviderWebhookEvent::STATUS_RECEIVED,
            ]
        );

        $created = count($inserted) === 1;

        $event = ProviderWebhookEvent::query()
            ->where('provider_event_id', $eventId)
            ->firstOrFail();

        if (
            $event->provider_job_id !== $providerJobId ||
            $event->event_type !== $eventType ||
            $event->payload !== $payload
        ) {
            abort(409, 'Conflicting provider event.');
        }

        if ($created) {
            ProcessPixelforgeWebhook::dispatch($event->id)
                ->onQueue('webhooks')
                ->afterCommit();
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
