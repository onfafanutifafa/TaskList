<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateConversionRequest extends FormRequest
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
            'from' => ['required', 'string', Rule::in(config('psp.currencies.supported'))],
            'to' => ['required', 'string', 'different:from', Rule::in(config('psp.currencies.supported'))],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => strtoupper((string) $this->input('from')),
            'to' => strtoupper((string) $this->input('to')),
        ]);
    }
}
