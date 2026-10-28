<?php

namespace App\Services;

use App\Models\City;
use App\Models\Province;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads provinces and cities/municipalities from the PSGC data file in database/data (generated from
 * the Philippine Statistics Authority PSGC workbook). Safe to re-run: entries are matched by PSGC
 * code, then by name, and the Super Admin's renames and hidden entries are left as they are; only
 * missing entries are added and missing PSGC codes / regions filled in. Nothing is deleted.
 */
class PsgcLocationImporter
{
    public const DEFAULT_PATH = 'database/data/psgc-locations.json';

    /**
     * @return array{provinces_added: int, cities_added: int, provinces_matched: int, cities_matched: int, source: string}
     */
    public function import(?string $path = null): array
    {
        $path ??= base_path(self::DEFAULT_PATH);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! is_array($data['provinces'] ?? null)) {
            throw new RuntimeException("PSGC data file not found or invalid: {$path}");
        }

        $result = ['provinces_added' => 0, 'cities_added' => 0, 'provinces_matched' => 0, 'cities_matched' => 0, 'source' => (string) ($data['source'] ?? '')];

        DB::transaction(function () use ($data, &$result): void {
            foreach ($data['provinces'] as $entry) {
                $province = $this->match(Province::query(), $entry);
                if ($province) {
                    $province->fill(array_filter([
                        'psgc_code' => $province->psgc_code ?? $entry['psgc_code'],
                        'region' => $province->region ?? ($entry['region'] ?? null),
                    ]))->save();
                    $result['provinces_matched']++;
                } else {
                    $province = Province::create([
                        'psgc_code' => $entry['psgc_code'],
                        'name' => $entry['name'],
                        'region' => $entry['region'] ?? null,
                        'is_active' => true,
                    ]);
                    $result['provinces_added']++;
                }

                foreach ($entry['cities'] ?? [] as $cityEntry) {
                    $city = $this->match(City::query()->where('province_id', $province->id), $cityEntry);
                    if ($city) {
                        $city->fill(array_filter([
                            'psgc_code' => $city->psgc_code ?? $cityEntry['psgc_code'],
                            'kind' => $city->kind ?? ($cityEntry['kind'] ?? null),
                        ]))->save();
                        $result['cities_matched']++;

                        continue;
                    }
                    $province->cities()->create([
                        'psgc_code' => $cityEntry['psgc_code'],
                        'name' => $cityEntry['name'],
                        'kind' => $cityEntry['kind'] ?? null,
                        'is_active' => true,
                    ]);
                    $result['cities_added']++;
                }
            }
        });

        return $result;
    }

    /**
     * @template TModel of Province|City
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $entry
     * @return TModel|null
     */
    protected function match($query, array $entry)
    {
        return (clone $query)->where('psgc_code', $entry['psgc_code'])->first()
            ?? (clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $entry['name'])])->first();
    }
}
