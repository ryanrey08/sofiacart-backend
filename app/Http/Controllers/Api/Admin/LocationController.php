<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Merchant;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super Admin management of the provinces and cities/municipalities offered in merchant
 * registration. Merchants store the chosen names as text; entries used by existing stores can be
 * renamed or deactivated but not deleted.
 */
class LocationController extends Controller
{
    public function provinces(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $provinces = Province::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->withCount(['cities', 'cities as active_cities_count' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get();

        $usage = Merchant::query()->whereIn('province', $provinces->pluck('name'))->selectRaw('province, count(*) as total')->groupBy('province')->pluck('total', 'province');

        return response()->json(['data' => $provinces->map(fn (Province $province) => [
            ...$this->presentProvince($province),
            'cities_count' => $province->cities_count,
            'active_cities_count' => $province->active_cities_count,
            'stores_using' => (int) ($usage[$province->name] ?? 0),
        ])->values()]);
    }

    public function storeProvince(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'region' => ['nullable', 'string', 'max:255']]);
        $name = trim($data['name']);
        $this->ensureUniqueProvince($name);

        $province = Province::create(['name' => $name, 'region' => $data['region'] ?? null, 'is_active' => true]);

        return response()->json(['data' => $this->presentProvince($province), 'message' => 'Province added.'], 201);
    }

    public function updateProvince(Request $request, Province $province): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $this->ensureUniqueProvince($data['name'], $province->id);
        }

        $province->update($data);

        return response()->json(['data' => $this->presentProvince($province->fresh()), 'message' => 'Province updated.']);
    }

    public function destroyProvince(Province $province): JsonResponse
    {
        $cityNames = $province->cities()->pluck('name');
        $inUse = Merchant::query()->where('province', $province->name)->exists();

        if ($inUse) {
            abort(409, 'This province is used by existing stores. Deactivate it instead.');
        }

        $province->delete();

        return response()->json(['message' => "Province deleted with its {$cityNames->count()} cities."]);
    }

    public function cities(Province $province): JsonResponse
    {
        $cities = $province->cities()->orderBy('name')->get();
        $usage = Merchant::query()->where('province', $province->name)->whereIn('city', $cities->pluck('name'))
            ->selectRaw('city, count(*) as total')->groupBy('city')->pluck('total', 'city');

        return response()->json(['data' => $cities->map(fn (City $city) => [
            ...$this->presentCity($city),
            'stores_using' => (int) ($usage[$city->name] ?? 0),
        ])->values()]);
    }

    public function storeCity(Request $request, Province $province): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'kind' => ['nullable', 'in:city,municipality']]);
        $name = trim($data['name']);
        $this->ensureUniqueCity($province, $name);

        $city = $province->cities()->create(['name' => $name, 'kind' => $data['kind'] ?? null, 'is_active' => true]);

        return response()->json(['data' => $this->presentCity($city), 'message' => 'City added.'], 201);
    }

    public function updateCity(Request $request, City $city): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'kind' => ['sometimes', 'nullable', 'in:city,municipality'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $this->ensureUniqueCity($city->province, $data['name'], $city->id);
        }

        $city->update($data);

        return response()->json(['data' => $this->presentCity($city->fresh()), 'message' => 'City updated.']);
    }

    public function destroyCity(City $city): JsonResponse
    {
        $inUse = Merchant::query()->where('province', $city->province->name)->where('city', $city->name)->exists();
        if ($inUse) {
            abort(409, 'This city is used by existing stores. Deactivate it instead.');
        }

        $city->delete();

        return response()->json(['message' => 'City deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentProvince(Province $province): array
    {
        return [
            'id' => $province->id,
            'name' => $province->name,
            'region' => $province->region,
            'psgc_code' => $province->psgc_code,
            'is_active' => $province->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentCity(City $city): array
    {
        return [
            'id' => $city->id,
            'province_id' => $city->province_id,
            'name' => $city->name,
            'kind' => $city->kind,
            'psgc_code' => $city->psgc_code,
            'is_active' => $city->is_active,
        ];
    }

    protected function ensureUniqueProvince(string $name, ?int $ignoreId = null): void
    {
        if (Province::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            abort(422, 'A province with that name already exists.');
        }
    }

    protected function ensureUniqueCity(Province $province, string $name, ?int $ignoreId = null): void
    {
        if ($province->cities()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            abort(422, "That city already exists in {$province->name}.");
        }
    }
}
