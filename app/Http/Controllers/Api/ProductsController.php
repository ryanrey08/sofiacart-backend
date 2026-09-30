<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Product::query()->with('category');
        $this->scopeMerchant($query, $request);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($categoryId = $request->integer('category_id')) {
            $query->where('category_id', $categoryId);
        }

        return ProductResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureCategoryBelongsToMerchant($data['merchant_id'], $data['category_id'] ?? null);
        $data['images'] = $this->storeImages($request, $data['merchant_id']);

        $product = Product::create($data);

        return ProductResource::make($product->load('category'));
    }

    public function show(Request $request, int $product): ProductResource
    {
        return ProductResource::make($this->scopeMerchant(Product::query()->with('category'), $request)->findOrFail($product));
    }

    public function update(UpdateProductRequest $request, int $product): ProductResource
    {
        $model = $this->scopeMerchant(Product::query()->with('category'), $request)->findOrFail($product);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $this->ensureCategoryBelongsToMerchant($data['merchant_id'], $data['category_id'] ?? $model->category_id);

        if ($images = $this->storeImages($request, $data['merchant_id'])) {
            $data['images'] = $images;
        }

        $model->update($data);

        return ProductResource::make($model->fresh()->load('category'));
    }

    public function destroy(Request $request, int $product)
    {
        $this->scopeMerchant(Product::query(), $request)->findOrFail($product)->delete();

        return response()->json(status: 204);
    }

    protected function storeImages(Request $request, int $merchantId): ?array
    {
        if (! $request->hasFile('images')) {
            return null;
        }

        $paths = [];

        foreach ($request->file('images') as $index => $file) {
            try {
                $path = $file->store("products/{$merchantId}", 'public');
            } catch (Throwable) {
                $this->discardStoredImages($paths);

                throw ValidationException::withMessages([
                    "images.{$index}" => ["The images.{$index} failed to upload."],
                ]);
            }

            if (! is_string($path) || $path === '') {
                $this->discardStoredImages($paths);

                throw ValidationException::withMessages([
                    "images.{$index}" => ["The images.{$index} failed to upload."],
                ]);
            }

            $paths[] = $path;
        }

        return $paths;
    }

    protected function discardStoredImages(array $paths): void
    {
        try {
            Storage::disk('public')->delete($paths);
        } catch (Throwable) {
        }
    }

    protected function ensureCategoryBelongsToMerchant(int $merchantId, ?int $categoryId): void
    {
        if ($categoryId) {
            Category::where('merchant_id', $merchantId)->findOrFail($categoryId);
        }
    }
}
