<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $available_units
 * @property int $reserved_units
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id',
    ];

    protected $casts = [
        'available_units' => 'integer',
        'reserved_units' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** @return HasMany<WalletReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(WalletReservation::class);
    }
}
