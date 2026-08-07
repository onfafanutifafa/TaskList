<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // API-key auth is handled by middleware
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            // Amount is an integer in the currency's MINOR unit (pesewas/cents). No floats.
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', Rule::in(config('psp.currencies.supported'))],
            'phone' => ['required', 'string', 'regex:/^\+?\d{6,15}$/'],
            'network' => ['required', 'string', Rule::in(array_keys(config('psp.networks')))],
            'reference' => ['required', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:140'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }

    public function messages(): array
    {
        return [
            'amount.integer' => 'Amount must be an integer in minor units (e.g. 1050 = GHS 10.50).',
            'phone.regex' => 'Phone must be an MSISDN: 6–15 digits, optional leading +.',
        ];
    }
}
