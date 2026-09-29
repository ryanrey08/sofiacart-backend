<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Category::query();
        $this->scopeMerchant($query, $request);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return CategoryResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);

        return CategoryResource::make(Category::create($data));
    }

    public function show(Request $request, int $category): CategoryResource
    {
        return CategoryResource::make($this->scopeMerchant(Category::query(), $request)->findOrFail($category));
    }

    public function update(UpdateCategoryRequest $request, int $category): CategoryResource
    {
        $model = $this->scopeMerchant(Category::query(), $request)->findOrFail($category);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $model->update($data);

        return CategoryResource::make($model->fresh());
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        $this->scopeMerchant(Category::query(), $request)->findOrFail($category)->delete();

        return response()->json(status: 204);
    }
}
