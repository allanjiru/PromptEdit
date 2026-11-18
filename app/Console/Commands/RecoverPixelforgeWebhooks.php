<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\ProviderWebhookEvent;
use Illuminate\Support\Facades\DB;
use Throwable;


class RecoverPixelforgeWebhooks extends Command
{
    protected $signature = 'pixelforge:recover-webhooks
        {--limit=100 : Maximum events to recover}';

    protected $description =
        'Redispatch stale Pixelforge webhook events safely';

    public function handle(): int
    {
        $limit = max(
            1,
            min(500, (int) $this->option('limit'))
        );

        $claimed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $eventId = DB::transaction(function () {
                $event = ProviderWebhookEvent::query()
                    ->whereIn('status', [
                        ProviderWebhookEvent::STATUS_RECEIVED,
                        ProviderWebhookEvent::STATUS_PROCESSING,
                        ProviderWebhookEvent::STATUS_FAILED,
                    ])
                    ->where('created_at', '<=', now()->subMinutes(2))
                    ->where(function ($query) {
                        $query->whereNull('next_retry_at')
                            ->orWhere('next_retry_at', '<=', now());
                    })
                    ->where(function ($query) {
                        $query->whereNull('recovery_claimed_until')
                            ->orWhere(
                                'recovery_claimed_until',
                                '<=',
                                now()
                            );
                    })
                    ->orderBy('id')
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if (! $event) {
                    return null;
                }

                $attempts = $event->recovery_attempts + 1;

                $delay = min(
                    3600,
                    60 * (2 ** min($attempts - 1, 6))
                );

                $event->recovery_attempts = $attempts;
                $event->last_attempted_at = now();
                $event->next_retry_at = now()->addSeconds($delay);
                $event->recovery_claimed_until = now()->addMinutes(2);
                $event->save();

                return $event->id;
            });

            if ($eventId === null) {
                break;
            }

            try {
                ProcessPixelforgeWebhook::dispatch($eventId)
                    ->onQueue('webhooks');

                $claimed++;
            } catch (Throwable $exception) {
                report($exception);

                // Preserve the claim until it expires.
                // The scheduler will retry after the lease.
            }
        }

        $this->info(
            "Dispatched {$claimed} webhook recovery jobs."
        );

        return self::SUCCESS;
    }
}
