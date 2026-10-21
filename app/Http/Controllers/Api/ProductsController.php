<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductChangeRequestStatus;
use App\Enums\ProductStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Concerns\SyncsProductMedia;
use App\Services\ProductApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductsController extends Controller
{
    use InteractsWithMerchantScope;
    use SyncsProductMedia;

    /**
     * Relations every product response needs: catalog data, the merchant (for the listing status)
     * and the approval state.
     */
    protected const RELATIONS = [
        'category',
        'variants',
        'imageRecords',
        'merchant:id,store_name,store_slug,status',
        'pendingChangeRequest',
        'latestChangeRequest',
    ];

    public function __construct(
        protected ProductApprovalService $approvals,
    ) {}

    public function index(Request $request)
    {
        $query = Product::query()->with(self::RELATIONS);
        $this->scopeMerchant($query, $request);

        if ($this->isAdmin($request)) {
            if ($request->filled('merchant_id')) {
                $query->where('merchant_id', $request->integer('merchant_id'));
            }
        }

        // `review=pending`: new submissions plus approved products with edits awaiting approval.
        if ($request->string('review')->toString() === 'pending') {
            $query->awaitingReview();
        }

        if ($request->boolean('has_pending_changes')) {
            $query->whereHas('changeRequests', fn ($requests) => $requests->where('status', ProductChangeRequestStatus::Pending->value));
        }

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

        // The review queue is worked oldest submission first; everything else lists newest first.
        $request->string('review')->toString() === 'pending'
            ? $query->orderByRaw('COALESCE(submitted_at, updated_at) asc')->orderBy('id')
            : $query->latest();

        return ProductResource::collection($query->paginate($this->pageSize($request)));
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureCategoryBelongsToMerchant($data['merchant_id'], $data['category_id'] ?? null);
        // New products go to review unless saved as a draft; they are never created active.
        $data['status'] = ProductStatus::from($data['status'] ?? ProductStatus::PendingApproval->value);
        $this->approvals->assertStatusChangeAllowed(null, $data['status']);
        $paths = $this->storeImages($request, $data['merchant_id']);

        try {
            $product = DB::transaction(function () use ($data, $paths, $request): Product {
                $data = $this->normaliseProductData($data);
                unset($data['images'], $data['main_image_index'], $data['variants']);
                $product = Product::create($data);
                $this->syncImages($product, $paths, $request->integer('main_image_index'));
                $this->syncVariants($product, $request->input('variants'));

                if ($product->status === ProductStatus::PendingApproval) {
                    $this->approvals->markSubmitted($product, $request->user(), $request);
                }

                return $product;
            });
        } catch (Throwable $exception) {
            $this->discardStoredImages($paths ?? []);
            throw $exception;
        }

        return ProductResource::make($product->load(self::RELATIONS));
    }

    public function show(Request $request, int $product): ProductResource
    {
        $query = Product::query()->with(self::RELATIONS);

        if (! $this->isAdmin($request)) {
            return ProductResource::make($this->scopeMerchant($query, $request)->findOrFail($product));
        }

        $model = $query->with(['reviewer:id,name', 'pendingChangeRequest.submitter:id,name', 'latestChangeRequest.reviewer:id,name'])
            ->findOrFail($product);

        return ProductResource::make($model)->additional(['meta' => [
            'review_history' => $this->approvals->history($model)->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'reason' => $log->metadata['reason'] ?? null,
                'fields' => $log->metadata['fields'] ?? null,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'role' => $log->actor->role?->value] : null,
                'created_at' => $log->created_at?->toISOString(),
            ])->values(),
        ]]);
    }

    public function update(UpdateProductRequest $request, int $product): ProductResource
    {
        $model = $this->scopeMerchant(Product::query()->with(['category', 'variants', 'imageRecords']), $request)->findOrFail($product);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $this->ensureCategoryBelongsToMerchant($data['merchant_id'], $data['category_id'] ?? $model->category_id);

        $paths = $this->storeImages($request, $data['merchant_id']);
        $oldPaths = $model->imageRecords->pluck('path')->merge($model->images ?? [])->all();
        $mainImageIndex = $request->filled('main_image_index') ? $request->integer('main_image_index') : null;
        $galleryReplaced = false;

        if ($paths !== null && $mainImageIndex !== null && $mainImageIndex >= count($paths)) {
            $this->discardStoredImages($paths);

            throw ValidationException::withMessages(['main_image_index' => ['The selected main image does not exist.']]);
        }

        try {
            DB::transaction(function () use ($data, $model, $paths, $mainImageIndex, $request, &$galleryReplaced): void {
                $model = Product::whereKey($model->id)->lockForUpdate()->firstOrFail();
                if ($model->merchant_id !== $data['merchant_id'] && $model->orderItems()->exists()) {
                    throw ValidationException::withMessages(['merchant_id' => ['Ordered products cannot be transferred between merchants.']]);
                }
                $data = $this->normaliseProductData($data);
                $target = isset($data['status']) ? ProductStatus::from($data['status']) : null;
                $this->approvals->assertStatusChangeAllowed($model, $target);

                $imageIds = $request->has('image_ids') ? array_map('intval', (array) $request->input('image_ids', [])) : null;
                $mainImageId = $request->filled('main_image_id') ? $request->integer('main_image_id') : null;
                $variants = $request->has('variants') ? $request->input('variants') : null;
                $diff = $this->approvals->contentChanges(
                    $model,
                    $data,
                    $paths === null ? null : ['paths' => $paths, 'main_index' => $mainImageIndex],
                    $imageIds === null ? null : ['ids' => $imageIds, 'main_id' => $mainImageId],
                    is_array($variants) ? $variants : null,
                );

                if ($model->isApproved()) {
                    // Live product: operational fields apply now; customer-facing edits wait for review
                    // while the approved version stays on the storefront.
                    $model->update(array_intersect_key($data, array_flip(ProductApprovalService::DIRECT_FIELDS)));
                    if (is_array($variants)) {
                        $this->syncVariantStock($model, $variants);
                    }
                    $this->approvals->stageChanges($model, $request->user(), $diff, $request);

                    return;
                }

                $wasPending = $model->status === ProductStatus::PendingApproval;
                $staysPending = $target === null || $target === ProductStatus::PendingApproval;
                if ($wasPending && $staysPending && ($diff['changes'] !== [] || $diff['images'] !== null)) {
                    throw ValidationException::withMessages([
                        'status' => ['This product is awaiting approval and cannot be edited. Withdraw it from review to make changes.'],
                    ]);
                }

                unset($data['images'], $data['main_image_index'], $data['image_ids'], $data['main_image_id'], $data['variants']);
                $model->update($data);

                if ($paths !== null) {
                    $model->imageRecords()->delete();
                    $this->syncImages($model, $paths, $mainImageIndex);
                    $galleryReplaced = true;
                } elseif ($imageIds !== null) {
                    $this->syncExistingImages($model, $imageIds, $mainImageId);
                }

                if ($request->has('variants')) {
                    $this->syncVariants($model, $variants);
                }

                if (! $wasPending && $model->status === ProductStatus::PendingApproval) {
                    $this->approvals->markSubmitted($model, $request->user(), $request);
                } elseif ($wasPending && $model->status !== ProductStatus::PendingApproval) {
                    $this->approvals->markWithdrawn($model, $request->user(), $request);
                }
            });
        } catch (Throwable $exception) {
            $this->discardStoredImages($paths ?? []);
            throw $exception;
        }

        if ($galleryReplaced) {
            $this->discardStoredImages(array_diff($oldPaths, $paths));
        }

        return ProductResource::make($model->fresh()->load(self::RELATIONS));
    }

    public function destroy(Request $request, int $product)
    {
        $pendingUploads = [];

        DB::transaction(function () use ($request, $product, &$pendingUploads): void {
            $model = $this->scopeMerchant(Product::query(), $request)->lockForUpdate()->findOrFail($product);
            abort_if($model->orderItems()->exists(), 409, 'Products referenced by orders cannot be deleted.');
            $pendingUploads = $model->changeRequests()->pluck('images')
                ->flatMap(fn ($images) => $images['paths'] ?? [])->all();
            $model->delete();
        });

        $this->discardStoredImages($pendingUploads);

        return response()->json(status: 204);
    }

    /**
     * Merchant: send a draft, rejected or archived (never approved) product for review.
     */
    public function submit(Request $request, int $product): ProductResource
    {
        $model = $this->ownProduct($request, $product);

        return ProductResource::make($this->approvals->submit($model, $request->user(), $request)->fresh()->load(self::RELATIONS));
    }

    /**
     * Merchant: take a pending product out of the review queue (back to draft) to edit it.
     */
    public function withdraw(Request $request, int $product): ProductResource
    {
        $model = $this->ownProduct($request, $product);

        return ProductResource::make($this->approvals->withdraw($model, $request->user(), $request)->fresh()->load(self::RELATIONS));
    }

    /**
     * Merchant: cancel pending edits to an approved product. The live version is unaffected.
     */
    public function withdrawChanges(Request $request, int $product): ProductResource
    {
        $model = $this->ownProduct($request, $product);
        $changeRequest = $model->pendingChangeRequest()->first()
            ?? throw ValidationException::withMessages(['changes' => ['This product has no changes awaiting approval.']]);

        $this->approvals->withdrawChanges($changeRequest, $request->user(), $request);

        return ProductResource::make($model->fresh()->load(self::RELATIONS));
    }

    /**
     * The approval actions on /api/v1 are for the owning merchant; admins review through /api/admin.
     */
    protected function ownProduct(Request $request, int $product): Product
    {
        abort_if($this->isAdmin($request), 403, 'Admins review products through the admin product approval endpoints.');

        return $this->scopeMerchant(Product::query(), $request)->findOrFail($product);
    }

    /**
     * On an approved product, variant stock is operational and applies without review.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    protected function syncVariantStock(Product $product, array $variants): void
    {
        $existing = $product->variants()->lockForUpdate()->get()->keyBy('sku');

        foreach ($variants as $variant) {
            $current = $existing->get($variant['sku'] ?? null);

            if ($current && (int) $current->stock !== (int) $variant['stock']) {
                $current->update(['stock' => (int) $variant['stock']]);
            }
        }
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

    protected function ensureCategoryBelongsToMerchant(int $merchantId, ?int $categoryId): void
    {
        if ($categoryId) {
            Category::where('merchant_id', $merchantId)->findOrFail($categoryId);
        }
    }

    protected function normaliseProductData(array $data): array
    {
        // Partial updates (e.g. a status change) must not null out prices or the description.
        if (array_key_exists('regular_price', $data) || array_key_exists('price', $data)) {
            $data['price'] = $data['regular_price'] ?? $data['price'] ?? null;
            $data['regular_price'] = $data['regular_price'] ?? $data['price'];
        }

        if (array_key_exists('full_description', $data) || array_key_exists('description', $data)) {
            $data['description'] = $data['full_description'] ?? $data['description'] ?? null;
        }

        return $data;
    }
}
