<?php

namespace App\Http\Requests\Projects;

use App\Concerns\ProjectValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveProjectRequest extends FormRequest
{
    use ProjectValidationRules;

    /**
     * Authorization happens in the controller through ProjectPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|mixed>>
     */
    public function rules(): array
    {
        return $this->projectRules();
    }

    /**
     * Normalise optional inputs before validation.
     */
    protected function prepareForValidation(): void
    {
        $brief = (array) $this->input('brief', []);
        $brief['brand_colors'] = array_values(array_filter((array) ($brief['brand_colors'] ?? [])));

        $this->merge([
            'currency' => $this->filled('currency') ? strtoupper((string) $this->input('currency')) : null,
            'brief' => $brief,
        ]);
    }

    /**
     * The validated attributes ready for Project::fill(), with the text
     * direction derived from the language.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $data = $this->validated();

        return [
            ...$data,
            'name' => trim(strip_tags($data['name'])),
            'direction' => config("languages.{$data['language']}.dir", 'ltr'),
            'brief' => array_map(
                fn ($value) => is_string($value) ? trim(strip_tags($value)) : $value,
                $data['brief'],
            ),
        ];
    }
}
