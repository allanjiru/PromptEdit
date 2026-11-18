<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_DEMO_CREDIT = 'demo_credit';

    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_RESERVATION_RELEASE = 'reservation_release';

    public const TYPE_GENERATION_CHARGE = 'generation_charge';

    protected $fillable = [
        'wallet_id',
        'generation_id',
        'type',
        'idempotency_key',
        'available_delta_units',
        'reserved_delta_units',
        'available_after_units',
        'reserved_after_units',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'available_delta_units' => 'integer',
            'reserved_delta_units' => 'integer',
            'available_after_units' => 'integer',
            'reserved_after_units' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function isCredit(): bool
    {
        return $this->available_delta_units > 0;
    }

    public function isDebit(): bool
    {
        return $this->available_delta_units < 0;
    }
}
