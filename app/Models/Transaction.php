<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'metadata' // Add metadata to fillable properties
    ];
    protected $casts = [
        'metadata' => 'array', // Cast metadata as array
    ];
    public function wallet() {
        return $this->belongsTo(Wallet::class);
    }

    // Optional: if you want to link the recipient wallet
    public function recipientWallet()
    {
        return $this->belongsTo(Wallet::class, 'recipient_wallet_id'); // assuming you store it in metadata
    }

}
