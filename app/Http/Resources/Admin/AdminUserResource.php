<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'role'        => $this->role,
            'status'      => $this->status,
            'created_at'  => $this->created_at->toDateTimeString(),
            'wallet'      => $this->whenLoaded('wallet', fn() => [
                'balance'      => $this->wallet->balance,
                'currency'     => $this->wallet->currency,
                'transactions' => $this->wallet->transactions->map(fn($tx) => [
                    'id'          => $tx->id,
                    'type'        => $tx->type,
                    'amount'      => $tx->amount,
                    'status'      => $tx->status,
                    'reference'   => $tx->reference,
                    'description' => $tx->description,
                    'created_at'  => $tx->created_at->toDateTimeString(),
                ])
            ]),
        ];
    }
}
