<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id', 
        'type', 
        'amount', 
        'reference', 
        'idempotency_key',
        'description', 
        'status',
        'metadata', // Add metadata to fillable properties
        'flagged'
    ];
    protected $casts = [
        'metadata' => 'array', // Cast metadata as array
        'amount' => 'decimal:2',
        'flagged' => 'boolean',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    // Optional: if you want to link the recipient wallet
    public function recipientWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'recipient_wallet_id');
    }

    protected static function booted(): void
    {
        static::created(fn() => static::flushAdminAnalyticsCache());
        static::updated(fn() => static::flushAdminAnalyticsCache());
        static::deleted(fn() => static::flushAdminAnalyticsCache());
    }

    protected static function flushAdminAnalyticsCache(): void
    {
        Cache::forget('admin.analytics.summary');
        Cache::forget('admin.analytics.transactions');
    }
}
