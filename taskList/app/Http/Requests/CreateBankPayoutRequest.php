<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBankPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(config('psp.banking.currencies'))],
            'reference' => ['required', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:140'],
            'beneficiary' => ['required', 'array'],
            'beneficiary.account_name' => ['required', 'string', 'max:140'],
            'beneficiary.account_number' => ['required_without:beneficiary.iban', 'nullable', 'string', 'max:34'],
            'beneficiary.iban' => ['required_without:beneficiary.account_number', 'nullable', 'string', 'max:34'],
            'beneficiary.bank_name' => ['nullable', 'string', 'max:140'],
            'beneficiary.routing_number' => ['nullable', 'string', 'max:20'],
            'beneficiary.sort_code' => ['nullable', 'string', 'max:20'],
            'beneficiary.swift_bic' => ['nullable', 'string', 'max:20'],
            'beneficiary.country' => ['nullable', 'string', 'size:2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }
}
