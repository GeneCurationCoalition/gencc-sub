<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliasRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // for now, we let the controller handle this.
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
            'type' => 'required|min:1|max:20',
            'class' => 'required|integer|between:1,2',
            'name' => 'required|alpha_dash:ascii|min:1|max:255',
            'value' => 'required|string|min:1|max:255',
        ];
    }
}
