<?php

namespace App\Services;

use App\Enums\RegistrationOptionType;
use App\Models\City;
use App\Models\Merchant;
use App\Models\Province;
use App\Models\RegistrationOption;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Super Admin-managed dropdown lists (business type, category, government ID type, province,
 * city) as offered to merchants, and the validation that only active entries are accepted.
 */
class RegistrationOptionsService
{
    /**
     * Fields validated against a managed list => the list they use.
     */
    public const FIELDS = [
        'business_type' => RegistrationOptionType::BusinessType,
        'business_category' => RegistrationOptionType::Category,
        'store_category' => RegistrationOptionType::Category,
        'government_id_type' => RegistrationOptionType::GovernmentIdType,
    ];

    /**
     * Everything the registration form needs: active options in display order, and active provinces.
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $options = RegistrationOption::query()->active()->ordered()->get(['type', 'name'])->groupBy(fn (RegistrationOption $option) => $option->type->value);
        $names = fn (RegistrationOptionType $type) => $options->get($type->value, collect())->pluck('name')->values();

        return [
            'business_types' => $names(RegistrationOptionType::BusinessType),
            'categories' => $names(RegistrationOptionType::Category),
            'government_id_types' => $names(RegistrationOptionType::GovernmentIdType),
            'provinces' => Province::query()->active()->orderBy('name')
                ->withCount(['cities' => fn ($query) => $query->active()])
                ->get(['id', 'name'])
                ->map(fn (Province $province) => [
                    'id' => $province->id,
                    'name' => $province->name,
                    'has_cities' => $province->cities_count > 0,
                ])->values(),
        ];
    }

    /**
     * Active cities of an active province, alphabetical.
     *
     * @return list<array{id: int, name: string, kind: ?string}>
     */
    public function cities(Province $province): array
    {
        abort_unless($province->is_active, 404);

        return $province->cities()->active()->orderBy('name')->get(['id', 'name', 'kind'])
            ->map(fn (City $city) => ['id' => $city->id, 'name' => $city->name, 'kind' => $city->kind])
            ->all();
    }

    /**
     * Validation rules for the managed fields. With $partial (profile changes) a field is only
     * checked when it is sent.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(bool $partial = false): array
    {
        $presence = $partial ? ['sometimes', 'required'] : ['required'];
        $rules = [];

        foreach (self::FIELDS as $field => $type) {
            $rules[$field] = [...$presence, 'string', 'max:255', Rule::exists('registration_options', 'name')
                ->where('type', $type->value)->where('is_active', true)];
        }

        $rules['province'] = [...$presence, 'string', 'max:255', Rule::exists('provinces', 'name')->where('is_active', true)];
        $rules['city'] = [...$presence, 'string', 'max:255'];

        return $rules;
    }

    /**
     * Names of the list-checked fields in $input that still hold the store's saved value. Edit forms
     * resend every field, and a store may keep a value that was renamed, hidden or never listed (it
     * registered before the lists existed), so only changed values are checked against the lists.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    public function unchangedFields(array $input, ?Merchant $merchant): array
    {
        if (! $merchant) {
            return [];
        }

        return array_values(array_filter(
            [...array_keys(self::FIELDS), 'province', 'city'],
            fn (string $field) => array_key_exists($field, $input)
                && is_string($input[$field])
                && trim($input[$field]) === trim((string) $merchant->getAttribute($field)),
        ));
    }

    /**
     * The city must be one of the province's active cities whenever that province has a city list
     * (a province without any cities yet accepts a typed city name). For partial updates the
     * store's current province / city stand in for whichever of the two is not being changed.
     *
     * @param  array<string, mixed>  $input
     */
    public function validateCity(Validator $validator, array $input, ?string $currentProvince = null, ?string $currentCity = null): void
    {
        $changesLocation = array_key_exists('province', $input) || array_key_exists('city', $input);
        if (! $changesLocation || $validator->errors()->hasAny(['province', 'city'])) {
            return;
        }

        $provinceName = $input['province'] ?? $currentProvince;
        $cityName = $input['city'] ?? $currentCity;
        $province = is_string($provinceName) ? Province::query()->active()->where('name', $provinceName)->first() : null;

        if (! $province || ! $province->cities()->active()->exists()) {
            return;
        }

        if (! is_string($cityName) || ! $province->cities()->active()->where('name', $cityName)->exists()) {
            $validator->errors()->add('city', 'Select a city or municipality in '.$province->name.'.');
        }
    }

    /**
     * Friendly messages for the managed fields.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $messages["{$field}.exists"] = 'Select one of the listed '.Str::of($field)->replace('_', ' ')->lower().' options.';
        }
        $messages['province.exists'] = 'Select one of the listed provinces.';

        return $messages;
    }
}
