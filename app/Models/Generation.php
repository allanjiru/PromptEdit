<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Generation extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const SUBMISSION_PENDING = 'pending';
    public const SUBMISSION_SUBMITTING = 'submitting';
    public const SUBMISSION_ACCEPTED = 'accepted';
    public const SUBMISSION_UNCERTAIN = 'uncertain';
    public const SUBMISSION_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'user_id',
        'request_key',
        'prompt',
        'image_size',
        'quality',
        'reference_image_urls',
    ];

    protected $hidden = [
        'provider_cost_units',
    ];

    protected function casts(): array
    {
        return [
            'reference_image_urls' => 'array',
            'result_image_urls' => 'array',
            'provider_request_payload' => 'array',

            'estimated_charge_units' => 'integer',
            'provider_cost_units' => 'integer',
            'customer_charge_units' => 'integer',

            'submission_attempts' => 'integer',
            'submission_last_attempted_at' => 'datetime',
            'submission_reconciliation_due_at' => 'datetime',
            'submission_claimed_until' => 'datetime'
            ,
            'recovery_dispatch_until' => 'datetime',

            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(WalletReservation::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isFailed();
    }

    public function needsSubmissionRecovery(): bool
    {
        return ! $this->isTerminal()
            && $this->provider_job_id === null
            && in_array($this->submission_status, [
                self::SUBMISSION_PENDING,
                self::SUBMISSION_SUBMITTING,
                self::SUBMISSION_UNCERTAIN,
            ], true);
    }
}
