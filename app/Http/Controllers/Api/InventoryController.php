<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Requests\ListInventoryItemsRequest;
use App\Http\Requests\ListInventoryLogsRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\ProductResource;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    use InteractsWithMerchantScope;

    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Paginated stock pools (product base stock and variants) with on-hand/reserved/available figures.
     */
    public function items(ListInventoryItemsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewInventory', Product::class);

        $query = $this->inventoryService->inventoryItemsQuery($this->inventoryMerchantId($request));

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($builder) use ($search): void {
                foreach (['name', 'sku', 'brand', 'category_name', 'color', 'size'] as $column) {
                    $builder->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        foreach (['category_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        if ($request->filled('stock_status')) {
            $query->where('stock_status', $request->validated('stock_status'));
        }

        if ($request->filled('type')) {
            $query->where('item_type', $request->validated('type'));
        }

        [$column, $direction] = match ($request->validated('sort')) {
            'name_desc' => ['name', 'desc'],
            'sku_asc' => ['sku', 'asc'],
            'sku_desc' => ['sku', 'desc'],
            'stock_asc' => ['on_hand', 'asc'],
            'stock_desc' => ['on_hand', 'desc'],
            'available_asc' => ['available', 'asc'],
            'available_desc' => ['available', 'desc'],
            'reserved_asc' => ['reserved', 'asc'],
            'reserved_desc' => ['reserved', 'desc'],
            'updated_asc' => ['updated_at', 'asc'],
            'updated_desc' => ['updated_at', 'desc'],
            default => ['name', 'asc'],
        };

        $query->orderBy($column, $direction)
            ->orderBy('product_id')
            ->orderBy('item_type')
            ->orderBy('product_variant_id');

        return InventoryItemResource::collection($query->paginate($this->pageSize($request))->withQueryString());
    }

    /**
     * Totals for the inventory overview cards and stock-status chart.
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewInventory', Product::class);

        $query = $this->inventoryService->inventoryItemsQuery($this->inventoryMerchantId($request));

        return response()->json(['data' => $this->inventoryService->summarize($query)]);
    }

    /**
     * Stock details for one product: the product, each of its stock pools and recent movements.
     */
    public function show(Request $request, int $product): JsonResponse
    {
        $model = $this->scopeMerchant(Product::query(), $request)->findOrFail($product);
        Gate::authorize('viewInventory', $model);

        $items = $this->inventoryService->inventoryItemsQuery($model->merchant_id)
            ->where('product_id', $model->id)
            ->orderBy('item_type')
            ->orderBy('product_variant_id')
            ->get();

        $movements = $model->inventoryLogs()
            ->with(['variant', 'user'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'product' => ProductResource::make($model->load(['category', 'variants', 'imageRecords'])),
                'items' => InventoryItemResource::collection($items),
                'recent_movements' => InventoryLogResource::collection($movements),
            ],
        ]);
    }

    /**
     * Stock movement history.
     */
    public function index(ListInventoryLogsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewInventory', Product::class);

        $query = InventoryLog::query()->with(['product', 'variant', 'user']);
        $this->scopeMerchant($query, $request);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        foreach (['product_id', 'product_variant_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        foreach (['type', 'reference_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        if ($reason = $request->string('reason')->toString()) {
            $query->where('reason', 'like', "%{$reason}%");
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('supplier', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                    ->orWhereHas('variant', fn (Builder $variant) => $variant->where('sku', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $request->validated('sort') === 'oldest'
            ? $query->oldest('created_at')->orderBy('id')
            : $query->latest('created_at')->orderByDesc('id');

        return InventoryLogResource::collection($query->paginate($this->pageSize($request))->withQueryString());
    }

    /**
     * Increase, decrease or set stock for a product or one of its variants.
     */
    public function adjust(AdjustInventoryRequest $request): JsonResponse
    {
        $product = $this->scopeMerchant(Product::query(), $request)->findOrFail($request->integer('product_id'));
        Gate::authorize('manageInventory', $product);

        $variantId = $request->filled('product_variant_id') ? $request->integer('product_variant_id') : null;

        $log = $this->inventoryService->adjustStock(
            $product,
            $variantId,
            $request->adjustmentType(),
            $request->adjustmentQuantity(),
            $request->safe()->only(['reason', 'reference_type', 'reference_number', 'supplier', 'reference_date', 'notes']),
            $request->user()?->id,
            $request->quantityField(),
        );

        $item = $this->inventoryService->inventoryItemsQuery($product->merchant_id)
            ->where('product_id', $product->id)
            ->where('item_type', $variantId ? 'variant' : 'product')
            ->when($variantId, fn ($query) => $query->where('product_variant_id', $variantId))
            ->first();

        return response()->json([
            'message' => 'Inventory adjusted successfully.',
            'product' => ProductResource::make($product->refresh()->load('category')),
            'inventory_item' => InventoryItemResource::make($item),
            'inventory_log' => InventoryLogResource::make($log->load(['product', 'variant', 'user'])),
        ]);
    }

    /**
     * Merchants always see their own stock; admins see every merchant unless they filter by one.
     */
    protected function inventoryMerchantId(Request $request): ?int
    {
        if ($this->isAdmin($request)) {
            return $request->filled('merchant_id') ? $request->integer('merchant_id') : null;
        }

        return $this->requiredMerchantId($request);
    }
}
