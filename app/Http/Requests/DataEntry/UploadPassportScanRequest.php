<?php

namespace App\Http\Requests\DataEntry;

use App\Rules\PassportScanFile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload or replace a passport's scan copy (multipart field "scan").
 */
class UploadPassportScanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scan' => PassportScanFile::rules(required: true),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scan.required' => 'Please upload the passport scan copy.',
            'scan.mimes' => 'The scan must be a JPG, PNG or PDF file.',
            'scan.max' => 'The scan may not be larger than 5 MB.',
        ];
    }
}
