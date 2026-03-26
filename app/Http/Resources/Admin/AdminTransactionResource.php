<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'reference'  => $this->reference,
            'type'       => $this->type,
            'amount'     => $this->amount,
            'status'     => $this->status,
            'flagged'    => $this->flagged,
            'created_at' => $this->created_at->toDateTimeString(),
            'user'       => $this->whenLoaded('wallet', function () {
                $user = $this->wallet?->user;

                if (! $user) {
                    return null;
                }

                return [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ];
            }),
        ];
    }
}
