<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
{
    return [
        'wallet_id' => 'required|exists:wallets,id',
        'amount' => 'required|numeric|min:0.01',
        'recipient_address' => 'required|string|exists:wallets,address',
        'description' => 'nullable|string|max:255',
        'client_idempotency_key' => 'required|string|max:255',
        // 'type' removed
        // 'recipient_description' removed
        // 'reference' removed
    ];
}

}
