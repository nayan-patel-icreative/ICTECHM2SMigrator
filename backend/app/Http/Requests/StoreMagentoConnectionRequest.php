<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMagentoConnectionRequest extends FormRequest
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
        return [
            'api_url'         => ['required', 'url', 'max:255'],
            'access_token'    => ['nullable', 'string', 'max:1024'],
            'store_view_code' => ['nullable', 'string', 'max:64'],
            'store_view_name' => ['nullable', 'string', 'max:255'],
            'language_config' => ['nullable', 'array'],
            'files_path'      => ['nullable', 'string', 'max:1024'],
        ];
    }
}
