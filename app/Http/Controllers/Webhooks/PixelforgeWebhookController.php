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
        // Authenticate before validating or persisting.
        $verifier->verify($request);

        $payload = json_decode(
            $request->getContent(),
            true
        );

        $validated = Validator::make(
            $payload ?? [],
            [
                'id' => ['required', 'string', 'max:255'],
                'type' => ['required', 'string', 'max:80'],
                'data' => ['required', 'array'],
                'data.id' => [
                    'required',
                    'string',
                    'regex:/^gen_[A-Za-z0-9]+$/',
                ],
            ]
        )->validate();

        $eventId = $validated['id'];
        $providerJobId = $validated['data']['id'];
        $eventType = $validated['type'];

        $jsonPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        );

        // PostgreSQL handles concurrent inserts using
        // the unique index on provider_event_id.
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
                    ?,
                    ?,
                    ?,
                    CAST(? AS json),
                    ?,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (provider_event_id)
                DO NOTHING
                RETURNING id
            SQL,
            [
                $eventId,
                $providerJobId,
                $eventType,
                $jsonPayload,
                ProviderWebhookEvent::STATUS_RECEIVED,
            ]
        );

        $created = count($inserted) === 1;

        $event = ProviderWebhookEvent::query()
            ->where('provider_event_id', $eventId)
            ->firstOrFail();

        // Reject an event ID reused with different data.
        if (
            $event->provider_job_id !== $providerJobId ||
            $event->event_type !== $eventType ||
            $event->payload !== $payload
        ) {
            abort(409, 'Conflicting provider event.');
        }

        // Only the request that created the event
        // dispatches its initial processing job.
        if ($created) {
            ProcessPixelforgeWebhook::dispatch($event->id)
                ->onQueue('webhooks')
                ->afterCommit();
        }

        return response()->json([
            'received' => true,
        ], 200);
    }
}
