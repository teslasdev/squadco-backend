<?php

namespace App\Http\Requests\Api\V1\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class Step1Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name'        => 'required|string|max:255',
            'date_of_birth'    => 'required|date|before_or_equal:' . now()->subYears(18)->toDateString(),
            'gender'           => 'required|in:male,female',
            'ippis_id'         => 'required|string|unique:workers,ippis_id',
            'mda_id'           => 'required|exists:mdas,id',
            'department_id'    => 'required|exists:departments,id',
            'job_title'        => 'required|string|max:255',
            'grade_level'      => 'required|integer|between:1,17',
            'step'             => 'required|integer|between:1,15',
            'employment_date'  => 'required|date|before_or_equal:today',
            'employment_type'  => 'required|in:permanent,contract,secondment,casual',
            'state_of_posting' => 'required|string',
            'lga'              => 'required|string',
            'office_address'   => 'required|string',
        ];
    }
}
