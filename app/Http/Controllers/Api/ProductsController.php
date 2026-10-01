<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'variants', 'imageRecords']);
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

        if ($stockStatus = $request->string('stock_status')->toString()) {
            match ($stockStatus) {
                'out_of_stock' => $query->where('stock_quantity', 0),
                'low_stock' => $query->where('stock_quantity', '>', 0)
                    ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                    ->where('track_inventory', true),
                'active' => $query->where('stock_quantity', '>', 0)
                    ->where(function ($builder): void {
                        $builder->where('track_inventory', false)
                            ->orWhereColumn('stock_quantity', '>', 'low_stock_threshold');
                    }),
                default => null,
            };
        }

        return ProductResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureCategoryBelongsToMerchant($data['merchant_id'], $data['category_id'] ?? null);
        $paths = $this->storeImages($request, $data['merchant_id']);

        try {
            $product = DB::transaction(function () use ($data, $paths, $request): Product {
                $data = $this->normaliseProductData($data);
                unset($data['images'], $data['main_image_index'], $data['variants']);
                $product = Product::create($data);
                $this->syncImages($product, $paths, $request->integer('main_image_index'));
                $this->syncVariants($product, $request->input('variants'));

                return $product;
            });
        } catch (Throwable $exception) {
            $this->discardStoredImages($paths ?? []);
            throw $exception;
        }

        return ProductResource::make($product->load(['category', 'variants', 'imageRecords']));
    }

    public function show(Request $request, int $product): ProductResource
    {
        return ProductResource::make($this->scopeMerchant(Product::query()->with(['category', 'variants', 'imageRecords']), $request)->findOrFail($product));
    }

    public function update(UpdateProductRequest $request, int $product): ProductResource
    {
        $model = $this->scopeMerchant(Product::query()->with(['category', 'variants', 'imageRecords']), $request)->findOrFail($product);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $this->ensureCategoryBelongsToMerchant($data['merchant_id'], $data['category_id'] ?? $model->category_id);

        $paths = $this->storeImages($request, $data['merchant_id']);
        $oldPaths = $model->imageRecords->pluck('path')->merge($model->images ?? [])->all();

        try {
            DB::transaction(function () use ($data, $model, $paths, $request): void {
                $model = Product::whereKey($model->id)->lockForUpdate()->firstOrFail();
                if ($model->merchant_id !== $data['merchant_id'] && $model->orderItems()->exists()) {
                    throw ValidationException::withMessages(['merchant_id' => ['Ordered products cannot be transferred between merchants.']]);
                }
                $data = $this->normaliseProductData($data);
                unset($data['images'], $data['main_image_index'], $data['image_ids'], $data['main_image_id'], $data['variants']);
                $model->update($data);

                if ($paths !== null) {
                    $model->imageRecords()->delete();
                    $this->syncImages($model, $paths, $request->integer('main_image_index'));
                } elseif ($request->has('image_ids')) {
                    $this->syncExistingImages(
                        $model,
                        $request->input('image_ids', []),
                        $request->integer('main_image_id'),
                    );
                }

                if ($request->has('variants')) {
                    $this->syncVariants($model, $request->input('variants'));
                }
            });
        } catch (Throwable $exception) {
            $this->discardStoredImages($paths ?? []);
            throw $exception;
        }

        if ($paths !== null) {
            $this->discardStoredImages(array_diff($oldPaths, $paths));
        }

        return ProductResource::make($model->fresh()->load(['category', 'variants', 'imageRecords']));
    }

    public function destroy(Request $request, int $product)
    {
        DB::transaction(function () use ($request, $product): void {
            $model = $this->scopeMerchant(Product::query(), $request)->lockForUpdate()->findOrFail($product);
            abort_if($model->orderItems()->exists(), 409, 'Products referenced by orders cannot be deleted.');
            $model->delete();
        });

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

    protected function normaliseProductData(array $data): array
    {
        $data['price'] = $data['regular_price'] ?? $data['price'] ?? null;
        $data['regular_price'] = $data['regular_price'] ?? $data['price'];
        $data['description'] = $data['full_description'] ?? $data['description'] ?? null;

        return $data;
    }

    protected function syncImages(Product $product, ?array $paths, ?int $mainIndex): void
    {
        if ($paths === null) {
            return;
        }

        if ($mainIndex !== null && $mainIndex >= count($paths)) {
            throw ValidationException::withMessages([
                'main_image_index' => ['The selected main image does not exist.'],
            ]);
        }

        $product->update(['images' => $paths]);
        foreach ($paths as $index => $path) {
            $product->imageRecords()->create([
                'path' => $path,
                'is_main' => $mainIndex === null ? $index === 0 : $index === $mainIndex,
                'sort_order' => $index,
            ]);
        }
    }

    protected function syncVariants(Product $product, mixed $variants): void
    {
        if ($variants === null) {
            return;
        }

        $existing = $product->variants()->lockForUpdate()->get()->keyBy('sku');
        $submitted = collect($variants)->pluck('sku');
        foreach ($existing as $variant) {
            if (! $submitted->contains($variant->sku)) {
                if (\App\Models\OrderItem::where('product_variant_id', $variant->id)->exists()) {
                    throw ValidationException::withMessages(['variants' => ['Ordered variants cannot be removed.']]);
                }
                $variant->delete();
            }
        }
        foreach ($variants as $index => $variant) {
            $attributes = [
                'sku' => $variant['sku'],
                'color' => $variant['color'] ?? null,
                'size' => $variant['size'] ?? null,
                'attributes' => $variant['attributes'] ?? null,
                'price' => $variant['price'],
                'stock' => $variant['stock'],
                'sort_order' => $variant['sort_order'] ?? $index,
            ];
            if ($current = $existing->get($variant['sku'])) {
                $current->update($attributes);
            } else {
                $product->variants()->create($attributes);
            }
        }
    }

    protected function syncExistingImages(Product $product, array $imageIds, ?int $mainId): void
    {
        $images = $product->imageRecords()->whereIn('id', $imageIds)->get()
            ->sortBy(fn ($image) => array_search($image->id, $imageIds, true))
            ->values();

        if ($images->isEmpty()) {
            $product->imageRecords()->delete();
        } else {
            $product->imageRecords()->whereNotIn('id', $images->pluck('id'))->delete();
        }
        foreach ($images as $index => $image) {
            $image->update([
                'sort_order' => $index,
                'is_main' => $mainId ? $image->id === $mainId : $index === 0,
            ]);
        }
        $product->update(['images' => $images->pluck('path')->values()->all()]);
    }
}
