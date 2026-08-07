<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCryptoDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            // Amount is in the asset's minor units (USDT/USDC: 1_000_000 = 1.00).
            'amount' => ['required', 'integer', 'min:1'],
            'asset' => ['required', 'string', Rule::in(array_keys(config('psp.crypto.assets')))],
            'chain' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:140'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('asset')) {
            $this->merge(['asset' => strtoupper((string) $this->input('asset'))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $asset = $this->input('asset');
            $chain = $this->input('chain');

            if ($asset && $chain && ! config("psp.crypto.assets.{$asset}.{$chain}")) {
                $supported = implode(', ', array_keys(config("psp.crypto.assets.{$asset}", [])));
                $v->errors()->add('chain', "Unsupported chain for {$asset}. Supported: {$supported}.");
            }
        });
    }
}
