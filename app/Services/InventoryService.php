<?php

namespace App\Services;

use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Lock and validate products for an incoming order items payload.
     *
     * @param int $merchantId
     * @param array $items
     * @return Collection<int, Product>
     * @throws ValidationException
     */
    public function lockAndValidateProductsForOrder(int $merchantId, array $items): Collection
    {
        $productIds = collect($items)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, Product> $products */
        $products = Product::where('merchant_id', $merchantId)
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::whereIn('id', collect($items)->pluck('product_variant_id')->filter()->unique()->sort())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($products as $product) {
            $product->setRelation('lockedVariants', $variants);
        }

        $requestedQuantities = [];
        $variantQuantities = [];

        foreach ($items as $index => $item) {
            if (empty($item['product_id'])) {
                continue;
            }

            $pid = (int) $item['product_id'];
            /** @var Product|null $product */
            $product = $products->get($pid);

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ["Product #{$pid} does not exist or does not belong to the merchant."],
                ]);
            }

            if ($product->status !== ProductStatus::Active) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ["The product '{$product->name}' is not active and cannot be ordered."],
                ]);
            }
            $variant = null;
            if (isset($item['product_variant_id'])) {
                $variant = $variants->get((int) $item['product_variant_id']);
                if (! $variant || $variant->product_id !== $pid) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_variant_id" => ['The variant does not belong to the selected product.'],
                    ]);
                }
            }

            if (isset($item['unit_price'])) {
                $submittedPrice = round((float) $item['unit_price'], 2);
                $actualPrice = round((float) ($variant?->price ?? $product->price), 2);

                if (abs($submittedPrice - $actualPrice) > 0.001) {
                    throw ValidationException::withMessages([
                        "items.{$index}.unit_price" => ["The unit price for '{$product->name}' does not match the current product price."],
                    ]);
                }
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            if ($variant) {
                $variantQuantities[$variant->id] = ($variantQuantities[$variant->id] ?? 0) + $quantity;
            } else {
                $requestedQuantities[$pid] = ($requestedQuantities[$pid] ?? 0) + $quantity;
            }
        }
        foreach ($variantQuantities as $id => $quantity) {
            if ($variants->get($id)->stock < $quantity) {
                throw ValidationException::withMessages(['items' => ['Insufficient variant stock.']]);
            }
        }

        foreach ($requestedQuantities as $pid => $totalQty) {
            $product = $products->get($pid);
            if ($product && $product->stock_quantity < $totalQty) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for product '{$product->name}'. Available: {$product->stock_quantity}, requested: {$totalQty}."],
                ]);
            }
        }

        return $products;
    }

    /**
     * Deduct stock for order items and write inventory logs.
     */
    public function deductStockForOrder(Order $order, array $items, Collection $lockedProducts, ?int $userId = null): void
    {
        $quantities = [];
        $variantQuantities = [];
        foreach ($items as $item) {
            if (! empty($item['product_id'])) {
                $pid = (int) $item['product_id'];
                if (! empty($item['product_variant_id'])) {
                    $vid = (int) $item['product_variant_id'];
                    $variantQuantities[$vid] = ($variantQuantities[$vid] ?? 0) + (int) $item['quantity'];
                } else {
                    $quantities[$pid] = ($quantities[$pid] ?? 0) + (int) $item['quantity'];
                }
            }
        }
        foreach ($variantQuantities as $id => $qty) {
            $variant = $lockedProducts->first()->getRelation('lockedVariants')->get($id);
            $variant->decrement('stock', $qty);
            InventoryLog::create([
                'merchant_id' => $order->merchant_id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'user_id' => $userId,
                'type' => InventoryMovementType::Sale,
                'reason' => 'Order created: '.$order->order_number,
                'quantity_change' => -$qty,
                'resulting_stock' => $variant->stock,
                'reference_type' => 'order',
                'reference_number' => $order->order_number,
                'notes' => 'Deducted variant #'.$id.' for order #'.$order->order_number,
                'created_at' => now(),
            ]);
        }

        foreach ($quantities as $pid => $qty) {
            /** @var Product|null $product */
            $product = $lockedProducts->get($pid);
            if ($product) {
                $newStock = $product->stock_quantity - $qty;
                $product->update(['stock_quantity' => $newStock]);

                InventoryLog::create([
                    'merchant_id' => $order->merchant_id,
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'type' => InventoryMovementType::Sale,
                    'reason' => 'Order created: ' . $order->order_number,
                    'quantity_change' => -$qty,
                    'resulting_stock' => $newStock,
                    'reference_type' => 'order',
                    'reference_number' => $order->order_number,
                    'notes' => "Deducted for order #{$order->order_number}",
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * Restore stock for an order idempotently.
     */
    public function restoreStockForOrder(
        Order $order,
        string $reason,
        ?int $userId = null,
        InventoryMovementType $type = InventoryMovementType::Cancellation,
    ): bool
    {
        if ($order->inventory_restored) {
            return false;
        }

        $order->loadMissing('items');

        $productIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($productIds->isNotEmpty()) {
            $products = Product::where('merchant_id', $order->merchant_id)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variantQuantities = $order->items->whereNotNull('product_variant_id')
                ->groupBy('product_variant_id')->map(fn ($items) => $items->sum('quantity'));
            $variants = ProductVariant::whereIn('id', $variantQuantities->keys()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($variantQuantities as $id => $qty) {
                if ($variant = $variants->get($id)) {
                    $variant->increment('stock', $qty);
                    InventoryLog::create([
                        'merchant_id' => $order->merchant_id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'user_id' => $userId,
                        'type' => $type,
                        'reason' => $reason,
                        'quantity_change' => $qty,
                        'resulting_stock' => $variant->stock,
                        'reference_type' => 'order',
                        'reference_number' => $order->order_number,
                        'notes' => 'Restored variant #'.$id.' for order #'.$order->order_number,
                        'created_at' => now(),
                    ]);
                }
            }

            $quantities = [];
            foreach ($order->items as $item) {
                if ($item->product_id && ! $item->product_variant_id) {
                    $pid = (int) $item->product_id;
                    $quantities[$pid] = ($quantities[$pid] ?? 0) + (int) $item->quantity;
                }
            }

            foreach ($quantities as $pid => $qty) {
                $product = $products->get($pid);
                if ($product) {
                    $newStock = $product->stock_quantity + $qty;
                    $product->update(['stock_quantity' => $newStock]);

                    InventoryLog::create([
                        'merchant_id' => $order->merchant_id,
                        'product_id' => $product->id,
                        'user_id' => $userId,
                        'type' => $type,
                        'reason' => $reason,
                        'quantity_change' => $qty,
                        'resulting_stock' => $newStock,
                        'reference_type' => 'order',
                        'reference_number' => $order->order_number,
                        'notes' => "Restored stock for order #{$order->order_number}",
                        'created_at' => now(),
                    ]);
                }
            }
        }

        $order->update(['inventory_restored' => true]);

        return true;
    }

    /**
     * Restore stock for the items of a processed return request and write inventory logs.
     *
     * Rows are locked in ascending id order (products, then variants) to match order creation.
     */
    public function restoreStockForReturn(ReturnRequest $return, Order $order, ?int $userId = null): void
    {
        $quantities = [];
        $variantQuantities = [];

        foreach ($return->items()->with('orderItem')->get() as $item) {
            if (! $item->orderItem->product_id) {
                continue;
            }

            if ($variantId = $item->orderItem->product_variant_id) {
                $variantQuantities[$variantId] = ($variantQuantities[$variantId] ?? 0) + $item->quantity;
            } else {
                $productId = $item->orderItem->product_id;
                $quantities[$productId] = ($quantities[$productId] ?? 0) + $item->quantity;
            }
        }

        $logAttributes = [
            'merchant_id' => $order->merchant_id,
            'user_id' => $userId,
            'type' => InventoryMovementType::Return,
            'reason' => 'Return processed: '.$return->id,
            'reference_type' => 'return_request',
            'reference_number' => (string) $return->id,
            'created_at' => now(),
        ];

        ksort($quantities);
        foreach ($quantities as $productId => $quantity) {
            $product = Product::where('merchant_id', $order->merchant_id)->lockForUpdate()->find($productId);

            if ($product) {
                $product->increment('stock_quantity', $quantity);
                InventoryLog::create([
                    ...$logAttributes,
                    'product_id' => $productId,
                    'quantity_change' => $quantity,
                    'resulting_stock' => $product->stock_quantity,
                    'notes' => 'Restored returned items for order #'.$order->order_number,
                ]);
            }
        }

        ksort($variantQuantities);
        foreach ($variantQuantities as $variantId => $quantity) {
            $variant = ProductVariant::whereHas('product', fn ($query) => $query->where('merchant_id', $order->merchant_id))
                ->lockForUpdate()
                ->find($variantId);

            if ($variant) {
                $variant->increment('stock', $quantity);
                InventoryLog::create([
                    ...$logAttributes,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'quantity_change' => $quantity,
                    'resulting_stock' => $variant->stock,
                    'notes' => 'Restored variant #'.$variantId.' for order #'.$order->order_number,
                ]);
            }
        }
    }

    /**
     * Manually adjust a product's (or one of its variants') stock and record the movement.
     *
     * Stored stock (products.stock_quantity / product_variants.stock) is the available quantity:
     * order creation already deducts it. On-hand stock is available plus the quantity reserved by
     * open orders, so "set" replaces the on-hand count and can never drop below what is reserved.
     *
     * @param  array{reason?: ?string, reference_type?: ?string, reference_number?: ?string, supplier?: ?string, reference_date?: ?string, notes?: ?string}  $details
     *
     * @throws ValidationException
     */
    public function adjustStock(
        Product $product,
        ?int $variantId,
        InventoryAdjustmentType $type,
        int $quantity,
        array $details = [],
        ?int $userId = null,
        string $errorField = 'quantity',
    ): InventoryLog {
        return DB::transaction(function () use ($product, $variantId, $type, $quantity, $details, $userId, $errorField): InventoryLog {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variant = null;

            if ($variantId !== null) {
                $variant = ProductVariant::where('product_id', $product->id)->whereKey($variantId)->lockForUpdate()->first();

                if (! $variant) {
                    throw ValidationException::withMessages([
                        'product_variant_id' => ['The selected variant does not belong to this product.'],
                    ]);
                }
            }

            $available = $variant ? (int) $variant->stock : (int) $product->stock_quantity;
            $reserved = $this->reservedQuantity($product->id, $variant?->id);

            $change = match ($type) {
                InventoryAdjustmentType::Increase => $quantity,
                InventoryAdjustmentType::Decrease => -$quantity,
                InventoryAdjustmentType::Set => $quantity - ($available + $reserved),
            };

            if ($change === 0) {
                throw ValidationException::withMessages([
                    $errorField => ['The new stock level matches the current stock.'],
                ]);
            }

            $newAvailable = $available + $change;

            if ($newAvailable < 0) {
                throw ValidationException::withMessages([
                    $errorField => [$type === InventoryAdjustmentType::Set
                        ? "Stock cannot be set below the {$reserved} unit(s) reserved for open orders."
                        : "The resulting stock cannot be negative. Available: {$available}."],
                ]);
            }

            if ($variant) {
                $variant->update(['stock' => $newAvailable]);
            } else {
                $product->update(['stock_quantity' => $newAvailable]);
            }

            return InventoryLog::create([
                'merchant_id' => $product->merchant_id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'user_id' => $userId,
                'type' => $type->movementType(),
                'reason' => filled($details['reason'] ?? null) ? $details['reason'] : $type->defaultReason(),
                'quantity_change' => $change,
                'resulting_stock' => $newAvailable,
                'reference_type' => $details['reference_type'] ?? null,
                'reference_number' => $details['reference_number'] ?? null,
                'supplier' => $details['supplier'] ?? null,
                'reference_date' => $details['reference_date'] ?? null,
                'notes' => $details['notes'] ?? null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Quantity of a product's base stock (or a variant's stock) held by open orders.
     */
    public function reservedQuantity(int $productId, ?int $variantId = null): int
    {
        return (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::reservingOrderStatuses())
            ->where('orders.inventory_restored', false)
            ->when(
                $variantId,
                fn ($query) => $query->where('order_items.product_variant_id', $variantId),
                fn ($query) => $query->where('order_items.product_id', $productId)->whereNull('order_items.product_variant_id'),
            )
            ->sum('order_items.quantity');
    }

    /**
     * Every stock pool as one row: each product's base stock plus each of its variants.
     *
     * Columns: item_type, product_id, product_variant_id, merchant_id, category_id, category_name,
     * name, sku, brand, product_status, color, size, images, price, cost_price, track_inventory,
     * low_stock_threshold, available, reserved, on_hand, stock_status, updated_at.
     */
    public function inventoryItemsQuery(?int $merchantId = null): Builder
    {
        $products = DB::table('products')
            ->leftJoinSub($this->reservedQuantitiesQuery('product_id', $merchantId), 'reserved', 'reserved.item_id', '=', 'products.id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                DB::raw("'product' as item_type"),
                'products.id as product_id',
                DB::raw('NULL as product_variant_id'),
                'products.merchant_id',
                'products.category_id',
                'categories.name as category_name',
                'products.name',
                'products.sku',
                'products.brand',
                'products.status as product_status',
                DB::raw('NULL as color'),
                DB::raw('NULL as size'),
                'products.images',
                'products.price',
                'products.cost_price',
                'products.track_inventory',
                'products.low_stock_threshold',
                'products.stock_quantity as available',
                DB::raw('COALESCE(reserved.quantity, 0) as reserved'),
                DB::raw('products.stock_quantity + COALESCE(reserved.quantity, 0) as on_hand'),
                DB::raw($this->stockStatusExpression('products.stock_quantity').' as stock_status'),
                'products.updated_at',
            ]);

        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoinSub($this->reservedQuantitiesQuery('product_variant_id', $merchantId), 'reserved', 'reserved.item_id', '=', 'product_variants.id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                DB::raw("'variant' as item_type"),
                'products.id as product_id',
                'product_variants.id as product_variant_id',
                'products.merchant_id',
                'products.category_id',
                'categories.name as category_name',
                'products.name',
                'product_variants.sku',
                'products.brand',
                'products.status as product_status',
                'product_variants.color',
                'product_variants.size',
                'products.images',
                'product_variants.price',
                'products.cost_price',
                'products.track_inventory',
                'products.low_stock_threshold',
                'product_variants.stock as available',
                DB::raw('COALESCE(reserved.quantity, 0) as reserved'),
                DB::raw('product_variants.stock + COALESCE(reserved.quantity, 0) as on_hand'),
                DB::raw($this->stockStatusExpression('product_variants.stock').' as stock_status'),
                'product_variants.updated_at',
            ]);

        if ($merchantId !== null) {
            $products->where('products.merchant_id', $merchantId);
            $variants->where('products.merchant_id', $merchantId);
        }

        return DB::query()->fromSub($products->unionAll($variants), 'inventory_items');
    }

    /**
     * Aggregate stock figures for an inventory items query.
     *
     * @return array{total_items: int, total_skus: int, in_stock: int, low_stock: int, out_of_stock: int, reserved_items: int, total_on_hand: int, total_reserved: int, total_available: int, inventory_value: string}
     */
    public function summarize(Builder $items): array
    {
        $row = (clone $items)->selectRaw(
            'COUNT(*) as total_items,
            COUNT(DISTINCT sku) as total_skus,
            SUM(CASE WHEN stock_status = ? THEN 1 ELSE 0 END) as in_stock,
            SUM(CASE WHEN stock_status = ? THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN stock_status = ? THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN reserved > 0 THEN 1 ELSE 0 END) as reserved_items,
            SUM(on_hand) as total_on_hand,
            SUM(reserved) as total_reserved,
            SUM(available) as total_available,
            SUM(on_hand * COALESCE(cost_price, 0)) as inventory_value',
            ['active', 'low_stock', 'out_of_stock'],
        )->first();

        return [
            'total_items' => (int) $row->total_items,
            'total_skus' => (int) $row->total_skus,
            'in_stock' => (int) $row->in_stock,
            'low_stock' => (int) $row->low_stock,
            'out_of_stock' => (int) $row->out_of_stock,
            'reserved_items' => (int) $row->reserved_items,
            'total_on_hand' => (int) $row->total_on_hand,
            'total_reserved' => (int) $row->total_reserved,
            'total_available' => (int) $row->total_available,
            'inventory_value' => number_format((float) $row->inventory_value, 2, '.', ''),
        ];
    }

    /**
     * @return list<string>
     */
    public static function reservingOrderStatuses(): array
    {
        return [OrderStatus::Pending->value, OrderStatus::Processing->value, OrderStatus::OutForDelivery->value];
    }

    /**
     * Per product (base stock) or per variant quantities held by open orders.
     */
    protected function reservedQuantitiesQuery(string $column, ?int $merchantId): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::reservingOrderStatuses())
            ->where('orders.inventory_restored', false)
            ->whereNotNull("order_items.{$column}")
            ->when($column === 'product_id', fn ($query) => $query->whereNull('order_items.product_variant_id'))
            ->when($merchantId !== null, fn ($query) => $query->where('orders.merchant_id', $merchantId))
            ->groupBy("order_items.{$column}")
            ->select(["order_items.{$column} as item_id", DB::raw('SUM(order_items.quantity) as quantity')]);
    }

    /**
     * Mirrors Product::getStockStatusAttribute() so list filters and resources agree.
     */
    protected function stockStatusExpression(string $availableColumn): string
    {
        return "CASE WHEN {$availableColumn} <= 0 THEN 'out_of_stock' "
            ."WHEN products.track_inventory = 1 AND {$availableColumn} <= products.low_stock_threshold THEN 'low_stock' "
            ."ELSE 'active' END";
    }
}
