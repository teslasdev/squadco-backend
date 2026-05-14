<?php

namespace App\Http\Requests\Api\V1\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class Step2Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workerId = (int) $this->route('worker_id');

        return [
            'nin'                      => ['required', 'string', 'size:11', Rule::unique('workers', 'nin')->ignore($workerId)],
            'bvn'                      => ['required', 'string', 'size:11', Rule::unique('workers', 'bvn')->ignore($workerId)],
            'phone'                    => 'required|string|size:11',
            'email'                    => ['required', 'email', Rule::unique('workers', 'email')->ignore($workerId)],
            'home_address'             => 'required|string',
            'next_of_kin_name'         => 'required|string',
            'next_of_kin_phone'        => 'required|string|size:11',
            'next_of_kin_relationship' => 'required|string',
        ];
    }
}
