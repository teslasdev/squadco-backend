<?php

namespace App\Http\Requests\Api\V1\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class Step3Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salary_amount'       => 'required|numeric|min:1',
            'bank_name'           => 'required|string',
            'bank_code'           => 'required|string',
            'bank_account_number' => 'required|string|size:10',
            'bank_account_name'   => 'required|string',
        ];
    }
}
