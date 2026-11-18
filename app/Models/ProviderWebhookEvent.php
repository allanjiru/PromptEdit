<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider_event_id',
        'provider_job_id',
        'event_type',
        'payload',
    ];

    protected $hidden = [
        'payload',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_RECEIVED,
            self::STATUS_PROCESSING,
        ], true);
    }
}
