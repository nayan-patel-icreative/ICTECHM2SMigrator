<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartOrderMigrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_gid' => ['required', 'string', 'max:255', 'regex:/^gid:\/\/shopify\/Location\/[0-9]+$/'],
        ];
    }
}
