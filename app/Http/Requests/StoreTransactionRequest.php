<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

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
        if ($this->is('api/transactions/transfer') || $this->is('transactions/transfer')) {
            return [
                'recipient' => 'required|string',
                'amount' => 'required|numeric|gt:0',
                'description' => 'nullable|string|max:255',
                'client_idempotency_key' => 'required|string|max:100',
            ];
        }

        return [
            'wallet_id' => 'required|exists:wallets,id',
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|gt:0',
            'reference' => 'required|string|max:255',
            'idempotency_key' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }

}
