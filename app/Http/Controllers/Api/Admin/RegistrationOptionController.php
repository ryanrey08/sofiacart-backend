<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RegistrationOptionType;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\RegistrationOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Super Admin management of the merchant registration dropdowns (business types, categories,
 * government ID types). Merchants store the chosen name as text, so renaming or deactivating an
 * option never changes existing stores; an option in use cannot be deleted, only deactivated.
 */
class RegistrationOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $options = RegistrationOption::query()->ordered()->get()->groupBy(fn (RegistrationOption $option) => $option->type->value);

        return response()->json(['data' => collect(RegistrationOptionType::cases())->map(fn (RegistrationOptionType $type) => [
            'type' => $type->value,
            'label' => $type->label(),
            'options' => $options->get($type->value, collect())->map(fn (RegistrationOption $option) => $this->present($option))->values(),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(RegistrationOptionType::class)],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $type = RegistrationOptionType::from($data['type']);
        $name = trim($data['name']);
        $this->ensureUniqueName($type, $name);

        $option = RegistrationOption::create([
            'type' => $type,
            'name' => $name,
            'sort_order' => (int) RegistrationOption::query()->ofType($type)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        return response()->json(['data' => $this->present($option), 'message' => 'Option added.'], 201);
    }

    public function update(Request $request, RegistrationOption $registrationOption): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $this->ensureUniqueName($registrationOption->type, $data['name'], $registrationOption->id);
        }

        $registrationOption->update($data);

        return response()->json(['data' => $this->present($registrationOption->fresh()), 'message' => 'Option updated.']);
    }

    /**
     * Sets the display order of one list from the given ids (top to bottom).
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(RegistrationOptionType::class)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $type = RegistrationOptionType::from($data['type']);
        $ids = array_map('intval', $data['ids']);

        $existing = RegistrationOption::query()->ofType($type)->pluck('id')->sort()->values()->all();
        if ($existing !== collect($ids)->sort()->values()->all()) {
            abort(422, 'The order must list every option of this type exactly once.');
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                RegistrationOption::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return $this->index();
    }

    public function destroy(RegistrationOption $registrationOption): JsonResponse
    {
        if ($this->usage($registrationOption) > 0) {
            abort(409, 'This option is used by existing stores. Deactivate it instead, so they keep their value.');
        }

        $registrationOption->delete();

        return response()->json(['message' => 'Option deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(RegistrationOption $option): array
    {
        return [
            'id' => $option->id,
            'type' => $option->type->value,
            'name' => $option->name,
            'sort_order' => $option->sort_order,
            'is_active' => $option->is_active,
            'stores_using' => $this->usage($option),
        ];
    }

    protected function usage(RegistrationOption $option): int
    {
        return Merchant::query()->where(function ($query) use ($option): void {
            foreach (RegistrationOption::merchantColumns($option->type) as $column) {
                $query->orWhere($column, $option->name);
            }
        })->count();
    }

    protected function ensureUniqueName(RegistrationOptionType $type, string $name, ?int $ignoreId = null): void
    {
        $exists = RegistrationOption::query()->ofType($type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            abort(422, 'That option already exists in this list.');
        }
    }
}
