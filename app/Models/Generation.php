<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array<int, mixed>|null $result_image_urls
 * @property array<int, string>|null $reference_image_urls
 * @property array<string, mixed>|null $provider_request_payload
 * @property int $id
 * @property int $user_id
 * @property string $request_key
 * @property string|null $provider_job_id
 * @property string $prompt
 * @property string $image_size
 * @property string $quality
 * @property string $status
 * @property int|null $estimated_charge_units
 * @property int|null $provider_cost_units
 * @property int|null $customer_charge_units
 * @property string|null $error_message
 * @property string $submission_status
 * @property int $submission_attempts
 * @property string|null $submission_claim_token
 * @property string|null $recovery_dispatch_token
 * @property string|null $submission_last_error
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $submission_last_attempted_at
 * @property CarbonInterface|null $submission_reconciliation_due_at
 * @property CarbonInterface|null $submission_claimed_until
 * @property CarbonInterface|null $recovery_dispatch_until
 * @property int $result_reconciliation_attempts
 * @property CarbonInterface|null $result_reconciliation_due_at
 * @property string|null $result_reconciliation_claim_token
 * @property CarbonInterface|null $result_reconciliation_claimed_until
 * @property CarbonInterface|null $result_reconciliation_exhausted_at
 */
class Generation extends Model
{
    public const MAX_REFERENCE_IMAGES = 4;

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
            'submission_claimed_until' => 'datetime',
            'recovery_dispatch_until' => 'datetime',

            'result_reconciliation_attempts' => 'integer',
            'result_reconciliation_due_at' => 'datetime',
            'result_reconciliation_claimed_until' => 'datetime',
            'result_reconciliation_exhausted_at' => 'datetime',

            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<WalletReservation, $this> */
    public function reservation(): HasOne
    {
        return $this->hasOne(WalletReservation::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
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
