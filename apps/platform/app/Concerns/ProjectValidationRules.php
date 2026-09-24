<?php

namespace App\Concerns;

use App\Enums\BrandTone;
use App\Enums\CreationMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

trait ProjectValidationRules
{
    /**
     * Validation rules shared by project create and update.
     *
     * @return array<string, array<int, ValidationRule|string|In|Enum>>
     */
    protected function projectRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Import modes are accepted once their flows exist (M2 design import, M4 product CSV).
            'creation_mode' => ['required', Rule::enum(CreationMode::class)->only([CreationMode::Describe])],
            'language' => ['required', 'string', Rule::in(array_keys(config('languages')))],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'brief' => ['required', 'array:niche,audience,tone,style,brand_colors'],
            'brief.niche' => ['required', 'string', 'min:3', 'max:160'],
            'brief.audience' => ['nullable', 'string', 'max:300'],
            'brief.tone' => ['required', Rule::enum(BrandTone::class)],
            'brief.style' => ['nullable', 'string', 'max:500'],
            'brief.brand_colors' => ['nullable', 'array', 'max:3'],
            'brief.brand_colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
