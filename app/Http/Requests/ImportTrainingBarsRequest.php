<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportTrainingBarsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $configuredToken = config('services.vm_training_import.token');
        $requestToken = $this->header('X-VM-Import-Token');

        return is_string($configuredToken)
            && $configuredToken !== ''
            && is_string($requestToken)
            && hash_equals($configuredToken, $requestToken);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'players' => ['required', 'array'],
            'players.*.vm_player_id' => ['required', 'integer'],
            'players.*.name' => ['nullable', 'string', 'max:255'],
            'players.*.training_bar' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
