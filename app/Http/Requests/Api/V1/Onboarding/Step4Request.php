<?php

namespace App\Http\Requests\Api\V1\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class Step4Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Persona-style live enrolment: three live frames are required so we can
        // run identity + 2 head-turn liveness checks at enrol time (same as the
        // verification flow). A printed photo of a dead worker cannot pass the
        // pose checks, which closes the ghost-enrolment hole.
        return [
            'frame_straight' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'frame_right'    => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'frame_left'     => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ];
    }
}
