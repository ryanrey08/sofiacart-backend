<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkCategoryActionRequest;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Requests\UpdateCategoryStatusRequest;
use App\Http\Requests\UploadCategoryImageRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CategoryController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->categoriesQuery($request)
            ->with('parent')
            ->withCount(['products', 'children']);

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        match ($request->string('status')->toString()) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        if ($request->filled('parent_id')) {
            $parentId = $request->string('parent_id')->toString();

            if (in_array($parentId, ['none', 'root', 'null'], true)) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', (int) $parentId);
            }
        }

        match ($request->string('sort')->toString()) {
            'name_asc' => $query->orderBy('name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'products_desc' => $query->orderByDesc('products_count')->orderBy('name'),
            'products_asc' => $query->orderBy('products_count')->orderBy('name'),
            'newest' => $query->latest()->orderByDesc('id'),
            'oldest' => $query->oldest()->orderBy('id'),
            default => $query->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        };

        return CategoryResource::collection($query->paginate($this->pageSize($request, 10))->withQueryString());
    }

    public function stats(Request $request): JsonResponse
    {
        $total = $this->categoriesQuery($request)->count();
        $active = $this->categoriesQuery($request)->where('is_active', true)->count();
        $totalProducts = Product::query()
            ->whereIn('category_id', $this->categoriesQuery($request)->select('id'))
            ->count();

        return response()->json([
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'total_products' => $totalProducts,
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureValidParent($data['merchant_id'], $data['parent_id'] ?? null);
        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['merchant_id'], $data['name']);
        unset($data['image']);

        $imagePath = $this->storeImage($request->file('image'), $data['merchant_id']);

        try {
            $category = Category::create([...$data, 'image_path' => $imagePath]);
        } catch (Throwable $exception) {
            $this->discardImage($imagePath);

            throw $exception;
        }

        return CategoryResource::make($this->loadDetails($category));
    }

    public function show(Request $request, int $category): CategoryResource
    {
        $model = $this->findCategory($request, $category);
        Gate::authorize('view', $model);

        return CategoryResource::make($this->loadDetails($model));
    }

    public function update(UpdateCategoryRequest $request, int $category): CategoryResource
    {
        $model = $this->findCategory($request, $category);
        Gate::authorize('update', $model);

        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);

        if ($data['merchant_id'] !== $model->merchant_id && ($model->products()->exists() || $model->children()->exists())) {
            throw ValidationException::withMessages([
                'merchant_id' => ['Categories with products or subcategories cannot be transferred between merchants.'],
            ]);
        }

        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $model->parent_id;
        $this->ensureValidParent($data['merchant_id'], $parentId, $model);
        $data['parent_id'] = $parentId;

        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);

        $oldImagePath = $model->image_path;
        $newImagePath = $this->storeImage($request->file('image'), $data['merchant_id']);

        if ($newImagePath !== null) {
            $data['image_path'] = $newImagePath;
        } elseif ($removeImage) {
            $data['image_path'] = null;
        }

        try {
            $model->update($data);
        } catch (Throwable $exception) {
            $this->discardImage($newImagePath);

            throw $exception;
        }

        if ($oldImagePath !== null && $model->image_path !== $oldImagePath) {
            $this->discardImage($oldImagePath);
        }

        return CategoryResource::make($this->loadDetails($model));
    }

    public function uploadImage(UploadCategoryImageRequest $request, int $category): CategoryResource
    {
        $model = $this->findCategory($request, $category);
        Gate::authorize('update', $model);

        $oldImagePath = $model->image_path;
        $newImagePath = $this->storeImage($request->file('image'), $model->merchant_id);

        try {
            $model->update(['image_path' => $newImagePath]);
        } catch (Throwable $exception) {
            $this->discardImage($newImagePath);

            throw $exception;
        }

        $this->discardImage($oldImagePath);

        return CategoryResource::make($this->loadDetails($model));
    }

    public function updateStatus(UpdateCategoryStatusRequest $request, int $category): CategoryResource
    {
        $model = $this->findCategory($request, $category);
        Gate::authorize('update', $model);

        $model->update([
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : ! $model->is_active,
        ]);

        return CategoryResource::make($this->loadDetails($model));
    }

    public function bulk(BulkCategoryActionRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated('ids'));
        $action = $request->validated('action');

        $categories = $this->scopeMerchant(Category::query(), $request)->whereKey($ids)->get();

        if ($categories->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['One or more selected categories could not be found.'],
            ]);
        }

        $ability = $action === 'delete' ? 'delete' : 'update';
        $categories->each(fn (Category $category) => Gate::authorize($ability, $category));

        match ($action) {
            'delete' => DB::transaction(fn () => Category::whereKey($ids)->delete()),
            'activate' => Category::whereKey($ids)->update(['is_active' => true]),
            'deactivate' => Category::whereKey($ids)->update(['is_active' => false]),
        };

        if ($action === 'delete') {
            $categories->pluck('image_path')->filter()->each(fn (string $path) => $this->discardImage($path));
        }

        return response()->json([
            'data' => [
                'action' => $action,
                'affected' => $categories->count(),
                'ids' => $categories->pluck('id')->values(),
            ],
        ]);
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        $model = $this->findCategory($request, $category);
        Gate::authorize('delete', $model);

        $model->delete();
        $this->discardImage($model->image_path);

        return response()->json(status: 204);
    }

    protected function categoriesQuery(Request $request): Builder
    {
        $query = $this->scopeMerchant(Category::query(), $request);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        return $query;
    }

    protected function findCategory(Request $request, int $category): Category
    {
        return $this->scopeMerchant(Category::query(), $request)->findOrFail($category);
    }

    protected function loadDetails(Category $category): Category
    {
        return $category->refresh()->load('parent')->loadCount(['products', 'children']);
    }

    protected function ensureValidParent(int $merchantId, ?int $parentId, ?Category $category = null): void
    {
        if ($parentId === null) {
            return;
        }

        if (! Category::where('merchant_id', $merchantId)->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent category is invalid.'],
            ]);
        }

        if ($category !== null && $category->isSelfOrAncestorOf($parentId)) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be its own parent or be nested under one of its subcategories.'],
            ]);
        }
    }

    protected function uniqueSlug(int $merchantId, string $name): string
    {
        $base = Str::limit(Str::slug($name), 240, '') ?: 'category';
        $base = trim($base, '-') ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (Category::where('merchant_id', $merchantId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    protected function storeImage(?UploadedFile $file, int $merchantId): ?string
    {
        if ($file === null) {
            return null;
        }

        $path = $file->store("categories/{$merchantId}", 'public');

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'image' => ['The image failed to upload.'],
            ]);
        }

        return $path;
    }

    protected function discardImage(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable) {
        }
    }
}
