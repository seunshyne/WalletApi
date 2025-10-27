<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillables = ['user_id', 'currency', 'balance'];

    public function user() {
        return $this->belongsTo(User::class);
    }
    public function transactions() {
        return $this->hasMany(Transaction::class);
    }
}
